<?php
/**
 * Medición de un request de experimento: tiempo, memoria pico, avisos y fatales de PHP.
 *
 * Se activa solo con una cookie de experimento válida. Escribe una línea JSON por request en
 * wp-content/imagina-turbo/experiments/{id}.log (escritura acotada: solo en requests firmados por el
 * propio sitio). Corre desde el loader MU, sin funciones de WordPress.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

/**
 * Contador de errores y registro en shutdown.
 */
final class ExperimentProbe {

	/** Avisos que cuentan: un plugin que falta suele provocar warnings (funciones o clases inexistentes). */
	private const COUNTED = E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE | E_RECOVERABLE_ERROR;

	private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	private static int $errors = 0;

	/** @var list<string> */
	private static array $messages = [];

	private static int $startNs = 0;

	private static string $file = '';

	/** @var array{id: string, mode: int, nonce: string} */
	private static array $experiment = [
		'id'    => '',
		'mode'  => 0,
		'nonce' => '',
	];

	/** @var \Closure(): list<string>|null */
	private static ?\Closure $hidden = null;

	/**
	 * @param array{id: string, mode: int, nonce: string} $experiment Experimento.
	 * @param \Closure(): list<string>                    $hidden     Plugins quitados (al final).
	 */
	public static function start( array $experiment, string $dataDir, int $startNs, \Closure $hidden ): void {
		self::$experiment = $experiment;
		self::$startNs    = $startNs;
		self::$file       = $dataDir . '/experiments/' . $experiment['id'] . '.log';
		self::$hidden     = $hidden;
		set_error_handler( [ self::class, 'onError' ] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- solo cuenta y devuelve false para que siga el manejador normal.
		register_shutdown_function( [ self::class, 'onShutdown' ] );
	}

	/**
	 * Cuenta y deja seguir al manejador de PHP (o al siguiente).
	 */
	public static function onError( int $errno, string $message, string $file = '', int $line = 0 ): bool {
		unset( $file, $line );
		if ( 0 !== ( $errno & self::COUNTED ) ) {
			++self::$errors;
			if ( count( self::$messages ) < 3 ) {
				self::$messages[] = self::clean( $message );
			}
		}
		return false;
	}

	public static function onShutdown(): void {
		$fatal = '';
		$error = error_get_last();
		if ( is_array( $error ) && 0 !== ( $error['type'] & self::FATAL ) ) {
			$fatal = self::clean( (string) $error['message'] );
		}
		$line = [
			'n'  => self::$experiment['nonce'],
			'm'  => self::$experiment['mode'],
			'ms' => round( ( hrtime( true ) - self::$startNs ) / 1e6, 1 ),
			'kb' => (int) round( memory_get_peak_usage() / 1024 ),
			'e'  => self::$errors,
			'em' => self::$messages,
			'f'  => $fatal,
			'h'  => null !== self::$hidden ? count( ( self::$hidden )() ) : 0,
		];
		$dir  = dirname( self::$file );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- loader MU, sin WP_Filesystem.
		}
		file_put_contents( self::$file, json_encode( $line ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- loader MU.
	}

	/**
	 * Mensaje corto y sin rutas del servidor.
	 */
	private static function clean( string $message ): string {
		$message = (string) strtok( $message, "\n" );
		if ( defined( 'ABSPATH' ) ) {
			$message = str_replace( (string) ABSPATH, '', $message );
		}
		return substr( $message, 0, 200 );
	}
}
