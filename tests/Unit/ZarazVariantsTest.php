<?php
/**
 * Zaraz, etiquetas de analítica y variantes por cookie en el edge (0.18.0).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Cloudflare\Client;
use ImaginaTurbo\Cloudflare\Phases;
use ImaginaTurbo\Cloudflare\PlanLimits;
use ImaginaTurbo\Cloudflare\SiteContext;
use ImaginaTurbo\Cloudflare\Templates;
use ImaginaTurbo\Cloudflare\Zaraz;
use ImaginaTurbo\Integrations\AnalyticsTags;

/**
 * Configuración de Zaraz (pura y por API), detección y reglas de variantes.
 */
final class ZarazVariantsTest extends TestCase {

	/** @var list<array{method: string, url: string, body: mixed}> */
	private array $requests = [];

	/** @var list<array{0: int, 1: array<string, mixed>}> */
	private array $responses = [];

	protected function setUp(): void {
		parent::setUp();
		$this->requests  = [];
		$this->responses = [];
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ): array {
				$this->requests[] = [
					'method' => $args['method'],
					'url'    => $url,
					'body'   => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
				];
				$next = array_shift( $this->responses ) ?? [
					200,
					[
						'success' => true,
						'errors'  => [],
						'result'  => [],
					],
				];
				return [
					'code'    => $next[0],
					'body'    => json_encode( $next[1] ),
					'headers' => [],
				];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $r ): int => $r['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $r ): string => $r['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
	}

	private function zaraz(): Zaraz {
		return new Zaraz( new Client( static fn(): string => 'cfut_PRUEBA0123456789abcdefghijklmnopqrstuvwxyz', null, static function (): void {} ), 'z1' );
	}

