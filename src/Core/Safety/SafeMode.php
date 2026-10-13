<?php
/**
 * Modo seguro automático ante errores fatales.
 *
 * Funciona solo con archivos (sin base de datos): en un shutdown tras un fatal no se puede
 * confiar en que $wpdb ni el correo funcionen. El registro del evento y el aviso por correo
 * los hace el plugin principal en el siguiente request de admin (ver SafeModeNotifier).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Safety;

// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- corre sin WordPress (shutdown).

/**
 * Contador de fatales con ventana y bandera de modo seguro.
 */
final class SafeMode {

	public const FLAG    = 'safe-mode.flag';
	public const COUNTER = 'fatals.json';

	public const DEFAULT_WINDOW    = 300;
	public const DEFAULT_THRESHOLD = 3;

	private const FATAL_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	/**
	 * @param string $dataDir   Directorio de datos (wp-content/imagina-turbo).
	 * @param string $pluginDir Directorio del plugin, para saber si el fatal es nuestro.
	 * @param int    $window    Ventana en segundos.
	 * @param int    $threshold Fatales dentro de la ventana que activan el modo seguro.
	 */
	public function __construct(
		private readonly string $dataDir,
		private readonly string $pluginDir,
		private readonly int $window = self::DEFAULT_WINDOW,
		private readonly int $threshold = self::DEFAULT_THRESHOLD,
	) {
	}

	/**
	 * Existe la bandera (comprobación barata para el loader).
	 */
	public static function flagExists( string $dataDir ): bool {
		return '' !== $dataDir && is_file( $dataDir . '/' . self::FLAG );
	}

	public function isActive(): bool {
		return self::flagExists( $this->dataDir );
	}

	/**
	 * Contenido de la bandera: reason, at, context, notified.
	 *
	 * @return array<string, mixed>|null
	 */
	public function details(): ?array {
		$file = $this->dataDir . '/' . self::FLAG;
		if ( ! is_file( $file ) ) {
			return null;
		}
		$raw  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : [
			'reason'   => 'unknown',
			'at'       => (int) filemtime( $file ),
			'context'  => [],
			'notified' => false,
		];
	}

