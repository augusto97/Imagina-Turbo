<?php
/**
 * Selectores del contador y el total del mini-carrito por tema (se pueden ampliar en los ajustes).
 *
 * Verificados en el código de los temas (wordpress.org, 2026-10-13): Astra, Kadence, Blocksy,
 * Storefront y OceanWP (claves de woocommerce_add_to_cart_fragments y su marcado). Flatsome, Woodmart
 * y el widget de carrito de Elementor Pro son de pago: sus selectores salen de su documentación y de
 * sitios en producción y están marcados como no verificados hasta probarlos en un sitio real.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\WooCart;

/**
 * Mapa de temas.
 */
final class ThemeSelectors {

	/**
	 * Por plantilla (tema padre).
	 *
	 * menu: contenedor del mini-carrito; al pasar el cursor o tocarlo se carga el desplegable.
	 *
	 * @return array<string, array{count: list<string>, total: list<string>, menu?: list<string>, verified: bool}>
	 */
	public static function map(): array {
		return [
			'astra'      => [
				// Icono por defecto: número en .ast-count-text. Iconos SVG: atributo data-cart-total.
				'count'    => [ '.ast-site-header-cart .ast-count-text', '.ast-site-header-cart .astra-icon[data-cart-total]' ],
				'total'    => [ '.ast-woo-header-cart-total .woocommerce-Price-amount', '.ast-woo-header-cart-total-only .woocommerce-Price-amount' ],
				'menu'     => [ '.ast-site-header-cart' ],
				'verified' => true,
			],
			'kadence'    => [
				// .header-cart-total contiene la CANTIDAD (el nombre engaña). El botón no muestra precio.
				'count'    => [ '.header-cart-button .header-cart-total' ],
				'total'    => [],
				'menu'     => [ '.header-cart-wrap', '.header-mobile-cart-wrap' ],
				'verified' => true,
			],
			'blocksy'    => [
				'count'    => [ '.ct-dynamic-count-cart' ],
				'total'    => [ '.ct-header-cart .ct-label .ct-amount' ],
				'menu'     => [ '.ct-header-cart' ],
				'verified' => true,
			],
			'storefront' => [
				// "3 items": el texto se arma con las formas del tema (ver plural()).
				'count'    => [ 'a.cart-contents .count', 'a.footer-cart-contents .count' ],
				'total'    => [ 'a.cart-contents > .woocommerce-Price-amount' ],
				'menu'     => [ '.site-header-cart' ],
				'verified' => true,
			],
			'oceanwp'    => [
				// Estilo normal: el total pierde la clase .woocommerce-Price-amount (OceanWP la renombra).
				'count'    => [ 'a.wcmenucart .wcmenucart-details.count', 'a.wcmenucart .wcmenucart-cart-icon .wcmenucart-count' ],
				'total'    => [ 'a.wcmenucart .woocommerce-Price-wcmenucart-details', 'a.wcmenucart .wcmenucart-total .woocommerce-Price-amount' ],
				'menu'     => [ '.woo-menu-icon' ],
				'verified' => true,
			],
			'flatsome'   => [
				'count'    => [ '.header-cart-link .cart-icon strong' ],
				'total'    => [ '.header-cart-link .cart-price .woocommerce-Price-amount' ],
				'menu'     => [ '.cart-item.has-dropdown' ],
				'verified' => false,
			],
			'woodmart'   => [
				'count'    => [ '.wd-header-cart .wd-cart-number' ],
				'total'    => [ '.wd-header-cart .wd-cart-subtotal .woocommerce-Price-amount' ],
				'menu'     => [ '.wd-header-cart' ],
				'verified' => false,
			],
		];
	}

	/**
	 * Selectores que aplican a cualquier tema (widget de carrito de Elementor Pro, usado con Hello).
	 *
	 * @return array{count: list<string>, total: list<string>, menu: list<string>}
	 */
	public static function generic(): array {
		return [
			'count' => [ '.elementor-menu-cart__toggle .elementor-button-icon[data-counter]' ],
			'total' => [ '.elementor-menu-cart__toggle .elementor-button-text .woocommerce-Price-amount' ],
			'menu'  => [ '.elementor-menu-cart__wrapper' ],
		];
	}

	/**
	 * Selectores para un tema (plantilla y hoja de estilos hija) más los genéricos y los propios.
	 *
	 * @param list<string> $customCount Selectores propios del contador.
	 * @param list<string> $customTotal Selectores propios del total.
	 * @return array{count: list<string>, total: list<string>, menu: list<string>, theme: string, verified: bool}
	 */
	public static function resolve( string $template, string $stylesheet, array $customCount = [], array $customTotal = [] ): array {
		$map     = self::map();
		$theme   = isset( $map[ $template ] ) ? $template : ( isset( $map[ $stylesheet ] ) ? $stylesheet : '' );
		$own     = '' !== $theme ? $map[ $theme ] : [
			'count'    => [],
			'total'    => [],
			'verified' => false,
		];
		$generic = self::generic();
		return [
			'count'    => array_values( array_unique( array_merge( $own['count'], $generic['count'], $customCount ) ) ),
			'total'    => array_values( array_unique( array_merge( $own['total'], $generic['total'], $customTotal ) ) ),
			'menu'     => array_values( array_unique( array_merge( $own['menu'] ?? [], $generic['menu'] ) ) ),
			'theme'    => $theme,
			'verified' => $own['verified'],
		];
	}

	/**
	 * Formas singular y plural del contador con texto (Storefront: "%d item" / "%d items"), traducidas
	 * con el dominio del tema. Null si el tema no lleva texto en el contador.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public static function plural( string $theme ): ?array {
		if ( 'storefront' !== $theme ) {
			return null;
		}
		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- textos del tema, con su dominio.
		return [
			/* translators: %d: número de productos en el carrito (texto del tema Storefront). */
			_n( '%d item', '%d items', 1, 'storefront' ),
			/* translators: %d: número de productos en el carrito (texto del tema Storefront). */
			_n( '%d item', '%d items', 2, 'storefront' ),
		];
		// phpcs:enable WordPress.WP.I18n.TextDomainMismatch
	}
}
