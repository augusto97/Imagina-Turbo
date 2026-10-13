<?php
/**
 * Utilidades de archivos: escritura atómica y directorio de datos protegido.
 *
 * Se usa PHP nativo a propósito: WP_Filesystem puede pedir credenciales FTP y estos archivos
 * deben escribirse sin interacción (el directorio es del propio plugin, dentro de wp-content).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Support;

// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Operaciones de archivos del plugin.
 */
final class Filesystem {

	/**
	 * Escribe un archivo de forma atómica: temporal en el mismo directorio + rename().
	 *
	 * @param string        $file      Destino.
	 * @param string        $contents  Contenido.
	 * @param callable|null $validator Recibe la ruta del temporal; si devuelve false no se reemplaza el destino.
	 */
	public static function atomicWrite( string $file, string $contents, ?callable $validator = null ): bool {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}
		$tmp = $dir . '/.' . basename( $file ) . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		if ( false === file_put_contents( $tmp, $contents, LOCK_EX ) ) {
			return false;
		}
		chmod( $tmp, 0644 );

		if ( null !== $validator && true !== $validator( $tmp ) ) {
			unlink( $tmp );
			return false;
		}
		if ( ! rename( $tmp, $file ) ) {
			unlink( $tmp );
			return false;
		}
		self::invalidateOpcache( $file );
		return true;
	}

	/**
	 * Invalida un archivo en OPcache si la API está disponible y no restringida.
	 */
	public static function invalidateOpcache( string $file ): void {
		if ( ! function_exists( 'opcache_invalidate' ) || ! (bool) ini_get( 'opcache.enable' ) ) {
			return;
		}
		$restrict = (string) ini_get( 'opcache.restrict_api' );
		if ( '' !== $restrict && ! str_starts_with( __FILE__, $restrict ) ) {
			return;
		}
		opcache_invalidate( $file, true );
	}

	/**
	 * Crea el directorio de datos con index.php vacío y reglas de denegación (Apache y IIS).
	 */
	public static function ensureDataDir( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			// Se busca el primer ancestro existente: si es un archivo o no se puede escribir, ni se intenta.
			$ancestor = dirname( $dir );
			while ( ! file_exists( $ancestor ) && dirname( $ancestor ) !== $ancestor ) {
				$ancestor = dirname( $ancestor );
			}
			if ( ! is_dir( $ancestor ) || ! is_writable( $ancestor ) ) {
				return false;
			}
			if ( ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
				return false;
			}
		}
		$files = [
			'index.php'  => "<?php\n// Silencio.\n",
			'.htaccess'  => "# Imagina Turbo: datos internos, sin acceso web.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		];
		foreach ( $files as $name => $contents ) {
			$path = $dir . '/' . $name;
			if ( ! is_file( $path ) ) {
				file_put_contents( $path, $contents, LOCK_EX );
			}
		}
		return is_dir( $dir ) && is_writable( $dir );
	}

	/**
	 * Borra un directorio con todo su contenido (solo dentro de la base indicada, por seguridad).
	 */
	public static function removeDir( string $dir, string $mustBeInside ): bool {
		$realDir  = realpath( $dir );
		$realBase = realpath( $mustBeInside );
		if ( false === $realDir || false === $realBase || ! str_starts_with( $realDir, rtrim( $realBase, '/' ) . '/' ) ) {
			return false;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $realDir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( ! $item instanceof \SplFileInfo ) {
				continue;
			}
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		return rmdir( $realDir );
	}
}
