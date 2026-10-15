<?php
/**
 * Guardia PHP del escudo (docs/02 módulo 6): corta tráfico basura antes de cargar plugins y tema.
 *
 * Corre en la etapa temprana (loader MU): sin funciones de WordPress, sin base de datos y sin
 * autoloader. Solo usa RequestType, que la etapa temprana ya cargó.
 *
 * - Asset inexistente que llegó a PHP (StaticMiss) → 404 vacío, cacheable 10 minutos.
 * - ?author=N sin sesión → 404 (enumeración de usuarios).
 * - XML-RPC → 403, salvo que un plugin activo lo requiera (la config ya lo resolvió).
 * - /wp-json/wp/v2/users sin sesión ni credenciales → 401, salvo que un plugin lo requiera.
 * - Precarga especulativa (Sec-Purpose: prefetch, de Speculation Rules, Speed Brain o cualquier otro
 *   script) de una URL con efectos → 503 sin ejecutar nada: agregar o quitar del carrito, cerrar sesión,
 *   enlaces con nonce, carrito, pago y cuenta. El navegador descarta la precarga y hace la petición
 *   normal si el visitante sí abre el enlace. Las reglas de Imagina Turbo ya excluyen estas URL; esto
 *   cubre las de otros (0.11.0).
 *
 * "Sin sesión" se decide por la presencia de la cookie wordpress_logged_in_: la sesión real todavía no
 * se puede validar aquí, así que un visitante con esa cookie conserva el comportamiento del core.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Shield;

use ImaginaTurbo\Core\Request\RequestType;

/**
 * Decisión y respuesta.
 */
final class EarlyGuard {

	public const STATIC_TTL = 600;

	/**
	 * Qué responder, o null si el request sigue.
	 *
	 * @param array<string, mixed> $shield  Ajustes compilados (static, author, xmlrpc, rest_users).
	 * @param array<mixed>         $get     $_GET.
	 * @param array<mixed>         $cookies $_COOKIE.
	 * @param array<mixed>         $server  $_SERVER.
	 * @return array{status: int, headers: list<string>, body: string, reason: string}|null
	 */
	public static function decide( RequestType $type, string $path, string $restPrefix, array $shield, array $get, array $cookies, array $server ): ?array {
		if ( RequestType::StaticMiss === $type && true === ( $shield['static'] ?? false ) ) {
			return [
				'status'  => 404,
				'headers' => [ 'Content-Type: text/plain; charset=utf-8', 'Cache-Control: public, max-age=' . self::STATIC_TTL, 'X-Robots-Tag: noindex' ],
				'body'    => '',
				'reason'  => 'static_miss',
			];
		}

		$loggedIn = self::hasLoginCookie( $cookies );

		if ( RequestType::FrontendHtml === $type && true === ( $shield['author'] ?? false ) && ! $loggedIn && isset( $get['author'] ) && is_scalar( $get['author'] ) && 1 === preg_match( '/^\s*\d+\s*$/', (string) $get['author'] ) ) {
			return [
				'status'  => 404,
				'headers' => [ 'Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store', 'X-Robots-Tag: noindex' ],
				'body'    => '',
				'reason'  => 'author',
			];
		}

		if ( RequestType::XmlRpc === $type && true === ( $shield['xmlrpc'] ?? false ) ) {
			return [
				'status'  => 403,
				'headers' => [ 'Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store' ],
				'body'    => 'XML-RPC disabled.',
				'reason'  => 'xmlrpc',
			];
		}

		if ( true === ( $shield['prefetch'] ?? false ) && self::isPrefetch( $server ) && self::hasSideEffects( $path, $get, is_array( $shield['prefetch_paths'] ?? null ) ? $shield['prefetch_paths'] : [] ) ) {
			return [
				'status'  => 503,
				'headers' => [ 'Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store', 'X-Robots-Tag: noindex' ],
				'body'    => '',
				'reason'  => 'prefetch',
			];
		}

		if ( RequestType::Rest === $type && true === ( $shield['rest_users'] ?? false ) && ! $loggedIn && ! self::hasCredentials( $server ) && self::isUsersRoute( $path, $restPrefix, $get ) ) {
			return [
				'status'  => 401,
				'headers' => [ 'Content-Type: application/json; charset=utf-8', 'Cache-Control: no-store' ],
				'body'    => '{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":401}}',
				'reason'  => 'rest_users',
			];
		}
		return null;
	}