	/**
	 * Activa el modo seguro.
	 *
	 * @param array<string, mixed> $context Datos del motivo (sin información sensible).
	 */
	public function enter( string $reason, array $context = [], ?int $now = null ): bool {
		if ( ! is_dir( $this->dataDir ) ) {
			return false;
		}
		$data = [
			'reason'   => $reason,
			'at'       => $now ?? time(),
			'context'  => $context,
			'notified' => false,
		];
		return self::atomicWrite( $this->dataDir . '/' . self::FLAG, (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Sale del modo seguro y limpia el contador.
	 */
	public function leave(): bool {
		foreach ( [ self::FLAG, self::COUNTER ] as $name ) {
			$file = $this->dataDir . '/' . $name;
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		return ! $this->isActive();
	}

	/**
	 * Marca la bandera como notificada (evento registrado y correo enviado).
	 */
	public function markNotified(): bool {
		$details = $this->details();
		if ( null === $details ) {
			return false;
		}
		$details['notified'] = true;
		return self::atomicWrite( $this->dataDir . '/' . self::FLAG, (string) json_encode( $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * El error es de un tipo fatal.
	 *
	 * @param array<string, mixed>|null $error Resultado de error_get_last().
	 */
	public static function isFatal( ?array $error ): bool {
		return is_array( $error ) && isset( $error['type'] ) && is_int( $error['type'] ) && 0 !== ( $error['type'] & self::FATAL_TYPES );
	}

	/**
	 * El fatal cuenta para el modo seguro: se filtraron plugins o el archivo es del propio plugin.
	 *
	 * @param array<string, mixed> $error Resultado de error_get_last().
	 */
	public function isAttributable( array $error, bool $pluginsFiltered ): bool {
		if ( $pluginsFiltered ) {
			return true;
		}
		$file = isset( $error['file'] ) && is_string( $error['file'] ) ? $error['file'] : '';
		if ( '' === $file || '' === $this->pluginDir ) {
			return false;
		}
		$dirs = [ rtrim( $this->pluginDir, '/\\' ) ];
		$real = realpath( $this->pluginDir );
		if ( false !== $real ) {
			$dirs[] = rtrim( $real, '/\\' );
		}
		foreach ( $dirs as $dir ) {
			if ( str_starts_with( $file, $dir . DIRECTORY_SEPARATOR ) || str_starts_with( $file, $dir . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Registra un fatal. Devuelve cuántos hay en la ventana; activa el modo seguro al llegar al umbral.
	 *
	 * @param array<string, mixed> $error Resultado de error_get_last().
	 */
	public function recordFatal( array $error, ?int $now = null ): int {
		$now = $now ?? time();
		if ( ! is_dir( $this->dataDir ) ) {
			return 0;
		}

		$handle = fopen( $this->dataDir . '/' . self::COUNTER, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return 0;
		}

		$count = 0;
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				return 0;
			}
			$raw   = stream_get_contents( $handle );
			$times = json_decode( false === $raw ? '' : $raw, true );
			$times = is_array( $times ) ? array_values( array_filter( $times, 'is_int' ) ) : [];
			$times = array_values( array_filter( $times, fn( int $t ): bool => $t > $now - $this->window ) );

			$times[] = $now;
			$count   = count( $times );

			ftruncate( $handle, 0 );
			rewind( $handle );
			fwrite( $handle, (string) json_encode( $times ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fflush( $handle );
			flock( $handle, LOCK_UN );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		if ( $count >= $this->threshold && ! $this->isActive() ) {
			$this->enter(
				'fatal_errors',
				[
					'count'   => $count,
					'window'  => $this->window,
					'message' => self::safeMessage( $error ),
					'file'    => self::relativeFile( $error ),
					'line'    => isset( $error['line'] ) && is_int( $error['line'] ) ? $error['line'] : 0,
				],
				$now
			);
		}

		return $count;
	}

	/**
	 * Manejador de shutdown. Nunca lanza excepciones.
	 */
	public function handleShutdown( bool $pluginsFiltered ): void {
		try {
			$error = error_get_last();
			if ( ! self::isFatal( $error ) || ! is_array( $error ) ) {
				return;
			}
			if ( $this->isActive() || ! $this->isAttributable( $error, $pluginsFiltered ) ) {
				return;
			}
			$this->recordFatal( $error );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- un shutdown nunca debe fallar.
		}
	}

	/**
	 * Mensaje recortado y sin rutas absolutas del servidor.
	 *
	 * @param array<string, mixed> $error Error.
	 */
	private static function safeMessage( array $error ): string {
		$message = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '';
		$message = strtok( $message, "\n" );
		$message = false === $message ? '' : $message;
		if ( defined( 'ABSPATH' ) ) {
			$message = str_replace( (string) ABSPATH, '', $message );
		}
		return substr( $message, 0, 300 );
	}

	/**
	 * Archivo relativo a ABSPATH.
	 *
	 * @param array<string, mixed> $error Error.
	 */
	private static function relativeFile( array $error ): string {
		$file = isset( $error['file'] ) && is_string( $error['file'] ) ? $error['file'] : '';
		if ( defined( 'ABSPATH' ) && str_starts_with( $file, (string) ABSPATH ) ) {
			$file = substr( $file, strlen( (string) ABSPATH ) );
		}
		return substr( $file, 0, 255 );
	}

	/**
	 * Escritura atómica: temporal + rename.
	 */
	private static function atomicWrite( string $file, string $contents ): bool {
		$tmp = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false === file_put_contents( $tmp, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false;
		}
		if ( ! rename( $tmp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}
		return true;
	}
}
