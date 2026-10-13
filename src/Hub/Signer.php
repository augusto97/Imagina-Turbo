<?php
/**
 * Firma de las peticiones al hub (docs/04, protocolo con el hub).
 *
 * Cadena canónica: MÉTODO\nRUTA\nTIMESTAMP\nNONCE\nsha256_hex(cuerpo enviado), firmada con HMAC-SHA256
 * y la clave del sitio. El cuerpo es el que viaja por la red (comprimido si va con gzip).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Hub;

/**
 * Firma y verificación (la verificación la usa el hub; aquí sirve para pruebas y el hub simulado).
 */
final class Signer {

	/** Diferencia máxima de reloj aceptada. */
	public const MAX_SKEW = 300;

	public static function canonical( string $method, string $path, int $timestamp, string $nonce, string $body ): string {
		return strtoupper( $method ) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body );
	}

	public static function sign( string $key, string $method, string $path, int $timestamp, string $nonce, string $body ): string {
		return hash_hmac( 'sha256', self::canonical( $method, $path, $timestamp, $nonce, $body ), $key );
	}

	/**
	 * Cabeceras firmadas para una petición.
	 *
	 * @return array<string, string>
	 */
	public static function headers( string $siteId, string $key, string $license, string $method, string $path, string $body, ?int $now = null, ?string $nonce = null ): array {
		$timestamp = $now ?? time();
		$nonce     = $nonce ?? bin2hex( random_bytes( 16 ) );
		return [
			'X-ITB-Site'      => $siteId,
			'X-ITB-License'   => $license,
			'X-ITB-Timestamp' => (string) $timestamp,
			'X-ITB-Nonce'     => $nonce,
			'X-ITB-Signature' => self::sign( $key, $method, $path, $timestamp, $nonce, $body ),
		];
	}

	/**
	 * Verificación (ventana de tiempo y firma). El control de nonces repetidos es del hub.
	 */
	public static function verify( string $key, string $method, string $path, string $timestamp, string $nonce, string $body, string $signature, ?int $now = null ): bool {
		if ( ! ctype_digit( $timestamp ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $nonce ) ) {
			return false;
		}
		if ( abs( ( $now ?? time() ) - (int) $timestamp ) > self::MAX_SKEW ) {
			return false;
		}
		return hash_equals( self::sign( $key, $method, $path, (int) $timestamp, $nonce, $body ), $signature );
	}
}