	/**
	 * Envía la respuesta y termina el request (no se cargan plugins ni tema).
	 *
	 * @param array{status: int, headers: list<string>, body: string, reason: string} $response Respuesta.
	 */
	public static function respond( array $response ): never {
		if ( ! headers_sent() ) {
			http_response_code( $response['status'] );
			foreach ( $response['headers'] as $header ) {
				header( $header, true );
			}
			header( 'X-ITB-Shield: ' . $response['reason'], true );
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cuerpo fijo, sin datos del request.
		exit;
	}

	/**
	 * @param array<mixed> $cookies $_COOKIE.
	 */
	private static function hasLoginCookie( array $cookies ): bool {
		foreach ( array_keys( $cookies ) as $name ) {
			if ( is_string( $name ) && str_starts_with( $name, 'wordpress_logged_in_' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Credenciales HTTP (contraseñas de aplicación, JWT). El .htaccess de WordPress copia la cabecera
	 * Authorization a HTTP_AUTHORIZATION aunque venga vacía: solo cuenta si tiene valor.
	 *
	 * @param array<mixed> $server $_SERVER.
	 */
	private static function hasCredentials( array $server ): bool {
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER' ] as $key ) {
			if ( isset( $server[ $key ] ) && is_string( $server[ $key ] ) && '' !== trim( $server[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * /wp-json/wp/v2/users… o ?rest_route=/wp/v2/users…
	 *
	 * @param array<mixed> $get $_GET.
	 */
	public static function isUsersRoute( string $path, string $restPrefix, array $get ): bool {
		$base = '/' . trim( $restPrefix, '/' ) . '/wp/v2/users';
		if ( str_starts_with( strtolower( $path ), $base ) ) {
			return true;
		}
		$route = $get['rest_route'] ?? null;
		return is_string( $route ) && str_starts_with( strtolower( '/' . ltrim( $route, '/' ) ), '/wp/v2/users' );
	}

	/** Parámetros que cambian algo al abrir la URL (WooCommerce, core y plugins comunes). */
	public const SIDE_EFFECT_PARAMS = [ 'add-to-cart', 'remove_item', 'undo_item', 'removed_item', 'empty-cart', 'order_again', 'cancel_order', 'add_to_wishlist', 'remove_from_wishlist', 'add-to-compare', '_wpnonce', 'wc-ajax' ];

	/**
	 * Precarga especulativa: Sec-Purpose contiene "prefetch" (también "prefetch;prerender").
	 *
	 * @param array<mixed> $server $_SERVER.
	 */
	public static function isPrefetch( array $server ): bool {
		$purpose = $server['HTTP_SEC_PURPOSE'] ?? ( $server['HTTP_PURPOSE'] ?? '' );
		return is_string( $purpose ) && str_contains( strtolower( $purpose ), 'prefetch' );
	}

	/**
	 * La URL cambia algo al abrirse o es del flujo de compra o de la cuenta.
	 *
	 * @param array<mixed> $get   $_GET.
	 * @param array<mixed> $paths Prefijos de rutas que nunca se precargan (carrito, pago, cuenta).
	 */
	public static function hasSideEffects( string $path, array $get, array $paths ): bool {
		foreach ( self::SIDE_EFFECT_PARAMS as $param ) {
			if ( isset( $get[ $param ] ) ) {
				return true;
			}
		}
		if ( 'logout' === ( $get['action'] ?? null ) || str_contains( $path, 'customer-logout' ) || str_contains( $path, 'wp-login.php' ) ) {
			return true;
		}
		$path = rtrim( $path, '/' );
		foreach ( $paths as $prefix ) {
			if ( ! is_string( $prefix ) ) {
				continue;
			}
			$prefix = rtrim( $prefix, '/' );
			if ( '' !== $prefix && ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
