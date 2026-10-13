<?php
/**
 * Cookie firmada que desactiva el plugin solo para el administrador que la tiene.
 *
 * Formato: user_id|expira|hmac_sha256(user_id|expira, clave). Se valida en el loader,
 * sin funciones pluggable, con las constantes de wp-config.php.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Safety;

/**
 * Emisión y validación de la cookie itb_bypass.
 */
final class AdminBypassCookie {

	public const NAME = 'itb_bypass';

	/** Vida máxima: 8 horas. */
	public const MAX_TTL = 28800;

	/** Margen por relojes desfasados entre servidores. */
	private const CLOCK_SKEW = 60;

	/**
	 * Clave derivada de AUTH_KEY y AUTH_SALT; null si no están definidas o son las de ejemplo.
	 */
	public static function key(): ?string {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) ) {
			return null;
		}
		$secret = (string) AUTH_KEY . (string) AUTH_SALT;
		if ( strlen( $secret ) < 32 || str_contains( $secret, 'put your unique phrase here' ) ) {
			return null;
		}
		return hash_hmac( 'sha256', 'itb_bypass', $secret, true );
	}

	/**
	 * Valor de la cookie para un usuario y una fecha de expiración.
	 */
	public static function create( int $userId, int $expires, string $key ): string {
		$payload = $userId . '|' . $expires;
		return $payload . '|' . hash_hmac( 'sha256', $payload, $key );
	}

	/**
	 * Id del usuario si la cookie es válida y no expiró; null si no.
	 *
	 * @param mixed $cookie Valor crudo de la cookie.
	 */
	public static function validate( mixed $cookie, string $key, int $now ): ?int {
		if ( ! is_string( $cookie ) || strlen( $cookie ) > 128 ) {
			return null;
		}
		$parts = explode( '|', $cookie );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		[ $user, $expires, $mac ] = $parts;
		if ( ! ctype_digit( $user ) || ! ctype_digit( $expires ) || 64 !== strlen( $mac ) ) {
			return null;
		}
		$expiresAt = (int) $expires;
		if ( $expiresAt < $now || $expiresAt > $now + self::MAX_TTL + self::CLOCK_SKEW ) {
			return null;
		}
		$expected = hash_hmac( 'sha256', $user . '|' . $expires, $key );
		if ( ! hash_equals( $expected, $mac ) ) {
			return null;
		}
		$userId = (int) $user;
		return $userId > 0 ? $userId : null;
	}

	/**
	 * La cookie del request actual es válida.
	 *
	 * @param array<mixed> $cookies Equivalente a $_COOKIE.
	 */
	public static function isValidRequest( array $cookies, ?int $now = null ): bool {
		if ( ! isset( $cookies[ self::NAME ] ) ) {
			return false;
		}
		$key = self::key();
		if ( null === $key ) {
			return false;
		}
		return null !== self::validate( $cookies[ self::NAME ], $key, $now ?? time() );
	}
}
