<?php
/**
 * Token de Cloudflare cifrado (regla 9).
 *
 * sodium_crypto_secretbox con una clave derivada de AUTH_KEY y SECURE_AUTH_SALT. Junto al cifrado se
 * guarda un identificador corto de la clave: si alguien rota las claves de wp-config.php, el token ya
 * no se puede descifrar y Cloudflare queda "desconectado: reconectar el token" en lugar de fallar en
 * cada llamada. El token nunca se exporta ni se devuelve al admin (solo sus últimos 4 caracteres).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Guardar, leer y borrar el token.
 */
final class TokenVault {

	public const OPTION = 'itb_cf_token';

	/** Formato de un API Token de Cloudflare (los nuevos llevan prefijo cfut_/cfat_). */
	private const PATTERN = '/^[A-Za-z0-9_\-]{30,200}$/';

	/**
	 * @param string|null $keyMaterial Material de la clave (pruebas); por defecto AUTH_KEY + SECURE_AUTH_SALT.
	 */
	public function __construct( private readonly ?string $keyMaterial = null ) {
	}

	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	public static function valid( string $token ): bool {
		return 1 === preg_match( self::PATTERN, $token );
	}

	/**
	 * Cifra y guarda. Devuelve false si sodium no está disponible o el token no tiene la forma esperada.
	 */
	public function store( string $token ): bool {
		$token = trim( $token );
		if ( ! self::available() || ! self::valid( $token ) ) {
			return false;
		}
		$key   = $this->key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $token, $nonce, $key );
		update_option(
			self::OPTION,
			[
				'v'     => 1,
				'kid'   => self::keyId( $key ),
				'nonce' => base64_encode( $nonce ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binario a texto.
				'box'   => base64_encode( $box ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binario a texto.
				'last4' => substr( $token, -4 ),
				'saved' => time(),
			],
			false
		);
		sodium_memzero( $key );
		return true;
	}

	/**
	 * Token en claro, o null si no hay, si las claves cambiaron o si el dato está dañado.
	 */
	public function read(): ?string {
		$stored = $this->stored();
		if ( null === $stored || ! self::available() ) {
			return null;
		}
		$key = $this->key();
		if ( ! hash_equals( $stored['kid'], self::keyId( $key ) ) ) {
			return null;
		}
		$nonce = base64_decode( $stored['nonce'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- texto a binario.
		$box   = base64_decode( $stored['box'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- texto a binario.
		if ( false === $nonce || false === $box || SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== strlen( $nonce ) ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( $box, $nonce, $key );
		sodium_memzero( $key );
		return is_string( $plain ) && self::valid( $plain ) ? $plain : null;
	}

	/**
	 * Estado sin el token: none (no hay), ok, rotated (las claves de wp-config cambiaron) o broken.
	 *
	 * @return array{state: string, last4: string, saved: int}
	 */
	public function status(): array {
		$stored = $this->stored();
		if ( null === $stored ) {
			return [
				'state' => 'none',
				'last4' => '',
				'saved' => 0,
			];
		}
		$state = 'ok';
		if ( ! self::available() ) {
			$state = 'broken';
		} else {
			$key = $this->key();
			if ( ! hash_equals( $stored['kid'], self::keyId( $key ) ) ) {
				$state = 'rotated';
			} elseif ( null === $this->read() ) {
				$state = 'broken';
			}
			sodium_memzero( $key );
		}
		return [
			'state' => $state,
			'last4' => $stored['last4'],
			'saved' => $stored['saved'],
		];
	}

	public function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @return array{kid: string, nonce: string, box: string, last4: string, saved: int}|null
	 */
	private function stored(): ?array {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) || ! is_string( $stored['kid'] ?? null ) || ! is_string( $stored['nonce'] ?? null ) || ! is_string( $stored['box'] ?? null ) ) {
			return null;
		}
		return [
			'kid'   => $stored['kid'],
			'nonce' => $stored['nonce'],
			'box'   => $stored['box'],
			'last4' => is_string( $stored['last4'] ?? null ) ? $stored['last4'] : '',
			'saved' => is_int( $stored['saved'] ?? null ) ? $stored['saved'] : 0,
		];
	}

	private function key(): string {
		$material = $this->keyMaterial ?? ( ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' ) . '|' . ( defined( 'SECURE_AUTH_SALT' ) ? (string) SECURE_AUTH_SALT : '' ) );
		return sodium_crypto_generichash( 'imagina-turbo-cf-token|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function keyId( string $key ): string {
		return substr( hash( 'sha256', 'kid|' . $key ), 0, 12 );
	}
}
