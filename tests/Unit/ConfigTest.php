<?php
/**
 * Ajustes, compilador y lector de la configuración compilada.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Config\SitePaths;
use ImaginaTurbo\Core\Environment\Detector;
use ImaginaTurbo\Core\ModuleCatalog;

final class ConfigTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options = [
			'active_plugins'                  => [ 'woocommerce/woocommerce.php', 'wps-hide-login/wps-hide-login.php', 'perfmatters/perfmatters.php' ],
			'woocommerce_cart_page_id'        => 10,
			'woocommerce_checkout_page_id'    => 11,
			'woocommerce_myaccount_page_id'   => 12,
			'whl_page'                        => 'acceso',
		];
		Functions\when( 'get_option' )->alias( fn( string $name, mixed $default = false ): mixed => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'get_stylesheet' )->justReturn( 'astra' );
		Functions\when( 'get_template' )->justReturn( 'astra' );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://tienda.test/' );
		Functions\when( 'site_url' )->justReturn( 'https://tienda.test/' );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'get_permalink' )->alias(
			static fn( int $id ): string => [
				10 => 'https://tienda.test/carrito/',
				11 => 'https://tienda.test/finalizar-compra/',
				12 => 'https://tienda.test/Mi-Cuenta/',
			][ $id ] ?? ''
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_delete_file' )->alias( 'unlink' );
	}

	private function compiler( string $dataDir ): ConfigCompiler {
		return new ConfigCompiler(
			new Settings(),
			new Detector(),
			new SitePaths(),
			new ModuleCatalog(),
			$dataDir,
			self::pluginDir() . '/data/compat-matrix-v1.json',
			'0.1.0'
		);
	}

	public function test_settings_sanitize_discards_unknown_and_invalid(): void {
		$clean = Settings::sanitize(
			[
				'modules'         => [
					'profiler'    => true,
					'MAL'         => true,
					'shield'      => 'yes',
					'woo_cart'    => false,
				],
				'tag_prefix'      => 'k7',
				'debug'           => 'true',
				'staging_domains' => [ 'Staging.Ejemplo.com ', 'no válido!', 5, 'staging.ejemplo.com' ],
				'desconocida'     => 1,
			]
		);
		$this->assertSame(
			[
				'profiler' => true,
				'woo_cart' => false,
			],
			$clean['modules']
		);
		$this->assertSame( 'k7', $clean['tag_prefix'] );
		$this->assertFalse( $clean['debug'] );
		$this->assertSame( [ 'staging.ejemplo.com' ], $clean['staging_domains'] );
		$this->assertArrayNotHasKey( 'desconocida', $clean );
	}

	public function test_settings_initialize_generates_prefix_and_merges_modules(): void {
		$settings = new Settings();
		$settings->ensureInitialized();
		$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9]$/', (string) $settings->get( 'tag_prefix' ) );

		$settings->update( [ 'modules' => [ 'shield' => false ] ] );
		$settings->update( [ 'modules' => [ 'tweaks' => true ] ] );
		$this->assertSame(
			[
				'shield' => false,
				'tweaks' => true,
			],
			( new Settings() )->modules()
		);
	}

	public function test_settings_import_export(): void {
		$settings = new Settings();
		$settings->ensureInitialized();
		$prefix = $settings->get( 'tag_prefix' );
		$settings->update( [ 'debug' => true ] );

		$export = $settings->export();
		$this->assertArrayNotHasKey( 'tag_prefix', $export['settings'], 'el prefijo es propio de cada sitio' );

		$settings->update( [ 'debug' => false ] );
		$settings->import( $export );
		$this->assertTrue( $settings->get( 'debug' ) );
		$this->assertSame( $prefix, $settings->get( 'tag_prefix' ) );

		foreach ( [ null, [], [ 'plugin' => 'otro' ], [ 'plugin' => 'imagina-turbo', 'version' => 99, 'settings' => [] ] ] as $bad ) {
			try {
				$settings->import( $bad );
				$this->fail( 'Debió rechazar la importación.' );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_build_resolves_paths_modules_and_only_active_rules(): void {
		$warnings = [];
		$data     = $this->compiler( $this->tmp )->build( $warnings );

		$this->assertSame( CompiledConfig::FORMAT_VERSION, $data['v'] );
		$this->assertSame( [ '/carrito' ], $data['paths']['cart'] );
		$this->assertSame( [ '/finalizar-compra' ], $data['paths']['checkout'] );
		$this->assertSame( [ '/mi-cuenta' ], $data['paths']['account'] );
		$this->assertSame( [ '/acceso' ], $data['paths']['login'] );
		$this->assertSame( '', $data['home_path'] );
		$this->assertSame( 'wp-json', $data['rest_prefix'] );

		$this->assertEqualsCanonicalizing(
			[ 'plugin:woocommerce/woocommerce.php', 'plugin:wps-hide-login/wps-hide-login.php', 'theme:astra' ],
			$data['integrations']
		);
		$this->assertContains( 'itb_bypass', $data['bypass_cookies'] );
		$this->assertFalse( $data['requires']['xmlrpc'] );

		// Solo los módulos implementados y seguros quedan activos. Ajustes rápidos se apaga porque
		// Perfmatters está activo (solapamiento).
		$this->assertTrue( $data['modules']['profiler'] );
		$this->assertSame( [ 'profiler', 'shield' ], array_keys( array_filter( $data['modules'] ) ) );
		$this->assertSame( 0.01, $data['profiler']['rate'] );
		$this->assertSame( 0, $data['profiler']['intensive_until'] );
		$this->assertArrayHasKey( 'plugin_filter', $data['modules'] );
		$this->assertSame( [], $warnings );

		// Caché: apagado por defecto (riesgo moderado), en modo solo sincronizar.
		$this->assertFalse( $data['modules']['cache_coordinator'] );
		$this->assertSame( 'sync', $data['cache']['mode'] );
		$this->assertSame( DAY_IN_SECONDS, $data['cache']['edge_ttl'] );
		$this->assertTrue( $data['cache']['nonce_refresh'] );
		$this->assertTrue( $data['cache']['edge_html'] );
	}

	public function test_settings_sanitize_cache_keys(): void {
		$clean = Settings::sanitize(
			[
				'cache_mode'          => 'headers',
				'cache_edge_ttl'      => 5,
				'cache_swr'           => '3600',
				'cache_sie'           => 10 * YEAR_IN_SECONDS,
				'cache_nonce_refresh' => false,
				'cache_warm'          => 'no',
			]
		);
		$this->assertSame( 'headers', $clean['cache_mode'] );
		$this->assertSame( 60, $clean['cache_edge_ttl'] );
		$this->assertSame( 3600, $clean['cache_swr'] );
		$this->assertSame( 30 * DAY_IN_SECONDS, $clean['cache_sie'] );
		$this->assertFalse( $clean['cache_nonce_refresh'] );
		$this->assertTrue( $clean['cache_warm'], 'un valor no booleano deja el de por defecto' );
		$this->assertSame( 'sync', Settings::sanitize( [ 'cache_mode' => 'edge' ] )['cache_mode'] );
	}

	public function test_hash_is_deterministic_and_ignores_timestamp(): void {
		$a = $this->compiler( $this->tmp )->build();
		$b = $this->compiler( $this->tmp )->build();
		$this->assertSame( $a['hash'], $b['hash'] );
		$this->assertSame( $a['hash'], ConfigCompiler::hash( array_merge( $a, [ 'generated' => 1 ] ) ) );

		$this->options['active_plugins'][] = 'jetpack/jetpack.php';
		$c                                 = $this->compiler( $this->tmp )->build();
		$this->assertNotSame( $a['hash'], $c['hash'] );
		$this->assertTrue( $c['requires']['xmlrpc'] );
	}

	public function test_compile_writes_loadable_file_atomically(): void {
		$dir    = $this->tmp . '/data';
		$result = $this->compiler( $dir )->compile();

		$this->assertTrue( $result['ok'], implode( ', ', $result['errors'] ) );
		$this->assertFileExists( $dir . '/config.php' );
		$this->assertFileExists( $dir . '/index.php' );
		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertSame( [], glob( $dir . '/.*.tmp' ) ?: [], 'sin temporales' );

		$config = CompiledConfig::load( $dir . '/config.php' );
		$this->assertNotNull( $config );
		$this->assertSame( $result['hash'], $config->hash() );
		$this->assertSame( '0.1.0', $config->pluginVersion() );
		$this->assertSame( [ '/carrito' ], $config->classifierConfig()['paths']['cart'] );

		$this->compiler( $dir )->delete();
		$this->assertFileDoesNotExist( $dir . '/config.php' );
	}

	public function test_compile_reports_unwritable_dir_without_throwing(): void {
		$file = $this->tmp . '/archivo';
		touch( $file );
		$result = $this->compiler( $file . '/sub' )->compile();
		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_compile_survives_broken_matrix(): void {
		$compiler = new ConfigCompiler( new Settings(), new Detector(), new SitePaths(), new ModuleCatalog(), $this->tmp, $this->tmp . '/no-existe.json', '0.1.0' );
		$result   = $compiler->compile();
		$this->assertTrue( $result['ok'] );
		$this->assertNotEmpty( $result['warnings'] );
		$config = CompiledConfig::load( $this->tmp . '/config.php' );
		$this->assertNotNull( $config );
		$this->assertContains( 'wordpress_logged_in_', $config->get( 'bypass_cookies' ) );
	}

	public function test_compiled_config_rejects_bad_files(): void {
		$file = $this->tmp . '/config.php';

		$this->assertNull( CompiledConfig::load( $file ), 'no existe' );

		file_put_contents( $file, '<?php return [ "v" => 999, "hash" => "x" ];' );
		$this->assertNull( CompiledConfig::load( $file ), 'otra versión' );

		file_put_contents( $file, '<?php return "hola";' );
		$this->assertNull( CompiledConfig::load( $file ), 'no es array' );

		file_put_contents( $file, '<?php return [ "v" => 1, "hash" => "x", "modules" => "mal" ];' );
		$this->assertNull( CompiledConfig::load( $file ), 'sección con tipo incorrecto' );

		file_put_contents( $file, '<?php return [ "v" => 1, ' );
		$this->assertNull( CompiledConfig::load( $file ), 'error de sintaxis' );

		file_put_contents( $file, '<?php return [ "v" => 1, "hash" => "abc", "modules" => [ "x" => true, "y" => "no" ] ];' );
		$config = CompiledConfig::load( $file );
		$this->assertNotNull( $config );
		$this->assertTrue( $config->moduleEnabled( 'x' ) );
		$this->assertFalse( $config->moduleEnabled( 'y' ) );
		$this->assertSame( [ 'x' => true ], $config->modules() );
	}

	public function test_paths_per_site_in_multisite(): void {
		$this->assertSame( '/d/config.php', CompiledConfig::path( '/d' ) );
		$this->assertSame( '/d/config-5.php', CompiledConfig::path( '/d', 5, true ) );
	}

	public function test_render_is_valid_php_and_guarded(): void {
		$code = ConfigCompiler::render(
			[
				'v'    => 1,
				'hash' => "a'b",
			],
			'1.0.0"; echo 1; //'
		);
		$file = $this->tmp . '/r.php';
		file_put_contents( $file, $code );
		exec( 'php -l ' . escapeshellarg( $file ), $out, $code_ );
		$this->assertSame( 0, $code_ );
		$this->assertStringNotContainsString( 'echo 1', $code );
	}

	public function test_scheduled_compile_runs_once_and_halt_blocks_rescheduling(): void {
		Functions\expect( 'add_action' )->once();
		$compiler = $this->compiler( $this->tmp );
		$compiler->schedule();
		$compiler->schedule();
		$compiler->halt();
		// Como deactivated_plugin después del hook de desactivación.
		$compiler->schedule();
		$compiler->runScheduled();
		$this->assertFileDoesNotExist( $this->tmp . '/config.php' );
	}

	public function test_scheduled_compile_writes_file(): void {
		Functions\when( 'add_action' )->justReturn( true );
		$compiler = $this->compiler( $this->tmp );
		$compiler->schedule();
		$compiler->runScheduled();
		$this->assertFileExists( $this->tmp . '/config.php' );
	}
}
