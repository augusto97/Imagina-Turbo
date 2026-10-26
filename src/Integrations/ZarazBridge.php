<?php
/**
 * Zaraz en el sitio (0.18.0): apaga las etiquetas locales que Zaraz reemplaza y le pasa los eventos
 * de WooCommerce.
 *
 * Solo después de configurar Zaraz desde el admin (CloudflareService::zarazApply), que deja en la
 * config compilada qué tomó: ga4 y meta.
 * - En todas las peticiones, no solo las que llegan con CF-Ray: el origen y el borde comparten la caché
 *   de páginas (el rastreador de LSCache y el recalentado visitan el origen directo), y una página
 *   guardada con las etiquetas del sitio contaría dos veces en Cloudflare. Si Zaraz pierde las
 *   herramientas, se apaga o la zona se pausa, la tarea horaria devuelve las etiquetas (Plugin).
 * - GA4 de Site Kit: googlesitekit_analytics-4_tag_blocked. Meta for WooCommerce:
 *   facebook_for_woocommerce_integration_pixel_enabled en falso (píxel y API de conversiones), en
 *   todos los tipos de petición por los que pasa (también el checkout y el admin).
 * - E-commerce: en la ficha, el carrito, el pago y la página de pedido recibido, los datos de
 *   zaraz.ecommerce (Product Viewed, Cart Viewed, Checkout Started, Order Completed) y itb-zaraz.js
 *   (1,3 KB) que los envía, más "Product Added" en el navegador. El pedido solo se informa con su
 *   clave (la de la URL de la página de gracias).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Integrations;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;
use ImaginaTurbo\Modules\CacheCoordinator\Purger;

/**
 * Registro y datos.
 */
final class ZarazBridge {

	/**
	 * @param array{ga4: bool, meta: bool} $takeover Lo que Zaraz reemplaza.
	 */
	public function __construct( private readonly array $takeover ) {
	}

	/**
	 * @return array{ga4: bool, meta: bool}
	 */
	public static function compiled(): array {
		$z = Runtime::$config?->get( 'zaraz', [] );
		$z = is_array( $z ) ? $z : [];
		return [
			'ga4'  => true === ( $z['ga4'] ?? false ),
			'meta' => true === ( $z['meta'] ?? false ),
		];
	}

	/**
	 * Cambia lo que Zaraz reemplaza: ajuste, config compilada y purga total (las páginas guardadas
	 * llevan las etiquetas de antes).
	 *
	 * @param array{ga4: bool, meta: bool} $takeover Nuevo estado.
	 */
	public static function setTakeover( Container $container, array $takeover, string $reason ): void {
		$container->get( Settings::class )->update( [ 'zaraz_takeover' => $takeover ] );
		$container->get( ConfigCompiler::class )->compile();
		$container->get( Purger::class )->purge( PurgeRequest::all( $reason ) );
	}

	public function register( bool $html ): void {
		if ( ! ( $this->takeover['ga4'] || $this->takeover['meta'] ) ) {
			return;
		}
		if ( $this->takeover['ga4'] ) {
			add_filter( 'googlesitekit_analytics-4_tag_blocked', '__return_true' );
		}
		if ( $this->takeover['meta'] ) {
			add_filter( 'facebook_for_woocommerce_integration_pixel_enabled', '__return_false' );
		}
		// WooCommerce carga después que este plugin: se comprueba al imprimir, no al registrar.
		if ( $html ) {
			add_action( 'wp_footer', [ $this, 'printData' ], 50 );
		}
	}

	public function printData(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$data = [
			'currency' => get_woocommerce_currency(),
			'events'   => [],
		];
		if ( is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product instanceof \WC_Product ) {
				$data['product']  = self::product( $product );
				$data['events'][] = [ 'Product Viewed', $data['product'] + [ 'currency' => $data['currency'] ] ];
			}
		} elseif ( is_order_received_page() ) {
			$order = self::receivedOrder();
			if ( null !== $order ) {
				$data['events'][] = [ 'Order Completed', self::order( $order ) ];
			}
		} elseif ( is_cart() || is_checkout() ) {
			$data['events'][] = [ is_cart() ? 'Cart Viewed' : 'Checkout Started', self::cart( $data['currency'] ) ];
		}
		$file = is_file( Runtime::$pluginDir . '/assets/js/itb-zaraz.min.js' ) ? 'itb-zaraz.min.js' : 'itb-zaraz.js';
		$code = (string) file_get_contents( Runtime::$pluginDir . '/assets/js/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo propio, < 2 KB.
		wp_print_inline_script_tag(
			'window.itbZaraz=' . wp_json_encode( $data ) . ';' . $code,
			[
				'id'               => 'itb-zaraz',
				'data-no-defer'    => '1',
				'data-no-optimize' => '1',
				'nowprocket'       => true,
			]
		);
	}

	/**
	 * @return array{product_id: string, sku: string, name: string, price: float, category: string}
	 */
	public static function product( \WC_Product $product ): array {
		$terms = get_the_terms( $product->get_parent_id() ?: $product->get_id(), 'product_cat' );
		return [
			'product_id' => (string) $product->get_id(),
			'sku'        => (string) $product->get_sku(),
			'name'       => wp_strip_all_tags( $product->get_name() ),
			'price'      => (float) wc_get_price_to_display( $product ),
			'category'   => is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ? $terms[0]->name : '',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function cart( string $currency ): array {
		$cart     = WC()->cart;
		$products = [];
		if ( null !== $cart ) {
			foreach ( $cart->get_cart() as $item ) {
				$product = is_array( $item ) && ( $item['data'] ?? null ) instanceof \WC_Product ? $item['data'] : null;
				if ( null !== $product ) {
					$products[] = self::product( $product ) + [ 'quantity' => (int) ( $item['quantity'] ?? 1 ) ];
				}
			}
		}
		return [
			'products' => array_slice( $products, 0, 50 ),
			'value'    => null !== $cart ? (float) $cart->get_total( 'edit' ) : 0.0,
			'currency' => $currency,
		];
	}

	/**
	 * El pedido de la página de gracias, solo con su clave correcta.
	 */
	private static function receivedOrder(): ?\WC_Order {
		$id    = absint( get_query_var( 'order-received' ) );
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- la clave del pedido es la autorización (igual que WooCommerce).
		$order = $id > 0 ? wc_get_order( $id ) : false;
		return $order instanceof \WC_Order && '' !== $key && hash_equals( $order->get_order_key(), $key ) ? $order : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function order( \WC_Order $order ): array {
		$products = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			if ( $product instanceof \WC_Product ) {
				$products[] = self::product( $product ) + [ 'quantity' => (int) $item->get_quantity() ];
			}
		}
		return [
			'order_id' => (string) $order->get_order_number(),
			'total'    => (float) $order->get_total(),
			'revenue'  => (float) $order->get_total() - (float) $order->get_total_tax() - (float) $order->get_shipping_total(),
			'shipping' => (float) $order->get_shipping_total(),
			'tax'      => (float) $order->get_total_tax(),
			'discount' => (float) $order->get_discount_total(),
			'coupon'   => implode( ',', $order->get_coupon_codes() ),
			'currency' => $order->get_currency(),
			'products' => array_slice( $products, 0, 50 ),
		];
	}
}
