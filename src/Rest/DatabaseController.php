<?php
/**
 * Base de datos: GET /db/status, POST /db/audit, POST /db/actions/{action}, POST /db/restore/{batch},
 * PUT /db/settings.
 *
 * Las rutas eran /database/...: firewalls de servidor y de plugins con reglas tipo 7G/8G bloquean con 403
 * cualquier URL que contenga "/database/" antes de llegar a WordPress (visto en producción, 0.10.6).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\Database\DatabaseActions;
use ImaginaTurbo\Modules\Database\DatabaseAudit;
use ImaginaTurbo\Modules\Database\DatabaseService;
use ImaginaTurbo\Repository\DbBackupRepository;

/**
 * Auditoría (siempre), acciones con respaldo (con el módulo activo) y restauración.
 */
final class DatabaseController extends Controller {

	public function registerRoutes(): void {
		$this->route( '/db/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$this->route(
			'/db/audit',
			\WP_REST_Server::CREATABLE,
			function (): \WP_REST_Response {
				$this->container->get( DatabaseAudit::class )->run( $this->container->get( DatabaseService::class )->keep() );
				return $this->ok( $this->status() );
			}
		);
		$this->route(
			'/db/actions/(?P<action>transients|action_scheduler|revisions|autoload)',
			\WP_REST_Server::CREATABLE,
			[ $this, 'action' ],
			[
				'confirm' => [
					'type'    => 'boolean',
					'default' => false,
				],
				'options' => [
					'type'     => 'array',
					'items'    => [
						'type'      => 'string',
						'maxLength' => 191,
					],
					'maxItems' => 200,
				],
			]
		);
		$this->route(
			'/db/restore/(?P<batch>[a-f0-9]{12})',
			\WP_REST_Server::CREATABLE,
			[ $this, 'restore' ],
			[
				'confirm' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route(
			'/db/settings',
			'PUT',
			[ $this, 'settings' ],
			[
				'db_keep_revisions' => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				],
				'db_schedule'       => [
					'type'  => 'array',
					'items' => [
						'type' => 'string',
						'enum' => [ 'transients', 'action_scheduler', 'revisions' ],
					],
				],
				'db_schedule_days'  => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 90,
				],
				'db_as_retention'   => [
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 30,
				],
			]
		);
	}

	private function enabled(): bool {
		foreach ( $this->container->get( Diagnostics::class )->modules() as $module ) {
			if ( 'database' === ( $module['id'] ?? null ) ) {
				return true === ( $module['enabled'] ?? false );
			}
		}
		return false;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$settings = $this->container->get( Settings::class );
		$audit    = get_option( DatabaseAudit::OPTION, null );
		$state    = get_option( DatabaseService::STATE_OPTION, [] );
		return [
			'enabled'  => $this->enabled(),
			'audit'    => is_array( $audit ) ? $audit : null,
			'batches'  => $this->container->get( DbBackupRepository::class )->batches(),
			'keep'     => $this->container->get( DatabaseService::class )->keep(),
			'settings' => [
				'db_keep_revisions' => (int) $settings->get( 'db_keep_revisions', 5 ),
				'db_schedule'       => array_values( (array) $settings->get( 'db_schedule', [] ) ),
				'db_schedule_days'  => (int) $settings->get( 'db_schedule_days', 7 ),
				'db_as_retention'   => (int) $settings->get( 'db_as_retention', 0 ),
			],
			'last_run' => is_array( $state ) ? (int) ( $state['last'] ?? 0 ) : 0,
		];
	}

	public function action( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->enabled() ) {
			return $this->error( 'itb_db_disabled', __( 'Activa el módulo Base de datos para ejecutar acciones.', 'imagina-turbo' ), 409 );
		}
		if ( true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma la acción: las filas se guardan antes en un respaldo restaurable.', 'imagina-turbo' ), 428 );
		}
		$actions = $this->container->get( DatabaseActions::class );
		$result  = match ( (string) $request->get_param( 'action' ) ) {
			'transients'       => $actions->transients(),
			'action_scheduler' => $actions->actionScheduler(),
			'revisions'        => $actions->revisions( $this->container->get( DatabaseService::class )->keep() ),
			default            => $actions->autoload( array_values( array_filter( (array) $request->get_param( 'options' ), 'is_string' ) ) ),
		};
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->container->get( DatabaseAudit::class )->run( $this->container->get( DatabaseService::class )->keep() );
		return $this->ok( [ 'result' => $result ] + $this->status() );
	}

	public function restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma la restauración del lote.', 'imagina-turbo' ), 428 );
		}
		$result = $this->container->get( DatabaseActions::class )->restore( (string) $request->get_param( 'batch' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->container->get( DatabaseAudit::class )->run( $this->container->get( DatabaseService::class )->keep() );
		return $this->ok( [ 'restored' => $result ] + $this->status() );
	}

	public function settings( \WP_REST_Request $request ): \WP_REST_Response {
		$changes = [];
		foreach ( [ 'db_keep_revisions', 'db_schedule', 'db_schedule_days', 'db_as_retention' ] as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$changes[ $key ] = $request->get_param( $key );
			}
		}
		$this->container->get( Settings::class )->update( $changes );
		if ( isset( $changes['db_as_retention'] ) ) {
			// La retención viaja en la configuración compilada (la lee Action Scheduler en cron y AJAX).
			$this->container->get( ConfigCompiler::class )->compile();
		}
		$this->container->get( EventLog::class )->info(
			'database',
			'settings',
			__( 'Ajustes de base de datos actualizados.', 'imagina-turbo' ),
			[
				'changes' => $changes,
				'user'    => get_current_user_id(),
			]
		);
		return $this->ok( $this->status() );
	}
}
