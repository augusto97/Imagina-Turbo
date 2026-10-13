<?php
/**
 * GET /nonce (público): nonces nuevos para una página cacheada (docs/04, endpoints públicos).
 *
 * Solo para acciones que la página trae firmadas (NonceRefresh), con límite de tasa, sin escribir
 * en la base y con `no-store` en el navegador y en el edge.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Modules\CacheCoordinator\Adapters\CacheAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\HeaderEmitter;
use ImaginaTurbo\Modules\CacheCoordinator\NonceRefresh;
use ImaginaTurbo\Support\RateLimiter;

/**
 * Endpoint público de nonces.
 */
final class NonceController {

	public const PER_MINUTE = 30;

	public function __construct( private readonly CacheAdapter $adapter ) {
	}

	public function registerRoutes(): void {
		register_rest_route(
			RestRegistrar::NAMESPACE,
			'/nonce',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'a' => [
						'type'     => 'array',
						'items'    => [
							'type'      => 'string',
							'maxLength' => 100,
						],
						'maxItems' => NonceRefresh::MAX_ACTIONS,
						'required' => true,
					],
					't' => [
						'type'     => 'integer',
						'required' => true,
					],
					's' => [
						'type'     => 'string',
						'pattern'  => '^[a-f0-9]{32}$',
						'required' => true,
					],
				],
			]
		);
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$this->adapter->noCache( 'nonce' );
		if ( ! RateLimiter::allow( 'nonce', RateLimiter::clientIp(), self::PER_MINUTE ) ) {
			return self::noStore( new \WP_Error( 'itb_rate_limited', __( 'Demasiadas peticiones.', 'imagina-turbo' ), [ 'status' => 429 ] ) );
		}
		$actions = NonceRefresh::verify( $request->get_param( 'a' ), (int) $request->get_param( 't' ), (string) $request->get_param( 's' ) );
		if ( null === $actions ) {
			return self::noStore( new \WP_Error( 'itb_nonce_invalid', __( 'Petición no válida.', 'imagina-turbo' ), [ 'status' => 400 ] ) );
		}

		// Los nonces de WooCommerce de un invitado con carrito dependen de su sesión (nonce_user_logged_out).
		foreach ( $actions as $action ) {
			if ( str_starts_with( $action, 'woocommerce' ) && function_exists( 'WC' ) && ! is_user_logged_in() ) {
				WC()->initialize_session();
				break;
			}
		}

		$nonces = [];
		foreach ( $actions as $action ) {
			$nonces[ $action ] = wp_create_nonce( $action );
		}
		return self::noStore(
			new \WP_REST_Response(
				[
					'n' => $nonces,
					't' => time(),
				]
			)
		);
	}

	private static function noStore( \WP_REST_Response|\WP_Error $response ): \WP_REST_Response {
		if ( $response instanceof \WP_Error ) {
			$status   = $response->get_error_data();
			$response = new \WP_REST_Response(
				[
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				],
				is_array( $status ) && is_int( $status['status'] ?? null ) ? $status['status'] : 400
			);
		}
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( HeaderEmitter::HEADER, 'no-store' );
		return $response;
	}
}
