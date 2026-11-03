<?php
/**
 * Conector de Claude en el admin (0.22.0): GET /connector, DELETE /connector/connections/{id},
 * DELETE /connector/connections.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\Connector\ChangeSet;
use ImaginaTurbo\Modules\Connector\Connector;
use ImaginaTurbo\Modules\Connector\TokenStore;
use ImaginaTurbo\Repository\EventRepository;

/**
 * Estado, accesos y actividad.
 */
final class ConnectorController extends Controller {

	public function registerRoutes(): void {
		$this->route( '/connector', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$this->route( '/connector/connections/(?P<id>\d+)', \WP_REST_Server::DELETABLE, [ $this, 'revoke' ] );
		$this->route( '/connector/connections', \WP_REST_Server::DELETABLE, [ $this, 'revokeAll' ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$connections = [];
		foreach ( ( new TokenStore() )->active() as $grant ) {
			$user          = get_userdata( $grant['user_id'] );
			$connections[] = [
				'id'           => $grant['id'],
				'client'       => $grant['client_name'],
				'client_host'  => (string) wp_parse_url( $grant['client_id'], PHP_URL_HOST ),
				'user'         => $user instanceof \WP_User ? $user->display_name : '#' . $grant['user_id'],
				'level'        => in_array( Connector::SCOPE_ADJUST, Connector::scopes( $grant['scope'] ), true ) ? 'adjust' : 'read',
				'created_at'   => $grant['created_at'],
				'last_used_at' => $grant['last_used_at'],
			];
		}
		$activity = $this->container->get( EventRepository::class )->query( [ 'module' => 'connector' ], 1, 20 );
		return [
			'enabled'     => true === ( $this->container->get( Settings::class )->modules()['connector'] ?? false ),
			'url'         => Connector::resource(),
			'problems'    => Connector::problems(),
			'egress'      => Connector::ANTHROPIC_EGRESS,
			'connections' => $connections,
			'changes'     => array_slice( ( new ChangeSet( $this->container ) )->history(), 0, 10 ),
			'activity'    => $activity['items'],
		];
	}

	public function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		( new TokenStore() )->revoke( $id );
		$this->container->get( EventLog::class )->warning(
			'connector',
			'revoked',
			__( 'Acceso de Claude cortado desde el admin.', 'imagina-turbo' ),
			[
				'user'  => get_current_user_id(),
				'grant' => $id,
			]
		);
		return $this->ok( $this->status() );
	}

	public function revokeAll(): \WP_REST_Response {
		$count = ( new TokenStore() )->revokeAll();
		$this->container->get( EventLog::class )->warning(
			'connector',
			'revoked_all',
			__( 'Todos los accesos de Claude cortados desde el admin.', 'imagina-turbo' ),
			[
				'user'  => get_current_user_id(),
				'count' => $count,
			]
		);
		return $this->ok( $this->status() );
	}
}
