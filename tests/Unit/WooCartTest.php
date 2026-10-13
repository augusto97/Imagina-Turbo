<?php
/**
 * Carrito por Store API: selectores por tema, ajustes, cookies de carrito y script de vistos.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Modules\CacheCoordinator\HeaderEmitter;
use ImaginaTurbo\Modules\WooCart\ThemeSelectors;
use ImaginaTurbo\Modules\WooCart\WooCartModule;

final class WooCartTest extends TestCase {

	public function test_theme_selectors_resolve(): void {
		$astra = ThemeSelectors::resolve( 'astra', 'astra-child', [ '.mi .contador' ], [] );
		$this->assertSame( 'astra', $astra['theme'] );
		$this->assertTrue( $astra['verified'] );
		$this->assertContains( '.ast-site-header-cart .ast-count-text', $astra['count'] );
		$this->assertContains( '.mi .contador', $astra['count'], 'los propios se suman' );
		$this->assertContains( '.elementor-menu-cart__toggle .elementor-button-icon[data-counter]', $astra['count'], 'genéricos siempre' );
		$this->assertContains( '.ast-site-header-cart', $astra['menu'] );

		// Hija de un tema conocido por hoja de estilos; desconocido: solo genéricos y propios.
		$this->assertSame( 'kadence', ThemeSelectors::resolve( 'otro', 'kadence' )['theme'] );
		$unknown = ThemeSelectors::resolve( 'mi-tema', 'mi-tema', [], [ '.total' ] );
		$this->assertSame( '', $unknown['theme'] );
		$this->assertFalse( $unknown['verified'] );
		$this->assertContains( '.total', $unknown['total'] );

		// Los de pago no verificados quedan marcados.
		$this->assertFalse( ThemeSelectors::resolve( 'flatsome', 'flatsome' )['verified'] );
		// Kadence no tiene precio en el botón.
		$this->assertSame( [ '.elementor-menu-cart__toggle .elementor-button-text .woocommerce-Price-amount' ], ThemeSelectors::resolve( 'kadence', 'kadence' )['total'] );
	}

	public function test_plural_forms_only_for_text_counters(): void {
		Functions\when( '_n' )->alias( static fn( string $one, string $many, int $n ): string => 1 === $n ? $one : $many );
		$this->assertSame( [ '%d item', '%d items' ], ThemeSelectors::plural( 'storefront' ) );
		$this->assertNull( ThemeSelectors::plural( 'astra' ) );
	}

	public function test_selector_sanitizer(): void {
		$this->assertSame(
			[ '.a .b', '#x > span[data-count]', 'a.cart-contents .count' ],
			Settings::selectors( [ ' .a   .b ', '#x > span[data-count]', 'a.cart-contents .count', '.a .b', '</script><script>', '.x{color:red}', 5, str_repeat( 'a', 201 ) ] )
		);
		$this->assertCount( 20, Settings::selectors( array_map( static fn( int $i ): string => '.s' . $i, range( 1, 30 ) ) ) );
	}

	public function test_cart_cookie_detection(): void {
		$prefixes = [ 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_' ];
		$this->assertTrue( HeaderEmitter::hasCookie( [ '_ga', 'wp_woocommerce_session_abc123' ], $prefixes ) );
		$this->assertTrue( HeaderEmitter::hasCookie( [ 'woocommerce_items_in_cart' ], $prefixes ) );
		$this->assertFalse( HeaderEmitter::hasCookie( [ '_ga', 'woocommerce_recently_viewed' ], $prefixes ) );
		$this->assertFalse( HeaderEmitter::hasCookie( [ 'wp_woocommerce_session_x' ], [] ) );
	}

	public function test_recently_viewed_script(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$js = WooCartModule::recentlyViewedScript( 42, '/tienda/' );
		$this->assertStringContainsString( 'var p=42', $js );
		$this->assertStringContainsString( 'slice(-15)', $js, 'máximo 15, como WooCommerce' );
		$this->assertStringContainsString( '"\/tienda\/"', $js );
		$this->assertStringNotContainsString( '<', $js );
		// Un path con comillas no rompe el script.
		$this->assertStringContainsString( '"\/a\"b\/"', WooCartModule::recentlyViewedScript( 1, '/a"b/' ) );
	}

	public function test_cart_script_stays_under_budget(): void {
		$min = self::pluginDir() . '/assets/js/itb-cart.min.js';
		$src = self::pluginDir() . '/assets/js/itb-cart.js';
		$this->assertFileExists( $src );
		if ( is_file( $min ) ) {
			$this->assertLessThan( 3072, filesize( $min ) );
		}
		$code = (string) file_get_contents( $src );
		$this->assertStringContainsString( 'document.prerendering', $code, 'nada de Store API en prerender' );
		$this->assertStringContainsString( 'storeApiNonce', $code );
		$this->assertStringNotContainsString( 'get_refreshed_fragments', $code, 'el endpoint de fragments viene de la config, solo bajo demanda' );
	}
}
