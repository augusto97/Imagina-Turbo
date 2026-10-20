<?php
/**
 * Hojas de estilo tardías al head (0.16.0).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use ImaginaTurbo\Modules\AssetManager\LateStyles;

/**
 * Validación de lo que se guarda y de lo que llega del inventario.
 */
final class LateStylesTest extends TestCase {

	private const HOME = 'https://tienda.test/';

	public function test_sheet_only_own_css(): void {
		$ok = LateStyles::sheet(
			[
				'h'     => 'wd-shop-off-canvas-sidebar',
				'src'   => 'https://tienda.test/wp-content/themes/woodmart/css/parts/woo-shop-opt-off-canvas-sidebar.min.css',
				'ver'   => '8.5.4',
				'media' => '',
			],
			self::HOME
		);
		$this->assertSame( 'all', $ok['media'] ?? null );
		$this->assertSame( '8.5.4', $ok['ver'] ?? null );
		$this->assertNotNull(
			LateStyles::sheet(
				[
					'h'   => 'a',
					'src' => '/wp-content/a.css?x=1',
				],
				self::HOME
			),
			'ruta relativa'
		);
		$this->assertNotNull(
			LateStyles::sheet(
				[
					'h'   => 'a',
					'src' => '//tienda.test/a.css',
				],
				self::HOME
			)
		);
		foreach ( [
			[
				'h'   => 'a',
				'src' => 'https://otro.test/a.css',
			],
			[
				'h'   => 'a',
				'src' => '/a.js',
			],
			[
				'h'   => 'a',
				'src' => 'a.css',
			],
			[
				'h'   => 'a',
				'src' => '/a.css" onload="x',
			],
			[
				'h'   => 'itb-cart',
				'src' => '/a.css',
			],
			[
				'h'   => 'admin-bar',
				'src' => '/a.css',
			],
			[
				'h'   => 'a b',
				'src' => '/a.css',
			],
		] as $bad ) {
			$this->assertNull( LateStyles::sheet( $bad, self::HOME ), json_encode( $bad ) ?: '' );
		}
	}

	public function test_normalize_by_template(): void {
		$out = LateStyles::normalize(
			[
				'tax:product_cat' => [
					[
						'h'   => 'b',
						'src' => '/b.css',
					],
					[
						'h'   => 'a',
						'src' => '/a.css',
					],
					[
						'h'   => 'a',
						'src' => '/a2.css',
					],
					[
						'h'   => 'x',
						'src' => 'https://cdn.otro/x.css',
					],
				],
				'DROP TABLE'      => [
					[
						'h'   => 'a',
						'src' => '/a.css',
					],
				],
				'portada'         => [],
			],
			self::HOME
		);
		$this->assertSame( [ 'tax:product_cat' ], array_keys( $out ) );
		$this->assertSame( [ 'b', 'a' ], array_column( $out['tax:product_cat'], 'h' ), 'orden de impresión y sin repetidos' );
		$this->assertSame( '/a.css', $out['tax:product_cat'][1]['src'] );
	}
}
