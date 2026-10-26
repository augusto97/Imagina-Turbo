<?php
/**
 * Respuestas AJAX de solo render, desde la caché de objetos y antes de cargar los plugins (0.17.0).
 *
 * WoodMart carga los megamenús por AJAX (admin-ajax.php?action=woodmart_load_html_dropdowns, POST con
 * los ids de los bloques HTML) en la primera interacción de cada visitante nuevo: 1,3 s de PHP en La
 * Tienda del Pintor, y el desplegable vacío hasta entonces. Lo mismo con las pestañas y la paginación
 * de carruseles de productos. Son renders puros: el mismo pedido da la misma respuesta.
 *
 * - Solo las acciones de la lista (compiladas según el tema y los plugins activos), solo visitantes sin
 *   sesión de WordPress, solo con caché de objetos persistente (la carga WordPress antes de los
 *   MU-plugins: wp_start_object_cache en wp-settings.php) y nunca si el pedido trae un nonce.
 * - Acierto: se responde aquí mismo, sin cargar plugins (~30 ms en lugar de 1,3 s).
 * - Fallo: la respuesta normal se copia al terminar (shutdown, antes de cerrar la caché de objetos)
 *   si es JSON, código 200, sin Set-Cookie y sin nonces. El buffer solo lee una copia de esa respuesta
 *   JSON: no es el HTML de la página ni se modifica (regla 2).
 * - La llave lleva la acción, los parámetros, las cookies de variación y la generación de
 *   FragmentCache (un cambio de contenido las invalida todas). Caducan a las 6 h.
 *
 * Corre desde el loader MU (sin autoloader): no usa otras clases del plugin.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

/**
 * Servir y guardar.
 */
final class AjaxFragments {

	/** Igual que FragmentCache::GROUP. */
	public const GROUP = 'itb_frag';

	public const TTL = 21600;

	public const MAX_BYTES = 1048576;

	/** Acciones conocidas de solo render, por el tema o plugin que las define (verificadas en su JS). */
	public const KNOWN = [
		'woodmart' => [ 'woodmart_load_html_dropdowns', 'woodmart_get_products_shortcode', 'woodmart_get_products_tab_shortcode' ],
	];

	private static string $key = '';

	/**
	 * @param list<string>          $actions Acciones permitidas.
	 * @param list<string>          $vary    Cookies de variación.
	 * @param array<string, mixed>  $request $_GET + $_POST.
	 * @param array<string, mixed>  $cookies $_COOKIE.
	 */
	public static function maybeServe( array $actions, array $vary, array $request, array $cookies ): void {
		$action = is_string( $request['action'] ?? null ) ? $request['action'] : '';
		if ( '' === $action || ! in_array( $action, $actions, true ) || ! self::available() ) {
			return;
		}
		$key = self::key( $action, $request, $cookies, $vary );
		if ( null === $key ) {
			return;
		}
		$hit = wp_cache_get( $key, self::GROUP );
		if ( is_array( $hit ) && is_string( $hit['b'] ?? null ) && '' !== $hit['b'] ) {
			self::respond( $hit['b'], is_string( $hit['t'] ?? null ) ? $hit['t'] : 'application/json; charset=UTF-8' );
		}
		self::$key = $key;
		ob_start();
		add_action( 'shutdown', [ self::class, 'store' ], 0 );
	}

	public static function available(): bool {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_get' ) && function_exists( 'add_action' );
	}

	/**
	 * Llave, o null si el pedido no se debe guardar (sesión, nonce, demasiado grande).
	 *
	 * @param array<string, mixed> $request Parámetros.
	 * @param array<string, mixed> $cookies Cookies.
	 * @param list<string>         $vary    Cookies de variación.
	 */
	public static function key( string $action, array $request, array $cookies, array $vary ): ?string {
		foreach ( array_keys( $cookies ) as $name ) {
			if ( is_string( $name ) && str_starts_with( $name, 'wordpress_logged_in_' ) ) {
				return null;
			}
		}
		unset( $request['action'] );
		$params = self::normalize( $request );
		$json   = json_encode( $params ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- etapa temprana.
		if ( false === $json || strlen( $json ) > 20000 || 1 === preg_match( '/nonce|security|_wp_http_referer/i', (string) json_encode( array_keys( self::flatKeys( $request ) ) ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- idem.
			return null;
		}
		$variant = [];
		foreach ( $vary as $cookie ) {
			if ( isset( $cookies[ $cookie ] ) && is_string( $cookies[ $cookie ] ) ) {
				$variant[ $cookie ] = $cookies[ $cookie ];
			}
		}
		$gen = wp_cache_get( 'gen', self::GROUP );
		if ( ! is_string( $gen ) || '' === $gen ) {
			$gen = str_replace( '.', '', uniqid( '', true ) );
			wp_cache_set( 'gen', $gen, self::GROUP );
		}
		return 'a' . md5( $action . '|' . $json . '|' . json_encode( $variant ) . '|' . $gen ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- idem.
	}

	/**
	 * Parámetros ordenados (el orden de los ids no cambia el pedido).
	 *
	 * @param array<mixed> $value Parámetros.
	 * @return array<mixed>
	 */
	public static function normalize( array $value ): array {
		ksort( $value );
		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$value[ $k ] = self::normalize( $v );
			}
		}
		return $value;
	}

	/**
	 * Todas las claves, también las anidadas.
	 *
	 * @param array<mixed> $value Parámetros.
	 * @return array<string, true>
	 */
	private static function flatKeys( array $value ): array {
		$out = [];
		foreach ( $value as $k => $v ) {
			$out[ (string) $k ] = true;
			if ( is_array( $v ) ) {
				$out += self::flatKeys( $v );
			}
		}
		return $out;
	}

	/**
	 * Copia la respuesta al terminar, si se puede compartir.
	 */
	public static function store(): void {
		$body = ob_get_contents();
		if ( '' === self::$key || ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_BYTES || 200 !== http_response_code() ) {
			return;
		}
		$type = '';
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'set-cookie:' ) ) {
				return;
			}
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				$type = trim( substr( $header, 13 ) );
			}
		}
		if ( ! str_contains( strtolower( $type ), 'json' ) || ! is_array( json_decode( $body, true ) ) || 1 === preg_match( '/nonce/i', $body ) ) {
			return;
		}
		wp_cache_set(
			self::$key,
			[
				'b' => $body,
				't' => $type,
			],
			self::GROUP,
			self::TTL
		);
	}

	/**
	 * Responde con la copia y termina.
	 */
	private static function respond( string $body, string $type ): never {
		if ( ! headers_sent() ) {
			header( 'Content-Type: ' . str_replace( [ "\r", "\n" ], '', $type ) );
			header( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private' );
			header( 'X-Robots-Tag: noindex' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Itb-Fragment: hit' );
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON guardado tal como lo respondió el sitio.
		exit;
	}
}
