<?php
/**
 * Elementos diferidos de Elementor y caché de fragmentos (0.17.0).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Modules\AssetManager\AssetInventory;
use ImaginaTurbo\Modules\AssetManager\AssetScanner;
use ImaginaTurbo\Modules\CacheCoordinator\AjaxFragments;
use ImaginaTurbo\Modules\CacheCoordinator\FragmentCache;
use ImaginaTurbo\Modules\CacheCoordinator\FragmentVerifier;
use ImaginaTurbo\Modules\FrontendHints\LazyElements;

/**
 * Marcado, sanitización, llaves y verificación.
 */
final class FragmentsLazyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		LazyElements::reset();
		FragmentCache::reset();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		Functions\when( 'wp_is_serving_rest_request' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * Elemento de Elementor de mentira: solo get_settings.
	 *
	 * @param array<string, string> $settings Ajustes.
	 */
	private static function element( array $settings ): object {
		return new class( $settings ) {
			/**
			 * @param array<string, string> $settings Ajustes.
			 */
			public function __construct( private array $settings ) {
			}

			public function get_settings( string $key ): mixed {
				return $this->settings[ $key ] ?? null;
			}
		};
	}

	public function test_mode_from_classes(): void {
		$this->assertSame(
			[
				'mode'   => 'v',
				'height' => 400,
			],
			LazyElements::modeFromClasses( 'foo itb-lazy bar' )
		);
		$this->assertSame(
			[
				'mode'   => 'i',
				'height' => 0,
			],
			LazyElements::modeFromClasses( 'itb-lazy-interaction' )
		);
		$this->assertSame(
			[
				'mode'   => 'v',
				'height' => 600,
			],
			LazyElements::modeFromClasses( 'itb-lazy itb-lazy-h600' )
		);
		$this->assertSame( 300, LazyElements::modeFromClasses( 'itb-lazy-interaction itb-lazy-h300' )['height'] ?? null );
		$this->assertNull( LazyElements::modeFromClasses( 'itb-lazyish' ) );
		$this->assertNull( LazyElements::modeFromClasses( 'itb-lazy-h600' ), 'la altura sola no difiere' );
		$this->assertNull( LazyElements::modeFromClasses( '' ) );
	}

	public function test_wraps_marked_element_once_without_nesting(): void {
		$outer = self::element( [ 'css_classes' => 'itb-lazy itb-lazy-h500' ] );
		$inner = self::element( [ '_css_classes' => 'itb-lazy-interaction' ] );
		$plain = self::element( [] );
		$this->expectOutputString( '<!--itb-lz:v:500--><!--/itb-lz-->' );
		LazyElements::beforeRender( $outer );
		LazyElements::beforeRender( $inner );
		LazyElements::afterRender( $inner );
		LazyElements::beforeRender( $plain );
		LazyElements::afterRender( $plain );
		LazyElements::afterRender( $outer );
		$this->assertFalse( LazyElements::used(), 'el script sale cuando los marcadores se convierten' );
	}

	public function test_not_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->expectOutputString( '' );
		$el = self::element( [ 'css_classes' => 'itb-lazy' ] );
		LazyElements::beforeRender( $el );
		LazyElements::afterRender( $el );
		$this->assertFalse( LazyElements::used() );
	}

	public function test_markers_convert_to_wrapper(): void {
		$html = '<div>a</div><!--itb-lz:v:500--><div class="e-con">b</div><!--/itb-lz--><!--itb-lz:i:0--><p>c</p><!--/itb-lz--><!--itb-lz:v:9-->sin cierre';
		$this->assertSame(
			'<div>a</div><div class="itb-lz" data-itb-lz="v" style="min-height:500px"><template><div class="e-con">b</div></template></div><div class="itb-lz" data-itb-lz="i"><template><p>c</p></template></div><!--itb-lz:v:9-->sin cierre',
			LazyElements::convertMarkers( $html )
		);
		$this->assertTrue( LazyElements::used() );
	}

	public function test_wrap_document(): void {
		$this->assertSame( '<div class="itb-lz" data-itb-lz="i"><template><p>x</p></template></div>', LazyElements::wrap( '<p>x</p>', 'i', 0 ) );
	}

	public function test_lazy_documents_sanitized(): void {
		$out = Settings::lazyDocuments(
			[
				[
					'id'     => 7039,
					'mode'   => 'i',
					'height' => 9000,
				],
				[
					'id'   => 7039,
					'mode' => 'v',
				],
				[
					'id'   => -3,
					'mode' => 'v',
				],
				[ 'id' => '12' ],
				[
					'id'   => 15,
					'mode' => 'otro',
				],
			]
		);
		$this->assertSame(
			[
				[
					'id'     => 7039,
					'mode'   => 'i',
					'height' => 3000,
				],
				[
					'id'     => 15,
					'mode'   => 'v',
					'height' => 0,
				],
			],
			$out
		);
	}

	public function test_inventory_where_and_stats(): void {
		$this->assertSame( 'menu', AssetInventory::where( [ 'document', 'apply_filters', 'woodmart_get_html_block', 'start_el', 'walk', 'wp_nav_menu', 'get_header' ] ) );
		$this->assertSame( 'footer', AssetInventory::where( [ 'do_action', 'get_footer' ] ) );
		$this->assertSame( 'header', AssetInventory::where( [ 'get_header' ] ) );
		$this->assertSame( 'content', AssetInventory::where( [ 'the_content' ] ) );
		$this->assertSame(
			[
				'bytes' => 51,
				'tags'  => 4,
				'imgs'  => 1,
			],
			AssetInventory::htmlStats( '<!-- c --><div><img src="a.webp"><br></div><p>x</p>' )
		);
	}

	public function test_lazy_suggestion(): void {
		$this->assertSame( 'i', AssetScanner::lazySuggestion( 'menu', 4867 ) );
		$this->assertSame( 'v', AssetScanner::lazySuggestion( 'footer', 300 ) );
		$this->assertSame( '', AssetScanner::lazySuggestion( 'header', 5000 ), 'la cabecera está a la vista' );
		$this->assertSame( '', AssetScanner::lazySuggestion( 'content', 100 ) );
		$this->assertSame( 'v', AssetScanner::lazySuggestion( 'content', 800 ) );
	}

	public function test_pack_roundtrip_and_fingerprint(): void {
		$html = str_repeat( '<li class="menu-item"><a href="/x">X</a></li>', 200 );
		$this->assertSame( $html, FragmentCache::unpack( FragmentCache::pack( $html ) ) );
		$this->assertLessThan( strlen( $html ) / 5, strlen( FragmentCache::pack( $html ) ) );
		$this->assertSame( 'abc', FragmentCache::unpack( 'rabc' ) );
		$this->assertNull( FragmentCache::unpack( 'xabc' ) );

		$args              = new \stdClass();
		$args->menu        = 'categories';
		$args->walker      = new \ArrayObject();
		$args->fallback_cb = static fn() => '';
		$args->items_wrap  = '<ul>%3$s</ul>';
		$args->depth       = 0;
		$print             = FragmentCache::fingerprint( $args );
		$this->assertSame( 'object:ArrayObject', $print['walker'] );
		$this->assertSame( 'closure', $print['fallback_cb'] );
		$this->assertSame( [ 'depth', 'fallback_cb', 'items_wrap', 'menu', 'walker' ], array_keys( $print ) );
	}

	public function test_key_uri_drops_campaign_and_verifier_params(): void {
		$this->assertSame( '/carrito/', FragmentCache::keyUri( '/carrito/?itb_frag=h&itb_nc=abc123', [] ) );
		$this->assertSame( '/tienda/?orderby=price', FragmentCache::keyUri( '/tienda/?utm_source=fb&orderby=price&fbclid=x', [ 'fbclid' ] ) );
		$this->assertSame( '/', FragmentCache::keyUri( '/?gclid=1&UTM_MEDIUM=x', [ 'gclid' ] ) );
		$this->assertSame( '/x', FragmentCache::keyUri( '/x', [] ) );
	}

	public function test_ajax_key_rules(): void {
		Functions\when( 'wp_cache_get' )->justReturn( 'g1' );
		$base = AjaxFragments::key(
			'woodmart_load_html_dropdowns',
			[
				'action' => 'woodmart_load_html_dropdowns',
				'ids'    => [ '7039', '7041' ],
			],
			[],
			[ 'wmc_current_currency' ]
		);
		$this->assertIsString( $base );
		$this->assertSame(
			$base,
			AjaxFragments::key(
				'woodmart_load_html_dropdowns',
				[
					'ids'    => [ '7039', '7041' ],
					'action' => 'woodmart_load_html_dropdowns',
				],
				[ '_ga' => 'x' ],
				[ 'wmc_current_currency' ]
			),
			'otras cookies no cambian la llave'
		);
		$this->assertNotSame(
			$base,
			AjaxFragments::key( 'woodmart_load_html_dropdowns', [ 'ids' => [ '7039', '7041' ] ], [ 'wmc_current_currency' => 'USD' ], [ 'wmc_current_currency' ] ),
			'la moneda sí'
		);
		$this->assertNull( AjaxFragments::key( 'woodmart_load_html_dropdowns', [ 'ids' => [ '1' ] ], [ 'wordpress_logged_in_abc' => 'u' ], [] ), 'con sesión no' );
		$this->assertNull(
			AjaxFragments::key(
				'woodmart_get_products_shortcode',
				[
					'atts'     => [ 'x' => 1 ],
					'security' => 'abc',
				],
				[],
				[]
			),
			'con nonce no'
		);
		$this->assertNull( AjaxFragments::key( 'woodmart_get_products_shortcode', [ 'atts' => [ '_wpnonce' => 'a' ] ], [], [] ) );
		$this->assertSame(
			[
				'a' => [
					'b' => 1,
					'c' => 2,
				],
				'z' => 0,
			],
			AjaxFragments::normalize(
				[
					'z' => 0,
					'a' => [
						'c' => 2,
						'b' => 1,
					],
				]
			)
		);
	}

	public function test_known_ajax_actions_are_render_only(): void {
		foreach ( AjaxFragments::KNOWN as $actions ) {
			foreach ( $actions as $action ) {
				$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $action );
				$this->assertDoesNotMatchRegularExpression( '/add|remove|update|save|cart|login|checkout/', $action );
			}
		}
	}

	public function test_verifier_strips_its_params(): void {
		$reply = FragmentVerifier::strip(
			[
				'status'  => 200,
				'headers' => [],
				'body'    => '<a href="/carrito/?itb_frag=h&amp;itb_nc=abc123xyz">x</a><link rel="canonical" href="/carrito/?itb_frag=b&itb_nc=q1">',
			]
		);
		$this->assertSame( '<a href="/carrito/">x</a><link rel="canonical" href="/carrito/">', $reply['body'] );
	}

	public function test_lazy_script_under_budget(): void {
		$src = (string) file_get_contents( self::pluginDir() . '/assets/js/itb-lazy.js' );
		$this->assertStringContainsString( 'runReadyTrigger', $src );
		$this->assertStringContainsString( 'importNode', $src );
		$this->assertStringContainsString( 'beforeprint', $src );
		$min = self::pluginDir() . '/assets/js/itb-lazy.min.js';
		if ( is_file( $min ) ) {
			$this->assertLessThan( 3072, filesize( $min ) );
		}
	}
}
