<?php
/**
 * Presupuestos de rendimiento y auditoría de imágenes (0.19.0).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\ImageAudit;
use ImaginaTurbo\Fleet\FleetPolicy;
use ImaginaTurbo\Modules\Rum\Budgets;
use ImaginaTurbo\Modules\Rum\RumCollector;
use ImaginaTurbo\Modules\Rum\RumReport;

/**
 * Peso en el RUM, evaluación y avisos; escaneo de la biblioteca y recomendaciones de EWWW.
 */
final class BudgetsImagesTest extends TestCase {

	public function test_collector_buckets_page_weight(): void {
		$rows = RumCollector::rows(
			[
				't'  => 'portada',
				'd'  => 'm',
				'q'  => 63,
				'j'  => 412,
				's'  => 88,
				'kb' => 1890,
			],
			str_repeat( '0', 64 ),
			1_800_000_000
		);
		$hist = [];
		foreach ( $rows['hist'] as $row ) {
			$hist[ $row[3] ] = $row[4];
		}
		$this->assertSame( 12, $hist['req'] );
		$this->assertSame( 41, $hist['js'] );
		$this->assertSame( 17, $hist['css'] );
		$this->assertSame( 37, $hist['kb'] );

		// Valores inválidos o fuera de rango se descartan; los enormes se recortan al último intervalo.
		$rows = RumCollector::rows( [ 't' => 'portada', 'q' => -1, 'j' => 'x', 'kb' => 99999 ], '', 1_800_000_000 );
		$this->assertSame( [ [ 'kb', 400 ] ], array_map( static fn( array $r ): array => [ $r[3], $r[4] ], $rows['hist'] ) );
	}

	public function test_report_gives_weight_p75_by_template_and_site(): void {
		$rows = [];
		foreach ( [ 'portada', 'single:product' ] as $template ) {
			$rows[] = [ 'template' => $template, 'device' => 'm', 'metric' => 'nav', 'bucket' => 0, 'n' => 15 ];
			$rows[] = [ 'template' => $template, 'device' => 'm', 'metric' => 'js', 'bucket' => 'portada' === $template ? 30 : 50, 'n' => 15 ];
		}
		$byTemplate = RumReport::vitals( $rows );
		$this->assertCount( 2, $byTemplate );
		$this->assertSame( [ 305, 505 ], array_column( $byTemplate, 'js' ) );
		$site = RumReport::vitals( $rows, true );
		$this->assertCount( 1, $site );
		$this->assertSame( '*', $site[0]['template'] );
		$this->assertSame( 30, $site[0]['n'] );
		$this->assertSame( 505, $site[0]['js'] );
		$this->assertNull( $site[0]['lcp'] );
	}

	public function test_normalize_fills_defaults_and_clamps(): void {
		$this->assertSame( Budgets::DEFAULTS, Budgets::normalize( [] ) );
		$b = Budgets::normalize(
			[
				'lcp'  => 3000.4,
				'cls'  => 5,
				'js'   => -10,
				'kb'   => '900',
				'nope' => 1,
			]
		);
		$this->assertSame( 3000, $b['lcp'] );
		$this->assertSame( 1.0, $b['cls'] );
		$this->assertSame( 0, $b['js'] );
		$this->assertSame( 2500, $b['kb'] );
		$this->assertArrayNotHasKey( 'nope', $b );

		// El ajuste se guarda normalizado y una plantilla de la flota puede fijarlo.
		$this->assertSame( 2000, Settings::sanitize( [ 'budgets' => [ 'lcp' => 2000 ] ] )['budgets']['lcp'] );
		$this->assertContains( 'budgets', FleetPolicy::SETTINGS );
	}

	public function test_evaluate_and_diff(): void {
		$row      = static fn( string $template, int $n, array $values ): array => $values + [
			'template'     => $template,
			'device'       => 'm',
			'n'            => $n,
			'lcp'          => null,
			'inp'          => null,
			'cls'          => null,
			'ttfb'         => null,
			'req'          => null,
			'js'           => null,
			'css'          => null,
			'kb'           => null,
			'prerendered'  => 0,
			'prefetched'   => 0,
			'bfcache'      => 0,
			'back_forward' => 0,
		];
		$budgets  = Budgets::normalize( [ 'inp' => 0 ] );
		$breaches = Budgets::evaluate(
			[
				$row( '*', 100, [ 'lcp' => 3100, 'cls' => 0.05, 'inp' => 900 ] ),
				$row( 'single:product', 40, [ 'js' => 505, 'kb' => 2400 ] ),
				$row( 'tax:product_cat', 5, [ 'lcp' => 9000 ] ),
			],
			$budgets
		);
		// LCP del sitio y JS de la ficha; INP vale 0 (no se vigila) y la categoría tiene pocas visitas.
		$this->assertSame(
			[ [ '*', 'lcp', 3100, 2500 ], [ 'single:product', 'js', 505, 400 ] ],
			array_map( static fn( array $b ): array => [ $b['template'], $b['metric'], $b['value'], $b['budget'] ], $breaches )
		);

		$later = [ $breaches[1], [ 'template' => 'portada', 'device' => 'd', 'metric' => 'css', 'value' => 200, 'budget' => 150 ] ];
		$diff  = Budgets::diff( $breaches, $later );
		$this->assertSame( [ 'portada' ], array_column( $diff['new'], 'template' ) );
		$this->assertSame( [ 'lcp' ], array_column( $diff['resolved'], 'metric' ) );

		$text = Budgets::describe( $breaches );
		$this->assertStringContainsString( 'LCP en todo el sitio (móvil): 3,1 s > 2,5 s', $text );
		$this->assertStringContainsString( 'JS en single:product (móvil): 505 KB > 400 KB', $text );
	}

