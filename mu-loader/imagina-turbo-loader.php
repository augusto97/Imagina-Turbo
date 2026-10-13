<?php
/**
 * Plugin Name: Imagina Turbo (etapa temprana)
 * Description: Kill switch, modo seguro y clasificación de requests antes de cargar los plugins. Se instala y se elimina automáticamente con Imagina Turbo; no lo borres a mano.
 * Author: Imagina WP
 * License: GPL-2.0-or-later
 *
 * Itb-Loader-Version: __ITB_LOADER_VERSION__
 *
 * Este archivo es una plantilla: el instalador reemplaza los marcadores y lo copia a mu-plugins.
 * Debe seguir siendo válido en PHP antiguo, porque se ejecuta antes de cualquier comprobación.
 *
 * @package ImaginaTurbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( PHP_VERSION_ID >= 80100 && defined( 'WP_PLUGIN_DIR' ) ) {
	call_user_func(
		static function () {
			$itb_dir = WP_PLUGIN_DIR . '/__ITB_PLUGIN_DIRNAME__';
			// Plugin borrado sin pasar por la desinstalación: el loader queda huérfano y no hace nada.
			if ( ! is_file( $itb_dir . '/src/Core/EarlyLoader.php' ) || ! is_file( $itb_dir . '/src/Core/Runtime.php' ) ) {
				return;
			}
			require_once $itb_dir . '/src/Core/Runtime.php';
			require_once $itb_dir . '/src/Core/EarlyLoader.php';
			\ImaginaTurbo\Core\EarlyLoader::bootFromMu( $itb_dir );
		}
	);
}
