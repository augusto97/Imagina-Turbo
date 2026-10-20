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
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\FrontendHints\FrontendHintsModule;
use ImaginaTurbo\Modules\Rum\RumCollector;
use ImaginaTurbo\Modules\Rum\RumInsights;
use ImaginaTurbo\Modules\Rum\RumReport;
use ImaginaTurbo\Modules\Speculation\SpeculationModule;
use ImaginaTurbo\Rest\NavigationController;

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

	public function test_font_events_and_insights(): void {
		$rows  = RumCollector::rows(
			[
				't'  => 'tax:product_cat',
				'fo' => [ '/wp-content/themes/woodmart/fonts/woodmart-font-1-400.woff2', '/wp-content/uploads/x.php', '//evil.com/f.woff2', '/f3.woff' ],
			],
			'1',
			self::NOW
		);
		$fonts = array_values( array_filter( $rows['events'], static fn( array $e ): bool => 'font' === $e[1] ) );
		$this->assertCount( 1, $fonts, 'solo fuentes del sitio y hasta 2 por beacon' );
		$this->assertSame( '/wp-content/themes/woodmart/fonts/woodmart-font-1-400.woff2', $fonts[0][4] );

		$many   = (string) ( ( 1 << 30 ) - 1 );
		$few    = '7';
		$events = [
			[ 'template' => 'portada', 'device' => '*', 'value' => '/a.woff2', 'n' => 100, 'bits' => $many ],
			[ 'template' => 'portada', 'device' => '*', 'value' => '/b.woff2', 'n' => 60, 'bits' => $many ],
			[ 'template' => 'portada', 'device' => '*', 'value' => '/c.woff2', 'n' => 55, 'bits' => $many ],
			[ 'template' => 'portada', 'device' => '*', 'value' => '/d.woff2', 'n' => 40, 'bits' => $many ],
			[ 'template' => 'single:post', 'device' => '*', 'value' => '/a.woff2', 'n' => 90, 'bits' => $few ],
			[ 'template' => 'busqueda', 'device' => '*', 'value' => '/a.woff2', 'n' => 10, 'bits' => $many ],
			[ 'template' => 'tax:category', 'device' => '*', 'value' => '/borrada.woff2', 'n' => 90, 'bits' => $many ],
		];
		$out    = RumInsights::fonts( $events, static fn( string $p ): bool => '/borrada.woff2' !== $p );
		$this->assertSame( [ 'portada' => [ '/a.woff2', '/b.woff2' ] ], $out, 'hasta 2, con muchos visitantes, muestras suficientes y archivos que existen' );

		$dir = sys_get_temp_dir() . '/itb-fonts-' . getmypid();
		@mkdir( $dir . '/wp-content/fonts', 0777, true );
		file_put_contents( $dir . '/wp-content/fonts/a b.woff2', 'x' );
		$this->assertTrue( RumInsights::isFontFile( '/wp-content/fonts/a%20b.woff2', $dir . '/' ) );
		$this->assertTrue( RumInsights::isFontFile( '/sub/wp-content/fonts/a%20b.woff2', $dir . '/', '/sub' ), 'WordPress en subcarpeta' );
		$this->assertFalse( RumInsights::isFontFile( '/wp-content/fonts/../fonts/a%20b.woff2', $dir . '/' ) );
		$this->assertFalse( RumInsights::isFontFile( '/wp-content/fonts/no.woff2', $dir . '/' ) );
		$this->assertFalse( RumInsights::isFontFile( '/wp-config.php', $dir . '/' ) );
		unlink( $dir . '/wp-content/fonts/a b.woff2' );
	}

	public function test_manual_font_paths(): void {
		$home = 'https://tienda.test/';
		$this->assertSame(
			[ '/wp-content/uploads/f/work.woff2', '/wp-content/themes/w/fonts/icons.woff2?v=8.5.4', '/f/c.ttf' ],
			Settings::fontPaths(
				[
					'https://tienda.test/wp-content/uploads/f/work.woff2',
					'/wp-content/themes/w/fonts/icons.woff2?v=8.5.4',
					'/wp-content/uploads/f/work.woff2',
					'https://otro.test/f.woff2',
					'/f/../wp-config.php',
					'/f/a.css',
					'/f/b.woff2" onload="x',
					'f/rel.woff2',
					'/f/c.ttf',
					'/f/d.otf',
				],
				$home
			),
			'del propio sitio, sin repetir, hasta 3'
		);
		$this->assertSame( 'font/woff2', FrontendHintsModule::fontType( '/a.woff2?v=1' ), 'la query no cambia el tipo' );
	}

	public function test_font_preload_header(): void {
		$this->assertSame( 'font/woff2', FrontendHintsModule::fontType( '/a.WOFF2' ) );
		$this->assertSame( 'font/ttf', FrontendHintsModule::fontType( '/a.ttf' ) );
		$this->assertSame(
			'</f/a.woff2>; rel=preload; as=font; type=font/woff2; crossorigin, </f/bx.woff>; rel=preload; as=font; type=font/woff; crossorigin',
			FrontendHintsModule::fontLinkHeader( [ '/f/a.woff2', '/f/b"<x>.woff' ] )
		);
	}

	public function test_lcp_image_prioritized(): void {
		$this->assertSame( '<img fetchpriority="high" data-skip-lazy="1" data-no-lazy="1" data-itb-lcp="1" src="a.jpg" class="wp-image-4">', FrontendHintsModule::prioritize( '<img src="a.jpg" loading="lazy" class="wp-image-4" fetchpriority="low">' ) );
	}

	/**
	 * Biblioteca de medios falsa con las copias .webp de EWWW ("foto.png.webp").
	 */
	private function uploads(): string {
		$base = $this->tmp . '/uploads';
		mkdir( $base . '/2023/06', 0755, true );
		foreach ( [ 'a-600x600.png', 'a-600x600.png.webp', 'a-300x300.png', 'b.jpg', 'b.webp', 'c.png' ] as $f ) {
			touch( $base . '/2023/06/' . $f );
		}
		Functions\when( 'wp_get_upload_dir' )->justReturn(
			[
				'basedir' => $base,
				'baseurl' => 'https://tienda.test/wp-content/uploads',
			]
		);
		FrontendHintsModule::reset();
		return $base;
	}

	public function test_main_image_points_to_existing_webp(): void {
		$this->uploads();
		$u = 'https://tienda.test/wp-content/uploads/2023/06/';
		$this->assertSame( $u . 'a-600x600.png.webp', FrontendHintsModule::webpUrl( $u . 'a-600x600.png' ), 'EWWW, modo "agregar"' );
		$this->assertSame( $u . 'b.webp', FrontendHintsModule::webpUrl( $u . 'b.jpg' ), 'modo "reemplazar"' );
		$this->assertSame( 'http://tienda.test/wp-content/uploads/2023/06/a-600x600.png.webp', FrontendHintsModule::webpUrl( 'http://tienda.test/wp-content/uploads/2023/06/a-600x600.png?v=2' ), 'http o https, sin la consulta' );
		$this->assertNull( FrontendHintsModule::webpUrl( $u . 'c.png' ), 'sin copia: se queda el original' );
		$this->assertNull( FrontendHintsModule::webpUrl( 'https://cdn.otro.test/a-600x600.png' ) );
		$this->assertNull( FrontendHintsModule::webpUrl( $u . '../../a-600x600.png' ) );
		$this->assertSame( $u . 'a-600x600.png.webp 600w, ' . $u . 'a-300x300.png 300w', FrontendHintsModule::webpSrcset( $u . 'a-600x600.png 600w, ' . $u . 'a-300x300.png 300w' ) );

		$attr = FrontendHintsModule::eager(
			[
				'src'     => $u . 'a-600x600.png',
				'loading' => 'lazy',
				'class'   => 'wp-post-image',
			],
			true
		);
		$this->assertSame( 'eager', $attr['loading'] );
		$this->assertSame( 'high', $attr['fetchpriority'] );
		$this->assertSame( '1', $attr['data-skip-lazy'], 'EWWW y LiteSpeed Cache' );
		$this->assertSame( '1', $attr['data-no-lazy'], 'WP Rocket' );
		$this->assertSame( $u . 'a-600x600.png.webp', $attr['src'] );
		$this->assertSame( [ 'x', FrontendHintsModule::MARK ], FrontendHintsModule::ewwwExclusions( [ 'x' ] ), 'fuera de la reescritura de WebP por JS' );
	}

	public function test_featured_and_listing_images_skip_lazy(): void {
		$this->uploads();
		if ( ! class_exists( 'WP_Post' ) ) {
			eval( 'class WP_Post { public $ID = 0; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- clase mínima para la prueba.
		}
		$post     = static function ( int $id ): object {
			$p     = new \WP_Post();
			$p->ID = $id;
			return $p;
		};
		$featured = new \ReflectionProperty( FrontendHintsModule::class, 'lcpId' );
		$featured->setValue( null, 7 );
		$only = new \ReflectionProperty( FrontendHintsModule::class, 'featuredOnly' );
		$only->setValue( null, true );

		$menu = FrontendHintsModule::attachmentAttributes( [ 'class' => 'attachment-large' ], $post( 7 ), 'large' );
		$this->assertArrayNotHasKey( 'data-skip-lazy', $menu, 'la misma foto en el megamenú no es la principal' );
		$main = FrontendHintsModule::attachmentAttributes( [ 'class' => 'wp-post-image' ], $post( 7 ), 'woocommerce_single' );
		$this->assertSame( 'high', $main['fetchpriority'] );
		$thumb = FrontendHintsModule::attachmentAttributes( [ 'class' => 'wp-post-image' ], $post( 7 ), 'woocommerce_gallery_thumbnail' );
		$this->assertArrayNotHasKey( 'data-skip-lazy', $thumb, 'una sola vez' );

		FrontendHintsModule::reset();
		$this->assertTrue( FrontendHintsModule::isListing( 'tax:product_cat' ) );
		$this->assertTrue( FrontendHintsModule::isListing( 'archivo:product' ) );
		$this->assertFalse( FrontendHintsModule::isListing( 'portada' ) );
		$this->assertArrayNotHasKey( 'data-skip-lazy', FrontendHintsModule::attachmentAttributes( [], $post( 1 ), 'woocommerce_thumbnail' ), 'antes del listado (menús, cabecera)' );
		$marked = [];
		foreach ( [ 1, 2, 3 ] as $product ) {
			FrontendHintsModule::nextProduct();
			foreach ( [ 'main', 'hover' ] as $which ) {
				$a        = FrontendHintsModule::attachmentAttributes( [], $post( $product * 10 ), 'woocommerce_thumbnail' );
				$marked[] = $product . $which . ':' . ( isset( $a['data-skip-lazy'] ) ? ( $a['fetchpriority'] ?? 'eager' ) : '-' );
			}
		}
		$this->assertSame( [ '1main:high', '1hover:-', '2main:eager', '2hover:-', '3main:-', '3hover:-' ], $marked );
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

	public function test_own_no_store_ignores_sessions_and_404(): void {
		$cc = 'no-cache, must-revalidate, max-age=0, no-store, private';
		$this->assertTrue( NavigationController::ownNoStore( [ 'anon' => 1, 'status' => 200, 'cache_control' => $cc ] ) );
		$this->assertFalse( NavigationController::ownNoStore( [ 'anon' => 0, 'status' => 200, 'cache_control' => $cc ] ) );
		$this->assertFalse( NavigationController::ownNoStore( [ 'anon' => 1, 'status' => 404, 'cache_control' => $cc ] ) );
		$this->assertFalse( NavigationController::ownNoStore( [ 'anon' => 1, 'status' => 200, 'cache_control' => 'max-age=0' ] ) );
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

	public function test_lazy_render_rules_and_css(): void {
		$rules = Settings::lazyRender(
			[
				[
					'selector'  => '.wd-footer',
					'templates' => [],
					'height'    => 900,
				],
				[
					'selector'  => '.products > .wd-product:nth-child(n+5)',
					'templates' => [ 'tax:product_cat', 'MAL plantilla' ],
					'height'    => 10,
				],
				[ 'selector' => '.x{}</style><script>' ],
				[ 'selector' => 'a;b' ],
				'basura',
			]
		);
		$this->assertCount( 2, $rules, 'sin llaves, punto y coma ni "<"' );
		$this->assertSame( [ 'tax:product_cat' ], $rules[1]['templates'] );
		$this->assertSame( 50, $rules[1]['height'], 'altura mínima' );

		$this->assertSame( '.wd-footer{content-visibility:auto;contain-intrinsic-size:auto 900px}', FrontendHintsModule::lazyRenderCss( $rules, 'single:product' ), 'la tarjeta de producto solo en categorías' );
		$this->assertStringContainsString( 'nth-child(n+5){content-visibility:auto', FrontendHintsModule::lazyRenderCss( $rules, 'tax:product_cat' ) );
		$this->assertSame( '', FrontendHintsModule::lazyRenderCss( [], 'portada' ) );

		$this->assertSame( '.wd-footer', FrontendHintsModule::lazyRenderPresets( 'woodmart' )[0]['selector'] );
		$this->assertSame( [], FrontendHintsModule::lazyRenderPresets( 'tema-desconocido' ) );
		foreach ( [ 'woodmart', 'astra', 'hello-elementor' ] as $theme ) {
			$presets = FrontendHintsModule::lazyRenderPresets( $theme );
			$this->assertSame( $presets, Settings::lazyRender( $presets ), 'las sugerencias pasan la validación: ' . $theme );
		}
	}

	public function test_link_header_for_early_hints(): void {
		$this->assertSame(
			'<https://t.test/a.webp>; rel=preload; as=image; fetchpriority=high; imagesrcset="https://t.test/a.webp 600w, https://t.test/b.webp 300w"; imagesizes="(max-width: 600px) 100vw, 600px"',
			FrontendHintsModule::linkHeader(
				[
					'src'    => 'https://t.test/a.webp',
					'srcset' => 'https://t.test/a.webp 600w, https://t.test/b.webp 300w',
					'sizes'  => '(max-width: 600px) 100vw, 600px',
				]
			)
		);
		$this->assertSame(
			'<https://t.test/a.webp>; rel=preload; as=image; fetchpriority=high; imagesrcset="x.webp 1w, evil=1"',
			FrontendHintsModule::linkHeader(
				[
					'src'    => "https://t.test/a.webp\r\n",
					'srcset' => "x.webp 1w, \"evil=1",
					'sizes'  => '',
				]
			),
			'sin comillas ni saltos de línea: no se puede inyectar otra cabecera'
		);
	}
}
