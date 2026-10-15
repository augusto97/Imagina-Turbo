<?php
/**
 * Marca legible por JavaScript de que el visitante tiene sesión de WooCommerce (0.13.1).
 *
 * Los nonces woocommerce* de un invitado con sesión se calculan con su customer_id (filtro
 * nonce_user_logged_out de WooCommerce), así que los de una página cacheada no le sirven: el login de
 * la cabecera fallaba en silencio. La cookie de sesión es HttpOnly; esta (itb_wcs=1, sin datos) le dice
 * a itb-nonce.js que pida los nonces de esa sesión al cargar. Se escribe y se borra junto con la de
 * WooCommerce, en respuestas que ya llevan Set-Cookie (nunca cacheables).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

/**
 * Escucha wc_setcookie (filtro woocommerce_set_cookie_enabled, WooCommerce 6.3+).
 */
final class SessionMarker {

	public const COOKIE = 'itb_wcs';

	private const SESSION_PREFIX = 'wp_woocommerce_session_';

	public function register(): void {
		add_filter( 'woocommerce_set_cookie_enabled', [ $this, 'mirror' ], PHP_INT_MAX, 5 );
	}

	/**
	 * @param mixed $enabled Si WooCommerce escribe la cookie (sin cambios).
	 * @param mixed $name    Nombre.
	 * @param mixed $value   Valor.
	 * @param mixed $expire  Caducidad.
	 * @param mixed $secure  Solo HTTPS.
	 * @return mixed
	 */
	public function mirror( mixed $enabled, mixed $name = '', mixed $value = '', mixed $expire = 0, mixed $secure = false ): mixed {
		if ( true !== $enabled || ! is_string( $name ) || ! str_starts_with( $name, self::SESSION_PREFIX ) || headers_sent() ) {
			return $enabled;
		}
		$expire = self::expiry( $value, $expire, time() );
		setcookie(
			self::COOKIE,
			$expire > 0 ? '1' : '',
			[
				'expires'  => $expire > 0 ? $expire : 1,
				'path'     => defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) constant( 'COOKIE_DOMAIN' ) : '',
				'secure'   => (bool) $secure,
				'httponly' => false,
				'samesite' => 'Lax',
			]
		);
		return $enabled;
	}

	/**
	 * Caducidad de la marca: la de la sesión, o 0 si WooCommerce la está borrando.
	 */
	public static function expiry( mixed $value, mixed $expire, int $now ): int {
		$expire = is_numeric( $expire ) ? (int) $expire : 0;
		return is_string( $value ) && '' !== $value && $expire > $now ? $expire : 0;
	}
}
