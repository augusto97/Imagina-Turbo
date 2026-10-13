<?php
/**
 * Límite de tasa para endpoints públicos, sin escribir en la base de datos: APCu o la caché de
 * objetos persistente. Si no hay ninguna, no limita (y el endpoint debe ser barato por sí mismo).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Support;

/**
 * Ventana fija por minuto.
 */
final class RateLimiter {

	/**
	 * ¿Se permite otra petición de esta clave en el minuto actual?
	 */
	public static function allow( string $bucket, string $key, int $perMinute ): bool {
		$id = 'itb_rl_' . $bucket . '_' . md5( $key ) . '_' . intdiv( time(), 60 );
		if ( function_exists( 'apcu_enabled' ) && apcu_enabled() ) {
			apcu_add( $id, 0, 120 );
			$count = apcu_inc( $id );
			return false === $count || $count <= $perMinute;
		}
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $id, 0, 'itb', 120 );
			$count = wp_cache_incr( $id, 1, 'itb' );
			return false === $count || $count <= $perMinute;
		}
		return true;
	}

	/**
	 * IP del cliente tal como la ve PHP (el servidor debe poner la real si hay proxy: docs/03).
	 */
	public static function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}
}
