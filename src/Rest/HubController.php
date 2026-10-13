<?php
/**
 * Conexión con el hub: GET /hub, PUT /hub, POST /hub/register, POST /hub/send, DELETE /hub.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Hub\HubClient;
use ImaginaTurbo\Modules\Profiler\Rollup;

/**
 * Estado, URL, registro, envío manual y desconexión.
 */
final class HubController extends Controller {

	public function registerRoutes(): void {
		$this->route( '/hub', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->container->get( HubClient::class )->status() ) );
		$this->route(
			'/hub',
			'PUT',
			[ $this, 'updateUrl' ],
			[
				'url' => [
					'type'      => 'string',
					'maxLength' => 200,
					'required'  => true,
				],
			]
		);
		$this->route( '/hub/register', \WP_REST_Server::CREATABLE, [ $this, 'register' ] );
		$this->route( '/hub/send', \WP_REST_Server::CREATABLE, [ $this, 'send' ] );
		$this->route( '/hub', \WP_REST_Server::DELETABLE, [ $this, 'disconnect' ] );
	}

	public function updateUrl( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$raw = trim( (string) $request->get_param( 'url' ) );
		$url = Settings::sanitizeHubUrl( $raw );
		if ( '' !== $raw && '' === $url ) {
			return $this->error( 'itb_hub_url', __( 'URL no válida: debe ser https (http solo para localhost), sin usuario, consulta ni fragmento.', 'imagina-turbo' ), 400 );
		}
		$client = $this->container->get( HubClient::class );
		if ( $url !== $client->url() && $client->isRegistered() ) {
			// El registro (site_id y clave) pertenece al hub anterior.
			$client->disconnect();
		}
		$this->container->get( Settings::class )->update( [ 'hub_url' => $url ] );
		$this->container->get( EventLog::class )->info(
			'hub',
			'settings',
			'' === $url ? __( 'Hub desactivado.', 'imagina-turbo' ) : __( 'URL del hub actualizada.', 'imagina-turbo' ),
			[ 'url' => $url ]
		);
		return $this->ok( $client->status() );
	}

	public function register(): \WP_REST_Response|\WP_Error {
		$client = $this->container->get( HubClient::class );
		$result = $client->register();
		if ( is_wp_error( $result ) ) {
			return $this->error( (string) $result->get_error_code(), $result->get_error_message(), 502 );
		}
		return $this->ok( $client->status() );
	}

	public function send(): \WP_REST_Response|\WP_Error {
		$client = $this->container->get( HubClient::class );
		$this->container->get( Rollup::class )->run();
		$result = $client->sendTelemetry( true );
		$status = $client->status();
		if ( 'error' === $result['reason'] ) {
			return $this->error( 'itb_hub_send', '' !== $status['last_error'] ? (string) $status['last_error'] : __( 'El hub no recibió la telemetría.', 'imagina-turbo' ), 502 );
		}
		return $this->ok( array_merge( $status, [ 'result' => $result ] ) );
	}

	public function disconnect(): \WP_REST_Response {
		$client = $this->container->get( HubClient::class );
		$client->disconnect();
		return $this->ok( $client->status() );
	}
}
