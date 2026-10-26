<?php
/**
 * Coordinador de caché (fase 2a). Riesgo moderado, apagado por defecto.
 *
 * Dos modos:
 * - `sync` (al activarlo): sincroniza purgas con el plugin de caché, agrega nuestros tags a LSCache,
 *   recalienta y corrige nonces en páginas cacheadas. No emite cabeceras al edge.
 * - `headers`: además emite Cloudflare-CDN-Cache-Control y Cache-Tag (se activa tras verificar).
 *
 * En el frontend solo registra dos hooks; todo lo demás vive en admin, REST, AJAX y cron, que es
 * donde ocurren los cambios que purgan.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Integrations\SearchaniseRecentlyViewed;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\CacheAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\WpRocketAdapter;

/**
 * Registro del módulo.
 */
final class CacheModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
		$type = $request->type;
		if ( RequestType::StaticMiss === $type || RequestType::Robots === $type ) {
			return;
		}

		$settings = self::settings();

		// Carrito, checkout, cuenta y login también: salen con no-store para el edge (defensa adicional a
		// la regla de Cloudflare, que ya los excluye).
		if ( in_array( $type, [ RequestType::FrontendHtml, RequestType::Feed, RequestType::Sitemap, RequestType::Cart, RequestType::Checkout, RequestType::Account, RequestType::Login ], true ) ) {
			$bypass = Runtime::$config?->get( 'bypass_cookies', [] );
			// Quien tiene carrito nunca genera la copia que se guarda (su HTML puede llevar sus productos en el
			// mini-carrito). Con WooCart sí puede RECIBIR páginas cacheadas: eso lo decide la regla del edge.
			$cart = Runtime::$config?->get( 'cart_cookies', [] );
			$vary = Runtime::$config?->get( 'vary_cookies', [] );
			// Una cookie de variación (moneda, idioma) o de carrito se trata como bypass en el edge. Con
			// variantes en el edge (0.18.0) las de nombre exacto van en la llave: ya no lo impiden.
			$vary    = is_array( $vary ) ? array_values( array_filter( $vary, 'is_string' ) ) : [];
			$vary    = $settings['edge_variants'] ? array_values( array_filter( $vary, static fn( string $c ): bool => 1 !== preg_match( '/^[A-Za-z0-9_\-.]{2,80}$/', $c ) ) ) : $vary;
			$cookies = array_values( array_filter( array_merge( is_array( $bypass ) ? $bypass : [], is_array( $cart ) ? $cart : [], $vary ), 'is_string' ) );

			$onCacheable = null;
			if ( RequestType::FrontendHtml === $type && $settings['nonce_refresh'] ) {
				$onCacheable = static function () use ( $container ): void {
					$container->get( NonceRefresh::class )->start();
				};
			}
			$cartCookies = array_values( array_filter( is_array( $cart ) ? $cart : [], 'is_string' ) );
			( new HeaderEmitter( $settings, $cookies, $container->get( TagBuilder::class ), $container->get( CacheAdapter::class ), $request, $onCacheable, $cartCookies ) )->register();
		}

		// Caché de fragmentos (0.17.0): menús en las páginas que se renderizan; la generación se invalida
		// donde cambia el contenido (admin, REST, AJAX, cron, checkout).
		// El verificador del admin la prueba también apagada (cookie firmada + ?itb_frag=).
		$fragments = Runtime::$config?->get( 'fragments', [] );
		$fragOn    = is_array( $fragments ) && true === ( $fragments['on'] ?? false );
		if ( ( $fragOn || isset( $_GET['itb_frag'] ) ) && in_array( $type, [ RequestType::FrontendHtml, RequestType::Cart, RequestType::Checkout, RequestType::Account, RequestType::Login ], true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- la autorización es la cookie firmada.
			$vary   = Runtime::$config?->get( 'vary_cookies', [] );
			$ignore = is_array( $fragments ) && is_array( $fragments['ignore'] ?? null ) ? array_values( array_filter( $fragments['ignore'], 'is_string' ) ) : [];
			( new FragmentCache( is_array( $vary ) ? array_values( array_filter( $vary, 'is_string' ) ) : [], $fragOn, $ignore ) )->register();
		}
		if ( RequestType::FrontendHtml !== $type ) {
			FragmentCache::registerFlush();
		}

		// Searchanise escribe los productos vistos con Set-Cookie y los imprime en la página: al navegador.
		if ( RequestType::FrontendHtml === $type ) {
			( new SearchaniseRecentlyViewed() )->register();
		}

		// Sesión de WooCommerce de un invitado: itb-nonce.js refresca sus nonces woocommerce* al cargar.
		if ( $settings['nonce_refresh'] && ! in_array( $type, [ RequestType::Cron, RequestType::Cli, RequestType::Admin ], true ) ) {
			( new SessionMarker() )->register();
		}

		// Purgas: los cambios llegan desde admin, REST, AJAX, cron y, en tiendas, desde el checkout.
		$purger = static fn(): Purger => $container->get( Purger::class );
		( new PurgeMap( $purger, $settings['purge_all_hooks'] ) )->register();
		$container->get( CacheAdapter::class )->listen( static fn( PurgeRequest $r ) => $purger()->mirror( $r ) );

		$adapter = $container->get( CacheAdapter::class );
		if ( $adapter instanceof WpRocketAdapter && in_array( $type, [ RequestType::Admin, RequestType::AdminAjax, RequestType::Cron, RequestType::Cli ], true ) ) {
			$adapter->registerHtaccess();
		}
	}

	/**
	 * Ajustes compilados con valores por defecto.
	 *
	 * @return array{mode: string, edge_ttl: int, swr: int, sie: int, nonce_refresh: bool, warm: bool, adapter: string, server: string, edge_html: bool, mobile_split: bool, edge_variants: bool, purge_all_hooks: list<string>, sitemaps: list<string>, preload_exclude: list<string>, bypass_paths: list<string>}
	 */
	public static function settings(): array {
		$cache = Runtime::$config?->get( 'cache', [] );
		$cache = is_array( $cache ) ? $cache : [];
		$list  = static fn( mixed $v ): array => is_array( $v ) ? array_values( array_filter( $v, 'is_string' ) ) : [];
		return [
			'mode'            => 'headers' === ( $cache['mode'] ?? '' ) ? 'headers' : 'sync',
			'edge_ttl'        => is_int( $cache['edge_ttl'] ?? null ) ? $cache['edge_ttl'] : DAY_IN_SECONDS,
			'swr'             => is_int( $cache['swr'] ?? null ) ? $cache['swr'] : DAY_IN_SECONDS,
			'sie'             => is_int( $cache['sie'] ?? null ) ? $cache['sie'] : 3 * DAY_IN_SECONDS,
			'nonce_refresh'   => false !== ( $cache['nonce_refresh'] ?? true ),
			'warm'            => false !== ( $cache['warm'] ?? true ),
			'adapter'         => is_string( $cache['adapter'] ?? null ) ? $cache['adapter'] : 'none',
			'server'          => is_string( $cache['server'] ?? null ) ? $cache['server'] : 'unknown',
			'edge_html'       => false !== ( $cache['edge_html'] ?? true ),
			'mobile_split'    => true === ( $cache['mobile_split'] ?? false ),
			'edge_variants'   => true === ( $cache['edge_variants'] ?? false ),
			'purge_all_hooks' => $list( $cache['purge_all_hooks'] ?? [] ),
			'sitemaps'        => $list( $cache['sitemaps'] ?? [] ),
			'preload_exclude' => $list( $cache['preload_exclude'] ?? [] ),
			'bypass_paths'    => $list( $cache['bypass_paths'] ?? [] ),
		];
	}
}