	/**
	 * @param mixed $result Resultado.
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private static function ok( mixed $result ): array {
		return [
			200,
			[
				'success' => true,
				'errors'  => [],
				'result'  => $result,
			],
		];
	}

	public function test_tools_follow_documented_shape(): void {
		$ga = Zaraz::ga4Tool( 'G-ABC123XYZ' );
		$this->assertSame( 'google-analytics-4', $ga['component'] );
		$this->assertSame( 'component', $ga['type'] );
		$this->assertSame(
			[
				'tid'       => 'G-ABC123XYZ',
				'ecommerce' => true,
			],
			$ga['settings']
		);
		$this->assertSame( [ 'Pageview' ], $ga['actions']['itbpv']['firingTriggers'] );
		$fb = Zaraz::metaTool( '1234567890', 'EAAtoken' );
		$this->assertSame( 'facebook-pixel', $fb['component'] );
		$this->assertSame( '1234567890', $fb['settings']['property'] );
		$this->assertSame( 'EAAtoken', $fb['settings']['accessToken'] );
		$this->assertSame( 'PageView', $fb['actions']['itbpv']['data']['ev'] );
		$this->assertContains( 'server_network_requests', $fb['permissions'] );
		foreach ( [ 'enabled', 'blockingTriggers', 'name', 'defaultFields', 'component', 'settings', 'permissions', 'type' ] as $required ) {
			$this->assertArrayHasKey( $required, $ga );
			$this->assertArrayHasKey( $required, $fb );
		}
	}

	public function test_merge_and_strip_only_touch_own_tools(): void {
		$config = [
			'tools'    => [
				'abcd' => [
					'component' => 'tiktok',
					'enabled'   => true,
				],
			],
			'settings' => [
				'autoInjectScript' => true,
				'ecommerce'        => false,
			],
			'triggers' => [ 'Pageview' => [ 'name' => 'Pageview' ] ],
			'debugKey' => 'k',
		];
		$merged = Zaraz::merge( $config, [ Zaraz::TOOL_GA4 => Zaraz::ga4Tool( 'G-ABC123XYZ' ) ] );
		$this->assertArrayHasKey( 'abcd', $merged['tools'] );
		$this->assertArrayHasKey( Zaraz::TOOL_GA4, $merged['tools'] );
		$this->assertTrue( $merged['settings']['ecommerce'] );
		$this->assertTrue( $merged['settings']['autoInjectScript'] );
		foreach ( [ 'tools', 'triggers', 'variables', 'settings', 'dataLayer', 'debugKey', 'zarazVersion' ] as $required ) {
			$this->assertArrayHasKey( $required, $merged, 'el PUT exige la configuración completa' );
		}
		$this->assertSame( 'k', $merged['debugKey'] );

		$stripped = Zaraz::strip( $merged, false );
		$this->assertSame( [ 'abcd' ], array_keys( $stripped['tools'] ) );
		$this->assertFalse( $stripped['settings']['ecommerce'], 'e-commerce vuelve a como estaba' );

		$summary = Zaraz::summary( $merged );
		$this->assertTrue( $summary['ecommerce'] );
		$this->assertSame( 'G-ABC123XYZ', $summary['tools'][1]['id_value'] );
		$this->assertTrue( $summary['tools'][1]['own'] );
		$this->assertSame( Zaraz::TOOL_GA4, Zaraz::findTool( $merged, 'google-analytics', 'tid', 'g-abc123xyz' ) );
		$this->assertNull( Zaraz::findTool( $merged, 'google-analytics', 'tid', 'G-OTRO000' ) );
		$this->assertTrue( Zaraz::hasSecrets( [ 'variables' => [ 'v' => [ 'type' => 'secret' ] ] ] ) );
		$this->assertFalse( Zaraz::hasSecrets( [ 'variables' => [ 'v' => [ 'type' => 'string' ] ] ] ) );
	}

	public function test_write_publishes_only_in_preview(): void {
		$this->zaraz()->write( Zaraz::fillRequired( [] ), 'realtime' );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'PUT', $this->requests[0]['method'] );
		$this->assertStringEndsWith( '/zones/z1/settings/zaraz/config', $this->requests[0]['url'] );

		$this->requests  = [];
		$this->responses = [ self::ok( [] ), [ 403, [ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Authentication error' ] ], 'result' => null ] ] ];
		$result          = $this->zaraz()->write( Zaraz::fillRequired( [] ), 'preview' );
		$this->assertSame( 'POST', $this->requests[1]['method'] );
		$this->assertStringEndsWith( '/zaraz/publish', $this->requests[1]['url'] );
		$this->assertTrue( $result['ok'], 'la configuración quedó escrita' );
		$this->assertFalse( $result['published'], 'y avisa que falta publicar' );
	}

	public function test_secrets_use_export_and_block_unpublished_preview(): void {
		$zaraz = $this->zaraz();
		$read  = [
			'ok'       => true,
			'error'    => '',
			'config'   => [ 'tools' => [] ],
			'workflow' => 'realtime',
			'secrets'  => false,
		];
		$this->assertSame( [ 'tools' => [] ], $zaraz->baseConfig( $read ) );

		$this->responses = [ self::ok( [ 'tools' => [ 'x' => [] ] ] ) ];
		$this->assertSame( [ 'tools' => [ 'x' => [] ] ], $zaraz->baseConfig( [ 'secrets' => true ] + $read ) );
		$this->assertStringEndsWith( '/zaraz/export', $this->requests[0]['url'], 'con secretos, la exportada (los incluye)' );

		$this->assertNull( $zaraz->baseConfig( [ 'secrets' => true, 'workflow' => 'preview' ] + $read ), 'secretos y borrador: no se toca' );
	}

	public function test_read_reports_permission(): void {
		$this->responses = [ [ 403, [ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'no' ] ], 'result' => null ] ] ];
		$read            = $this->zaraz()->read();
		$this->assertFalse( $read['ok'] );
		$this->assertStringContainsString( 'Zaraz', $read['error'] );

		$this->responses = [ self::ok( [ 'variables' => [ 'a' => [ 'type' => 'secret' ] ] ] ), self::ok( 'preview' ) ];
		$read            = $this->zaraz()->read();
		$this->assertTrue( $read['ok'] );
		$this->assertSame( 'preview', $read['workflow'] );
		$this->assertTrue( $read['secrets'] );
	}

	public function test_scan_html_finds_tags(): void {
		$html = '<script src="https://www.googletagmanager.com/gtag/js?id=GT-NFB2BK4Z"></script><script>gtag("config","G-ABCDEF1234");'
			. "(function(w,d,s,l,i){})(window,document,'script','dataLayer','GTM-MC3J7B2G')</script>"
			. "<script>fbq('init', '987654321012345');</script>";
		$ids  = AnalyticsTags::scanHtml( $html );
		$this->assertSame( [ 'G-ABCDEF1234' ], $ids['ga4'] );
		$this->assertSame( [ 'GT-NFB2BK4Z' ], $ids['google_tag'] );
		$this->assertSame( [ 'GTM-MC3J7B2G' ], $ids['gtm'] );
		$this->assertSame( [ '987654321012345' ], $ids['meta'] );
		$this->assertTrue( AnalyticsTags::isGa4( 'G-ABC1234' ) );
		$this->assertFalse( AnalyticsTags::isGa4( 'UA-1234-1' ) );
		$this->assertTrue( AnalyticsTags::isPixel( '1234567890' ) );
		$this->assertFalse( AnalyticsTags::isPixel( '12ab' ) );
	}

	private static function variantSite( bool $variants ): SiteContext {
		return new SiteContext(
			'tienda.example.com',
			'',
			'wp-json',
			[ 'cart' => [ '/carrito' ] ],
			$variants ? [ 'wordpress_logged_in_' ] : [ 'wordpress_logged_in_', 'wcml_client_currency', 'cookie_notice_accepted' ],
			[],
			[],
			'itb-k7-',
			true,
			86400,
			false,
			[],
			false,
			[ 'wcml_client_currency', 'cookie_notice_accepted' ],
			$variants
		);
	}

	public function test_variant_rule_and_vary_settings(): void {
		$limits = PlanLimits::fromFile( 'free', self::pluginDir() . '/data/cloudflare-limits.json' );
		$off    = new Templates( self::variantSite( false ), $limits );
		$this->assertNotContains( 'itb-k7-variant', array_map( static fn( array $r ): string => $r['rule']->ref, $off->rules() ) );
		$this->assertArrayNotHasKey( 'vary', $off->htmlParameters() );
		$this->assertStringContainsString( 'not http.cookie contains "wcml_client_currency"', $off->htmlExpression() );

		$on    = new Templates( self::variantSite( true ), $limits );
		$rules = [];
		foreach ( $on->rules() as $item ) {
			$rules[ $item['rule']->ref ] = $item;
		}
		$this->assertArrayHasKey( 'itb-k7-variant', $rules );
		$rule = $rules['itb-k7-variant']['rule'];
		$this->assertSame( Phases::HEADERS, $rule->phase );
		$this->assertSame( 'rewrite', $rule->action );
		$header = $rule->parameters['headers'][ Templates::VARIANT_HEADER ];
		$this->assertSame( 'set', $header['operation'], 'siempre se escribe: una visita no puede elegir la variante' );
		$this->assertSame( 'concat("v1", "|", coalesce(http.request.cookies["wcml_client_currency"][0], ""), "|", coalesce(http.request.cookies["cookie_notice_accepted"][0], ""))', $header['expression'] );

		$params = $on->htmlParameters();
		$this->assertSame( 'bypass_by_default', $params['edge_ttl']['mode'], 'sin la cabecera del sitio no se guarda nada' );
		$this->assertSame( 'bypass', $params['vary']['default']['action'] );
		$this->assertSame( 'passthrough', $params['vary']['headers']['x-itb-variant']['action'] );
		$this->assertSame( 'normalize', $params['vary']['headers']['accept-encoding']['action'] );
		$this->assertStringNotContainsString( 'wcml_client_currency', $on->htmlExpression(), 'la moneda ya no impide la caché' );
		$this->assertStringContainsString( 'wordpress_logged_in_', $on->htmlExpression() );
		$this->assertSame( 10, $limits->rulesFor( Phases::HEADERS ), 'comparte el cupo de Transform Rules' );
		$this->assertContains( Phases::HEADERS, Phases::MANAGED, 'entra en los snapshots' );
	}

	public function test_zaraz_script_under_budget(): void {
		$src = (string) file_get_contents( self::pluginDir() . '/assets/js/itb-zaraz.js' );
		$this->assertStringContainsString( 'zaraz.ecommerce', $src );
		$this->assertStringContainsString( 'added_to_cart', $src );
		$this->assertStringContainsString( 'Order Completed', $src );
		$min = self::pluginDir() . '/assets/js/itb-zaraz.min.js';
		if ( is_file( $min ) ) {
			$this->assertLessThan( 3072, filesize( $min ) );
		}
	}
}
