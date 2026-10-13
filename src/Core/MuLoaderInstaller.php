<?php
/**
 * Instala, actualiza y elimina el loader MU (wp-content/mu-plugins/imagina-turbo-loader.php).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

use ImaginaTurbo\Support\Filesystem;

/**
 * Gestión del archivo del loader.
 */
final class MuLoaderInstaller {

	public const FILENAME = 'imagina-turbo-loader.php';

	/** Cambia solo cuando cambia el contenido de la plantilla. */
	public const LOADER_VERSION = '1';

	private const MARKER = 'Itb-Loader-Version:';

	/**
	 * @param string $pluginDir Directorio del plugin.
	 * @param string $muDir     Directorio de mu-plugins.
	 */
	public function __construct(
		private readonly string $pluginDir,
		private readonly string $muDir,
	) {
	}

	public function target(): string {
		return $this->muDir . '/' . self::FILENAME;
	}

	/**
	 * Contenido final del loader para este sitio.
	 */
	public function render(): string {
		// El nombre de carpeta va dentro de una cadena PHP: solo caracteres seguros.
		$dirname = basename( $this->pluginDir );
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]+$/', $dirname ) ) {
			return '';
		}
		$file = $this->pluginDir . '/mu-loader/' . self::FILENAME;
		if ( ! is_file( $file ) ) {
			return '';
		}
		$template = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo local del plugin.
		if ( false === $template ) {
			return '';
		}
		return str_replace(
			[ '__ITB_LOADER_VERSION__', '__ITB_PLUGIN_DIRNAME__' ],
			[ self::LOADER_VERSION, $dirname ],
			$template
		);
	}

	/**
	 * Instala o actualiza. Devuelve true si el loader quedó vigente.
	 */
	public function install(): bool {
		$contents = $this->render();
		if ( '' === $contents ) {
			return false;
		}
		if ( ! is_dir( $this->muDir ) && ! wp_mkdir_p( $this->muDir ) ) {
			return false;
		}
		$target = $this->target();
		if ( is_file( $target ) && ! $this->isOurs( $target ) ) {
			// Hay otro archivo con el mismo nombre: no se pisa.
			return false;
		}
		return Filesystem::atomicWrite( $target, $contents );
	}

	/**
	 * Elimina el loader solo si es nuestro.
	 */
	public function uninstall(): bool {
		$target = $this->target();
		if ( ! is_file( $target ) ) {
			return true;
		}
		if ( ! $this->isOurs( $target ) ) {
			return false;
		}
		wp_delete_file( $target );
		clearstatcache( true, $target );
		return ! file_exists( $target );
	}

	/**
	 * Instalado, con la versión de plantilla actual y apuntando a este directorio.
	 */
	public function isCurrent(): bool {
		$target = $this->target();
		if ( ! is_file( $target ) ) {
			return false;
		}
		$current = file_get_contents( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false !== $current && $current === $this->render();
	}

	/**
	 * Estado para el admin.
	 *
	 * @return array{installed: bool, current: bool, writable: bool, path: string}
	 */
	public function status(): array {
		$target = $this->target();
		return [
			'installed' => is_file( $target ) && $this->isOurs( $target ),
			'current'   => $this->isCurrent(),
			'writable'  => is_dir( $this->muDir ) ? wp_is_writable( $this->muDir ) : wp_is_writable( dirname( $this->muDir ) ),
			'path'      => defined( 'ABSPATH' ) ? str_replace( ABSPATH, '', $target ) : $target,
		];
	}

	private function isOurs( string $file ): bool {
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return false;
		}
		$head = (string) fread( $handle, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return str_contains( $head, self::MARKER ) && str_contains( $head, 'Imagina Turbo' );
	}
}
