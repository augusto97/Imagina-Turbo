<?php
/**
 * Peticiones del sitio a sí mismo para los experimentos (como visitante anónimo, sin seguir
 * redirecciones: una redirección también es una respuesta que comparar).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

use ImaginaTurbo\Modules\CacheCoordinator\Warmer;
use ImaginaTurbo\Support\CloudflareAccess;

/**
 * Cliente HTTP mínimo.
 *
 * @phpstan-import-type Reply from ResponseComparer
 */
final class LoopbackClient {

	/**
	 * @return Reply|string Respuesta o mensaje de error.
	 */
	public static function request( string $method, string $path, string $body, string $cookie ): array|string {
		// admin-ajax vive bajo site_url; el resto, bajo home_url.
		$url  = str_starts_with( $path, '/wp-admin/' ) ? site_url( $path ) : home_url( $path );
		$args = [
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'user-agent'  => Warmer::USER_AGENT,
			'headers'     => [ 'Cookie' => $cookie ],
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
		];
		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']                    = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		if ( CloudflareAccess::blockedResponse( $response ) ) {
			return CloudflareAccess::message( $url );
		}
		$headers = [];
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( "\n", array_map( 'strval', $value ) ) : (string) $value;
		}
		return [
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers,
			'body'    => wp_remote_retrieve_body( $response ),
		];
	}
}
