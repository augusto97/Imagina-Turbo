<?php
/**
 * Caché: GET /cache/status, PUT /cache/settings, POST /cache/purge, POST /cache/warm, POST /cache/inspect.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\CacheAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\WpRocketAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\CacheModule;
use ImaginaTurbo\Modules\CacheCoordinator\Edge\EdgePurger;
use ImaginaTurbo\Modules\CacheCoordinator\HeaderEmitter;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeWorker;
use ImaginaTurbo\Modules\CacheCoordinator\Purger;
use ImaginaTurbo\Modules\CacheCoordinator\TagBuilder;
use ImaginaTurbo\Modules\CacheCoordinator\Warmer;
use ImaginaTurbo\Repository\PurgeQueueRepository;
use ImaginaTurbo\Support\CloudflareAccess;

/**
 * Estado, ajustes y acciones de caché.
 */
final class CacheController extends Controller {

	public function registerRoutes(): void {
		$this->route( '/cache/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$this->route(
			'/cache/settings',
			'PUT',
			[ $this, 'updateSettings' ],
			[
				'mode'          => [
					'type' => 'string',
					'enum' => [ 'sync', 'headers' ],
				],
				'edge_ttl'      => [
					'type'    => 'integer',
					'minimum' => 60,
					'maximum' => YEAR_IN_SECONDS,
				],
				'swr'           => [
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 7 * DAY_IN_SECONDS,
				],
				'sie'           => [
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 30 * DAY_IN_SECONDS,
				],
				'nonce_refresh' => [ 'type' => 'boolean' ],
				'warm'          => [ 'type' => 'boolean' ],
				'confirm'       => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route(
			'/cache/purge',
			\WP_REST_Server::CREATABLE,
			[ $this, 'purge' ],
			[
				'type'    => [
					'type'     => 'string',
					'enum'     => [ 'all', 'url', 'tag' ],
					'required' => true,
				],
				'value'   => [
					'type'      => 'string',
					'maxLength' => 2000,
					'default'   => '',
				],
				'confirm' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route(
			'/cache/warm',
			\WP_REST_Server::CREATABLE,
			[ $this, 'warm' ],
			[
				'action' => [
					'type'     => 'string',
					'enum'     => [ 'seed', 'stop' ],
					'required' => true,
				],
			]
		);
		$this->route(
			'/cache/inspect',
			\WP_REST_Server::CREATABLE,
			[ $this, 'inspect' ],
			[
				'url' => [
					'type'      => 'string',
					'maxLength' => 2000,
					'required'  => true,
				],
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$settings = $this->container->get( Settings::class );
		$compiled = CacheModule::settings();
		$adapter  = $this->container->get( CacheAdapter::class );
		$enabled  = false;
		foreach ( $this->container->get( Diagnostics::class )->modules() as $module ) {
			if ( 'cache_coordinator' === ( $module['id'] ?? null ) ) {
				$enabled = true === ( $module['enabled'] ?? false );
			}
		}
		$queue = $this->container->get( PurgeQueueRepository::class )->counts();
		return [
			'enabled'  => $enabled,
			'settings' => [
				'mode'          => $settings->get( 'cache_mode', 'sync' ),
				'edge_ttl'      => $settings->get( 'cache_edge_ttl', DAY_IN_SECONDS ),
				'swr'           => $settings->get( 'cache_swr', DAY_IN_SECONDS ),
				'sie'           => $settings->get( 'cache_sie', 3 * DAY_IN_SECONDS ),
				'nonce_refresh' => $settings->get( 'cache_nonce_refresh', true ),
				'warm'          => $settings->get( 'cache_warm', true ),
			],
			'adapter'  => [
				'id'             => $compiled['adapter'],
				'server'         => $compiled['server'],
				'headers_on_hit' => $adapter->headersOnHit(),
				'edge_html'      => $compiled['edge_html'],
				'verified'       => 'litespeed' !== $compiled['adapter'] || 'openlitespeed' === $compiled['server'],
			],
			'edge'     => [
				'available'     => $this->container->get( EdgePurger::class )->available(),
				'supports_tags' => $this->container->get( EdgePurger::class )->supportsTags(),
			],
			'tags'     => [
				'prefix' => $this->container->get( TagBuilder::class )->prefix(),
			],
			'queue'    => [
				'tags' => $queue[ PurgeQueueRepository::KIND_TAG ],
				'urls' => $queue[ PurgeQueueRepository::KIND_URL ],
				'all'  => $queue[ PurgeQueueRepository::KIND_ALL ] > 0,
				'warm' => $queue[ PurgeQueueRepository::KIND_WARM ],
			],
			// Objeto aunque esté vacío: [] en JSON tiene métodos de arreglo en JavaScript (last.at).
			'last'     => (object) PurgeWorker::lastState(),
			'warmer'   => $this->container->get( Warmer::class )->state(),
			'sitemaps' => $compiled['sitemaps'],
		];
	}

	public function updateSettings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$changes = [];
		foreach ( [
			'mode'          => 'cache_mode',
			'edge_ttl'      => 'cache_edge_ttl',
			'swr'           => 'cache_swr',
			'sie'           => 'cache_sie',
			'nonce_refresh' => 'cache_nonce_refresh',
			'warm'          => 'cache_warm',
		] as $param => $key ) {
			if ( null !== $request->get_param( $param ) ) {
				$changes[ $key ] = $request->get_param( $param );
			}
		}
		$settings = $this->container->get( Settings::class );
		// Emitir cabeceras al edge es el paso sensible del módulo: se confirma explícitamente (regla 3).
		if ( 'headers' === ( $changes['cache_mode'] ?? null ) && 'headers' !== $settings->get( 'cache_mode', 'sync' ) && true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma que verificaste el sitio antes de emitir cabeceras para el edge.', 'imagina-turbo' ), 428 );
		}
		$settings->update( $changes );
		$result = $this->container->get( ConfigCompiler::class )->compile();
		if ( 'wp-rocket' === CacheModule::settings()['adapter'] ) {
			WpRocketAdapter::flushHtaccess();
		}
		$this->container->get( EventLog::class )->log(
			'cache_coordinator',
			'settings',
			isset( $changes['cache_mode'] ) ? EventLog::WARNING : EventLog::INFO,
			__( 'Ajustes de caché actualizados.', 'imagina-turbo' ),
			[
				'user'    => get_current_user_id(),
				'changes' => $changes,
				'config'  => $result['ok'],
			]
		);
		return $this->ok( $this->status() );
	}

	public function purge( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type  = (string) $request->get_param( 'type' );
		$value = trim( (string) $request->get_param( 'value' ) );
		if ( 'all' === $type ) {
			if ( true !== $request->get_param( 'confirm' ) ) {
				return $this->error( 'itb_confirm', __( 'Confirma la purga completa.', 'imagina-turbo' ), 428 );
			}
			$purge = PurgeRequest::all( 'manual' );
		} elseif ( 'url' === $type ) {
			$url = esc_url_raw( $value );
			if ( '' === $url || (string) wp_parse_url( $url, PHP_URL_HOST ) !== (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				return $this->error( 'itb_url', __( 'La URL debe ser de este sitio.', 'imagina-turbo' ), 400 );
			}
			$purge = new PurgeRequest( [], [ $url ], false, 'manual' );
		} else {
			$tags = $this->container->get( TagBuilder::class );
			$name = $tags->strip( $value ) ?? $value;
			if ( '' === $tags->tag( $name ) ) {
				return $this->error( 'itb_tag', __( 'Tag no válido. Ejemplos: home, p-123, t-45, pt-product, shop.', 'imagina-turbo' ), 400 );
			}
			$purge = new PurgeRequest( [ $name ], [], false, 'manual' );
		}

		$purger = $this->container->get( Purger::class );
		$purger->purge( $purge );
		$purger->flushToQueue();
		$summary = $this->container->get( PurgeWorker::class )->run();
		$this->container->get( EventLog::class )->warning(
			'cache_coordinator',
			'manual_purge',
			__( 'Purga manual.', 'imagina-turbo' ),
			[
				'user'  => get_current_user_id(),
				'type'  => $type,
				'value' => $value,
			]
		);
		return $this->ok( array_merge( $this->status(), [ 'result' => $summary ] ) );
	}

	public function warm( \WP_REST_Request $request ): \WP_REST_Response {
		$warmer = $this->container->get( Warmer::class );
		if ( 'stop' === $request->get_param( 'action' ) ) {
			$removed = $warmer->stop();
			return $this->ok( array_merge( $this->status(), [ 'result' => [ 'removed' => $removed ] ] ) );
		}
		$added = $warmer->seed();
		return $this->ok( array_merge( $this->status(), [ 'result' => [ 'added' => $added ] ] ) );
	}

	/**
	 * Pide una URL como visitante anónimo y muestra las cabeceras de caché que salen del origen.
	 */
	public function inspect( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$url = esc_url_raw( trim( (string) $request->get_param( 'url' ) ) );
		if ( '' === $url || (string) wp_parse_url( $url, PHP_URL_HOST ) !== (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return $this->error( 'itb_url', __( 'La URL debe ser de este sitio.', 'imagina-turbo' ), 400 );
		}
		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 15,
				'redirection' => 0,
				'cookies'     => [],
				'user-agent'  => Warmer::USER_AGENT,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
			]
		);
		if ( is_wp_error( $response ) ) {
			return $this->error( 'itb_inspect', $response->get_error_message(), 502 );
		}
		if ( CloudflareAccess::blockedResponse( $response ) ) {
			return $this->error( 'itb_access', CloudflareAccess::message( $url ), 502 );
		}
		$headers = [];
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		$keep = [];
		foreach ( [ 'cache-control', strtolower( HeaderEmitter::HEADER ), 'cache-tag', 'x-litespeed-cache', 'x-litespeed-tag', 'x-litespeed-cache-control', 'cf-cache-status', 'age', 'vary', 'expires', 'last-modified' ] as $name ) {
			if ( isset( $headers[ $name ] ) ) {
				$keep[ $name ] = substr( $headers[ $name ], 0, 1000 );
			}
		}
		$cookies = [];
		foreach ( wp_remote_retrieve_cookies( $response ) as $cookie ) {
			$cookies[] = $cookie->name;
		}
		return $this->ok(
			[
				'url'     => $url,
				'status'  => (int) wp_remote_retrieve_response_code( $response ),
				'headers' => (object) $keep,
				'cookies' => $cookies,
			]
		);
	}
}
