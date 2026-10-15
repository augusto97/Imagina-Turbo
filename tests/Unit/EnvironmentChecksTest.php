<?php
/**
 * 0.10.4: revisiones del entorno y dependencias de los scripts que no se difieren.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\EnvironmentChecks;
use ImaginaTurbo\Modules\CacheCoordinator\DelayJsExclusions;

final class EnvironmentChecksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, int $d = 0 ): string => number_format( (float) $n, $d ) );
		DelayJsExclusions::reset();
	}

	/**
	 * Estado de OPcache como el del sitio real antes y después del cambio.
	 *
	 * @return array<string, mixed>
	 */
	private static function opcache( int $usedMb, int $freeMb, float $hitRate, int $stringsFree = 4 ): array {
		return [
			'cache_full'             => 0 === $freeMb,
			'memory_usage'           => [
				'used_memory'   => $usedMb * 1048576,
				'free_memory'   => $freeMb * 1048576,
				'wasted_memory' => 0,
			],
			'interned_strings_usage' => [
				'buffer_size' => 64 * 1048576,
				'free_memory' => $stringsFree * 1048576,
			],
			'opcache_statistics'     => [
				'opcache_hit_rate' => $hitRate,
				'hits'             => 400000,
				'misses'           => 100000,
			],
		];
	}

	public function test_opcache_full_is_reported_with_numbers(): void {
		$full = EnvironmentChecks::opcache( self::opcache( 128, 0, 43.81 ) );
		$this->assertTrue( $full['problem'] );
		$this->assertSame( 128, $full['total_mb'] );
		$this->assertSame( 43.8, $full['hit_rate'] );

		$ok = EnvironmentChecks::opcache( self::opcache( 296, 216, 99.5, 13 ) );
		$this->assertFalse( $ok['problem'], '296 de 512 MB, cadenas con espacio, aciertos altos' );

		$this->assertTrue( EnvironmentChecks::opcache( self::opcache( 296, 216, 99.5, 0 ) )['problem'], 'cadenas internas agotadas' );
		$this->assertTrue( EnvironmentChecks::opcache( self::opcache( 296, 216, 80.0 ) )['problem'], 'aciertos bajos con muchas peticiones' );

		$fresh                                 = self::opcache( 100, 400, 50.0 );
		$fresh['opcache_statistics']['hits']   = 100;
		$fresh['opcache_statistics']['misses'] = 100;
		$this->assertFalse( EnvironmentChecks::opcache( $fresh )['problem'], 'recién reiniciado: pocas peticiones para juzgar' );

		$alerts = EnvironmentChecks::evaluate( self::opcache( 128, 0, 43.81 ), null, null, false );
		$this->assertSame( [ 'opcache_full' ], array_column( $alerts, 'code' ) );
		$this->assertStringContainsString( '128 de 128 MB', $alerts[0]['message'] );
	}

	public function test_webp_generated_but_not_served(): void {
		$none = [
			'webp'         => true,
			'webp_for_cdn' => false,
			'picture_webp' => false,
			'exactdn'      => false,
			'htaccess'     => false,
		];
		$this->assertSame( [ 'webp_not_served' ], array_column( EnvironmentChecks::evaluate( null, $none, null, true ), 'code' ) );
		$this->assertSame( [], EnvironmentChecks::evaluate( null, [ 'webp_for_cdn' => true ] + $none, null, true ), 'reescritura por JS' );
		$this->assertSame( [], EnvironmentChecks::evaluate( null, [ 'picture_webp' => true ] + $none, null, true ) );
		$this->assertSame( [], EnvironmentChecks::evaluate( null, [ 'exactdn' => true ] + $none, null, true ), 'Easy IO' );
		$this->assertSame( [], EnvironmentChecks::evaluate( null, [ 'webp' => false ] + $none, null, true ), 'sin WebP no hay nada que entregar' );

		$htaccess = [ 'htaccess' => true ] + $none;
		$this->assertSame( [], EnvironmentChecks::evaluate( null, $htaccess, null, false ), '.htaccess sin Cloudflare funciona' );
		$this->assertSame( [ 'webp_htaccess_cloudflare' ], array_column( EnvironmentChecks::evaluate( null, $htaccess, null, true ), 'code' ) );
	}

	public function test_litespeed_woo_interval(): void {
		$this->assertSame( 'lscache_woo_interval', EnvironmentChecks::litespeedWooInterval( 0 )['code'] ?? null );
		$this->assertNotNull( EnvironmentChecks::litespeedWooInterval( '3' ) );
		$this->assertNull( EnvironmentChecks::litespeedWooInterval( 1 ) );
		$this->assertNull( EnvironmentChecks::litespeedWooInterval( 2 ) );
	}

	public function test_mini_cart_block_alert(): void {
		$info = EnvironmentChecks::miniCartBlock( false );
		$this->assertSame( 'mini_cart_block', $info['code'] );
		$this->assertSame( 'info', $info['level'] );
		$this->assertStringNotContainsString( 'prerender', $info['message'] );
		$warn = EnvironmentChecks::miniCartBlock( true );
		$this->assertSame( 'warning', $warn['level'] );
		$this->assertStringContainsString( 'prerender', $warn['message'] );
	}

	public function test_two_lazy_loaders(): void {
		$ewww = [
			'webp'         => true,
			'webp_for_cdn' => true,
			'lazy_load'    => true,
		];
		$this->assertSame( [ 'double_lazy' ], array_column( EnvironmentChecks::evaluate( null, $ewww, [ 'lazy' => true ], false ), 'code' ) );
		$this->assertSame( [], EnvironmentChecks::evaluate( null, $ewww, [ 'lazy' => false ], false ) );
		$this->assertSame( [], EnvironmentChecks::evaluate( null, [ 'lazy_load' => false ] + $ewww, [ 'lazy' => true ], false ) );
	}

	public function test_login_page_cached_by_litespeed(): void {
		$this->assertSame( [ 'login_cached' ], array_column( EnvironmentChecks::evaluate( null, null, [ 'page_login' => true ], false ), 'code' ) );
		$this->assertSame( [], EnvironmentChecks::evaluate( null, null, [ 'page_login' => false ], false ) );
	}

	/**
	 * El caso real: api-fetch excluido y sus dependencias diferidas.
	 *
	 * @return array<string, array{src: string, deps: list<string>, inline: string}>
	 */
	private static function graph(): array {
		$node = static fn( string $src, array $deps = [], string $inline = '' ): array => [
			'src'    => $src,
			'deps'   => $deps,
			'inline' => $inline,
		];
		return [
			'wp-polyfill'     => $node( '/wp-includes/js/dist/vendor/wp-polyfill.min.js' ),
			'wp-hooks'        => $node( '/wp-includes/js/dist/hooks.min.js' ),
			'wp-i18n'         => $node( '/wp-includes/js/dist/i18n.min.js', [ 'wp-hooks' ] ),
			'wp-private-apis' => $node( '/wp-includes/js/dist/private-apis.min.js' ),
			'wp-url'          => $node( '/wp-includes/js/dist/url.min.js', [ 'wp-polyfill' ] ),
			'wp-api-fetch'    => $node( '/wp-includes/js/dist/api-fetch.min.js', [ 'wp-i18n', 'wp-url', 'wp-polyfill' ], 'wp.apiFetch.use( wp.apiFetch.createRootURLMiddleware( "/wp-json/" ) );' ),
			'wp-data'         => $node( '/wp-includes/js/dist/data.min.js', [ 'wp-private-apis', 'wp-polyfill' ], 'wp.data.use( wp.data.plugins.persistence, {} );' ),
			'stripe-express'  => $node( '/wp-content/plugins/woocommerce-gateway-stripe/build/express-checkout.js', [ 'wp-api-fetch', 'wp-data' ] ),
			'woodmart-theme'  => $node( '/wp-content/themes/woodmart/js/scripts/global/helpers.min.js', [ 'jquery' ] ),
			'jquery'          => $node( '', [ 'jquery-core' ] ),
			'jquery-core'     => $node( '/wp-includes/js/jquery/jquery.min.js' ),
			'not-printed'     => $node( '/wp-includes/js/dist/api-fetch.min.js?copy' ),
		];
	}

	public function test_dependencies_of_excluded_scripts_are_pinned(): void {
		$patterns = [ '/wp-includes/js/dist/api-fetch.min.js', '/wp-includes/js/dist/i18n.min.js', 'hooks.min.js' ];
		$pinned   = array_keys( DelayJsExclusions::pinnedHandles( self::graph(), [ 'stripe-express', 'woodmart-theme' ], $patterns ) );
		sort( $pinned );
		$this->assertSame( [ 'wp-api-fetch', 'wp-hooks', 'wp-i18n', 'wp-polyfill', 'wp-url' ], $pinned, 'api-fetch y todo lo que necesita; data, Stripe y el tema se siguen difiriendo' );

		$byInline = array_keys( DelayJsExclusions::pinnedHandles( self::graph(), [ 'stripe-express' ], [ 'wp\.data\.use' ] ) );
		sort( $byInline );
		$this->assertSame( [ 'wp-data', 'wp-polyfill', 'wp-private-apis' ], $byInline, 'el código en línea también cuenta' );

		$this->assertSame( [], DelayJsExclusions::pinnedHandles( self::graph(), [ 'woodmart-theme' ], $patterns ), 'solo lo que se imprime' );
		$this->assertSame( [], DelayJsExclusions::pinnedHandles( self::graph(), [ 'stripe-express' ], [] ) );

		DelayJsExclusions::reset();
		$broken = array_keys( DelayJsExclusions::pinnedHandles( self::graph(), [ 'stripe-express' ], [ '(sin cerrar', 'url.min.js' ] ) );
		sort( $broken );
		$this->assertSame( [ 'wp-polyfill', 'wp-url' ], $broken, 'un patrón inválido no anula los demás' );
	}

	public function test_native_defer_scripts_pin_their_dependencies_under_delay(): void {
		$graph = [
			'jquery'                   => [
				'src'    => '/wp-includes/js/jquery/jquery.min.js',
				'deps'   => [],
				'inline' => '',
			],
			'underscore'               => [
				'src'    => '/wp-includes/js/underscore.min.js',
				'deps'   => [],
				'inline' => '',
			],
			'wp-util'                  => [
				'src'    => '/wp-includes/js/wp-util.min.js',
				'deps'   => [ 'underscore', 'jquery' ],
				'inline' => '',
			],
			'wc-add-to-cart-variation' => [
				'src'      => '/woocommerce/assets/js/frontend/add-to-cart-variation.min.js',
				'deps'     => [ 'jquery', 'wp-util' ],
				'inline'   => '',
				'strategy' => 'defer',
			],
			'woodmart-theme'           => [
				'src'    => '/woodmart/js/scripts/global/helpers.min.js',
				'deps'   => [ 'jquery' ],
				'inline' => '',
			],
		];
		$this->assertSame( [], DelayJsExclusions::pinnedHandles( $graph, [ 'wc-add-to-cart-variation', 'woodmart-theme' ], [] ), 'sin Delayed, el defer nativo no importa' );
		$pinned = array_keys( DelayJsExclusions::pinnedHandles( $graph, [ 'wc-add-to-cart-variation', 'woodmart-theme' ], [], true ) );
		sort( $pinned );
		$this->assertSame( [ 'jquery', 'underscore', 'wc-add-to-cart-variation', 'wp-util' ], $pinned, 'LSCache no retrasa el script con defer: sus dependencias tampoco; el tema sí se retrasa' );

		$own = [
			'itb-cart' => [
				'src'    => '/wp-content/plugins/imagina-turbo/assets/js/itb-cart.min.js',
				'deps'   => [],
				'inline' => '',
			],
		];
		$this->assertSame( [ 'itb-cart' => true ], DelayJsExclusions::pinnedHandles( $own, [ 'itb-cart' ], [ DelayJsExclusions::OWN ] ), 'el contador del carrito no espera a la interacción' );
	}

	public function test_mark_tag_adds_attributes_once(): void {
		$tag = "<script id=\"wp-api-fetch-js\" src=\"/api-fetch.min.js\"></script>\n<script id=\"wp-api-fetch-js-after\">\nwp.apiFetch.use();\n</script>\n";
		$out = DelayJsExclusions::markTag( $tag );
		$this->assertSame( 2, substr_count( $out, 'data-no-defer="1" nowprocket' ), 'el externo y su código en línea' );
		$this->assertSame( $out, DelayJsExclusions::markTag( $out ), 'no se duplica' );
		$this->assertSame( '<scripts>', DelayJsExclusions::markTag( '<scripts>' ) );
		$this->assertStringNotContainsString( 'data-no-optimize', $out, 'sin Delayed, LSCache los sigue minificando' );

		$delayed = DelayJsExclusions::markTag( $tag, true );
		$this->assertSame( 2, substr_count( $delayed, 'data-no-defer="1" nowprocket data-no-optimize="1"' ), 'con Delayed, LSCache no los toca: no reescribe DOMContentLoaded' );
		$this->assertSame( $delayed, DelayJsExclusions::markTag( $delayed, true ) );
	}
}
