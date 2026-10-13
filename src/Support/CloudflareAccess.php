<?php
/**
 * Detecta cuando Cloudflare Access (Zero Trust) intercepta una petición del sitio a sí mismo.
 *
 * Varias funciones piden páginas del propio sitio (experimentos de carga condicional, inventario de
 * assets, inspecciones, auditoría). Esas peticiones pasan por Cloudflare, y si la ruta está dentro de
 * una aplicación de Access, Cloudflare responde con una redirección a la página de login del equipo
 * (https://<equipo>.cloudflareaccess.com/cdn-cgi/access/login/...) en lugar de llegar a WordPress.
 * Nada se da por bueno en ese caso, pero el error debe decir la causa.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Support;

/**
 * Detección y mensaje.
 */
final class CloudflareAccess {

	/**
	 * La URL es la del login de Access (dominio del equipo o ruta /cdn-cgi/access/).
	 */
	public static function isAccessUrl( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- función pura.
		$path = (string) parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- función pura.
		return str_ends_with( $host, '.cloudflareaccess.com' ) || str_starts_with( $path, '/cdn-cgi/access/' );
	}

	/**
	 * La respuesta es de Access: redirección a su login, o se siguió la redirección y se terminó allí.
	 */
	public static function blocked( int $status, string $location, string $finalUrl = '' ): bool {
		return ( $status >= 300 && $status < 400 && self::isAccessUrl( $location ) ) || self::isAccessUrl( $finalUrl );
	}

	/**
	 * Lo mismo a partir de una respuesta de wp_remote_*.
	 *
	 * @param array<string, mixed>|\WP_Error $response Respuesta.
	 */
	public static function blockedResponse( array|\WP_Error $response ): bool {
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$location = wp_remote_retrieve_header( $response, 'location' );
		$final    = '';
		$http     = $response['http_response'] ?? null;
		if ( $http instanceof \WP_HTTP_Requests_Response ) {
			$final = (string) $http->get_response_object()->url;
		}
		return self::blocked( (int) wp_remote_retrieve_response_code( $response ), is_string( $location ) ? $location : '', $final );
	}

	/**
	 * Mensaje para el admin.
	 */
	public static function message( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return sprintf(
			/* translators: %s: ruta */
			__( 'Cloudflare Access (Zero Trust) bloquea la petición del sitio a sí mismo en %s: la respuesta fue la página de login de Access, no WordPress. Para que esta función pueda probar esa ruta, añade en Zero Trust una política Bypass para ella o limita la aplicación de Access a la página de login.', 'imagina-turbo' ),
			'' === $path ? '/' : $path
		);
	}
}
