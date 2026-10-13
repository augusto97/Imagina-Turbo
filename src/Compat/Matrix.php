<?php
/**
 * Matriz de compatibilidad (data/compat-matrix-v1.json).
 *
 * Solo se lee al compilar la configuración o en el admin, nunca en el frontend.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Compat;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- mensajes internos; llegan al admin como JSON y React los escapa.

/**
 * Carga, valida y expone la matriz.
 */
final class Matrix {

	public const SCHEMA         = 'imagina-perf-compat';
	public const SCHEMA_VERSION = 1;

	/** Tamaño máximo aceptado (la v1 pesa ~280 KB). */
	private const MAX_BYTES = 5_000_000;

	/**
	 * @param array<string, mixed> $data Matriz validada.
	 */
	private function __construct( private readonly array $data ) {
	}

	/**
	 * Carga desde archivo.
	 *
	 * @throws MatrixException Si el archivo no existe, no es JSON válido o no cumple el esquema.
	 */
	public static function fromFile( string $file ): self {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			throw new MatrixException( sprintf( 'No se encuentra la matriz: %s', basename( $file ) ) );
		}
		$size = filesize( $file );
		if ( false === $size || $size > self::MAX_BYTES ) {
			throw new MatrixException( 'La matriz es demasiado grande.' );
		}
		$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo local.
		return self::fromJson( false === $json ? '' : $json );
	}

	/**
	 * Carga desde una cadena JSON.
	 *
	 * @throws MatrixException Si no es JSON válido o no cumple el esquema.
	 */
	public static function fromJson( string $json ): self {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new MatrixException( 'La matriz es demasiado grande.' );
		}
		try {
			$data = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			throw new MatrixException( 'La matriz no es JSON válido: ' . $e->getMessage(), 0, $e );
		}
		if ( ! is_array( $data ) ) {
			throw new MatrixException( 'La matriz no es un objeto JSON.' );
		}
		self::validate( $data );
		/** @var array<string, mixed> $data */
		return new self( $data );
	}

	/**
	 * Verifica la firma ed25519 de una matriz descargada (para el actualizador remoto).
	 *
	 * @param string $json         Contenido exacto firmado.
	 * @param string $signatureB64 Firma en base64.
	 * @param string $publicKeyB64 Clave pública en base64 (incluida en el plugin).
	 */
	public static function verifySignature( string $json, string $signatureB64, string $publicKeyB64 ): bool {
		$signature = base64_decode( $signatureB64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- firma ed25519.
		$publicKey = base64_decode( $publicKeyB64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- clave pública ed25519.
		if ( false === $signature || false === $publicKey
			|| SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature )
			|| SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $publicKey ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $signature, $json, $publicKey );
		} catch ( \SodiumException ) {
			return false;
		}
	}

	/**
	 * Valida la estructura mínima que el resto del código asume.
	 *
	 * @param array<mixed> $data Matriz decodificada.
	 * @throws MatrixException Si algo no cumple.
	 */
	private static function validate( array $data ): void {
		if ( self::SCHEMA !== ( $data['schema'] ?? null ) ) {
			throw new MatrixException( 'Esquema de matriz desconocido.' );
		}
		if ( self::SCHEMA_VERSION !== ( $data['schema_version'] ?? null ) ) {
			throw new MatrixException( 'Versión de esquema de matriz no soportada.' );
		}
		if ( ! is_string( $data['version'] ?? null ) || 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $data['version'] ) ) {
			throw new MatrixException( 'La matriz no tiene una versión válida.' );
		}
		if ( ! is_array( $data['core'] ?? null ) ) {
			throw new MatrixException( 'Falta la sección core.' );
		}
		foreach ( [ 'bypass_cookies', 'bypass_paths', 'ignore_query_params', 'staging_domains' ] as $key ) {
			if ( isset( $data['core'][ $key ] ) && ! self::isStringList( $data['core'][ $key ] ) ) {
				throw new MatrixException( sprintf( 'core.%s debe ser una lista de cadenas.', $key ) );
			}
		}
		if ( ! is_array( $data['integrations'] ?? null ) || ! array_is_list( $data['integrations'] ) ) {
			throw new MatrixException( 'Falta la lista de integraciones.' );
		}
		$seen = [];
		foreach ( $data['integrations'] as $index => $integration ) {
			if ( ! is_array( $integration ) || ! is_string( $integration['id'] ?? null ) || '' === $integration['id'] ) {
				throw new MatrixException( sprintf( 'Integración %d sin id.', $index ) );
			}
			$id = $integration['id'];
			if ( isset( $seen[ $id ] ) ) {
				throw new MatrixException( sprintf( 'Integración duplicada: %s', $id ) );
			}
			$seen[ $id ] = true;
			$type        = $integration['type'] ?? null;
			$detect      = $integration['detect'] ?? null;
			if ( ! is_array( $detect ) ) {
				throw new MatrixException( sprintf( 'Integración %s sin detect.', $id ) );
			}
			if ( 'plugin' === $type ) {
				$file   = $detect['plugin_file'] ?? null;
				$dir    = $detect['plugin_dir'] ?? null;
				$okFile = is_string( $file ) && str_ends_with( $file, '.php' );
				$okDir  = is_string( $dir ) && 1 === preg_match( '/^[A-Za-z0-9._-]+$/', $dir );
				if ( ! $okFile && ! $okDir ) {
					throw new MatrixException( sprintf( 'Integración %s: detect.plugin_file no válido.', $id ) );
				}
			}
			if ( 'theme' === $type && ( ! is_string( $detect['theme'] ?? null ) || '' === $detect['theme'] ) ) {
				throw new MatrixException( sprintf( 'Integración %s: detect.theme no válido.', $id ) );
			}
			if ( 'plugin' !== $type && 'theme' !== $type ) {
				throw new MatrixException( sprintf( 'Integración %s: tipo desconocido.', $id ) );
			}
		}
		if ( isset( $data['overlapping_plugins'] ) && ! is_array( $data['overlapping_plugins'] ) ) {
			throw new MatrixException( 'overlapping_plugins debe ser un objeto.' );
		}
	}

	/**
	 * @param mixed $value Valor.
	 */
	private static function isStringList( mixed $value ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}
		return true;
	}

	public function version(): string {
		return (string) $this->data['version'];
	}

	public function generated(): string {
		return is_string( $this->data['generated'] ?? null ) ? $this->data['generated'] : '';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function core(): array {
		/** @var array<string, mixed> */
		return $this->data['core'];
	}

	/**
	 * Lista de cadenas de core.{key}.
	 *
	 * @return list<string>
	 */
	public function coreList( string $key ): array {
		$value = $this->core()[ $key ] ?? [];
		/** @var list<string> $value */
		return is_array( $value ) ? $value : [];
	}

	/**
	 * Exclusiones de delay JS que no dependen de los plugins activos: las globales (regex de WP Rocket) y
	 * las de servicios de terceros que se rompen si se retrasan. De terceros solo se toman pagos y
	 * "otros servicios" (Turnstile, reCAPTCHA, mapas, chats): analítica y anuncios sí se pueden
	 * retrasar y excluirlos quitaría casi todo el beneficio.
	 *
	 * @return list<string>
	 */
	public function delayJsGlobal(): array {
		$out    = [];
		$global = $this->data['delay_js_global_exclusions'] ?? [];
		foreach ( is_array( $global ) && is_array( $global['regex'] ?? null ) ? $global['regex'] : [] as $pattern ) {
			if ( is_string( $pattern ) && '' !== $pattern ) {
				$out[] = $pattern;
			}
		}
		$third = $this->data['third_party_scripts_never_delay'] ?? [];
		foreach ( [ 'payment_processors', 'other_services' ] as $category ) {
			foreach ( is_array( $third ) && is_array( $third[ $category ] ?? null ) ? $third[ $category ] : [] as $service ) {
				foreach ( is_array( $service ) && is_array( $service['exclude_regex'] ?? null ) ? $service['exclude_regex'] : [] as $pattern ) {
					if ( is_string( $pattern ) && '' !== $pattern ) {
						$out[] = $pattern;
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function integrations(): array {
		/** @var list<array<string, mixed>> */
		return $this->data['integrations'];
	}

	/**
	 * Listas de plugins solapados por categoría. Cada entrada normalizada a slug, plugin_file y coordinate.
	 *
	 * @return array<string, list<array{slug: string, plugin_file: string, coordinate: bool}>>
	 */
	public function overlapping(): array {
		$out = [];
		$raw = $this->data['overlapping_plugins'] ?? [];
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $category => $entries ) {
			if ( ! is_string( $category ) || ! is_array( $entries ) || ! array_is_list( $entries ) ) {
				continue;
			}
			$out[ $category ] = [];
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$file = $entry['plugin_file'] ?? $entry['file'] ?? null;
				if ( ! is_string( $file ) || '' === $file ) {
					continue;
				}
				$out[ $category ][] = [
					'slug'        => is_string( $entry['slug'] ?? null ) ? $entry['slug'] : dirname( $file ),
					'plugin_file' => $file,
					'coordinate'  => true === ( $entry['coordinate'] ?? false ),
				];
			}
		}
		return $out;
	}
}
