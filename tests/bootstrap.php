<?php
/**
 * Bootstrap de las pruebas unitarias (sin WordPress; las funciones se simulan con Brain Monkey).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$itb_tmp = sys_get_temp_dir() . '/itb-tests-' . getmypid();
if ( ! is_dir( $itb_tmp ) ) {
	mkdir( $itb_tmp, 0755, true );
}

define( 'ITB_TESTS_TMP', $itb_tmp );
define( 'ABSPATH', $itb_tmp . '/wp/' );
define( 'WP_CONTENT_DIR', $itb_tmp . '/wp/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
define( 'ITB_VERSION', '0.1.0-test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'AUTH_KEY', 'test-auth-key-0123456789abcdefghijklmnopqrstuvwxyz' );
define( 'AUTH_SALT', 'test-auth-salt-0123456789abcdefghijklmnopqrstuvwxyz' );

register_shutdown_function(
	static function () use ( $itb_tmp ): void {
		if ( is_dir( $itb_tmp ) ) {
			exec( 'rm -rf ' . escapeshellarg( $itb_tmp ) );
		}
	}
);
