<?php
/**
 * 0.25.4: el conector propone reglas de assets en prueba, con el asset comprobado en la página.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use ImaginaTurbo\Modules\AssetManager\AssetRules;
use ImaginaTurbo\Modules\AssetManager\AssetScanner;
use ImaginaTurbo\Modules\Connector\Connector;
use ImaginaTurbo\Modules\Connector\Tools;

final class ConnectorAssetsTest extends TestCase {

	private const WOO_PAGES = [
		'cart'     => 7,
		'checkout' => 8,
	];

	/**
	 * Inventario de la portada como lo devuelve /assets/inspect.
	 *
	 * @return array<string, mixed>
	 */
	private static function inventory(): array {
		$asset = static fn( string $handle, bool $enqueued = true, array $neededBy = [] ): array => [
			'handle'    => $handle,
			'src'       => 'https://tienda.example/wp-content/plugins/x/' . $handle . '.css',
			'owner'     => 'x',
			'origin'    => 'plugin',
			'size'      => 4096,
			'blocking'  => true,
			'enqueued'  => $enqueued,
			'needed_by' => $neededBy,
			'rules'     => [],
		];
		return [
			'path'    => '/',
			'status'  => 200,
			'context' => [
				'template'  => 'portada',
				'id'        => 0,
				'post_type' => '',
			],
			'css'     => [
				$asset( 'woocommerce_bold_gateway_form_css' ),
				$asset( 'woocommerce-general' ),
				$asset( 'slider-base', false, [ 'slider-theme' ] ),
				$asset( 'wp-block-library' ),
			],
			'js'      => [
				$asset( 'contact-form-7' ),
				$asset( 'jquery-core' ),
			],
		];
	}

	/**
	 * @param array<string, mixed> $over Cambios.
	 * @return array{id: string, handle: string, type: string, mode: string, templates: list<string>, ids: list<int>, post_types: list<string>, status: string, note: string, verified_by: int, verified_at: int, created_at: int}
	 */
	private static function rule( array $over ): array {
		$rule = AssetRules::normalize( $over + [ 'status' => 'testing' ] );
		self::assertNotNull( $rule );
		return $rule;
	}

	public function test_locked_assets(): void {
		$this->assertTrue( Tools::wooAsset( 'woocommerce-general' ) );
		$this->assertTrue( Tools::wooAsset( 'wc-checkout' ) );
		$this->assertTrue( Tools::wooAsset( 'woocommerce' ) );
		$this->assertFalse( Tools::wooAsset( 'woocommerce_bold_gateway_form_css' ), 'otro plugin con el nombre de WooCommerce delante' );
		foreach ( [ 'jquery', 'jquery-core', 'jquery-migrate', 'jquery-ui-core', 'wp-block-library', 'wp-polyfill', 'global-styles', 'admin-bar', 'itb-delay', 'wc-order-attribution', 'sourcebuster-js', 'selectWoo' ] as $handle ) {
			$this->assertTrue( Tools::assetLocked( $handle ), $handle );
		}
		foreach ( [ 'woocommerce_bold_gateway_form_css', 'contact-form-7', 'woocommerce-general', 'wc-blocks-style', 'google-fonts' ] as $handle ) {
			$this->assertFalse( Tools::assetLocked( $handle ), $handle );
		}
	}

	public function test_bold_gateway_css_only_on_checkout(): void {
		// El caso real: la hoja de Bold se carga en toda la tienda; se deja solo en la página de pago.
		$rule  = self::rule(
			[
				'handle' => 'woocommerce_bold_gateway_form_css',
				'type'   => 'css',
				'mode'   => 'only',
				'ids'    => [ 8 ],
			]
		);
		$check = Tools::assetProposal( $rule, self::inventory(), self::WOO_PAGES );
		$this->assertSame( '', $check['error'] );
		$this->assertTrue( $check['removes_here'], 'en la portada se quita' );
		$this->assertCount( 1, $check['warnings'], 'avisa del carrito; en el pago se queda' );
		$this->assertStringContainsString( 'el carrito', $check['warnings'][0] );

		$everywhere = self::rule(
			[
				'handle'     => 'woocommerce_bold_gateway_form_css',
				'type'       => 'css',
				'mode'       => 'never',
				'post_types' => [ 'page', 'product', 'post' ],
			]
		);
		$check      = Tools::assetProposal( $everywhere, self::inventory(), self::WOO_PAGES );
		$this->assertSame( '', $check['error'], 'no es de WooCommerce: se avisa, no se bloquea' );
		$this->assertCount( 2, $check['warnings'] );
		$this->assertStringContainsString( 'el pago', $check['warnings'][1] );
	}

	public function test_woocommerce_assets_are_kept_on_cart_and_checkout(): void {
		$bad = Tools::assetProposal( self::rule( [ 'handle' => 'woocommerce-general', 'type' => 'css', 'mode' => 'never', 'post_types' => [ 'page' ] ] ), self::inventory(), self::WOO_PAGES );
		$this->assertStringContainsString( 'en el carrito', $bad['error'] );

		$shopOnly = self::rule(
			[
				'handle'    => 'woocommerce-general',
				'type'      => 'css',
				'mode'      => 'only',
				'templates' => [ 'archivo:product', 'single:product', 'tax:product_cat' ],
				'ids'       => [ 7, 8 ],
			]
		);
		$this->assertSame( '', Tools::assetProposal( $shopOnly, self::inventory(), self::WOO_PAGES )['error'], 'solo en la tienda, el carrito y el pago' );
		$this->assertSame( '', Tools::assetProposal( self::rule( [ 'handle' => 'woocommerce-general', 'type' => 'css', 'mode' => 'never', 'post_types' => [ 'page' ] ] ), self::inventory(), [] )['error'], 'sin WooCommerce no hay carrito ni pago' );
	}

	public function test_the_asset_must_be_on_the_page_and_removable(): void {
		$missing = Tools::assetProposal( self::rule( [ 'handle' => 'gtag', 'type' => 'js', 'mode' => 'never', 'templates' => [ 'portada' ] ] ), self::inventory(), self::WOO_PAGES );
		$this->assertStringContainsString( 'no se carga', $missing['error'] );
		$wrongType = Tools::assetProposal( self::rule( [ 'handle' => 'contact-form-7', 'type' => 'css', 'mode' => 'never', 'templates' => [ 'portada' ] ] ), self::inventory(), self::WOO_PAGES );
		$this->assertStringContainsString( 'no se carga el css', $wrongType['error'], 'el tipo también cuenta' );
		$dependency = Tools::assetProposal( self::rule( [ 'handle' => 'slider-base', 'type' => 'css', 'mode' => 'never', 'templates' => [ 'portada' ] ] ), self::inventory(), self::WOO_PAGES );
		$this->assertStringContainsString( 'slider-theme', $dependency['error'], 'otro asset lo necesita: no tendría efecto' );
		$core = Tools::assetProposal( self::rule( [ 'handle' => 'jquery-core', 'type' => 'js', 'mode' => 'never', 'templates' => [ 'portada' ] ] ), self::inventory(), self::WOO_PAGES );
		$this->assertStringContainsString( 'núcleo de WordPress', $core['error'] );
		$this->assertStringContainsString( 'núcleo de WordPress', Tools::assetProposal( self::rule( [ 'handle' => 'wp-block-library', 'type' => 'css', 'mode' => 'never', 'templates' => [ 'portada' ] ] ), self::inventory(), self::WOO_PAGES )['error'] );

		$cf7 = Tools::assetProposal( self::rule( [ 'handle' => 'contact-form-7', 'type' => 'js', 'mode' => 'only', 'ids' => [ 42 ] ] ), self::inventory(), self::WOO_PAGES );
		$this->assertSame( '', $cf7['error'] );
		$this->assertTrue( $cf7['removes_here'] );
		$notHere = Tools::assetProposal( self::rule( [ 'handle' => 'contact-form-7', 'type' => 'js', 'mode' => 'never', 'templates' => [ 'single:post' ] ] ), self::inventory(), self::WOO_PAGES );
		$this->assertFalse( $notHere['removes_here'], 'la regla no aplica en la página revisada: se le dice a Claude' );
		$this->assertNull( AssetRules::normalize( [ 'handle' => 'contact-form-7', 'type' => 'js', 'mode' => 'never' ] ), 'sin ningún lugar la regla no es válida' );
	}

	public function test_inventory_summary_and_blocking(): void {
		$summary = Tools::assetSummary( self::inventory(), self::WOO_PAGES );
		$css     = array_column( $summary['css'], null, 'handle' );
		$this->assertTrue( $css['wp-block-library']['locked'] );
		$this->assertFalse( $css['woocommerce_bold_gateway_form_css']['locked'] );
		$this->assertTrue( $css['woocommerce_bold_gateway_form_css']['blocking'] );
		$this->assertSame( [ 'slider-theme' ], $css['slider-base']['needed_by'] );
		$this->assertSame( self::WOO_PAGES, $summary['woo_pages'] );

		$this->assertTrue( AssetScanner::blocking( 'css', [ 'media' => 'all' ] ) );
		$this->assertTrue( AssetScanner::blocking( 'css', [] ) );
		$this->assertFalse( AssetScanner::blocking( 'css', [ 'media' => 'print' ] ) );
		$this->assertTrue( AssetScanner::blocking( 'js', [ 'footer' => false ] ), 'en el head sin defer' );
		$this->assertFalse( AssetScanner::blocking( 'js', [ 'footer' => true ] ) );
		$this->assertFalse( AssetScanner::blocking( 'js', [ 'footer' => false, 'strategy' => 'defer' ] ) );
		$this->assertFalse( AssetScanner::blocking( 'js', [ 'footer' => false, 'strategy' => 'async' ] ) );
	}

	public function test_tools_and_scopes(): void {
		$read   = array_column( Tools::definitions( false ), 'scope', 'name' );
		$adjust = array_column( Tools::definitions( true ), 'scope', 'name' );
		$this->assertArrayHasKey( 'inspect_assets', $read, 'revisar assets es de lectura' );
		$this->assertArrayNotHasKey( 'propose_asset_rule', $read );
		$this->assertSame( Connector::SCOPE_ADJUST, $adjust['propose_asset_rule'] );
		$this->assertSame( Connector::SCOPE_ADJUST, $adjust['withdraw_asset_rule'] );
		$this->assertArrayNotHasKey( 'activate_asset_rule', $adjust, 'activar es solo del dueño' );
		$this->assertArrayNotHasKey( 'scripts', Tools::WRITE_AREAS, 'la carga de scripts sigue siendo del dueño' );
		$this->assertArrayNotHasKey( 'assets', Tools::WRITE_AREAS );
	}
}
