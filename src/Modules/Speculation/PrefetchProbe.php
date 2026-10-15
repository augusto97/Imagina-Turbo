<?php
/**
 * ¿Llegan las precargas al sitio? (0.11.0)
 *
 * Con Speed Brain activo, Cloudflare responde 503 ("cf-speculation-refused: prefetch refused: not
 * eligible") a toda petición con Sec-Purpose: prefetch cuya página no está en su caché, venga de Speed
 * Brain o de las reglas de Imagina Turbo. Medido en un sitio real: 45 navegaciones en el RUM y 0 servidas
 * por prefetch. El prerender (Sec-Purpose: prefetch;prerender) sí pasa. Verificado con peticiones reales
 * el 2026-10-15; la documentación de Cloudflare dice que las precargas de Speed Brain solo se sirven
 * desde su caché y no llegan al origen.
 *
 * Se comprueba con una petición a la portada del propio sitio (pasa por Cloudflare si el DNS apunta al
 * proxy) y el resultado se guarda 6 horas. Solo en el admin.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Speculation;

use ImaginaTurbo\Modules\CacheCoordinator\Warmer;

/**
 * Comprobación y lectura del resultado guardado.
 */
final class PrefetchProbe {

	public const TRANSIENT = 'itb_prefetch_probe';

	/**
	 * Resultado guardado sin hacer peticiones ("refused", "ok", "direct" o "" si no hay).
	 */
	public static function cached(): string {
		$value = get_transient( self::TRANSIENT );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Comprueba (o devuelve lo guardado).
	 */
	public static function run( bool $force = false ): string {
		$cached = self::cached();
		if ( '' !== $cached && ! $force ) {
			return $cached;
		}
		$response = wp_remote_get(
			home_url( '/' ),
			[
				'timeout'     => 8,
				'redirection' => 0,
				'user-agent'  => Warmer::USER_AGENT,
				'headers'     => [ 'Sec-Purpose' => 'prefetch' ],
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
			]
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$refused = wp_remote_retrieve_header( $response, 'cf-speculation-refused' );
		$ray     = wp_remote_retrieve_header( $response, 'cf-ray' );
		$result  = self::classify( (int) wp_remote_retrieve_response_code( $response ), is_string( $refused ) ? $refused : '', is_string( $ray ) && '' !== $ray );
		set_transient( self::TRANSIENT, $result, 6 * HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * "refused": Cloudflare rechaza la precarga; "ok": llegó; "direct": la petición no pasó por Cloudflare.
	 */
	public static function classify( int $status, string $refused, bool $viaCloudflare ): string {
		if ( '' !== $refused || ( 503 === $status && $viaCloudflare ) ) {
			return 'refused';
		}
		return $viaCloudflare ? 'ok' : 'direct';
	}
}
