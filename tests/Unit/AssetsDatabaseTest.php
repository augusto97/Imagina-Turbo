<?php
/**
 * Fase 7: exclusiones de delay JS, gestor de assets y base de datos.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Compat\Matrix;
use ImaginaTurbo\Compat\Resolver;
use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\AssetManager\AssetManagerModule;
use ImaginaTurbo\Modules\AssetManager\AssetRules;
use ImaginaTurbo\Modules\AssetManager\AssetScanner;
use ImaginaTurbo\Modules\AssetManager\AssetSuggestions;
use ImaginaTurbo\Modules\CacheCoordinator\DelayJsExclusions;
use ImaginaTurbo\Modules\Database\DatabaseAudit;
use ImaginaTurbo\Modules\Database\DatabaseModule;
use ImaginaTurbo\Rest\AssetsController;

final class AssetsDatabaseTest extends TestCase {

	public function test_delay_js_literals_for_litespeed(): void {
		$this->assertSame(
			[ 'iubenda_cs.js', 'var _iub', '/wp-content/plugins/x/app.js' ],
			DelayJsExclusions::literals( [ 'iubenda_cs.js', 'var _iub', '/jquery-migrate(.min)?.js', '/wp-content/plugins/x/app\.js', '/uploads/(.*).js', 'ab', '[a-z]+' ] )
		);
	}

	public function test_delay_js_filters_merge_config(): void {
		Runtime::$config = CompiledConfig::fromArray(
			[
				'v'        => CompiledConfig::FORMAT_VERSION,
				'hash'     => 'x',
				'delay_js' => [ 'var _iub', '/jquery-migrate(.min)?.js' ],
			]
		);
		$this->assertSame( [ 'mine', 'var _iub', '/jquery-migrate(.min)?.js', DelayJsExclusions::OWN ], DelayJsExclusions::rocket( [ 'mine' ] ), 'más los scripts propios (carrito, prefetch, RUM)' );
		$this->assertSame( [ 'a', 'b', 'var _iub', DelayJsExclusions::OWN ], DelayJsExclusions::litespeed( "a\nb\n\n" ), 'LSCache también la recibe como texto' );
		$this->assertSame( 'x', DelayJsExclusions::rocket( 'x' ), 'un valor extraño se devuelve igual' );
	}

	public function test_resolver_collects_delay_js(): void {
		$matrix   = Matrix::fromFile( self::pluginDir() . '/data/compat-matrix-v1.json' );
		$none     = ( new Resolver() )->resolve( $matrix, [], '', '' );
		$resolved = ( new Resolver() )->resolve( $matrix, [ 'iubenda-cookie-law-solution/iubenda_cookie_solution.php' ], '', '' );
		$this->assertNotEmpty( $none['delay_js'], 'exclusiones globales (pasarelas de pago) siempre' );
		$this->assertContains( 'var _iub', $resolved['delay_js'] );
		$this->assertNotContains( 'var _iub', $none['delay_js'] );
		$this->assertSame( array_values( array_unique( $resolved['delay_js'] ) ), $resolved['delay_js'] );
	}

	public function test_asset_rule_normalize(): void {
		$rule = AssetRules::normalize(
			[
				'handle'    => 'contact-form-7',
				'type'      => 'js',
				'mode'      => 'only',
				'ids'       => [ '12', 0, -3, 12, 40 ],
				'templates' => [ 'single:page', 'Mal!' ],
			]
		);
		$this->assertNotNull( $rule );
		$this->assertSame( [ 12, 40 ], $rule['ids'] );
		$this->assertSame( [ 'single:page' ], $rule['templates'] );
		$this->assertSame( 'testing', $rule['status'], 'toda regla empieza en prueba' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{12}$/', $rule['id'] );

		$this->assertNull( AssetRules::normalize( [ 'handle' => 'x', 'type' => 'js', 'mode' => 'only' ] ), '"solo aquí" sin lugares quitaría el asset en todo el sitio' );
		$this->assertNull( AssetRules::normalize( [ 'handle' => 'x', 'type' => 'css', 'mode' => 'never' ] ), '"nunca aquí" sin lugares no quitaría nada' );
		$this->assertNull( AssetRules::normalize( [ 'handle' => '<script>', 'type' => 'js', 'mode' => 'never' ] ) );
		$this->assertNull( AssetRules::normalize( [ 'handle' => 'x', 'type' => 'img', 'mode' => 'never' ] ) );
	}

	public function test_asset_rule_matching(): void {
		$never = [
			'm'   => 'never',
			'tpl' => [ 'portada' ],
			'ids' => [ 7 ],
			'pt'  => [ 'product' ],
		];
		$only  = [ 'm' => 'only' ] + $never;
		$page  = static fn( string $tpl, int $id = 0, string $pt = '' ): array => [
			'template'  => $tpl,
			'id'        => $id,
			'post_type' => $pt,
		];
		$this->assertTrue( AssetRules::removes( $never, $page( 'portada' ) ) );
		$this->assertTrue( AssetRules::removes( $never, $page( 'single:page', 7, 'page' ) ) );
		$this->assertTrue( AssetRules::removes( $never, $page( 'single:product', 9, 'product' ) ) );
		$this->assertFalse( AssetRules::removes( $never, $page( 'blog' ) ) );
		$this->assertFalse( AssetRules::removes( $only, $page( 'portada' ) ), 'solo aquí: se queda donde coincide' );
		$this->assertTrue( AssetRules::removes( $only, $page( 'blog' ) ) );
	}

	public function test_asset_manager_reads_compiled_rules_and_protects_handles(): void {
		Runtime::$config = CompiledConfig::fromArray(
			[
				'v'      => CompiledConfig::FORMAT_VERSION,
				'hash'   => 'x',
				'assets' => [
					[
						'h'   => 'cf7',
						't'   => 'js',
						'm'   => 'only',
						'tpl' => [],
						'ids' => [ '5' ],
						'pt'  => [],
						's'   => 'active',
					],
					[
						'h' => 'roto',
						't' => 'img',
					],
				],
			]
		);
		$rules = AssetManagerModule::rules();
		$this->assertCount( 1, $rules );
		$this->assertSame( [ 5 ], $rules[0]['ids'] );
		$this->assertTrue( AssetManagerModule::protectedHandle( 'admin-bar' ) );
		$this->assertTrue( AssetManagerModule::protectedHandle( 'itb-rum' ) );
		$this->assertFalse( AssetManagerModule::protectedHandle( 'jquery' ) );
	}

	public function test_asset_origin(): void {
		Functions\when( 'home_url' )->justReturn( 'https://tienda.test' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->assertSame( [ 'kind' => 'plugin', 'owner' => 'contact-form-7' ], AssetScanner::origin( 'https://tienda.test/wp-content/plugins/contact-form-7/includes/js/index.js?ver=6' ) );
		$this->assertSame( [ 'kind' => 'theme', 'owner' => 'astra' ], AssetScanner::origin( '/wp-content/themes/astra/style.css' ) );
		$this->assertSame( [ 'kind' => 'core', 'owner' => 'wordpress' ], AssetScanner::origin( '/wp-includes/js/jquery/jquery.min.js' ) );
		$this->assertSame( [ 'kind' => 'external', 'owner' => 'cdn.example.com' ], AssetScanner::origin( 'https://cdn.example.com/plugins/x/a.js' ) );
		$this->assertSame( 'inline', AssetScanner::origin( '' )['kind'] );
	}

	public function test_inspect_path_validation(): void {
		Functions\when( 'home_url' )->justReturn( 'https://tienda.test' );
		Functions\when( 'untrailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/' ) );
		Functions\when( 'admin_url' )->justReturn( 'https://tienda.test/wp-admin/' );
		Functions\when( 'site_url' )->justReturn( 'https://tienda.test' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$this->assertSame( '/contacto/', AssetsController::path( '/contacto/' ) );
		$this->assertSame( '/contacto/?a=1', AssetsController::path( 'https://tienda.test/contacto/?a=1' ) );
		$this->assertSame( '/', AssetsController::path( '' ) );
		$this->assertNull( AssetsController::path( 'https://otro.test/' ) );
		$this->assertNull( AssetsController::path( '//otro.test/' ) );
		$this->assertNull( AssetsController::path( '/wp-admin/options.php' ) );
		$this->assertNull( AssetsController::path( '/wp-login.php' ) );
		$this->assertNull( AssetsController::path( '/a b' ) );
	}

	public function test_suggestions(): void {
		$cf7    = [
			[
				'handle'    => 'contact-form-7',
				'type'      => 'js',
				'enqueued'  => true,
				'needed_by' => [],
				'templates' => [ 'portada' ],
			],
			[
				'handle'    => 'swv',
				'type'      => 'js',
				'enqueued'  => true,
				'needed_by' => [ 'contact-form-7' ],
				'templates' => [ 'portada' ],
			],
		];
		$woo    = [
			[
				'handle'    => 'wc-add-to-cart',
				'type'      => 'js',
				'enqueued'  => true,
				'needed_by' => [],
				'templates' => [ 'portada', 'single:product' ],
			],
			[
				'handle'    => 'wc-order-attribution',
				'type'      => 'js',
				'enqueued'  => true,
				'needed_by' => [],
				'templates' => [ 'portada' ],
			],
			[
				'handle'    => 'wc-single-product',
				'type'      => 'js',
				'enqueued'  => true,
				'needed_by' => [],
				'templates' => [ 'single:product' ],
			],
		];
		$active = [
			'contact-form-7' => 'Contact Form 7',
			'woocommerce'    => 'WooCommerce',
			'revslider'      => 'Slider Revolution',
		];
		$none   = [
			'global' => false,
			'ids'    => [],
		];
		$result = AssetSuggestions::build(
			[
				'contact-form-7' => $cf7,
				'woocommerce'    => $woo,
				'revslider'      => [ $cf7[0] ],
			],
			[
				'contact-form-7' => [
					'global' => false,
					'ids'    => [ 15 ],
				],
				'woocommerce'    => $none,
				'revslider'      => $none,
			],
			$active,
			[ 3, 4 ]
		);
		$handles = array_column( $result['rules'], 'handle' );
		$this->assertContains( 'contact-form-7', $handles );
		$this->assertNotContains( 'swv', $handles, 'otro asset lo necesita: quitarlo no sirve' );
		$this->assertContains( 'wc-add-to-cart', $handles );
		$this->assertNotContains( 'wc-single-product', $handles, 'solo se vio dentro de la tienda' );
		$this->assertNotContains( 'wc-order-attribution', $handles, 'registra la página de entrada: en todas' );
		$cf7Rule = $result['rules'][ array_search( 'contact-form-7', $handles, true ) ];
		$this->assertSame( [ 15 ], $cf7Rule['ids'] );
		$this->assertSame( 'only', $cf7Rule['mode'] );
		$wooRule = $result['rules'][ array_search( 'wc-add-to-cart', $handles, true ) ];
		$this->assertSame( [ 3, 4 ], $wooRule['ids'], 'carrito, checkout y cuenta' );
		$this->assertContains( 'single:product', $wooRule['templates'] );
		$this->assertSame( [ 'revslider' ], array_column( $result['unused'], 'plugin' ), 'sin uso en el contenido: revisar el plugin' );

		$global = AssetSuggestions::build( [ 'contact-form-7' => $cf7 ], [ 'contact-form-7' => [ 'global' => true, 'ids' => [ 1 ] ] ], $active, [] );
		$this->assertSame( [], $global['rules'], 'en un widget o plantilla: se usa en cualquier página' );

		$this->assertSame(
			[
				'global' => true,
				'ids'    => [ 4, 9 ],
			],
			AssetSuggestions::usage(
				[
					[
						'id'        => 9,
						'post_type' => 'page',
					],
					[
						'id'        => 4,
						'post_type' => 'post',
					],
					[
						'id'        => 30,
						'post_type' => 'wp_template_part',
					],
				],
				false
			)
		);
	}

	public function test_option_owner(): void {
		$plugins = [
			'wordfence'           => [
				'name'   => 'Wordfence',
				'active' => false,
			],
			'woocommerce'         => [
				'name'   => 'WooCommerce',
				'active' => true,
			],
			'woocommerce-payments' => [
				'name'   => 'Payments',
				'active' => false,
			],
			'seo'                 => [
				'name'   => 'Corto',
				'active' => false,
			],
		];
		$this->assertSame( 'inactive', DatabaseAudit::owner( 'wordfence_options', $plugins )['kind'] );
		$this->assertSame( 'wordfence', DatabaseAudit::owner( 'wordfence_options', $plugins )['plugin'] );
		$this->assertSame( 'active', DatabaseAudit::owner( 'woocommerce_payments_settings', $plugins )['kind'], 'coincide también con uno activo: no se toca' );
		$this->assertSame( 'core', DatabaseAudit::owner( 'cron', $plugins )['kind'] );
		$this->assertSame( 'core', DatabaseAudit::owner( 'widget_block', $plugins )['kind'] );
		$this->assertSame( 'core', DatabaseAudit::owner( 'wp_user_roles', $plugins )['kind'] );
		$this->assertSame( 'unknown', DatabaseAudit::owner( 'seo_titles', $plugins )['kind'], 'prefijos de menos de 4 letras no se atribuyen' );
		$this->assertSame( [ 'mi-plugin', 'mi_plugin' ], DatabaseAudit::prefixes( 'mi-plugin-pro' ) );
	}

	public function test_table_owner_and_retention(): void {
		$plugins = [
			'woocommerce'      => [
				'name'   => 'WooCommerce',
				'active' => true,
			],
			'simple-history'   => [
				'name'   => 'Simple History',
				'active' => false,
			],
			'jet-form-builder' => [
				'name'   => 'JetFormBuilder',
				'active' => true,
			],
		];
		$kind = static fn( string $t ): string => DatabaseAudit::tableOwner( $t, $plugins )['kind'] . ':' . DatabaseAudit::tableOwner( $t, $plugins )['plugin'];
		$this->assertSame( 'core:', $kind( 'postmeta' ) );
		$this->assertSame( 'core:', $kind( 'actionscheduler_logs' ) );
		$this->assertSame( 'removed:wordfence', $kind( 'wffilemods' ), 'tablas que dejó Wordfence al desinstalarse' );
		$this->assertSame( 'inactive:simple-history', $kind( 'simple_history_contexts' ) );
		$this->assertSame( 'active:woocommerce', $kind( 'wc_orders' ) );
		$this->assertSame( 'active:jet-form-builder', $kind( 'jet_form_builder_records' ), 'por la carpeta del plugin' );
		$this->assertSame( 'unknown:', $kind( 'tabla_rara' ) );

		$this->assertSame( 0, DatabaseModule::retentionDays(), 'sin configuración no se toca la retención' );
		$config          = static fn( int $days ): ?CompiledConfig => CompiledConfig::fromArray(
			[
				'v'        => CompiledConfig::FORMAT_VERSION,
				'hash'     => 'x',
				'database' => [ 'as_retention' => $days ],
			]
		);
		Runtime::$config = $config( 1 );
		$this->assertSame( 3, DatabaseModule::retentionDays(), 'mínimo 3 días' );
		$this->assertSame( 3 * DAY_IN_SECONDS, DatabaseModule::retention( 2678400 ) );
		Runtime::$config = $config( 0 );
		$this->assertSame( 2678400, DatabaseModule::retention( 2678400 ), 'el valor de Action Scheduler' );
	}
}
