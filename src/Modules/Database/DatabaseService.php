<?php
/**
 * Limpiezas programadas por el usuario (tarea diaria; corre cada N días las acciones elegidas).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Database;

use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Repository\DbBackupRepository;

/**
 * Programación.
 */
final class DatabaseService {

	public const STATE_OPTION = 'itb_db_schedule_state';

	public function __construct(
		private readonly Settings $settings,
		private readonly DatabaseActions $actions,
		private readonly DbBackupRepository $backups,
	) {
	}

	/**
	 * Revisiones a conservar: las del ajuste rápido "Limitar revisiones" si está activo; si no, el
	 * valor propio de este módulo.
	 */
	public function keep(): int {
		$tweaks = $this->settings->get( 'tweaks', [] );
		if ( is_array( $tweaks ) && true === ( $tweaks['revisions'] ?? false ) ) {
			return max( 1, (int) $this->settings->get( 'tweak_revisions', 5 ) );
		}
		return max( 1, (int) $this->settings->get( 'db_keep_revisions', DatabaseAudit::DEFAULT_KEEP ) );
	}

	/**
	 * Tarea diaria: retención de respaldos y, si toca, las limpiezas programadas.
	 *
	 * @return list<array<string, mixed>> Resultados de lo que corrió.
	 */
	public function daily( bool $enabled ): array {
		$this->backups->deleteOlderThan( DbBackupRepository::RETENTION_DAYS );
		$schedule = $this->settings->get( 'db_schedule', [] );
		if ( ! $enabled || ! is_array( $schedule ) || [] === $schedule ) {
			return [];
		}
		$state = get_option( self::STATE_OPTION, [] );
		$last  = is_array( $state ) ? (int) ( $state['last'] ?? 0 ) : 0;
		$days  = max( 1, (int) $this->settings->get( 'db_schedule_days', 7 ) );
		// Una hora de margen: la tarea diaria no corre siempre a la misma hora exacta.
		if ( time() - $last < $days * DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			return [];
		}
		$results = [];
		foreach ( $schedule as $action ) {
			$results[] = match ( $action ) {
				'transients'       => $this->actions->transients(),
				'action_scheduler' => $this->actions->actionScheduler(),
				'revisions'        => $this->actions->revisions( $this->keep() ),
				default            => [],
			};
		}
		update_option(
			self::STATE_OPTION,
			[
				'last'    => time(),
				'results' => $results,
			],
			false
		);
		return $results;
	}
}
