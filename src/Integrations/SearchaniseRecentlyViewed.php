<?php
/**
 * Searchanise (Smart Search & Product Filter, smart-search-for-woocommerce): productos vistos en el
 * navegador (0.14.2).
 *
 * El plugin (1.0.21, includes/helpers/class-api.php) escribe la cookie se-recently-viewed-products con
 * wc_setcookie en cada página de producto (template_redirect) y pone su valor en
 * searchanise_options.recentlyViewedProducts (wp_localize_script). La página quedaba personal: LiteSpeed
 * Cache la guardaba sin la cookie pero con la lista de quien la regeneró (y se la mostraba a todos), e
 * Imagina Turbo la marcaba no cacheable para el edge, sin tags ni refresco de nonces.
 *
 * Igual que con woocommerce_recently_viewed (WooCart): el servidor no escribe la cookie ni imprime su
 * valor; un script en línea antes del de Searchanise la actualiza en el navegador con la misma lógica
 * (el producto primero, sin repetir, máximo 20, 180 días) y completa la configuración con la lista
 * anterior a esta vista, como hacía el servidor.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Integrations;

/**
 * Hooks del frontend (solo si Searchanise está activo).
 */
final class SearchaniseRecentlyViewed {

	public const COOKIE = 'se-recently-viewed-products';

	public const HANDLE = 'searchanise-widgets';

	public const LIMIT = 20;

	public function register(): void {
		add_filter( 'woocommerce_set_cookie_enabled', [ self::class, 'blockCookie' ], 10, 2 );
		add_action( 'template_redirect', [ self::class, 'neutralize' ], 0 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'addScript' ], 20 );
	}

	public static function active(): bool {
		return defined( 'SE_PLUGIN_VERSION' );
	}

	/**
	 * @param mixed $enabled Si WooCommerce escribe la cookie.
	 * @param mixed $name    Nombre.
	 * @return mixed
	 */
	public static function blockCookie( mixed $enabled, mixed $name = '' ): mixed {
		return self::COOKIE === $name && self::active() ? false : $enabled;
	}

	/**
	 * La página se genera sin la lista del visitante (la búsqueda, que no se cachea, la conserva).
	 */
	public static function neutralize(): void {
		if ( self::active() && ! is_search() ) {
			unset( $_COOKIE[ self::COOKIE ] );
		}
	}

	public static function addScript(): void {
		if ( ! self::active() || ! wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		$product = function_exists( 'is_product' ) && is_product() ? get_queried_object_id() : 0;
		$path    = defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		wp_add_inline_script( self::HANDLE, self::script( (int) $product, $path ), 'before' );
	}

	public static function script( int $productId, string $path ): string {
		return '(function(p,path){var n=' . wp_json_encode( self::COOKIE ) . ',m=("; "+document.cookie).match(/; se-recently-viewed-products=([^;]*)/),'
			. 'ids=m?decodeURIComponent(m[1]).split(",").filter(Boolean):[];'
			. 'if(window.searchanise_options){searchanise_options.recentlyViewedProducts=ids.join(",")}'
			. 'if(p){ids=ids.filter(function(i){return i!==String(p)});ids.unshift(String(p));'
			. 'document.cookie=n+"="+encodeURIComponent(ids.slice(0,' . self::LIMIT . ').join(","))+"; path="+path+"; max-age=15552000"+(location.protocol==="https:"?"; secure":"")+"; samesite=lax"}'
			. '})(' . $productId . ',' . wp_json_encode( $path ) . ');';
	}
}
