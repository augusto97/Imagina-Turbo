<?php
/**
 * Carrito por Store API (fase 3, docs/02 módulo 3). Riesgo moderado, apagado por defecto.
 *
 * Hace cacheable el catálogo aunque el visitante tenga carrito:
 * - Reemplaza wc-cart-fragments (el pedido a wc-ajax=get_refreshed_fragments en cada página) por
 *   itb-cart.js, que lee GET /wc/store/v1/cart y actualiza contador y total. Se registra con el mismo
 *   handle: los scripts del tema que dependen de él siguen cargando, sin el pedido sin caché.
 * - Las cookies de carrito dejan de impedir la caché (CacheModule y la regla de Cloudflare).
 * - wc_track_product_view (Set-Cookie en cada página de producto si está el widget de vistos) pasa al
 *   navegador: la página ya no envía cookies y se puede cachear.
 *
 * Sin output buffering: todo por hooks de scripts (regla 2).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\WooCart;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Runtime;

/**
 * Registro del módulo.
 */
final class WooCartModule implements ModuleInterface {

	public const HANDLE = 'wc-cart-fragments';

	public const COOKIE = 'woocommerce_items_in_cart';

	public function register( Container $container, RequestContext $request ): void {
		if ( ! in_array( $request->type, [ RequestType::FrontendHtml, RequestType::Cart, RequestType::Account ], true ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', [ $this, 'replaceFragments' ], PHP_INT_MAX );
		if ( RequestType::FrontendHtml === $request->type && self::settings()['recently_viewed'] ) {
			add_action( 'template_redirect', [ $this, 'moveProductViewToBrowser' ], 0 );
		}
	}

	/**
	 * @return array{count: list<string>, total: list<string>, menu: list<string>, recently_viewed: bool, theme: string, tax_incl: bool}
	 */
	public static function settings(): array {
		$woo  = Runtime::$config?->get( 'woo', [] );
		$woo  = is_array( $woo ) ? $woo : [];
		$list = static fn( mixed $v ): array => is_array( $v ) ? array_values( array_filter( $v, 'is_string' ) ) : [];
		return [
			'count'           => $list( $woo['count'] ?? [] ),
			'total'           => $list( $woo['total'] ?? [] ),
			'menu'            => $list( $woo['menu'] ?? [] ),
			'recently_viewed' => false !== ( $woo['recently_viewed'] ?? true ),
			'theme'           => is_string( $woo['theme'] ?? null ) ? $woo['theme'] : '',
			'tax_incl'        => true === ( $woo['tax_incl'] ?? false ),
		];
	}

	public function replaceFragments(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		wp_deregister_script( self::HANDLE );
		$dir  = dirname( __DIR__, 3 );
		$file = is_file( $dir . '/assets/js/itb-cart.min.js' ) ? 'itb-cart.min.js' : 'itb-cart.js';
		wp_register_script( self::HANDLE, plugins_url( 'assets/js/' . $file, $dir . '/imagina-turbo.php' ), [], ITB_VERSION, [ 'in_footer' => true ] );
		// Siempre: el contador del tema necesita corregirse en páginas cacheadas aunque el tema no
		// encole fragments por su cuenta (2,8 KB, sin dependencias).
		wp_enqueue_script( self::HANDLE );
		$config = $this->config();
		wp_add_inline_script( self::HANDLE, 'var itbCart=' . wp_json_encode( $config ) . ';', 'before' );
		$product = is_int( $config['v'] ) ? $config['v'] : 0;
		if ( $product > 0 ) {
			wp_add_inline_script( self::HANDLE, self::recentlyViewedScript( $product, is_string( $config['p'] ) ? $config['p'] : '/' ), 'after' );
		}
	}

	/**
	 * Configuración para itb-cart.js.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$settings = self::settings();
		$product  = 0;
		if ( $settings['recently_viewed'] && is_singular( 'product' ) && self::recentlyViewedWidget() ) {
			$product = get_queried_object_id();
		}
		return [
			'api' => esc_url_raw( rest_url( 'wc/store/v1/cart' ) ),
			'c'   => self::COOKIE,
			'k'   => substr( md5( home_url( '/' ) ), 0, 8 ),
			'n'   => $settings['count'],
			't'   => $settings['total'],
			'm'   => $settings['menu'],
			'w'   => class_exists( 'WC_AJAX' ) ? esc_url_raw( \WC_AJAX::get_endpoint( 'get_refreshed_fragments' ) ) : '',
			'z'   => html_entity_decode( wp_strip_all_tags( function_exists( 'wc_price' ) ? wc_price( 0 ) : '0' ), ENT_QUOTES, 'UTF-8' ),
			'v'   => $product,
			'x'   => $settings['tax_incl'],
			'f'   => ThemeSelectors::plural( $settings['theme'] ),
			'p'   => defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
		];
	}

	/**
	 * El widget de productos vistos lee la cookie woocommerce_recently_viewed. WooCommerce la escribe
	 * con Set-Cookie en cada página de producto (wc_track_product_view), lo que impide cachearlas: la
	 * escribe el navegador con la misma lógica (máximo 15, el último al final).
	 */
	public function moveProductViewToBrowser(): void {
		if ( function_exists( 'wc_track_product_view' ) ) {
			remove_action( 'template_redirect', 'wc_track_product_view', 20 );
		}
	}

	/**
	 * La misma lógica que wc_track_product_view, en el navegador: el producto al final, máximo 15, sin
	 * repetir. PHP lee la cookie igual (urldecode de "12|15").
	 */
	public static function recentlyViewedScript( int $productId, string $path ): string {
		return '(function(){var p=' . (int) $productId . ',m=("; "+document.cookie).match(/; woocommerce_recently_viewed=([^;]*)/),'
			. 'ids=m?decodeURIComponent(m[1]).split("|").filter(function(i){return i&&i!=String(p)}):[];ids.push(String(p));'
			. 'document.cookie="woocommerce_recently_viewed="+encodeURIComponent(ids.slice(-15).join("|"))+"; path="+' . wp_json_encode( $path )
			. '+(location.protocol==="https:"?"; secure":"")+"; samesite=lax"})();';
	}

	private static function recentlyViewedWidget(): bool {
		return (bool) is_active_widget( false, false, 'woocommerce_recently_viewed_products', true );
	}
}
