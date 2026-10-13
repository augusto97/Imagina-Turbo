<?php
/**
 * Cookie firmada de experimento: activa una regla de carga condicional solo para un request.
 *
 * Valor: id|modo|nonce|expira|hmac. id = regla (12 hex), modo 0 = sin filtrar (línea base) y
 * 1 = filtrado, nonce = identifica la medición en el registro del experimento. HMAC-SHA256 con una
 * clave derivada de AUTH_KEY y AUTH_SALT propia de esta cookie. Solo la envía el ejecutor de
 * experimentos del propio sitio (peticiones loopback); el navegador nunca la recibe.
 *
 * Se valida en el loader MU: sin funciones de WordPress.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

/**
 * Creación y validación.
 */
final class ExperimentCookie {

	public const NAME = 'itb_exp';

	/** Vida máxima: lo que dura un paso del experimento. */
	public const MAX_TTL = 600;

	public static function key(): ?string {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) ) {
			return null;
		}
		$secret = (string) AUTH_KEY . (string) AUTH_SALT;
		if ( strlen( $secret ) < 32 || str_contains( $secret, 'put your unique phrase here' ) ) {
			return null;
		}
		return hash_hmac( 'sha256', self::NAME, $secret, true );
	}

	public static function create( string $id, int $mode, string $nonce, int $expires, string $key ): string {
		$payload = $id . '|' . $mode . '|' . $nonce . '|' . $expires;
		return $payload . '|' . hash_hmac( 'sha256', $payload, $key );
	}

	/**
	 * @param mixed $cookie Valor crudo.
	 * @return array{id: string, mode: int, nonce: string}|null
	 */
	public static function validate( mixed $cookie, string $key, int $now ): ?array {
		if ( ! is_string( $cookie ) || strlen( $cookie ) > 160 ) {
			return null;
		}
		$parts = explode( '|', $cookie );
		if ( 5 !== count( $parts ) ) {
			return null;
		}
		[ $id, $mode, $nonce, $expires, $mac ] = $parts;
		if ( 1 !== preg_match( '/^[a-f0-9]{12}$/', $id ) || ! in_array( $mode, [ '0', '1' ], true ) || 1 !== preg_match( '/^[a-z0-9]{8,24}$/', $nonce ) || ! ctype_digit( $expires ) || 64 !== strlen( $mac ) ) {
			return null;
		}
		$expiresAt = (int) $expires;
		if ( $expiresAt < $now || $expiresAt > $now + self::MAX_TTL + 60 ) {
			return null;
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $id . '|' . $mode . '|' . $nonce . '|' . $expires, $key ), $mac ) ) {
			return null;
		}
		return [
			'id'    => $id,
			'mode'  => (int) $mode,
			'nonce' => $nonce,
		];
	}
}
