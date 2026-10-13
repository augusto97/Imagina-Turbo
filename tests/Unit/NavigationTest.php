<?php
/**
 * Fase 6: RUM (colector, informe, insights), navegación instantánea y pistas de frontend.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\FrontendHints\FrontendHintsModule;
use ImaginaTurbo\Modules\Rum\RumCollector;
use ImaginaTurbo\Modules\Rum\RumInsights;
use ImaginaTurbo\Modules\Rum\RumReport;
use ImaginaTurbo\Modules\Speculation\SpeculationModule;

final class NavigationTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function test_collector_rows(): void {
		$rows = RumCollector::rows(
			[
				't'  => 'single:product',
				'd'  => 'm',
				'l'  => 2410,
				'i'  => 180,
				'c'  => 95,
				'b'  => 320,
				'n'  => 'p',
				'lu' => '/wp-content/uploads/2026/10/hero-1024x768.jpg?ver=1',
				'pf' => 'portada',
				'pu' => '/producto/camiseta/',
				'x'  => [ 'unload-listener', '<script>', 'BroadcastChannel' ],
			],
			'4',
			self::NOW
		);
		$hist = array_column( $rows['hist'], 4, 3 );
		$this->assertSame( 96, $hist['lcp'], '2410 ms en intervalos de 25' );
		$this->assertSame( 18, $hist['inp'] );
		$this->assertSame( 9, $hist['cls'] );
		$this->assertSame( 12, $hist['ttfb'] );
		$this->assertSame( RumCollector::NAV['p'], $hist['nav'] );
		$this->assertSame( gmdate( 'Y-m-d H:00:00', self::NOW ), $rows['hist'][0][0] );

		$events = array_map( static fn( array $e ): string => $e[1] . ':' . $e[2] . ':' . $e[4], $rows['events'] );
		$this->assertContains( 'lcp:single:product:/wp-content/uploads/2026/10/hero-1024x768.jpg', $events, 'sin query' );
		$this->assertContains( 'next:portada:/producto/camiseta/', $events );
		$this->assertContains( 'nrr:single:product:unload-listener', $events );
		$this->assertContains( 'nrr:single:product:broadcastchannel', $events );
		$this->assertNotContains( 'nrr:single:product:<script>', $events, 'motivos raros se descartan' );
	}

	public function test_collector_rejects_garbage(): void {
		$this->assertSame(
			[
				'hist'   => [],
				'events' => [],
			],
			RumCollector::rows( [ 't' => 'DROP TABLE' ], '1', self::NOW )
		);
		$rows = RumCollector::rows(
			[
				't'  => 'portada',
				'l'  => -5,
				'i'  => 'x',
				'lu' => 'https://otro.com/a.jpg',
				'pf' => 'portada',
				'pu' => '//evil.com/x',
				'n'  => 'zz',
			],
			'1',
			self::NOW
		);
		$this->assertSame( [], $rows['hist'] );
		$this->assertSame( [], $rows['events'] );
		$this->assertSame( '#featured', RumCollector::rows( [ 't' => 'single:post', 'lr' => 'f' ], '1', self::NOW )['events'][0][4] );
		$this->assertSame( '', RumCollector::path( '/' . str_repeat( 'a', 130 ) ) );
		$this->assertSame( '/a/b', RumCollector::path( '/a/b#frag' ) );
	}

	public function test_origin_ip_bit_and_distinct(): void {
		$this->assertTrue( RumCollector::sameHost( 'https://tienda.com/x', 'tienda.com' ) );
		$this->assertTrue( RumCollector::sameHost( 'http://127.0.0.1:8080/', '127.0.0.1:8080' ) );
		$this->assertFalse( RumCollector::sameHost( 'https://tienda.com.evil.net/', 'tienda.com' ) );
		$this->assertFalse( RumCollector::sameHost( '', 'tienda.com' ) );
		$this->assertSame( '190.1.1.1', RumCollector::clientIp( [ 'HTTP_CF_RAY' => 'x', 'HTTP_CF_CONNECTING_IP' => '190.1.1.1', 'REMOTE_ADDR' => '172.1.1.1' ] ) );
		$this->assertSame( '172.1.1.1', RumCollector::clientIp( [ 'HTTP_CF_CONNECTING_IP' => '190.1.1.1', 'REMOTE_ADDR' => '172.1.1.1' ] ), 'sin CF-Ray la cabecera no se cree' );

		$bit = RumCollector::ipBit( '190.1.1.1', '2026-10-13' );
		$this->assertSame( 1, RumCollector::popcount( $bit ) );
		$this->assertSame( $bit, RumCollector::ipBit( '190.1.1.1', '2026-10-13' ) );
		$this->assertSame( 64, RumCollector::popcount( '18446744073709551615' ) );
		$this->assertSame( 0, RumCollector::distinct( '0' ) );
		$this->assertSame( 1, RumCollector::distinct( '1' ) );
		$twenty = (string) ( ( 1 << 20 ) - 1 );
		$this->assertGreaterThanOrEqual( 20, RumCollector::distinct( $twenty ) );
	}

	public function test_report_p75_and_navigation(): void {
		$this->assertNull( RumReport::p75( [ 10 => 3 ], 25 ), 'pocas muestras' );
		// 100 muestras: 70 en el intervalo 40 (1000-1025 ms) y 30 en el 100 (2500 ms): p75 = 2512.
		$this->assertSame( 2513, RumReport::p75( [ 40 => 70, 100 => 30 ], 25 ) );
		$rows = [
			[ 'template' => 'portada', 'device' => 'm', 'metric' => 'nav', 'bucket' => 0, 'n' => 60 ],
			[ 'template' => 'portada', 'device' => 'm', 'metric' => 'nav', 'bucket' => 4, 'n' => 30 ],
			[ 'template' => 'portada', 'device' => 'm', 'metric' => 'nav', 'bucket' => 5, 'n' => 10 ],
			[ 'template' => 'portada', 'device' => 'm', 'metric' => 'nav', 'bucket' => 2, 'n' => 4 ],
			[ 'template' => 'portada', 'device' => 'm', 'metric' => 'nav', 'bucket' => 3, 'n' => 16 ],
			[ 'template' => 'portada', 'device' => 'm', 'metric' => 'cls', 'bucket' => 9, 'n' => 20 ],
		];
		$nav  = RumReport::navigation( $rows );
		$this->assertSame( 100, $nav['navigations'] );
		$this->assertSame( 40.0, $nav['speculated_pct'] );
		$this->assertSame( 80.0, $nav['bfcache_pct'] );
		$vitals = RumReport::vitals( $rows );
		$this->assertSame( 120, $vitals[0]['n'] );
		$this->assertSame( 0.095, $vitals[0]['cls'] );
		$this->assertNull( $vitals[0]['lcp'] );
	}

	public function test_insights_predicted_requires_volume_distinct_and_content(): void {
		$many = (string) ( ( 1 << 30 ) - 1 );
		$few  = '3';
		$events = [
			[ 'template' => 'portada', 'device' => 'm', 'value' => '/tienda/', 'n' => 120, 'bits' => $many ],
			[ 'template' => 'portada', 'device' => 'm', 'value' => '/carrito/', 'n' => 80, 'bits' => $many ],
			[ 'template' => 'portada', 'device' => 'm', 'value' => '/spam/', 'n' => 200, 'bits' => $few ],
			[ 'template' => 'portada', 'device' => 'm', 'value' => '/no-existe/', 'n' => 60, 'bits' => $many ],
			[ 'template' => 'portada', 'device' => 'm', 'value' => '/raro/', 'n' => 5, 'bits' => $many ],
			// El mismo destino desde escritorio, con un solo visitante: no le quita los visitantes del móvil.
			[ 'template' => 'portada', 'device' => 'd', 'value' => '/tienda/', 'n' => 1, 'bits' => '1' ],
		];
		$content   = [ '/tienda/', '/carrito/', '/spam/', '/raro/' ];
		$excluded  = [ '/carrito', '/carrito/*' ];
		$predicted = RumInsights::predicted(
			$events,
			static fn( string $p ): bool => in_array( $p, $content, true ) && ! RumInsights::excluded( $p, $excluded )
		);
		$this->assertSame( [ 'portada' => [ '/tienda/' ] ], $predicted, 'spam de pocas IP, carrito excluido, inexistente y poco frecuente fuera' );
	}

	public function test_insights_lcp(): void {
		$many   = (string) ( ( 1 << 40 ) - 1 );
		$events = [
			[ 'template' => 'portada', 'device' => 'm', 'value' => '/wp-content/uploads/hero-768x400.jpg', 'n' => 90, 'bits' => $many ],
			[ 'template' => 'portada', 'device' => 'm', 'value' => '/wp-content/uploads/otra.jpg', 'n' => 10, 'bits' => $many ],
			[ 'template' => 'portada', 'device' => 'd', 'value' => '/wp-content/uploads/hero.jpg', 'n' => 500, 'bits' => $many ],
			[ 'template' => 'single:product', 'device' => 'm', 'value' => '#featured', 'n' => 70, 'bits' => $many ],
			[ 'template' => 'single:product', 'device' => 'm', 'value' => '/wp-content/uploads/logo.png', 'n' => 20, 'bits' => $many ],
			[ 'template' => 'single:post', 'device' => 'm', 'value' => '/wp-content/uploads/a.jpg', 'n' => 60, 'bits' => '1' ],
		];
		$lcp    = RumInsights::lcp( $events, static fn( string $p ): int => '/wp-content/uploads/hero-768x400.jpg' === $p ? 42 : 0 );
		$this->assertSame( 'image', $lcp['portada']['kind'] );
		$this->assertSame( 42, $lcp['portada']['id'] );
		$this->assertSame( 0.9, $lcp['portada']['share'] );
		$this->assertSame( 'featured', $lcp['single:product']['kind'] );
		$this->assertArrayNotHasKey( 'single:post', $lcp, 'un solo visitante no decide' );
		$this->assertSame( '/u/foto.jpg', RumInsights::originalPath( '/u/foto-300x200.jpg' ) );
		$this->assertTrue( RumInsights::excluded( '/mi-cuenta/pedidos/', [ '/mi-cuenta/*' ] ) );
		$this->assertFalse( RumInsights::excluded( '/mi-cuentas/', [ '/mi-cuenta' ] ) );
	}

	public function test_speculation_configuration_and_exclusions(): void {
		Runtime::$config = CompiledConfig::fromArray(
			[
				'v'           => CompiledConfig::FORMAT_VERSION,
				'hash'        => 'x',
				'speculation' => [
					'mode'      => 'prerender',
					'eagerness' => 'conservative',
					'exclude'   => [ '/carrito', '/carrito/*' ],
				],
			]
		);
		$this->assertNull( SpeculationModule::configuration( null ), 'con sesión o sin enlaces permanentes, el core lo deja apagado' );
		$this->assertSame(
			[
				'mode'      => 'prerender',
				'eagerness' => 'conservative',
			],
			SpeculationModule::configuration( [ 'mode' => 'auto' ] )
		);
		$this->assertSame( [ '/x/*', '/carrito', '/carrito/*' ], SpeculationModule::exclude( [ '/x/*' ], 'prefetch' ) );
		$this->assertSame( [ '/carrito', '/wc-api/v' ], SpeculationModule::prefixes( [ '/carrito', '/carrito/*', '/*/embed/*', '/*', '/wc-api/v*' ] ), 'nunca un prefijo vacío' );
	}

	public function test_lcp_image_prioritized(): void {
		$this->assertSame( '<img fetchpriority="high" src="a.jpg" class="wp-image-4">', FrontendHintsModule::prioritize( '<img src="a.jpg" loading="lazy" class="wp-image-4" fetchpriority="low">' ) );
	}

	public function test_facades(): void {
		Functions\when( 'add_query_arg' )->alias( static fn( string $k, string $v, string $url ): string => $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $k . '=' . $v );
		Functions\when( 'esc_url' )->returnArg();
		$yt = FrontendHintsModule::facadeHtml( '<iframe title="Mi video" width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed" allowfullscreen></iframe>' );
		$this->assertNotNull( $yt );
		$this->assertStringContainsString( 'data-src="https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed&autoplay=1"', $yt );
		$this->assertStringContainsString( 'i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $yt );
		$this->assertStringContainsString( 'aspect-ratio:560/315', $yt );
		$this->assertStringNotContainsString( '<iframe', $yt );
		$vimeo = FrontendHintsModule::facadeHtml( '<iframe src="https://player.vimeo.com/video/76979871?dnt=1" width="640" height="360" title="V"></iframe>' );
		$this->assertNotNull( $vimeo );
		$this->assertStringNotContainsString( 'background-image', $vimeo );
		$this->assertNull( FrontendHintsModule::facadeHtml( '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ?enablejsapi=1"></iframe>' ), 'otro script controla el reproductor' );
		$this->assertNull( FrontendHintsModule::facadeHtml( '<iframe src="https://open.spotify.com/embed/track/x"></iframe>' ) );
		$this->assertNull( FrontendHintsModule::facadeHtml( '<blockquote>tweet</blockquote>' ) );
	}

	public function test_scripts_stay_under_budget(): void {
		foreach ( [ 'itb-rum', 'itb-prefetch' ] as $name ) {
			$src = self::pluginDir() . '/assets/js/' . $name . '.js';
			$this->assertFileExists( $src );
			$min = self::pluginDir() . '/assets/js/' . $name . '.min.js';
			if ( is_file( $min ) ) {
				$this->assertLessThan( 3072, filesize( $min ) );
			}
		}
		$rum = (string) file_get_contents( self::pluginDir() . '/assets/js/itb-rum.js' );
		$this->assertStringContainsString( 'sendBeacon', $rum );
		$this->assertStringContainsString( 'notRestoredReasons', $rum );
		$this->assertStringContainsString( 'activationStart', $rum );
		$prefetch = (string) file_get_contents( self::pluginDir() . '/assets/js/itb-prefetch.js' );
		$this->assertStringContainsString( "supports( 'speculationrules' )", $prefetch, 'solo donde no hay Speculation Rules' );
		$this->assertStringContainsString( 'saveData', $prefetch );
	}
}
