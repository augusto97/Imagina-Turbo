<?php
/**
 * Benchmark de la etapa temprana (loader MU) contra el presupuesto de la fase 0:
 * < 0,3 ms por request y < 150 KB de memoria propia.
 *
 * Uso: php -d opcache.enable_cli=1 tests/Performance/loader-benchmark.php
 * Sale con código 1 si se pasa del presupuesto.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

const ITB_BUDGET_MS = 0.3;
const ITB_BUDGET_KB = 150;
const ITB_ITERATIONS = 10000;

$plugin = dirname( __DIR__, 2 );

if ( 'child' === ( $argv[1] ?? '' ) ) {
	// Proceso hijo: un request "frío" para medir memoria y luego el bucle caliente para medir tiempo.
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
	define( 'ITB_DATA_DIR', $argv[2] );
	define( 'AUTH_KEY', 'bench-auth-key-0123456789abcdefghijklmnopqrstuvwxyz' );
	define( 'AUTH_SALT', 'bench-auth-salt-0123456789abcdefghijklmnopqrstuvwxyz' );
	$_SERVER['REQUEST_URI']    = '/categoria-producto/zapatos/?orderby=price&utm_source=x';
	$_SERVER['REQUEST_METHOD'] = 'GET';

	$memBefore = memory_get_usage();
	require $plugin . '/src/Core/Runtime.php';
	require $plugin . '/src/Core/EarlyLoader.php';
	\ImaginaTurbo\Core\EarlyLoader::bootFromMu( $plugin );
	$memory = memory_get_usage() - $memBefore;

	if ( null === \ImaginaTurbo\Core\Runtime::$config || null === \ImaginaTurbo\Core\Runtime::$request ) {
		fwrite( STDERR, "La etapa temprana no cargó la config.\n" );
		exit( 2 );
	}

	$start = hrtime( true );
	for ( $i = 0; $i < ITB_ITERATIONS; $i++ ) {
		\ImaginaTurbo\Core\Runtime::$earlyRan = false;
		\ImaginaTurbo\Core\EarlyLoader::boot( $plugin );
	}
	$ms = ( hrtime( true ) - $start ) / 1e6 / ITB_ITERATIONS;

	echo json_encode(
		[
			'ms'      => $ms,
			'kb'      => $memory / 1024,
			'opcache' => function_exists( 'opcache_get_status' ) && is_array( opcache_get_status( false ) ) && ( opcache_get_status( false )['opcache_enabled'] ?? false ),
			'type'    => \ImaginaTurbo\Core\Runtime::$request->type->value,
		]
	);
	exit( 0 );
}

// Proceso padre: prepara una config compilada realista y lanza el hijo.
require $plugin . '/vendor/autoload.php';
define( 'ABSPATH', sys_get_temp_dir() . '/' );

$dir = sys_get_temp_dir() . '/itb-bench-' . getmypid();
mkdir( $dir, 0755, true );

$config = [
	'v'                           => 1,
	'hash'                        => str_repeat( 'a', 16 ),
	'generated'                   => time(),
	'plugin_version'              => 'bench',
	'matrix_version'              => '1.1.0',
	'blog_id'                     => 1,
	'home_path'                   => '',
	'site_path'                   => '',
	'rest_prefix'                 => 'wp-json',
	'lang_prefixes'               => [ 'es', 'en' ],
	'paths'                       => [
		'cart'     => [ '/carrito', '/en/cart' ],
		'checkout' => [ '/finalizar-compra', '/en/checkout' ],
		'account'  => [ '/mi-cuenta', '/en/my-account' ],
		'login'    => [ '/acceso' ],
	],
	'modules'                     => array_fill_keys( [ 'profiler', 'cache_coordinator', 'cloudflare', 'woo_cart', 'shield', 'tweaks', 'firewall', 'plugin_filter', 'speculation', 'rum', 'frontend_hints', 'asset_manager', 'database' ], false ),
	'filter'                      => [],
	'tags'                        => [ 'prefix' => 'k7' ],
	'bypass_cookies'              => [ 'wordpress_logged_in_', 'wp-postpass_', 'comment_author_', 'comment_author_email_', 'wptouch_switch_toggle', 'itb_bypass' ],
	'bypass_paths'                => [ '/wp-admin/*', '/wp-login.php', '/xmlrpc.php', '/wp-cron.php', '/*/feed/*', '/feed/*', '/*/embed/*', '/wp-json/*', '/?rest_route=*', '/wc-api/v*' ],
	'vary_cookies'                => [ 'wcml_client_currency' ],
	'require_cookie_before_cache' => [],
	'cart_cookies'                => [ 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_' ],
	'requires'                    => [
		'xmlrpc'        => false,
		'rest_users'    => false,
		'webhook_paths' => [],
	],
	'static_passthrough'          => [],
	'integrations'                => array_map( static fn( int $i ): string => 'plugin:plugin-' . $i . '/plugin-' . $i . '.php', range( 1, 25 ) ),
];
file_put_contents( $dir . '/config.php', \ImaginaTurbo\Core\Config\ConfigCompiler::render( $config, 'bench' ) );

$cmd = escapeshellarg( PHP_BINARY ) . ' -d opcache.enable_cli=1 -d opcache.validate_timestamps=1 ' . escapeshellarg( __FILE__ ) . ' child ' . escapeshellarg( $dir );
$out = shell_exec( $cmd );
exec( 'rm -rf ' . escapeshellarg( $dir ) );

$result = json_decode( (string) $out, true );
if ( ! is_array( $result ) ) {
	fwrite( STDERR, "El proceso de medición falló:\n" . $out . "\n" );
	exit( 2 );
}

printf(
	"Etapa temprana: %.4f ms por request (presupuesto %.1f ms) · %.1f KB de memoria (presupuesto %d KB) · OPcache: %s · tipo: %s\n",
	$result['ms'],
	ITB_BUDGET_MS,
	$result['kb'],
	ITB_BUDGET_KB,
	$result['opcache'] ? 'sí' : 'no',
	$result['type']
);

$ok = $result['ms'] < ITB_BUDGET_MS && $result['kb'] < ITB_BUDGET_KB;
echo $ok ? "OK: dentro del presupuesto.\n" : "FALLA: fuera del presupuesto.\n";
exit( $ok ? 0 : 1 );
