<?php
/**
 * Descarta las páginas precargadas cuando cambia el carrito o la sesión (0.14.0).
 *
 * Una página precargada (prefetch o prerender) antes de agregar un producto, iniciar o cerrar sesión
 * puede mostrar el estado anterior al abrirla. Shopify envía en sus endpoints de carrito
 * `Clear-Site-Data: "prefetchCache", "prerenderCache"` (shopify.dev, use-speculation-rules); Chrome
 * 138+ descarta entonces lo precargado y los demás navegadores ignoran la cabecera. No se usa
 * "cache": vaciaría toda la caché HTTP del sitio en el navegador.
 *
 * Solo se envía en respuestas que ya mutan estado (nunca cacheables).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Speculation;

/**
 * Hooks de WooCommerce y de la sesión de WordPress.
 */
final class ClearSpeculations {

	public const HEADER = 'Clear-Site-Data: "prefetchCache", "prerenderCache"';

	/** Acciones que cambian lo que muestra una página del sitio. */
	public const ACTIONS = [
		'woocommerce_add_to_cart',
		'woocommerce_cart_item_removed',
		'woocommerce_after_cart_item_quantity_update',
		'woocommerce_cart_emptied',
		'woocommerce_applied_coupon',
		'woocommerce_removed_coupon',
		'wp_login',
		'wp_logout',
	];

	private bool $sent = false;

	public function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( $action, [ $this, 'send' ], PHP_INT_MAX, 0 );
		}
	}

	public function send(): void {
		if ( $this->sent || headers_sent() ) {
			return;
		}
		$this->sent = true;
		header( self::HEADER, false );
	}
}