	public function test_image_scan_counts_sizes_webp_and_outliers(): void {
		$base = $this->tmp . '/uploads/';
		mkdir( $base . '2026/10', 0755, true );
		file_put_contents( $base . '2026/10/foto.jpg', str_repeat( 'a', 2000 ) );
		file_put_contents( $base . '2026/10/foto.jpg.webp', 'w' );
		file_put_contents( $base . '2026/10/foto-300x200.jpg', 'b' );
		file_put_contents( $base . '2026/10/foto-300x200.webp', 'w' );
		file_put_contents( $base . '2026/10/foto-1024x683.jpg', 'c' );
		file_put_contents( $base . '2026/10/logo.png', 'p' );

		$scan = ImageAudit::empty();
		$scan = ImageAudit::add(
			$scan,
			7,
			'image/jpeg',
			'2026/10/foto.jpg',
			[
				'width'    => 4000,
				'height'   => 2667,
				'filesize' => 2_000_000,
				'sizes'    => [
					'medium' => [ 'file' => 'foto-300x200.jpg', 'filesize' => 12_000 ],
					'large'  => [ 'file' => 'foto-1024x683.jpg', 'filesize' => 90_000 ],
				],
			],
			$base
		);
		$scan = ImageAudit::add( $scan, 9, 'image/png', '2026/10/logo.png', null, $base );
		$scan = ImageAudit::add( $scan, 12, 'image/jpeg', '2026/10/borrada.jpg', [], $base );

		$this->assertSame( 12, $scan['cursor'] );
		$this->assertSame( 3, $scan['total'] );
		$this->assertSame( [ 'image/jpeg' => 2, 'image/png' => 1 ], $scan['mime'] );
		$this->assertSame( 1, $scan['missing'] );
		$this->assertSame( 1, $scan['no_meta'] );
		$this->assertSame( 2_000_001, $scan['bytes_main'] );
		$this->assertSame( 102_000, $scan['bytes_sizes'] );
		// foto.jpg (append), foto-300x200 (replace), foto-1024x683 (sin WebP) y logo.png.
		$this->assertSame( 4, $scan['convertible'] );
		$this->assertSame( 2, $scan['webp'] );
		$this->assertSame( 0, $scan['avif'] );
		$this->assertSame( 1, $scan['large_n'] );
		$this->assertSame( 1, $scan['heavy_n'] );
		$this->assertSame( 7, $scan['heavy'][0]['id'] );
	}

	public function test_ewww_recommendations(): void {
		$ewww = [
			'version'         => '8.9.0',
			'jpg_level'       => 10,
			'png_level'       => 10,
			'webp'            => true,
			'webp_quality'    => 0,
			'webp_effective'  => 75,
			'sharpen'         => false,
			'naming'          => 'append',
			'max_width'       => 0,
			'max_height'      => 0,
			'metadata_remove' => true,
			'backup'          => false,
			'cloud'           => false,
			'queue'           => 120,
			'pending'         => 0,
			'bulk_resume'     => '',
			'optimized'       => 500,
			'saved_bytes'     => 1000,
		];
		$scan                = ImageAudit::empty();
		$scan['done']        = true;
		$scan['large_n']     = 3;
		$scan['convertible'] = 100;
		$scan['webp']        = 60;
		$ids                 = array_column( ImageAudit::recommendations( $ewww, $scan, [ 'EWWW Image Optimizer' ] ), 'id' );
		$this->assertSame( [ 'ewww_webp_quality', 'ewww_webp_lossy', 'ewww_resize', 'ewww_queue', 'webp_coverage' ], $ids );

		$ewww['webp_effective'] = 92;
		$ewww['sharpen']        = true;
		$ewww['queue']          = 0;
		$ewww['max_width']      = 2560;
		$scan['webp']           = 95;
		$this->assertSame( [ 'ewww_webp_lossy' ], array_column( ImageAudit::recommendations( $ewww, $scan, [ 'EWWW Image Optimizer' ] ), 'id' ) );

		$this->assertSame( [ 'no_optimizer' ], array_column( ImageAudit::recommendations( null, null, [] ), 'id' ) );
		$this->assertSame( [ 'two_optimizers' ], array_column( ImageAudit::recommendations( null, null, [ 'Smush', 'Imagify' ] ), 'id' ) );
		$this->assertSame( [ 'EWWW Image Optimizer', 'Smush' ], ImageAudit::optimizers( [ 'woocommerce/woocommerce.php', 'ewww-image-optimizer/ewww-image-optimizer.php', 'wp-smushit/wp-smush.php' ] ) );
	}

	public function test_rum_script_under_budget(): void {
		$src = (string) file_get_contents( self::pluginDir() . '/assets/js/itb-rum.js' );
		$this->assertStringContainsString( 'loadEventEnd', $src );
		$min = self::pluginDir() . '/assets/js/itb-rum.min.js';
		if ( is_file( $min ) ) {
			$this->assertLessThan( 3072, filesize( $min ) );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
	}
}
