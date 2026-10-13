<?php
/**
 * WooCommerce: GET /woo/status, PUT /woo/settings, POST /woo/inspect.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\CacheCoordinator\HeaderEmitter;
use ImaginaTurbo\Modules\CacheCoordinator\Warmer;
use ImaginaTurbo\Modules\WooCart\ThemeSelectors;
use ImaginaTurbo\Modules\WooCart\WooCartModule;
use ImaginaTurbo\Support\CloudflareAccess;

/**
 * Carrito por Store API.
 */
final class WooController extends Controller {

	public function registerRoutes(): void {
		$this->route( '/woo/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$selectors = [
			'type'     => 'array',
			'items'    => [
				'type'      => 'string',
				'maxLength' => 200,
			],
			'maxItems' => 20,
		];
		$this->route(
			'/woo/settings',
			'PUT',
			[ $this, 'update' ],
			[
				'count_selectors' => $selectors,
				'total_selectors' => $selectors,
				'recently_viewed' => [ 'type' => 'boolean' ],
			]
		);
		$this->route( '/woo/inspect', \WP_REST_Server::CREATABLE, [ $this, 'inspect' ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$settings = $this->container->get( Settings::class );
		$compiled = WooCartModule::settings();
		$enabled  = false;
		foreach ( $this->container->get( Diagnostics::class )->modules() as $module ) {
			if ( 'woo_cart' === ( $module['id'] ?? null ) ) {
				$enabled = true === ( $module['enabled'] ?? false );
			}
		}
		$theme = $compiled['theme'];
		$map   = ThemeSelectors::map();
		$count = $settings->get( 'woo_count_selectors', [] );
		$total = $settings->get( 'woo_total_selectors', [] );
		return [
			'woocommerce'     => class_exists( 'WooCommerce' ),
			'enabled'         => $enabled,
			'theme'           => [
				'slug'     => '' !== $theme ? $theme : get_template(),
				'known'    => '' !== $theme,
				'verified' => '' !== $theme && $map[ $theme ]['verified'],
			],
			'selectors'       => [
				'count' => $compiled['count'],
				'total' => $compiled['total'],
				'menu'  => $compiled['menu'],
			],
			'custom'          => [
				'count' => is_array( $count ) ? array_values( $count ) : [],
				'total' => is_array( $total ) ? array_values( $total ) : [],
			],
			'recently_viewed' => (bool) $settings->get( 'woo_recently_viewed', true ),
			'geolocation'     => 'geolocation_ajax' === get_option( 'woocommerce_default_customer_address', '' ),
			'tax_incl'        => $compiled['tax_incl'],
			'known_themes'    => array_keys( $map ),
		];
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		$changes = [];
		foreach ( [
			'count_selectors' => 'woo_count_selectors',
			'total_selectors' => 'woo_total_selectors',
			'recently_viewed' => 'woo_recently_viewed',
		] as $param => $key ) {
			if ( null !== $request->get_param( $param ) ) {
				$changes[ $key ] = $request->get_param( $param );
			}
		}
		$this->container->get( Settings::class )->update( $changes );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info( 'woo_cart', 'settings', __( 'Ajustes del carrito actualizados.', 'imagina-turbo' ), [ 'user' => get_current_user_id() ] );
		return $this->ok( $this->status() );
	}

	/**
	 * Pide la portada como visitante sin carrito y con carrito: la primera puede guardarse en el edge;
	 * la segunda nunca (su HTML lleva su mini-carrito), y ninguna carga cart-fragments original.
	 */
	public function inspect(): \WP_REST_Response {
		$url     = home_url( '/' );
		$results = [];
		foreach ( [
			'empty' => [],
			'cart'  => [ WooCartModule::COOKIE => '1' ],
		] as $case => $cookies ) {
			$response = wp_remote_get(
				add_query_arg( 'itb_inspect', (string) wp_rand(), $url ),
				[
					'timeout'     => 15,
					'redirection' => 0,
					'cookies'     => $cookies,
					'user-agent'  => Warmer::USER_AGENT,
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
				]
			);
			if ( is_wp_error( $response ) ) {
				$results[ $case ] = [ 'error' => $response->get_error_message() ];
				continue;
			}
			if ( CloudflareAccess::blockedResponse( $response ) ) {
				$results[ $case ] = [ 'error' => CloudflareAccess::message( $url ) ];
				continue;
			}
			$body             = (string) wp_remote_retrieve_body( $response );
			$edge             = wp_remote_retrieve_header( $response, strtolower( HeaderEmitter::HEADER ) );
			$results[ $case ] = [
				'status'      => (int) wp_remote_retrieve_response_code( $response ),
				'edge'        => is_array( $edge ) ? implode( ', ', $edge ) : (string) $edge,
				'script'      => str_contains( $body, 'itb-cart' ) && str_contains( $body, 'var itbCart=' ),
				'fragments'   => str_contains( $body, 'cart-fragments.min.js' ) || str_contains( $body, 'cart-fragments.js' ),
				'set_cookies' => array_map( static fn( $c ): string => $c->name, wp_remote_retrieve_cookies( $response ) ),
			];
		}
		return $this->ok( $results );
	}
}
