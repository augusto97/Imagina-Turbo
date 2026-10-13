<?php
/**
 * Fase 5: carga condicional (filtro temprano, protecciones, cookie de experimento, comparación,
 * política, candidatos e Imagina Search).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Integrations\ImaginaSearch;
use ImaginaTurbo\Modules\PluginFilter\CandidateFinder;
use ImaginaTurbo\Modules\PluginFilter\EarlyFilter;
use ImaginaTurbo\Modules\PluginFilter\ExperimentCookie;
use ImaginaTurbo\Modules\PluginFilter\ResponseComparer;
use ImaginaTurbo\Modules\PluginFilter\RulePolicy;
use ImaginaTurbo\Modules\PluginFilter\RuleStore;

final class PluginFilterTest extends TestCase {

	private const ACTIVE = [ 'akismet/akismet.php', 'elementor/elementor.php', 'hello.php', 'imagina-turbo/imagina-turbo.php', 'woocommerce-memberships/woocommerce-memberships.php', 'woocommerce/woocommerce.php', 'woo-smart-search/woo-smart-search.php' ];

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		EarlyFilter::reset();
		$this->options = [ 'rewrite_rules' => [ 'a' => 'b' ] ];
		Functions\when( 'get_option' )->alias( fn( string $name, mixed $default = false ): mixed => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_is_mobile' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		EarlyFilter::reset();
		parent::tearDown();
	}

	public function test_matches_by_exact_folder(): void {
		$this->assertTrue( EarlyFilter::matches( 'woocommerce/woocommerce.php', [ 'woocommerce/otro.php' ] ), 'misma carpeta' );
		$this->assertFalse( EarlyFilter::matches( 'woocommerce-memberships/woocommerce-memberships.php', [ 'woocommerce/woocommerce.php' ] ), 'nunca por prefijo' );
		$this->assertTrue( EarlyFilter::matches( 'hello.php', [ 'hello.php' ] ) );
		$this->assertFalse( EarlyFilter::matches( 'hello.php', [ 'hello/hello.php' ] ) );
		$this->assertFalse( EarlyFilter::matches( 'hello/hello.php', [ 'hello.php' ] ) );
	}

	public function test_filter_hides_and_protects_writes(): void {
		EarlyFilter::install( [ 'elementor/elementor.php', 'hello.php' ] );
		$filtered = EarlyFilter::filterActive( self::ACTIVE );
		$this->assertNotContains( 'elementor/elementor.php', $filtered );
		$this->assertNotContains( 'hello.php', $filtered );
		$this->assertContains( 'woocommerce-memberships/woocommerce-memberships.php', $filtered );
		$this->assertSame( [ 'elementor/elementor.php', 'hello.php' ], EarlyFilter::hidden() );
		$this->assertTrue( Runtime::$pluginsFiltered );

		// Un plugin (Freemius) lee la lista filtrada, agrega uno y la guarda: los ocultos vuelven.
		$saved = EarlyFilter::protectWrite( array_merge( $filtered, [ 'nuevo/nuevo.php' ] ), $filtered );
		$this->assertContains( 'elementor/elementor.php', $saved );
		$this->assertContains( 'hello.php', $saved );
		$this->assertContains( 'nuevo/nuevo.php', $saved );
		$sorted = $saved;
		sort( $sorted );
		$this->assertSame( $sorted, $saved, 'WordPress la guarda ordenada' );

		// rewrite_rules: mientras haya ocultos, se conserva la anterior.
		$this->assertSame( 'vieja', EarlyFilter::protectRewrite( 'nueva', 'vieja' ) );
	}

	public function test_no_filter_when_rewrite_rules_empty(): void {
		$this->options['rewrite_rules'] = '';
		EarlyFilter::install( [ 'elementor/elementor.php' ] );
		$this->assertSame( self::ACTIVE, EarlyFilter::filterActive( self::ACTIVE ) );
		$this->assertSame( [], EarlyFilter::hidden() );
		$this->assertSame( 'nueva', EarlyFilter::protectRewrite( 'nueva', 'vieja' ), 'sin ocultos, WordPress puede regenerarlas' );
		$this->assertFalse( Runtime::$pluginsFiltered );
	}

	public function test_sitewide_and_experiments_do_not_count_for_safe_mode(): void {
		EarlyFilter::install( [ 'elementor/elementor.php' ], false );
		$network = EarlyFilter::filterSitewide(
			[
				'elementor/elementor.php'     => 100,
				'woocommerce/woocommerce.php' => 200,
			]
		);
		$this->assertSame( [ 'woocommerce/woocommerce.php' => 200 ], $network );
		$this->assertFalse( Runtime::$pluginsFiltered, 'los fallos de un experimento se miden aparte' );
		$restored = EarlyFilter::protectSitewideWrite( $network, [ 'elementor/elementor.php' => 100 ] );
		$this->assertSame( 100, $restored['elementor/elementor.php'] );
	}

	public function test_experiment_cookie(): void {
		$key    = 'clave-de-prueba-0123456789';
		$now    = 1_800_000_000;
		$cookie = ExperimentCookie::create( 'abcdef012345', 1, 'n0nce1234', $now + 300, $key );
		$this->assertSame(
			[
				'id'    => 'abcdef012345',
				'mode'  => 1,
				'nonce' => 'n0nce1234',
			],
			ExperimentCookie::validate( $cookie, $key, $now )
		);
		$this->assertNull( ExperimentCookie::validate( $cookie, 'otra-clave', $now ) );
		$this->assertNull( ExperimentCookie::validate( $cookie, $key, $now + 400 ), 'expirada' );
		$this->assertNull( ExperimentCookie::validate( str_replace( '|1|', '|0|', $cookie ), $key, $now ), 'modo alterado' );
		$this->assertNull( ExperimentCookie::validate( ExperimentCookie::create( 'abcdef012345', 1, 'n0nce1234', $now + 9999, $key ), $key, $now ), 'vida demasiado larga' );
		$this->assertNotNull( ExperimentCookie::key(), 'AUTH_KEY y AUTH_SALT de las pruebas' );
	}

	public function test_comparer(): void {
		$json = static fn( string $body, int $status = 200 ): array => [
			'status'  => $status,
			'headers' => [ 'content-type' => 'application/json; charset=UTF-8' ],
			'body'    => $body,
		];
		// Nonces, hashes y fechas no cuentan.
		$this->assertNull( ResponseComparer::diff( $json( '{"a":1,"nonce":"0123abcdef","t":"2026-10-13T10:00:00"}' ), $json( '{"a":1,"nonce":"9876fedcba","t":"2026-10-13T10:00:05"}' ), ResponseComparer::EXACT ) );
		$this->assertSame( 'body', ResponseComparer::diff( $json( '{"price":"$10"}' ), $json( '{"price":"10 USD"}' ), ResponseComparer::EXACT )['field'] ?? '' );
		$this->assertSame( 'status', ResponseComparer::diff( $json( '{}' ), $json( '{}', 404 ), ResponseComparer::EXACT )['field'] ?? '' );
		// Estructural: valores distintos, misma forma.
		$this->assertNull( ResponseComparer::diff( $json( '{"hits":[{"id":1}],"total":5}' ), $json( '{"hits":[{"id":2}],"total":7}' ), ResponseComparer::STRUCTURAL ) );
		$this->assertNotNull( ResponseComparer::diff( $json( '{"hits":[{"id":1}],"total":5}' ), $json( '{"hits":[],"total":5}' ), ResponseComparer::STRUCTURAL ) );
		$this->assertSame( ResponseComparer::STRUCTURAL, ResponseComparer::calibrate( $json( '{"r":1}' ), $json( '{"r":2}' ) ) );
		$this->assertSame( ResponseComparer::EXACT, ResponseComparer::calibrate( $json( '{"r":1}' ), $json( '{"r":1}' ) ) );
		// Cookies por nombre.
		$a = $json( '{}' );
		$b = $json( '{}' );
		$b['headers']['set-cookie'] = "wp_woocommerce_session_x=1; path=/\nother=2";
		$this->assertSame( 'set-cookie', ResponseComparer::diff( $a, $b, ResponseComparer::EXACT )['field'] ?? '' );
		// HTML estructural: etiquetas.
		$this->assertSame( 'div span', ResponseComparer::fingerprint( '<div class="x"><span>1</span></div>', 'text/html', ResponseComparer::STRUCTURAL ) );
	}

	public function test_policy(): void {
		$this->assertSame( '', RulePolicy::blockedReason( 'WcAjax:get_refreshed_fragments' ) );
		$this->assertSame( '', RulePolicy::blockedReason( 'Rest:wss/v1' ) );
		$this->assertSame( '', RulePolicy::blockedReason( 'StaticMiss' ) );
		foreach ( [ 'WcAjax:checkout', 'WcAjax:update_order_review', 'Rest:wp/v2', 'Rest', 'Rest:wc/store/v1', 'Rest:imagina-turbo/v1', 'Admin', 'FrontendHtml', 'Checkout', 'WcAjax:x y', 'Rest:../etc' ] as $key ) {
			$this->assertNotSame( '', RulePolicy::blockedReason( $key ), $key );
		}
		$self = 'imagina-turbo/imagina-turbo.php';
		$this->assertSame( [ $self, 'woocommerce/woocommerce.php' ], RulePolicy::protectedPlugins( 'WcAjax:add_to_cart', self::ACTIVE, $self ) );
		$this->assertSame( [ $self ], RulePolicy::protectedPlugins( 'AdminAjax:x', self::ACTIVE, $self ) );
		$this->assertSame( [ $self, 'woo-smart-search/woo-smart-search.php', 'woocommerce/woocommerce.php' ], RulePolicy::protectedPlugins( 'Rest:wss/v1', self::ACTIVE, $self ) );
		$this->assertTrue( RulePolicy::defaultAllMethods( 'WcAjax:add_to_cart' ) );
		$this->assertFalse( RulePolicy::defaultAllMethods( 'Rest:wss/v1' ) );
		$this->assertSame( 'POST', RulePolicy::defaultProbes( 'WcAjax:get_refreshed_fragments', 'wp-json' )[0]['method'] );
		$this->assertSame( 'action=mi_accion', RulePolicy::defaultProbes( 'AdminAjax:mi_accion', 'wp-json' )[0]['body'] );
		$this->assertSame( '/api/mi/v1', RulePolicy::defaultProbes( 'Rest:mi/v1', 'api' )[0]['path'] );
		// Peticiones de prueba: solo rutas del sitio.
		$this->assertSame(
			[ '/ok', '/wp-json/x?q=1' ],
			array_column(
				RulePolicy::sanitizeProbes(
					[
						[
							'method' => 'get',
							'path'   => '/ok',
						],
						[
							'method' => 'GET',
							'path'   => 'https://otro.com/',
						],
						[
							'method' => 'GET',
							'path'   => '//otro.com/',
						],
						[
							'method' => 'DELETE',
							'path'   => '/x',
						],
						[
							'method' => 'GET',
							'path'   => '/wp-json/x?q=1',
						],
					]
				),
				'path'
			)
		);
	}

	public function test_rule_store_compiles_only_active_plugins(): void {
		$store = new RuleStore();
		$rule  = RuleStore::normalize(
			[
				'key'         => 'WcAjax:get_refreshed_fragments',
				'plugins'     => [ 'elementor/elementor.php', 'ya-no-esta/x.php' ],
				'all_methods' => true,
				'probes'      => [ RulePolicy::probe( 'POST', '/?wc-ajax=get_refreshed_fragments' ) ],
				'status'      => 'active',
			]
		);
		$this->assertNotNull( $rule );
		$store->save( $rule );
		$testing = RuleStore::normalize(
			[
				'key'     => 'Rest:wss/v1',
				'plugins' => [ 'akismet/akismet.php' ],
				'probes'  => [ RulePolicy::probe( 'GET', '/wp-json/wss/v1/popular' ) ],
				'status'  => 'testing',
			]
		);
		$this->assertNotNull( $testing );
		$store->save( $testing );
		$compiled = $store->compile( self::ACTIVE );
		$this->assertSame(
			[
				'WcAjax:get_refreshed_fragments' => [
					'p' => [ 'elementor/elementor.php' ],
					'w' => true,
				],
			],
			$compiled['filter'],
			'solo las activas, solo plugins que siguen activos'
		);
		$this->assertArrayHasKey( RuleStore::id( 'Rest:wss/v1' ), $compiled['experiments'] );
		$this->assertArrayHasKey( RuleStore::id( 'WcAjax:get_refreshed_fragments' ), $compiled['experiments'], 'las activas se pueden revisar' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{12}$/', RuleStore::id( 'x' ) );
	}

	public function test_candidates_from_profiler_samples(): void {
		$row  = static fn( string $type, string $route, array $costs ): array => [
			'request_type' => $type,
			'route_key'    => $route,
			'ms'           => 120,
			'mem_kb'       => 20000,
			'queries'      => 10,
			'overhead_us'  => 100,
			'cacheable'    => 0,
			'via_cf'       => 1,
			'plugins_cost' => json_encode( $costs ),
		];
		$rows = [
			$row(
				'WcAjax',
				'get_refreshed_fragments',
				[
					[
						'c' => 'plugin:elementor',
						'l' => 20,
						'h' => 5,
						'k' => 900,
					],
					[
						'c' => 'plugin:woocommerce',
						'l' => 30,
						'h' => 10,
						'k' => 1200,
					],
					[
						'c' => 'plugin:akismet',
						'l' => 0.1,
						'h' => 0,
						'k' => 5,
					],
				]
			),
			$row( 'FrontendHtml', 'portada', [] ),
			$row( 'Rest', 'wp/v2', [] ),
		];
		$found = CandidateFinder::find( $rows, self::ACTIVE, 'imagina-turbo/imagina-turbo.php' );
		$keys  = array_column( $found, 'key' );
		$this->assertSame( 'WcAjax:get_refreshed_fragments', $keys[0] );
		$this->assertSame( [ 'elementor/elementor.php' ], array_column( $found[0]['plugins'], 'plugin' ), 'woocommerce protegido; akismet cuesta poco' );
		$this->assertNotContains( 'FrontendHtml:portada', $keys );
		$this->assertContains( 'Rest:wss/v1', $keys, 'Imagina Search se propone aunque no haya muestras' );
		$wp = $found[ array_search( 'Rest:wp/v2', $keys, true ) ];
		$this->assertNotSame( '', $wp['blocked'] );
		$this->assertSame( 'elementor/elementor.php', CandidateFinder::file( 'elementor', self::ACTIVE ) );
		$this->assertSame( 'hello.php', CandidateFinder::file( 'hello', self::ACTIVE ) );
	}

	public function test_imagina_search_detection(): void {
		$this->assertSame( 'woo-smart-search/woo-smart-search.php', ImaginaSearch::plugin( self::ACTIVE ) );
		$this->assertSame( 'otra-carpeta/woo-smart-search.php', ImaginaSearch::plugin( [ 'otra-carpeta/woo-smart-search.php' ] ) );
		$this->assertNull( ImaginaSearch::plugin( [ 'woo-smart-search-pro/x.php' ] ) );
		Functions\when( 'get_posts' )->justReturn( [] );
		Functions\when( 'post_type_exists' )->justReturn( true );
		$probes = ImaginaSearch::probes( '/wp-json/' );
		$this->assertStringStartsWith( '/wp-json/wss/v1/search?q=', $probes[0]['path'] );
		$this->assertSame( '/wp-json/wss/v1/popular?limit=6', $probes[1]['path'] );
	}
}
