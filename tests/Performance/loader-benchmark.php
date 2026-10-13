<?php
/**
 * Benchmark de la etapa temprana (loader MU) contra el presupuesto:
 * - fase 0: < 0,3 ms por request y < 150 KB de memoria propia;
 * - fase 1 (perfilador, docs/02): < 0,2 ms extra en requests no muestreados y < 1 ms de medición
 *   en un request muestreado (sin contar el INSERT de la muestra, que se mide en el WordPress real).
 *
 * Uso: php -d opcache.enable_cli=1 tests/Performance/loader-benchmark.php
 * Sale con código 1 si se pasa del presupuesto.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

const ITB_BUDGET_MS = 0.3;
const ITB_BUDGET_KB = 150;
const ITB_BUDGET_PROFILER_MS = 0.2;
const ITB_BUDGET_SAMPLED_MS = 1.0;
const ITB_ITERATIONS = 10000;

$plugin = dirname( __DIR__, 2 );

if ( 'sampled' === ( $argv[1] ?? '' ) ) {
	// Request muestreado simulado: 30 plugins, 11 hooks clave con 20 callbacks cada uno.
	define( 'ABSPATH', sys_get_temp_dir() . '/itb-bench-wp/' );
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
	define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
	define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
	function add_action( string $hook, callable $callback, int $priority = 10, int $args = 1 ): bool { // phpcs:ignore
		return true;
	}
	function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): bool { // phpcs:ignore
		return true;
	}
	require $plugin . '/tests/Unit/Stubs/WpHook.php';
	require $plugin . '/src/Modules/Profiler/Collector.php';

	// Un sitio real llega al loader con ~3000 funciones y ~400 clases del núcleo ya definidas.
	$core = '';
	for ( $i = 0; $i < 3000; $i++ ) {
		$core .= "function itb_bench_core_{$i}() {}\n";
	}
	for ( $i = 0; $i < 400; $i++ ) {
		$core .= "class Itb_Bench_Core_{$i} {}\n";
	}
	eval( $core ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- solo el benchmark.

	$hooks = [ 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'wp', 'template_redirect', 'wp_enqueue_scripts', 'wp_head', 'wp_footer', 'rest_api_init', 'admin_init' ];
	$GLOBALS['wp_filter'] = [];
	foreach ( $hooks as $hook ) {
		$GLOBALS['wp_filter'][ $hook ] = new WP_Hook();
		// Mitad del núcleo (no se envuelven), mitad de plugins (closures: se envuelven y se atribuyen).
		for ( $i = 0; $i < 20; $i++ ) {
			$GLOBALS['wp_filter'][ $hook ]->add( 0 === $i % 2 ? 'itb_bench_core_' . $i : static fn( $x = null ) => $x, 10 + $i % 3, 'cb' . $i );
		}
	}
	$plugins = array_map( static fn( int $i ): string => WP_PLUGIN_DIR . '/plugin-' . $i . '/plugin-' . $i . '.php', range( 1, 30 ) );

	$run = static function ( bool $measure ) use ( $hooks, $plugins ): void {
		$c = null;
		if ( $measure ) {
			\ImaginaTurbo\Modules\Profiler\Collector::reset();
			$c = \ImaginaTurbo\Modules\Profiler\Collector::start( 0, (int) hrtime( true ), memory_get_usage(), microtime( true ) );
			$c->resetLoadCursor();
			foreach ( $plugins as $file ) {
				$c->onPluginLoaded( $file );
			}
		}
		foreach ( $hooks as $hook ) {
			$c?->beginHook( $hook );
			$GLOBALS['wp_filter'][ $hook ]->doAction( 'x' );
			$c?->endHook( $hook );
			$c?->mark( $hook );
		}
		if ( null !== $c ) {
			$c->phases();
			$c->components();
		}
	};

	$iterations = 2000;
	$run( true );
	$t = hrtime( true );
	for ( $i = 0; $i < $iterations; $i++ ) {
		$run( false );
	}
	$base = ( hrtime( true ) - $t ) / 1e6 / $iterations;
	$t    = hrtime( true );
	for ( $i = 0; $i < $iterations; $i++ ) {
		$run( true );
	}
	$with = ( hrtime( true ) - $t ) / 1e6 / $iterations;
	echo json_encode( [ 'ms' => max( 0.0, $with - $base ) ] );
	exit( 0 );
}

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
	'profiler'                    => [
		'rate'            => 0.000001,
		'intensive_until' => 0,
	],
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
$measure = static function ( array $config, string $dir ) use ( $plugin ): array {
	file_put_contents( $dir . '/config.php', \ImaginaTurbo\Core\Config\ConfigCompiler::render( $config, 'bench' ) );
	$cmd    = escapeshellarg( PHP_BINARY ) . ' -d opcache.enable_cli=1 -d opcache.validate_timestamps=1 ' . escapeshellarg( __FILE__ ) . ' child ' . escapeshellarg( $dir );
	$out    = shell_exec( $cmd );
	$result = json_decode( (string) $out, true );
	if ( ! is_array( $result ) ) {
		fwrite( STDERR, "El proceso de medición falló:\n" . $out . "\n" );
		exit( 2 );
	}
	return $result;
};

$off                         = $measure( $config, $dir );
$config['modules']['profiler'] = true;
$on                          = $measure( $config, $dir );
exec( 'rm -rf ' . escapeshellarg( $dir ) );

$sampled = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' sampled' ), true );
if ( ! is_array( $sampled ) ) {
	fwrite( STDERR, "La medición del request muestreado falló.\n" );
	exit( 2 );
}

$delta = max( 0.0, $on['ms'] - $off['ms'] );
printf(
	"Etapa temprana: %.4f ms por request (presupuesto %.1f ms) · %.1f KB de memoria (presupuesto %d KB) · OPcache: %s · tipo: %s\n",
	$on['ms'],
	ITB_BUDGET_MS,
	$on['kb'],
	ITB_BUDGET_KB,
	$on['opcache'] ? 'sí' : 'no',
	$on['type']
);
printf( "Perfilador sin muestrear: +%.4f ms (presupuesto %.1f ms)\n", $delta, ITB_BUDGET_PROFILER_MS );
printf( "Request muestreado (30 plugins, 11 hooks × 20 callbacks): %.4f ms de medición (presupuesto %.1f ms, sin el INSERT)\n", $sampled['ms'], ITB_BUDGET_SAMPLED_MS );

$ok = $on['ms'] < ITB_BUDGET_MS && $on['kb'] < ITB_BUDGET_KB && $delta < ITB_BUDGET_PROFILER_MS && $sampled['ms'] < ITB_BUDGET_SAMPLED_MS;
echo $ok ? "OK: dentro del presupuesto.\n" : "FALLA: fuera del presupuesto.\n";
exit( $ok ? 0 : 1 );
