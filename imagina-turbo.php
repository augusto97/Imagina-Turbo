<?php
/**
 * Plugin Name:       Imagina Turbo
 * Description:       Rendimiento para WordPress y WooCommerce: complementa a LiteSpeed Cache o WP Rocket, reemplaza a Perfmatters y orquesta Cloudflare.
 * Version:           0.8.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Imagina WP
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       imagina-turbo
 * Domain Path:       /languages
 *
 * Este archivo debe seguir siendo válido en PHP antiguo (para mostrar un aviso en lugar de un
 * error fatal) y compatible con la inyección de protección de Imagina Updater, que inserta su
 * bloque justo después de la comprobación de ABSPATH. Por eso no declara strict_types ni namespace.
 *
 * @package ImaginaTurbo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ITB_VERSION' ) ) {
	// Otra copia del plugin ya se cargó (por ejemplo, dos carpetas con el mismo plugin).
	return;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- prefijo ITB_ fijado en CLAUDE.md.
define( 'ITB_VERSION', '0.8.0' );
define( 'ITB_FILE', __FILE__ );
define( 'ITB_MIN_PHP', 80100 );
// phpcs:enable

if ( PHP_VERSION_ID < ITB_MIN_PHP ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Imagina Turbo necesita PHP 8.1 o superior. El plugin no se cargó.', 'imagina-turbo' );
			echo '</p></div>';
		}
	);
	return;
}

if ( is_file( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	// Sin Composer (copia de desarrollo): autoloader PSR-4 mínimo.
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

\ImaginaTurbo\Core\Plugin::boot( __FILE__ );
