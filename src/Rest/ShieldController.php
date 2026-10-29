<?php
/**
 * Escudo: GET /shield/status, PUT /shield/settings, POST /shield/test.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\CacheCoordinator\Purger;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;
use ImaginaTurbo\Modules\CacheCoordinator\Warmer;
use ImaginaTurbo\Modules\Shield\ShieldModule;
use ImaginaTurbo\Support\CloudflareAccess;

/**
 * Ajustes y prueba del escudo.
 */
final class ShieldController extends Controller {

	private const BOOLS = [ 'static', 'author', 'xmlrpc', 'rest_users', 'heartbeat', 'prefetch', 'facets', 'facets_views', 'facets_robots' ];

	/** User agent de la prueba de combinaciones: se identifica como crawler por su enlace, sin suplantar a nadie. */
	private const CRAWLER_TEST_UA = 'ImaginaTurbo-ShieldTest/1.0 (+https://imaginawp.com)';

	public function registerRoutes(): void {
		$this->route( '/shield/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$args = [];
		foreach ( self::BOOLS as $key ) {
			$args[ $key ] = [ 'type' => 'boolean' ];
		}
		$args['heartbeat_admin']  = [
			'type'    => 'integer',
			'minimum' => 15,
			'maximum' => 120,
		];
		$args['heartbeat_editor'] = [
			'type'    => 'integer',
			'minimum' => 15,
			'maximum' => 120,
		];
		$args['filter_limit']     = [
			'type'    => 'integer',
			'minimum' => 0,
			'maximum' => 20,
		];
		$this->route( '/shield/settings', 'PUT', [ $this, 'update' ], $args );
		$this->route( '/shield/test', \WP_REST_Server::CREATABLE, [ $this, 'test' ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$settings = $this->container->get( Settings::class );
		$compiled = Runtime::$config?->get( 'shield', [] );
		$compiled = is_array( $compiled ) ? $compiled : [];
		$enabled  = false;
		foreach ( $this->container->get( Diagnostics::class )->modules() as $module ) {
			if ( 'shield' === ( $module['id'] ?? null ) ) {
				$enabled = true === ( $module['enabled'] ?? false );
			}
		}
		$values = [];
		foreach ( self::BOOLS as $key ) {
			$values[ $key ] = (bool) $settings->get( 'shield_' . $key, ! in_array( $key, [ 'facets', 'facets_robots' ], true ) );
		}
		$values['heartbeat_admin']  = (int) $settings->get( 'shield_heartbeat_admin', 60 );
		$values['heartbeat_editor'] = (int) $settings->get( 'shield_heartbeat_editor', 15 );
		$values['filter_limit']     = (int) $settings->get( 'shield_filter_limit', 3 );
		$required                   = is_array( $compiled['required'] ?? null ) ? $compiled['required'] : [];
		return [
			'enabled'  => $enabled,
			'settings' => $values,
			'required' => [
				'xmlrpc'     => true === ( $required['xmlrpc'] ?? false ),
				'rest_users' => true === ( $required['rest_users'] ?? false ),
			],
			// robots.txt (0.21.0): uno físico no pasa por WordPress; entonces las reglas se copian a mano.
			'robots'   => [
				'url'      => home_url( '/robots.txt' ),
				'physical' => file_exists( ABSPATH . 'robots.txt' ),
				'rules'    => ShieldModule::robotsRules( $values['facets_views'] ),
			],
			'cron'     => [
				'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'command'  => '*/5 * * * * curl -fsS ' . site_url( 'wp-cron.php?doing_wp_cron' ) . ' >/dev/null 2>&1',
				'wp_cli'   => '*/5 * * * * cd ' . untrailingslashit( ABSPATH ) . ' && wp cron event run --due-now >/dev/null 2>&1',
			],
		];
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		$changes = [];
		foreach ( array_merge( self::BOOLS, [ 'heartbeat_admin', 'heartbeat_editor', 'filter_limit' ] ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$changes[ 'shield_' . $key ] = $request->get_param( $key );
			}
		}
		$this->container->get( Settings::class )->update( $changes );
		$this->container->get( ConfigCompiler::class )->compile();
		// El robots.txt queda en la caché del origen y de Cloudflare: se purga si cambian sus reglas.
		if ( isset( $changes['shield_facets_robots'] ) || isset( $changes['shield_facets_views'] ) ) {
			$this->container->get( Purger::class )->purge( new PurgeRequest( [], [ home_url( '/robots.txt' ) ], false, 'robots' ) );
		}
		$this->container->get( EventLog::class )->info(
			'shield',
			'settings',
			__( 'Ajustes del escudo actualizados.', 'imagina-turbo' ),
			[
				'user'    => get_current_user_id(),
				'changes' => $changes,
			]
		);
		return $this->ok( $this->status() );
	}

	/**
	 * Pide como anónimo un asset inexistente, ?author=1, xmlrpc.php, /wp/v2/users y, con la guardia de
	 * filtros activa, una combinación como crawler: muestra qué responde
	 * el escudo y cuánto tarda (un 404 de imagen debe resolverse sin cargar plugins).
	 */
	public function test(): \WP_REST_Response {
		$rest  = rest_get_url_prefix();
		$cases = [
			'static'     => home_url( '/wp-content/uploads/itb-no-existe-' . wp_rand() . '.jpg' ),
			'author'     => add_query_arg( 'author', '1', home_url( '/' ) ),
			'xmlrpc'     => site_url( 'xmlrpc.php' ),
			'rest_users' => home_url( '/' . $rest . '/wp/v2/users' ),
		];
		// Combinación de dos filtros pedida como crawler: con la guardia activa responde 403 sin cargar nada.
		if ( true === $this->container->get( Settings::class )->get( 'shield_facets', false ) ) {
			$shop            = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' );
			$cases['facets'] = add_query_arg(
				[
					'filter_itb_a' => 'x',
					'filter_itb_b' => 'y',
				],
				$shop
			);
		}
		$out = [];
		foreach ( $cases as $key => $url ) {
			$start = microtime( true );
			$args  = [
				'method'      => 'xmlrpc' === $key ? 'POST' : 'GET',
				'timeout'     => 10,
				'redirection' => 0,
				'user-agent'  => 'facets' === $key ? self::CRAWLER_TEST_UA : Warmer::USER_AGENT,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
			];
			if ( 'xmlrpc' === $key ) {
				$args['body'] = '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>';
			}
			$response = wp_remote_request( $url, $args );
			if ( CloudflareAccess::blockedResponse( $response ) ) {
				$out[ $key ] = [ 'error' => CloudflareAccess::message( $url ) ];
				continue;
			}
			$out[ $key ] = is_wp_error( $response ) ? [ 'error' => $response->get_error_message() ] : [
				'status' => (int) wp_remote_retrieve_response_code( $response ),
				'shield' => implode( ', ', (array) wp_remote_retrieve_header( $response, 'x-itb-shield' ) ),
				'ms'     => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'url'    => $url,
			];
		}
		return $this->ok( $out );
	}
}
