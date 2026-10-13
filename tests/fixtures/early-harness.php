<?php
/**
 * Ejecuta la etapa temprana en un proceso aislado (como lo haría mu-plugins).
 *
 * Uso: php early-harness.php <data_dir> <modo: ok|fatal> [uri] [cookies_json]
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'ITB_DATA_DIR', $argv[1] );
define( 'AUTH_KEY', 'test-auth-key-0123456789abcdefghijklmnopqrstuvwxyz' );
define( 'AUTH_SALT', 'test-auth-salt-0123456789abcdefghijklmnopqrstuvwxyz' );

$_SERVER['REQUEST_URI']    = $argv[3] ?? '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_COOKIE                   = json_decode( $argv[4] ?? '{}', true ) ?: [];

$itb_plugin = dirname( __DIR__, 2 );
require $itb_plugin . '/src/Core/Runtime.php';
require $itb_plugin . '/src/Core/EarlyLoader.php';
\ImaginaTurbo\Core\EarlyLoader::bootFromMu( $itb_plugin );

echo json_encode(
	[
		'kill'   => \ImaginaTurbo\Core\Runtime::$killReason,
		'safe'   => \ImaginaTurbo\Core\Runtime::$safeMode,
		'config' => null !== \ImaginaTurbo\Core\Runtime::$config,
		'type'   => \ImaginaTurbo\Core\Runtime::$request?->type->value,
	]
), "\n";

if ( 'fatal' === ( $argv[2] ?? '' ) ) {
	require __DIR__ . '/fatal.php';
}
