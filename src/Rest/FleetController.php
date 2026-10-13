<?php
/**
 * Flota: GET /fleet/status, POST /fleet/sync, POST /fleet/apply, POST /fleet/revert/{id},
 * PUT /fleet/settings y, para el hub, POST /fleet/remote (firmado, sin sesión).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Installer;
use ImaginaTurbo\Fleet\FleetService;
use ImaginaTurbo\Hub\HubClient;

/**
 * Plantilla de flota en el admin y lotes del hub.
 */
final class FleetController extends Controller {

	/** Peticiones del hub (válidas o no) por hora. */
	public const REMOTE_LIMIT = 60;

	private const RATE_TRANSIENT = 'itb_fleet_remote_rate';

	public function registerRoutes(): void {
		$this->route( '/fleet/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$this->route( '/fleet/sync', \WP_REST_Server::CREATABLE, [ $this, 'sync' ] );
		$confirm = [
			'confirm' => [
				'type'    => 'boolean',
				'default' => false,
			],
		];
		$this->route( '/fleet/apply', \WP_REST_Server::CREATABLE, [ $this, 'apply' ], $confirm );
		$this->route( '/fleet/revert/(?P<id>[a-f0-9]{12})', \WP_REST_Server::CREATABLE, [ $this, 'revert' ], $confirm );
		$this->route(
			'/fleet/settings',
			'PUT',
			[ $this, 'settings' ],
			$confirm + [
				'fleet_remote' => [
					'type'     => 'boolean',
					'required' => true,
				],
			]
		);

		// El hub pide el estado o aplica la plantilla en un lote de sitios. La autenticación es la firma
		// HMAC con la clave del sitio (no hay sesión ni nonce de WordPress).
		register_rest_route(
			RestRegistrar::NAMESPACE,
			'/fleet/remote',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'remote' ],
				'permission_callback' => [ $this, 'remoteAllowed' ],
				'args'                => [
					'action'         => [
						'type'     => 'string',
						'enum'     => [ 'status', 'apply' ],
						'required' => true,
					],
					'policy_version' => [
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					],
				],
			]
		);
	}

	private function fleet(): FleetService {
		return $this->container->get( FleetService::class );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$hub = $this->container->get( HubClient::class );
		return [
			'hub'       => [
				'configured' => $hub->isConfigured(),
				'registered' => $hub->status()['registered'],
			],
			'state'     => $this->fleet()->state(),
			'remote'    => true === $this->container->get( Settings::class )->get( 'fleet_remote', false ),
			'snapshots' => $this->fleet()->snapshots(),
			'endpoint'  => rest_url( RestRegistrar::NAMESPACE . '/fleet/remote' ),
		];
	}

	public function sync(): \WP_REST_Response|\WP_Error {
		$result = $this->fleet()->sync();
		if ( ! $result['ok'] ) {
			return $this->error( 'itb_fleet_sync', $result['error'], 502 );
		}
		return $this->ok( $this->status() );
	}

	public function apply( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Revisa las diferencias y confirma: se guarda un snapshot para revertir.', 'imagina-turbo' ), 428 );
		}
		$result = $this->fleet()->apply( 'admin' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( [ 'result' => $result ] + $this->status() );
	}

	public function revert( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma la reversión.', 'imagina-turbo' ), 428 );
		}
		if ( ! $this->fleet()->revert( (string) $request->get_param( 'id' ) ) ) {
			return $this->error( 'itb_fleet_snapshot', __( 'Ese snapshot ya no existe.', 'imagina-turbo' ), 404 );
		}
		return $this->ok( $this->status() );
	}

	public function settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$on = true === $request->get_param( 'fleet_remote' );
		// Autorizar que el hub cambie el sitio es sensible: confirmación explícita (regla 3).
		if ( $on && true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma que el hub puede aplicar la plantilla en este sitio.', 'imagina-turbo' ), 428 );
		}
		$this->container->get( Settings::class )->update( [ 'fleet_remote' => $on ] );
		$this->container->get( EventLog::class )->warning(
			'fleet',
			'remote',
			$on ? __( 'El hub puede aplicar la plantilla de flota en este sitio.', 'imagina-turbo' ) : __( 'El hub ya no puede aplicar la plantilla de flota en este sitio.', 'imagina-turbo' ),
			[ 'user' => get_current_user_id() ]
		);
		$this->fleet()->check();
		return $this->ok( $this->status() );
	}

	/**
	 * Permiso del endpoint del hub: límite de tasa y firma.
	 */
	public function remoteAllowed( \WP_REST_Request $request ): bool|\WP_Error {
		$count = (int) get_transient( self::RATE_TRANSIENT );
		if ( $count >= self::REMOTE_LIMIT ) {
			return new \WP_Error( 'itb_rate', __( 'Demasiadas peticiones.', 'imagina-turbo' ), [ 'status' => 429 ] );
		}
		set_transient( self::RATE_TRANSIENT, $count + 1, HOUR_IN_SECONDS );

		$headers = [];
		foreach ( [ 'x-itb-site', 'x-itb-timestamp', 'x-itb-nonce', 'x-itb-signature' ] as $name ) {
			$headers[ $name ] = (string) $request->get_header( $name );
		}
		$path   = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$reason = $this->container->get( HubClient::class )->verifyIncoming( 'POST', $path, $request->get_body(), $headers );
		if ( null !== $reason ) {
			$this->container->get( EventLog::class )->warning( 'fleet', 'remote_rejected', __( 'Petición de flota rechazada.', 'imagina-turbo' ), [ 'reason' => $reason ] );
			return new \WP_Error( 'itb_signature', __( 'Firma no válida.', 'imagina-turbo' ), [ 'status' => 401 ] );
		}
		return true;
	}

	/**
	 * Estado o aplicación pedidos por el hub. Antes de nada se descarga la plantilla del propio hub:
	 * el cuerpo de la petición no trae configuración, solo la versión que el hub espera aplicar.
	 */
	public function remote( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		self::noCache();
		Installer::maybeUpgrade();
		$sync = $this->fleet()->sync();
		if ( ! $sync['ok'] ) {
			return $this->error( 'itb_fleet_sync', $sync['error'], 502 );
		}
		$state = $this->fleet()->state();
		$base  = [
			'policy'  => null === $state['policy'] ? null : $state['policy']['id'] . '@' . $state['policy']['version'],
			'drift'   => $state['drift'],
			'applied' => $state['applied'],
			'remote'  => true === $this->container->get( Settings::class )->get( 'fleet_remote', false ),
		];
		if ( 'apply' !== $request->get_param( 'action' ) ) {
			return $this->ok( $base );
		}
		$result = $this->fleet()->apply( 'hub', (int) $request->get_param( 'policy_version' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$after = $this->fleet()->state();
		return $this->ok(
			[
				'result' => $result,
				'drift'  => $after['drift'],
			] + $base
		);
	}
}
