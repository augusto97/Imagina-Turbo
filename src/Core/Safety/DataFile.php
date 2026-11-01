<?php
/**
 * Archivos internos del directorio de datos que no deben leerse desde la web (0.21.2).
 *
 * El .htaccess del directorio no basta: OpenLiteSpeed no aplica "Require all denied" (y sus reglas de
 * reescritura solo cuentan tras reiniciar) y nginx no lee .htaccess. Por eso los archivos con detalles
 * (fatales, registros de experimentos, contadores) son .php que empiezan con una guarda: si alguien los
 * pide por URL, PHP termina sin mostrar nada, y __halt_compiler() hace que el resto ni se interprete.
 * Además, las líneas se escriben sin "<" ni ">", así que no pueden contener una etiqueta de PHP.
 *
 * Corre desde el loader MU: sin autoloader ni funciones de WordPress.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Safety;

// phpcs:disable WordPress.WP.AlternativeFunctions -- loader MU y shutdown: sin WP_Filesystem.

/**
 * Guarda, lectura y escritura de los archivos de datos.
 */
final class DataFile {

	/** Primera línea de cada archivo. */
	public const GUARD = "<?php exit; __halt_compiler(); ?>\n";

	/** Codificación: sin "<" ni ">" (nada que parezca una etiqueta de PHP). */
	public const JSON = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Contenido sin la guarda.
	 */
	public static function strip( string $raw ): string {
		return str_starts_with( $raw, self::GUARD ) ? substr( $raw, strlen( self::GUARD ) ) : $raw;
	}

	/**
	 * Contenido completo de un archivo nuevo: guarda + JSON.
	 *
	 * @param mixed $data Datos.
	 */
	public static function encode( mixed $data ): string {
		return self::GUARD . (string) json_encode( $data, self::JSON );
	}

	/**
	 * JSON del archivo, o null si no existe o no se puede leer.
	 */
	public static function decode( string $file ): mixed {
		if ( ! is_file( $file ) ) {
			return null;
		}
		$raw = file_get_contents( $file );
		return false === $raw ? null : json_decode( self::strip( $raw ), true );
	}

	/**
	 * Añade una línea JSON. La guarda va en la misma escritura que crea el archivo: si dos requests lo
	 * crean a la vez queda una guarda de más en medio, que la lectura descarta (no es JSON).
	 *
	 * @param array<string, mixed> $row Línea.
	 */
	public static function append( string $file, array $row ): bool {
		$line = json_encode( $row, self::JSON );
		if ( false === $line ) {
			return false;
		}
		return false !== file_put_contents( $file, ( is_file( $file ) ? '' : self::GUARD ) . $line . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * Líneas JSON del archivo (sin la guarda ni lo que no sea un objeto).
	 *
	 * @return list<array<mixed>>
	 */
	public static function lines( string $file ): array {
		if ( ! is_file( $file ) ) {
			return [];
		}
		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$rows  = [];
		foreach ( is_array( $lines ) ? $lines : [] as $line ) {
			$row = json_decode( $line, true );
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}
}
