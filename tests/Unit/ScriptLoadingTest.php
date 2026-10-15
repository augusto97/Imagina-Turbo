<?php
/**
 * Carga de scripts (0.14.0), Clear-Site-Data y desglose del TTFB en el RUM.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\Rum\RumCollector;
use ImaginaTurbo\Modules\Rum\RumReport;
use ImaginaTurbo\Modules\ScriptLoading\ScriptLoadingModule;
use ImaginaTurbo\Modules\Speculation\ClearSpeculations;

final class ScriptLoadingTest extends TestCase {

	private const NOW = 1792040000;

	protected function setUp(): void {
		parent::setUp();
		ScriptLoadingModule::reset();
		Runtime::$config = CompiledConfig::fromArray(
			[
				'v'       => CompiledConfig::FORMAT_VERSION,
				'hash'    => 'x',
				'scripts' => [
					'defer'         => true,
					'defer_exclude' => [ 'theme-inline-dep' ],
					'delay'         => [ 'googletagmanager.com', 'fbq(' ],
					'delay_timeout' => 5,
				],
			]
		);
	}

	/**
	 * @param list<string> $deps Dependencias.
	 * @return array{src: string, deps: list<string>, inline: string, strategy: string}
	 */
	private static function node( string $src, array $deps = [], string $strategy = '' ): array {
		return [
			'src'      => $src,
			'deps'     => $deps,
			'inline'   => '',
			'strategy' => $strategy,
		];
	}

	public function test_settings_from_compiled_config(): void {
		$this->assertSame(
			[
				'defer'         => true,
				'defer_exclude' => [ 'theme-inline-dep' ],
				'delay'         => [ 'googletagmanager.com', 'fbq(' ],
				'delay_timeout' => 5,
			],
			ScriptLoadingModule::settings()
		);
		Runtime::$config = null;
		$this->assertFalse( ScriptLoadingModule::settings()['defer'], 'apagado sin configuración' );
	}

	public function test_defer_candidates_skip_jquery_own_strategy_aliases_and_pinned(): void {
		$graph = [
			'jquery'          => self::node( '', [ 'jquery-core', 'jquery-migrate' ] ),
			'jquery-core'     => self::node( '/wp-includes/js/jquery/jquery.min.js' ),
			'jquery-migrate'  => self::node( '/wp-includes/js/jquery/jquery-migrate.min.js' ),
			'slick'           => self::node( '/t/slick.js', [ 'jquery' ] ),
			'theme'           => self::node( '/t/theme.js', [ 'slick', 'wp-util' ] ),
			'wp-util'         => self::node( '/wp-includes/js/wp-util.min.js' ),
			'variation'       => self::node( '/wc/add-to-cart-variation.js', [ 'jquery' ], 'defer' ),
			'stripe'          => self::node( 'https://js.stripe.com/v3/', [ 'api-fetch' ] ),
			'api-fetch'       => self::node( '/wp-includes/js/dist/api-fetch.min.js' ),
			'wc-cart-fragments' => self::node( '/wp-content/plugins/imagina-turbo/assets/js/itb-cart.min.js' ),
			'theme-inline-dep' => self::node( '/t/x.js' ),
			'not-printed'     => self::node( '/t/never.js' ),
		];
		$queue  = [ 'theme', 'variation', 'stripe', 'wc-cart-fragments', 'theme-inline-dep' ];
		$pinned = [
			'stripe'    => true,
			'api-fetch' => true,
		];
		$this->assertSame(
			[ 'slick', 'theme', 'wp-util' ],
			ScriptLoadingModule::deferCandidates( $graph, $queue, array_merge( ScriptLoadingModule::KEEP, [ 'theme-inline-dep' ] ), $pinned )
		);
	}

	public function test_defer_only_where_core_will_defer(): void {
		$after = self::node( '/t/after.js' );
		$after['after'] = true;
		$graph = [
			'with-after' => $after,
			'lib'        => self::node( '/t/lib.js' ),
			'blocking'   => self::node( '/t/blocking.js', [ 'lib' ] ),
			'base'       => self::node( '/t/base.js' ),
			'native'     => self::node( '/t/native.js', [ 'base' ], 'defer' ),
			'core'       => self::node( '/t/core.js' ),
			'alias'      => self::node( '', [ 'core' ] ),
			'user'       => self::node( '/t/user.js', [ 'alias' ] ),
		];
		$this->assertSame(
			[ 'base', 'core', 'user' ],
			ScriptLoadingModule::deferCandidates( $graph, [ 'with-after', 'blocking', 'native', 'user' ], [ 'blocking' ], [] ),
			'con código "after" no; con un dependiente que bloquea no (WordPress lo movería al pie); a través de un alias sí'
		);
	}

	public function test_rewrite_tag_makes_external_scripts_inert(): void {
		$tag = "<script type=\"text/javascript\" async src=\"https://www.googletagmanager.com/gtag/js?id=G-1\" id=\"google_gtagjs-js\"></script>\n";
		$out = ScriptLoadingModule::rewriteTag( $tag );
		$this->assertStringContainsString( '<script type="text/plain" data-itb-delay async data-itb-src="https://www.googletagmanager.com/gtag/js?id=G-1" id="google_gtagjs-js">', $out );
		$this->assertStringNotContainsString( ' src=', $out );
		// Módulos: conservan el tipo para el cargador.
		$this->assertStringContainsString( 'data-itb-type="module"', ScriptLoadingModule::rewriteTag( '<script type="module" src="/a.js"></script>' ) );
		// Las etiquetas en línea ya marcadas o sin src no se tocan.
		$inline = '<script type="text/plain" data-itb-delay id="x-js-after">gtag("js")</script>';
		$this->assertSame( $inline, ScriptLoadingModule::rewriteTag( $inline ) );
		$this->assertSame( '<script>var a=1</script>', ScriptLoadingModule::rewriteTag( '<script>var a=1</script>' ) );
	}

	public function test_inline_and_attribute_filters(): void {
		$this->assertSame(
			[
				'id'             => 'meta-pixel',
				'type'           => 'text/plain',
				'data-itb-delay' => true,
			],
			ScriptLoadingModule::delayInline( [ 'id' => 'meta-pixel' ], "fbq('init','1')" )
		);
		$this->assertSame( [ 'id' => 'other' ], ScriptLoadingModule::delayInline( [ 'id' => 'other' ], 'var x = 1;' ) );
		$this->assertSame( [ 'id' => 'itb-rum-loader' ], ScriptLoadingModule::delayInline( [ 'id' => 'itb-rum-loader' ], 'fbq(' ), 'los propios nunca' );

		$attrs = ScriptLoadingModule::delayAttributes(
			[
				'src' => 'https://www.googletagmanager.com/gtm.js',
				'id'  => 'gtm',
			]
		);
		$this->assertIsArray( $attrs );
		$this->assertArrayNotHasKey( 'src', $attrs );
		$this->assertSame( 'https://www.googletagmanager.com/gtm.js', $attrs['data-itb-src'] );
		$this->assertSame( 'text/plain', $attrs['type'] );
		// Los de un handle se resuelven en script_loader_tag.
		$handle = [
			'src' => 'https://www.googletagmanager.com/gtag/js',
			'id'  => 'google_gtagjs-js',
		];
		$this->assertSame( $handle, ScriptLoadingModule::delayAttributes( $handle ) );
		$this->assertTrue( ScriptLoadingModule::matches( 'https://WWW.GoogleTagManager.com/x', [ 'googletagmanager.com' ] ) );
		$this->assertFalse( ScriptLoadingModule::matches( '', [ 'x' ] ) );
	}

	public function test_settings_sanitizers(): void {
		$this->assertSame( [ 'slick', 'wc-add-to-cart-variation' ], Settings::scriptHandles( [ 'slick', ' wc-add-to-cart-variation ', 'mal handle', 7, 'slick' ] ) );
		$this->assertSame( [ 'googletagmanager.com', 'fbq(' ], Settings::delayPatterns( [ 'googletagmanager.com', 'fbq(', 'ab', '<script>', 'con espacio', null ] ) );
		$this->assertSame( 30, Settings::sanitize( [ 'script_delay_timeout' => 99 ] )['script_delay_timeout'] );
		$this->assertFalse( Settings::defaults()['script_defer'], 'apagado por defecto' );
	}

	public function test_loader_script_stays_under_budget(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/itb-delay.js' );
		$this->assertStringContainsString( 'data-itb-delay', $code );
		$min = (string) preg_replace( [ '#/\*.*?\*/#s', '#^\s*//.*$#m', '/\s+/' ], [ '', '', ' ' ], $code );
		$this->assertLessThan( 3072, strlen( $min ) );
	}

	public function test_clear_site_data_only_for_speculation_caches(): void {
		$this->assertSame( 'Clear-Site-Data: "prefetchCache", "prerenderCache"', ClearSpeculations::HEADER );
		$this->assertStringNotContainsString( '"cache"', ClearSpeculations::HEADER, 'nunca vaciar toda la caché del navegador' );
		$this->assertContains( 'woocommerce_add_to_cart', ClearSpeculations::ACTIONS );
		$this->assertContains( 'wp_logout', ClearSpeculations::ACTIONS );
	}

	public function test_rum_ttfb_breakdown_rows(): void {
		$cached = RumCollector::rows(
			[
				't' => 'portada',
				'd' => 'm',
				'b' => 900,
				'n' => 'n',
				'k' => 610,
				'w' => 260,
				'a' => 3600,
			],
			'1',
			self::NOW
		);
		$hist   = array_column( $cached['hist'], 4, 3 );
		$this->assertSame( 24, $hist['conn'] );
		$this->assertSame( 10, $hist['waith'] );
		$this->assertSame( 1, $hist['hit'] );

		$generated = array_column( RumCollector::rows( [ 't' => 'portada', 'd' => 'm', 'w' => 1800, 'a' => 2 ], '1', self::NOW )['hist'], 4, 3 );
		$this->assertSame( 72, $generated['waitm'] );
		$this->assertSame( 0, $generated['hit'] );

		$unknown = array_column( RumCollector::rows( [ 't' => 'portada', 'd' => 'm', 'w' => 100 ], '1', self::NOW )['hist'], 4, 3 );
		$this->assertSame( 4, $unknown['wait'], 'sin edad (copias anteriores a 0.14.0)' );
		$this->assertArrayNotHasKey( 'hit', $unknown );
	}

	public function test_rum_ttfb_breakdown_report(): void {
		$rows = [];
		$add  = static function ( string $metric, int $bucket, int $n, string $device = 'm' ) use ( &$rows ): void {
			$rows[] = [
				'template' => 'portada',
				'device'   => $device,
				'metric'   => $metric,
				'bucket'   => $bucket,
				'n'        => $n,
			];
		};
		$add( 'ttfb', 36, 20 );
		$add( 'conn', 24, 20 );
		$add( 'waith', 3, 15 );
		$add( 'waitm', 72, 5 );
		$add( 'hit', 1, 15 );
		$add( 'hit', 0, 5 );
		$add( 'ttfb', 4, 3, 'd' );
		$out = RumReport::ttfbBreakdown( $rows );
		$this->assertSame( 'm', $out[0]['device'] );
		$this->assertSame( 913, $out[0]['ttfb'] );
		$this->assertSame( 613, $out[0]['conn'] );
		$this->assertSame( 88, $out[0]['wait_cached'] );
		$this->assertNull( $out[0]['wait_generated'], 'menos de 10 muestras' );
		$this->assertSame( 75.0, $out[0]['cached_pct'] );
		$this->assertSame( 20, $out[0]['cached_n'] );
		$this->assertNull( $out[1]['cached_pct'] );
	}
}
