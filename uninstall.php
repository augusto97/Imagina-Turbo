<?php
/**
 * Desinstalación: borra tablas, opciones, capacidad, tareas, loader MU y archivos de datos.
 *
 * WordPress incluye este archivo sin cargar el archivo principal del plugin.
 *
 * @package ImaginaTurbo
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( PHP_VERSION_ID < 80100 ) {
	return;
}

if ( is_file( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		function ( $class_name ) {
			$prefix = 'ImaginaTurbo\\';
			if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
				return;
			}
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	);
}

\ImaginaTurbo\Core\Installer::uninstall( __DIR__ );
