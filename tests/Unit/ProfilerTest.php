<?php
/**
 * Perfilador: atribución de costos, envoltorio de hooks, fases, informes y cabeceras.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Modules\Profiler\CacheabilityAudit;
use ImaginaTurbo\Modules\Profiler\Collector;
use ImaginaTurbo\Modules\Profiler\Report;
use ImaginaTurbo\Modules\Profiler\Rollup;
use ImaginaTurbo\Modules\Profiler\RouteKey;
use ImaginaTurbo\Modules\Profiler\SampleWriter;
use ImaginaTurbo\Modules\Profiler\ServerTiming;

require_once __DIR__ . '/Stubs/WpHook.php';

final class ProfilerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Collector::reset();
		unset( $GLOBALS['wp_filter'] );
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
	}

	protected function tearDown(): void {
		Collector::reset();
		unset( $GLOBALS['wp_filter'] );
		parent::tearDown();
	}

	private static function start(): Collector {
		return Collector::start( Collector::SOURCE_RANDOM, (int) hrtime( true ), memory_get_usage(), microtime( true ) );
	}

	/**
	 * Crea un "plugin" en WP_PLUGIN_DIR con una función nueva que tarda $ms. Devuelve su nombre.
	 * Hay que llamarla después de arrancar el recolector: lo definido antes cuenta como núcleo.
	 */
	private static function fakePlugin( string $slug, int $ms ): string {
		$dir = WP_PLUGIN_DIR . '/' . $slug;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		$function = 'itb_test_' . $slug . '_' . bin2hex( random_bytes( 4 ) );
		$file     = $dir . '/' . $function . '.php';
		file_put_contents( $file, "<?php\nfunction {$function}( ...\$args ) { usleep( {$ms}000 ); if ( isset( \$args[0] ) && is_callable( \$args[0] ) ) { ( \$args[0] )(); } }\n" );
		require $file;
		return $function;
	}

	public function test_component_for_file(): void {
		$c = self::start();
		$this->assertSame( 'plugin:woocommerce', $c->componentForFile( WP_PLUGIN_DIR . '/woocommerce/includes/class-wc.php' ) );
		$this->assertSame( 'plugin:hello', $c->componentForFile( WP_PLUGIN_DIR . '/hello.php' ) );
		$this->assertSame( 'mu:zz-loader', $c->componentForFile( WPMU_PLUGIN_DIR . '/zz-loader.php' ) );
		$this->assertSame( 'theme:astra', $c->componentForFile( WP_CONTENT_DIR . '/themes/astra/functions.php' ) );
		$this->assertSame( 'core', $c->componentForFile( ABSPATH . 'wp-includes/plugin.php' ) );
		$this->assertSame( 'other', $c->componentForFile( '/srv/otro/archivo.php' ) );
		// Un prefijo parecido no es el mismo directorio.
		$this->assertSame( 'other', $c->componentForFile( WP_PLUGIN_DIR . 'x/foo/foo.php' ) );
	}

	public function test_start_is_singleton(): void {
		$a = self::start();
		$this->assertSame( $a, self::start() );
		$this->assertSame( $a, Collector::active() );
	}

	public function test_plugin_load_cost_is_attributed(): void {
		$c = self::start();
		$c->resetLoadCursor();
		usleep( 3000 );
		$c->onPluginLoaded( WP_PLUGIN_DIR . '/acme/acme.php' );
		usleep( 1000 );
		$c->onPluginLoaded( WP_PLUGIN_DIR . '/beta/beta.php' );

		$components = $c->components();
		$this->assertGreaterThanOrEqual( 2.5, $components['plugin:acme']['load_ms'] );
		$this->assertLessThan( 50, $components['plugin:acme']['load_ms'] );
		$this->assertGreaterThan( 0.5, $components['plugin:beta']['load_ms'] );
		$this->assertSame( [ 'plugin:acme', 'plugin:beta' ], array_keys( $components ) );
	}

	public function test_hook_wrapping_measures_exclusive_time_and_restores_callbacks(): void {
		$c    = self::start();
		$acme = self::fakePlugin( 'acme', 4 );
		$beta = self::fakePlugin( 'beta', 2 );

		$init   = new \WP_Hook();
		$wpHead = new \WP_Hook();
		$wpHead->add( $acme, 10, 'acme' );
		// beta tarda 2 ms y, dentro, dispara wp_head (4 ms de acme): su tiempo exclusivo es 2 ms, no 6.
		$nested = static function () use ( $c, $wpHead ): void {
			$c->beginHook( 'wp_head' );
			$wpHead->doAction();
			$c->endHook( 'wp_head' );
		};
		$init->add( static fn() => $beta( $nested ), 10, 'beta' );
		$GLOBALS['wp_filter'] = [
			'init'    => $init,
			'wp_head' => $wpHead,
		];
		$originalInit = $init->callbacks;

		$c->beginHook( 'init' );
		$this->assertNotSame( $originalInit[10]['beta']['function'], $init->callbacks[10]['beta']['function'], 'envuelto durante el hook' );
		$init->doAction();
		$c->endHook( 'init' );

		$this->assertSame( $originalInit, $init->callbacks, 'callbacks restaurados al terminar' );
		$components = $c->components();
		// La closure de beta está definida en este archivo de pruebas: queda como "other".
		$this->assertArrayHasKey( 'plugin:acme', $components );
		$this->assertGreaterThanOrEqual( 3.5, $components['plugin:acme']['hook_ms'] );
		$this->assertGreaterThanOrEqual( 1.5, $components['other']['hook_ms'] );
		$this->assertLessThan( 3.5, $components['other']['hook_ms'], 'tiempo exclusivo: no incluye el hook anidado' );
	}

	public function test_nested_same_hook_wraps_once(): void {
		$c    = self::start();
		$hook = new \WP_Hook();
		$hook->add( self::fakePlugin( 'acme', 1 ), 10, 'acme' );
		$GLOBALS['wp_filter'] = [ 'init' => $hook ];
		$original             = $hook->callbacks;

		$c->beginHook( 'init' );
		$wrapped = $hook->callbacks;
		$this->assertNotSame( $original, $wrapped );
		$c->beginHook( 'init' );
		$this->assertSame( $wrapped, $hook->callbacks, 'no se envuelve dos veces' );
		$c->endHook( 'init' );
		$this->assertSame( $wrapped, $hook->callbacks, 'sigue envuelto hasta cerrar el externo' );
		$c->endHook( 'init' );
		$this->assertSame( $original, $hook->callbacks );
	}

	public function test_callbacks_removed_during_hook_are_not_resurrected(): void {
		$c    = self::start();
		$hook = new \WP_Hook();
		$hook->add( self::fakePlugin( 'acme', 1 ), 10, 'acme' );
		$GLOBALS['wp_filter'] = [ 'init' => $hook ];

		$c->beginHook( 'init' );
		unset( $hook->callbacks[10]['acme'] );
		$c->endHook( 'init' );
		$this->assertArrayNotHasKey( 'acme', $hook->callbacks[10] );
	}

	public function test_core_callbacks_are_not_wrapped(): void {
		$c   = self::start();
		$dir = ABSPATH . 'wp-includes';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		$coreFn = 'itb_test_core_' . bin2hex( random_bytes( 4 ) );
		file_put_contents( $dir . '/' . $coreFn . '.php', "<?php\nfunction {$coreFn}() {}\n" );
		require $dir . '/' . $coreFn . '.php';
		$plugin = self::fakePlugin( 'beta', 0 );

		$hook = new \WP_Hook();
		$hook->add( $coreFn, 10, 'core' );
		$hook->add( 'strlen', 10, 'internal' );
		$hook->add( [ new \ArrayObject(), 'count' ], 10, 'internal-class', 0 );
		$hook->add( $plugin, 10, 'plugin' );
		$GLOBALS['wp_filter'] = [ 'init' => $hook ];
		$original             = $hook->callbacks;

		$c->beginHook( 'init' );
		foreach ( [ 'core', 'internal', 'internal-class' ] as $id ) {
			$this->assertSame( $original[10][ $id ]['function'], $hook->callbacks[10][ $id ]['function'], $id );
		}
		$this->assertNotSame( $original[10]['plugin']['function'], $hook->callbacks[10]['plugin']['function'] );
		$hook->doAction( 'x' );
		$c->endHook( 'init' );
		$this->assertSame( $original, $hook->callbacks );
		$this->assertSame( [ 'plugin:beta' ], array_keys( $c->components() ) );
	}

	public function test_non_callable_callbacks_are_left_alone(): void {
		$c    = self::start();
		$hook = new \WP_Hook();
		$hook->callbacks[10]['roto'] = [
			'function'      => 'itb_funcion_que_no_existe',
			'accepted_args' => 1,
		];
		$hook->callbacks[10]['roto2'] = [
			'function'      => [ 'Itb_Clase_Que_No_Existe', 'metodo' ],
			'accepted_args' => 1,
		];
		$GLOBALS['wp_filter'] = [ 'init' => $hook ];
		$original             = $hook->callbacks;
		$c->beginHook( 'init' );
		$this->assertSame( $original, $hook->callbacks );
		$c->endHook( 'init' );
	}

	public function test_symbol_cache_persists_between_requests(): void {
		$file   = $this->tmp . '/profiler-symbols.php';
		$plugin = self::fakePlugin( 'acme', 0 );
		$run    = static function ( string $key ) use ( $file, $plugin ): Collector {
			Collector::reset();
			$c    = Collector::start( Collector::SOURCE_RANDOM, (int) hrtime( true ), memory_get_usage(), microtime( true ), [], $file, $key );
			$hook = new \WP_Hook();
			$hook->add( $plugin, 10, 'acme' );
			$hook->add( 'strlen', 10, 'internal' );
			$GLOBALS['wp_filter'] = [ 'init' => $hook ];
			$c->beginHook( 'init' );
			$c->endHook( 'init' );
			return $c;
		};

		$first = $run( 'hash-a' );
		$this->assertTrue( SampleWriter::saveSymbols( $first ) );
		$saved = include $file;
		$this->assertSame( 'hash-a', $saved['k'] );
		$this->assertSame( 'plugin:acme', $saved['f'][ $plugin ] );
		$this->assertSame( 'core', $saved['f']['strlen'] );

		$this->assertNull( $run( 'hash-a' )->symbolCacheUpdate(), 'todo salió de la caché: nada que guardar' );
		$this->assertNotNull( $run( 'hash-b' )->symbolCacheUpdate(), 'otra config: la caché no vale' );

		$saved['at'] = time() - 2 * 86400;
		file_put_contents( $file, '<?php return ' . var_export( $saved, true ) . ';' );
		$this->assertNotNull( $run( 'hash-a' )->symbolCacheUpdate(), 'caducada' );

		file_put_contents( $file, '<?php return [ roto' );
		$this->assertNotNull( $run( 'hash-a' )->symbolCacheUpdate(), 'archivo dañado: se ignora sin romper' );
		Collector::reset();
		$this->assertFalse( SampleWriter::saveSymbols( Collector::start( 0, 0, 0, 0.0 ) ), 'sin archivo configurado no escribe' );
	}

	public function test_block_render_is_measured_for_plugins_only(): void {
		$c        = self::start();
		$template_path = WP_PLUGIN_DIR . '/acme/blocks/mini-cart/render.php'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- mismo nombre que usa el núcleo.
		// Así registra el núcleo un bloque con "render" en block.json: closure propia que incluye la plantilla.
		$fromMetadata = static function ( $attributes, $content ) use ( $template_path ) {
			usleep( 2000 );
			return $template_path . $content;
		};
		$plugin = self::fakePlugin( 'beta', 1 );

		$args = $c->wrapBlockRender( [ 'render_callback' => $fromMetadata ], 'acme/mini-cart' );
		$this->assertNotSame( $fromMetadata, $args['render_callback'] );
		$this->assertSame( $template_path . 'x', ( $args['render_callback'] )( [], 'x' ) );

		$args = $c->wrapBlockRender( [ 'render_callback' => $plugin ], 'beta/block' );
		( $args['render_callback'] )();

		// Como WooCommerce Blocks: [ $objeto, 'render_callback' ] de una clase del plugin.
		$dir   = WP_PLUGIN_DIR . '/gamma';
		$class = 'Itb_Test_Block_' . bin2hex( random_bytes( 4 ) );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $dir . '/' . $class . '.php', "<?php\nclass {$class} { public function render() { usleep( 1000 ); return 'ok'; } }\n" );
		require $dir . '/' . $class . '.php';
		$args = $c->wrapBlockRender( [ 'render_callback' => [ new $class(), 'render' ] ], 'gamma/block' );
		$this->assertInstanceOf( \Closure::class, $args['render_callback'] );
		$this->assertSame( 'ok', ( $args['render_callback'] )() );

		$core = [ 'render_callback' => 'strlen' ];
		$this->assertSame( $core, $c->wrapBlockRender( $core, 'core/paragraph' ) );
		$this->assertSame( [ 'title' => 'Sin render' ], $c->wrapBlockRender( [ 'title' => 'Sin render' ], 'acme/static' ) );
		$this->assertSame( 'no-array', $c->wrapBlockRender( 'no-array', 'x/y' ) );

		$components = $c->components();
		$this->assertGreaterThanOrEqual( 1.8, $components['plugin:acme']['hook_ms'] );
		$this->assertGreaterThanOrEqual( 0.8, $components['plugin:beta']['hook_ms'] );
		$this->assertGreaterThanOrEqual( 0.8, $components['plugin:gamma']['hook_ms'] );
	}

	public function test_phases_are_ordered_durations(): void {
		$c = self::start();
		usleep( 1000 );
		$c->mark( 'plugins_loaded' );
		usleep( 2000 );
		$c->mark( 'init' );
		$c->mark( 'init' ); // Solo cuenta la primera vez.
		$phases = $c->phases();
		$this->assertSame( [ 'loader', 'plugins_loaded', 'init' ], array_keys( $phases ) );
		$this->assertGreaterThanOrEqual( 1.8, $phases['init'] );
		$this->assertEqualsWithDelta( $c->elapsedMs(), array_sum( $phases ), 1.0 );
	}

	public function test_report_plugins_counts_absence_as_zero(): void {
		$rows = [
			[
				'ms'           => 100,
				'plugins_cost' => '[{"c":"plugin:a","l":10,"h":10,"k":100},{"c":"plugin:b","l":4,"h":0,"k":50}]',
			],
			[
				'ms'           => 100,
				'plugins_cost' => '[{"c":"plugin:a","l":10,"h":0,"k":100}]',
			],
		];
		$out = Report::plugins( $rows );
		$this->assertSame( 'plugin:a', $out[0]['component'] );
		$this->assertSame( 15.0, $out[0]['total_ms'] );
		$this->assertSame( 15.0, $out[0]['share_pct'] );
		$this->assertSame( 2.0, $out[1]['total_ms'] );
		$this->assertSame( 50.0, $out[1]['seen_pct'] );
		$this->assertSame( [], Report::plugins( [] ) );
	}

	public function test_report_summary_phases_routes_and_passive(): void {
		$rows = [];
		foreach ( [ 10, 20, 30, 40, 500 ] as $i => $ms ) {
			$rows[] = [
				'request_type'  => 0 === $i ? 'Rest' : 'FrontendHtml',
				'route_key'     => 0 === $i ? 'wp/v2/posts' : 'single:post',
				'status'        => 200,
				'ms'            => $ms,
				'mem_kb'        => 2048,
				'queries'       => 10,
				'cacheable'     => $i > 2 ? 1 : 0,
				'via_cf'        => 0,
				'overhead_us'   => 300,
				'set_cookies'   => 1 === $i ? 'woocommerce_recently_viewed' : null,
				'cache_control' => 2 === $i ? 'no-store, private' : null,
				'phases'        => '{"loader":2,"plugins_loaded":' . $ms . '}',
				'plugins_cost'  => '[]',
			];
		}
		$summary = Report::summary( $rows );
		$this->assertSame( 5, $summary['samples'] );
		$this->assertSame( 30, $summary['ms_p50'] );
		$this->assertSame( 500, $summary['ms_p95'] );
		$this->assertSame( 2.0, $summary['mem_avg_mb'] );
		$this->assertSame( 40.0, $summary['cacheable_pct'] );
		$this->assertSame( [ 'FrontendHtml' => 4, 'Rest' => 1 ], $summary['by_type'] );
		$this->assertSame( 0, Report::summary( [] )['samples'] );

		$phases = Report::phases( $rows );
		$this->assertSame( [ 'loader', 'plugins_loaded' ], array_column( $phases, 'phase' ) );
		$mixed = Report::phases(
			[
				[ 'phases' => '{"loader":1,"init":2,"shutdown":3}' ],
				[ 'phases' => '{"loader":1,"init":2,"template_redirect":1,"shutdown":3}' ],
			]
		);
		$this->assertSame( [ 'loader', 'init', 'template_redirect', 'shutdown' ], array_column( $mixed, 'phase' ), 'orden del request, no de aparición' );
		$this->assertSame( 120.0, $phases[1]['ms'] );

		$routes = Report::routes( $rows );
		$this->assertSame( 'single:post', $routes[0]['route_key'] );
		$this->assertSame( 4, $routes[0]['n'] );

		$passive = Report::passiveAudit( $rows );
		$this->assertSame( 4, $passive['eligible'] );
		$this->assertSame( [ 'woocommerce_recently_viewed' => 1 ], $passive['cookies'] );
		$this->assertSame( 1, $passive['private_cache'] );
	}

	public function test_rollup_percentiles(): void {
		$this->assertSame( 0, Rollup::percentile( [], 50 ) );
		$this->assertSame( 7, Rollup::percentile( [ 7 ], 95 ) );
		$values = range( 1, 100 );
		shuffle( $values );
		$this->assertSame( 50, Rollup::percentile( $values, 50 ) );
		$this->assertSame( 95, Rollup::percentile( $values, 95 ) );

		$agg = Rollup::aggregate(
			[
				[ 'request_type' => 'Rest', 'route_key' => 'a', 'ms' => 10, 'mem_kb' => 100, 'queries' => 1 ],
				[ 'request_type' => 'Rest', 'route_key' => 'a', 'ms' => 30, 'mem_kb' => 300, 'queries' => 4 ],
				[ 'request_type' => 'Rest', 'route_key' => 'b', 'ms' => 5, 'mem_kb' => 50, 'queries' => 0 ],
			]
		);
		$this->assertCount( 2, $agg );
		$this->assertSame( 2, $agg[0]['n'] );
		$this->assertSame( 10, $agg[0]['ms_p50'] );
		$this->assertSame( 30, $agg[0]['ms_p95'] );
		$this->assertSame( 3, $agg[0]['queries_avg'] );
	}

	public function test_parse_headers_and_cacheability(): void {
		$parsed = SampleWriter::parseHeaders(
			[
				'Content-Type: text/html',
				'Set-Cookie: woocommerce_recently_viewed=12; path=/',
				'set-cookie: woocommerce_recently_viewed=13; path=/',
				'Set-Cookie: =raro',
				'Cache-Control: Max-Age=60',
			]
		);
		$this->assertSame( [ 'woocommerce_recently_viewed' ], $parsed['cookies'] );
		$this->assertSame( 'max-age=60', $parsed['cache_control'] );

		$html = new RequestContext( RequestType::FrontendHtml, '', '/', 'GET' );
		$_COOKIE = [];
		$this->assertTrue( SampleWriter::looksCacheable( $html, 200, [ 'cookies' => [], 'cache_control' => 'max-age=60' ] ) );
		$this->assertFalse( SampleWriter::looksCacheable( $html, 200, [ 'cookies' => [ 'x' ], 'cache_control' => '' ] ) );
		$this->assertFalse( SampleWriter::looksCacheable( $html, 200, [ 'cookies' => [], 'cache_control' => 'no-cache, private' ] ) );
		$this->assertFalse( SampleWriter::looksCacheable( $html, 404, [ 'cookies' => [], 'cache_control' => '' ] ) );
		$this->assertFalse( SampleWriter::looksCacheable( new RequestContext( RequestType::FrontendHtml, '', '/', 'POST' ), 200, [ 'cookies' => [], 'cache_control' => '' ] ) );
		$this->assertFalse( SampleWriter::looksCacheable( new RequestContext( RequestType::Cart, '', '/carrito', 'GET' ), 200, [ 'cookies' => [], 'cache_control' => '' ] ) );
		$_COOKIE = [ 'wordpress_logged_in_abc' => '1' ];
		$this->assertFalse( SampleWriter::looksCacheable( $html, 200, [ 'cookies' => [], 'cache_control' => '' ] ) );
		$_COOKIE = [];
	}

	public function test_server_timing_metric_is_safe(): void {
		$this->assertSame( 'itb-p1;dur=1.50;desc="plugin:x \\"y\\""', ServerTiming::metric( 'itb-p1', 1.5, "plugin:x \"y\"\n" ) );
		$this->assertSame( 'itb-fase-a-b;dur=0.00;desc="d"', ServerTiming::metric( 'itb-fase-A b', -3, 'd' ) );
		$this->assertSame( 'x;dur=1.00;desc="hasta aqu"', ServerTiming::metric( 'x', 1, 'hasta aquí' ), 'solo ASCII' );
	}

	public function test_route_key_for_endpoints(): void {
		$this->assertSame( '(raíz)', RouteKey::current( new RequestContext( RequestType::Rest, '', '/wp-json/', 'GET' ) ) );
		$this->assertSame( 'wc/store/v1/cart', RouteKey::current( new RequestContext( RequestType::StoreApi, 'wc/store/v1/cart', '/wp-json/wc/store/v1/cart', 'GET' ) ) );
		$this->assertSame( 'cron', RouteKey::current( new RequestContext( RequestType::Cron, '', '/wp-cron.php', 'GET' ) ) );
		$this->assertSame( RouteKey::MAX, strlen( RouteKey::current( new RequestContext( RequestType::AdminAjax, str_repeat( 'a', 300 ), '/', 'POST' ) ) ) );
	}

	public function test_audit_analysis(): void {
		$ok = CacheabilityAudit::analyze( 200, [ 'cache-control' => 'max-age=600', 'x-litespeed-cache' => 'hit' ] );
		$this->assertSame( 'ok', $ok['verdict'] );
		$this->assertSame( [ 'cache-control' => 'max-age=600', 'x-litespeed-cache' => 'hit' ], $ok['headers'] );

		$bad = CacheabilityAudit::analyze( 200, [ 'set-cookie' => [ 'woocommerce_recently_viewed=1; path=/', 'pll_language=es' ], 'cache-control' => 'no-store' ] );
		$this->assertSame( 'warning', $bad['verdict'] );
		$this->assertCount( 3, $bad['issues'] );
		$this->assertSame( [ 'woocommerce_recently_viewed', 'pll_language' ], array_column( $bad['cookies'], 'name' ) );
		$this->assertStringContainsString( 'localStorage', $bad['cookies'][0]['hint'] );
		$this->assertStringContainsString( 'Polylang', $bad['cookies'][1]['hint'] );

		$this->assertSame( 'error', CacheabilityAudit::analyze( 500, [] )['verdict'] );
		$this->assertCount( 1, CacheabilityAudit::analyze( 200, [ 'set-cookie' => 'PHPSESSID=1' ] )['cookies'] );
	}

	/**
	 * El caso real (0.10.5): _fbp y se-recently-viewed-products solo en la visita que regenera la página.
	 */
	public function test_audit_second_request_from_cache(): void {
		$first = CacheabilityAudit::analyze(
			200,
			[
				'set-cookie'                => [ '_fbp=fb.1.1.2.AQ; path=/', 'se-recently-viewed-products=4690; path=/' ],
				'x-litespeed-cache'         => 'miss',
				'x-litespeed-cache-control' => 'public,max-age=604800',
			]
		);
		$this->assertSame( 'warning', $first['verdict'] );
		$this->assertStringContainsString( 'Meta', $first['cookies'][0]['hint'] );
		$this->assertStringContainsString( 'Searchanise', $first['cookies'][1]['hint'] );

		$cached = CacheabilityAudit::reconcile( $first, 200, [ 'x-litespeed-cache' => 'hit' ] );
		$this->assertSame( 'info', $cached['verdict'], 'la caché funciona: no es un problema' );
		$this->assertSame( [], $cached['issues'] );
		$this->assertStringContainsString( 'x-litespeed-cache: hit', $cached['notes'][0] );
		$this->assertSame( 'hit', $cached['headers']['x-litespeed-cache'] );

		$again = CacheabilityAudit::reconcile( $first, 200, [ 'set-cookie' => '_fbp=otra; path=/' ] );
		$this->assertSame( $first, $again, 'si la segunda también trae cookies, sigue siendo un problema' );

		$withNoStore = CacheabilityAudit::analyze( 200, [ 'set-cookie' => 'x=1', 'cache-control' => 'no-store' ] );
		$this->assertSame( 'warning', CacheabilityAudit::reconcile( $withNoStore, 200, [ 'cache-control' => 'no-store' ] )['verdict'], 'los demás problemas se conservan' );
	}

	public function test_audit_page_marked_dynamic_on_purpose(): void {
		$search = CacheabilityAudit::analyze(
			200,
			[
				'cache-control'             => 'no-cache, must-revalidate, max-age=0, no-store, private',
				'x-litespeed-cache-control' => 'no-cache',
			]
		);
		$this->assertSame( 'info', $search['verdict'] );
		$this->assertSame( [], $search['issues'] );
		$this->assertCount( 1, $search['notes'] );
		$this->assertSame( 'warning', CacheabilityAudit::analyze( 200, [ 'cache-control' => 'no-store' ] )['verdict'], 'sin la marca del plugin de caché sigue siendo un problema' );
	}

	public function test_per_visit_requests(): void {
		$row  = static fn( string $type, string $route, int $ms, int $anon = 1, int $source = Collector::SOURCE_RANDOM ): array => [
			'request_type' => $type,
			'route_key'    => $route,
			'ms'           => $ms,
			'anon'         => $anon,
			'source'       => $source,
		];
		$rows = [];
		// 48 h al 1 %: 20 muestras del menú de WoodMart = ~1.000 al día.
		for ( $i = 0; $i < 20; $i++ ) {
			$rows[] = $row( 'AdminAjax', 'woodmart_load_html_dropdowns', 800 );
		}
		for ( $i = 0; $i < 4; $i++ ) {
			$rows[] = $row( 'StoreApi', 'wc/store/v1', 300 );
		}
		$rows[] = $row( 'AdminAjax', 'rara', 5000 );
		$rows[] = $row( 'AdminAjax', 'heartbeat', 400, 0 );
		$rows[] = $row( 'AdminAjax', 'heartbeat', 400, 0 );
		$rows[] = $row( 'AdminAjax', 'heartbeat', 400, 0 );
		$rows[] = $row( 'FrontendHtml', 'portada', 900 );
		$rows[] = $row( 'FrontendHtml', 'portada', 900 );
		$rows[] = $row( 'FrontendHtml', 'portada', 900 );
		$rows[] = $row( 'AdminAjax', 'woodmart_load_html_dropdowns', 800, 1, Collector::SOURCE_INTENSIVE );

		$items = Report::perVisit( $rows, 0.01, 48, 1500.0, [ 'AdminAjax:woodmart_load_html_dropdowns' => 'active' ] );
		$this->assertSame( [ 'woodmart_load_html_dropdowns', 'wc/store/v1' ], array_column( $items, 'route_key' ), 'solo visitantes sin sesión, muestreo aleatorio y al menos 3 muestras; sin HTML' );
		$this->assertSame( 1000, $items[0]['per_day'] );
		$this->assertSame( 800, $items[0]['cpu_s_day'], '1.000 × 0,8 s' );
		$this->assertSame( 0.67, $items[0]['per_view'] );
		$this->assertTrue( $items[0]['per_visit'] );
		$this->assertSame( 'active', $items[0]['rule'] );
		$this->assertStringContainsString( 'WoodMart', $items[0]['hint'] );
		$this->assertFalse( $items[1]['per_visit'], '200 al día con 1.500 visitas' );

		$noRum = Report::perVisit( $rows, 0.01, 48, null );
		$this->assertNull( $noRum[0]['per_view'] );
		$this->assertFalse( $noRum[0]['per_visit'], 'sin páginas vistas no se afirma "en cada visita"' );
		$this->assertSame( [], Report::perVisit( $rows, 0.0, 48, null ) );

		$this->assertTrue( SampleWriter::anonymous( [ 'woocommerce_items_in_cart' => '1' ] ) );
		$this->assertFalse( SampleWriter::anonymous( [ 'wordpress_logged_in_abc' => 'x' ] ) );
	}

	public function test_php_workers_estimate(): void {
		$rows = [];
		// 48 h al 1 %: 10 muestras de 3,6 s en una hora = 3.600 s de PHP en esa hora = 1 proceso todo el tiempo.
		for ( $i = 0; $i < 10; $i++ ) {
			$rows[] = [
				'created_at'   => '2026-10-15 14:' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) . ':00',
				'request_type' => 'AdminAjax',
				'route_key'    => 'woodmart_load_html_dropdowns',
				'ms'           => 3600,
				'source'       => Collector::SOURCE_RANDOM,
			];
		}
		$rows[] = [
			'created_at'   => '2026-10-15 03:00:00',
			'request_type' => 'Cron',
			'route_key'    => 'cron',
			'ms'           => 6000,
			'source'       => Collector::SOURCE_RANDOM,
		];
		$rows[] = [
			'created_at'   => '2026-10-15 14:30:00',
			'request_type' => 'Admin',
			'route_key'    => 'x',
			'ms'           => 90000,
			'source'       => Collector::SOURCE_INTENSIVE,
		];
		$w = Report::workers( $rows, 0.01, 48 );
		$this->assertSame( 1.0, $w['peak'] );
		$this->assertSame( '2026-10-15 14', $w['peak_hour'] );
		$this->assertSame( 0.02, $w['avg'], '42.000 s de PHP en 48 h' );
		$this->assertSame( 'woodmart_load_html_dropdowns', $w['top'][0]['route_key'] );
		$this->assertSame( 1, $w['top'][1]['slow'], 'más de 5 s' );
		$this->assertSame( 0.0, Report::workers( [], 0.01, 48 )['avg'] );
	}

	public function test_platform_audit(): void {
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => number_format( (float) $n ) );
		$base   = [
			'icons_svg'        => true,
			'optimized_markup' => false,
			'element_cache'    => true,
			'css_print'        => 'external',
			'google_fonts'     => true,
			'local_fonts'      => false,
			'font_display'     => 'swap',
			'lazy_bg'          => true,
			'fa4_shim'         => false,
		];
		$status = static fn( array $checks ): array => array_combine( array_column( $checks, 'id' ), array_column( $checks, 'status' ) );
		$this->assertSame(
			[
				'el_icons'   => 'ok',
				'el_cache'   => 'ok',
				'el_css'     => 'ok',
				'el_fa4'     => 'ok',
				'el_display' => 'ok',
				'el_lazy_bg' => 'ok',
				'el_fonts'   => 'warn',
				'el_markup'  => 'info',
			],
			$status( \ImaginaTurbo\Core\PlatformAudit::elementor( $base ) ),
			'el sitio real: solo las fuentes de Google desde Google'
		);
		$this->assertSame( 'warn', $status( \ImaginaTurbo\Core\PlatformAudit::elementor( [ 'css_print' => 'internal', 'fa4_shim' => true ] + $base ) )['el_css'] );

		$woo = [
			'hpos'           => true,
			'hpos_cache'     => false,
			'lookup'         => false,
			'lookup_running' => false,
			'lookup_aborted' => false,
		];
		$this->assertSame( [ 'wc_hpos_cache' => 'warn', 'wc_lookup' => 'warn' ], $status( \ImaginaTurbo\Core\PlatformAudit::woocommerce( $woo, true ) ) );
		$this->assertSame( 'info', $status( \ImaginaTurbo\Core\PlatformAudit::woocommerce( $woo, false ) )['wc_hpos_cache'], 'sin caché de objetos no rinde' );
		$this->assertSame( [ 'wc_lookup' => 'warn' ], $status( \ImaginaTurbo\Core\PlatformAudit::woocommerce( [ 'hpos' => false, 'lookup_aborted' => true ] + $woo, true ) ) );

		$this->assertSame( [ 'object_cache' => 'warn', 'indexes' => 'warn' ], $status( \ImaginaTurbo\Core\PlatformAudit::server( false, true, 250000, false ) ) );
		$this->assertSame( [ 'object_cache' => 'ok' ], $status( \ImaginaTurbo\Core\PlatformAudit::server( true, true, 43000, false ) ), 'el sitio real: Redis activo, postmeta chico' );
	}
}
