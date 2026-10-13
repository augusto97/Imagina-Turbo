<?php
/**
 * Tareas en segundo plano: Action Scheduler si está disponible, WP-Cron como respaldo.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Support;

/**
 * Programación de las tareas recurrentes del plugin.
 */
final class Jobs {

	public const GROUP = 'imagina-turbo';

	public const DAILY_CLEANUP = 'itb_daily_cleanup';

	public const HOURLY_ROLLUP = 'itb_profiler_rollup';

	public const HOURLY_TELEMETRY = 'itb_hub_telemetry';

	public const DAILY_AUDIT = 'itb_daily_audit';

	/**
	 * Tareas recurrentes: hook => intervalo en segundos.
	 *
	 * @return array<string, int>
	 */
	public static function recurring(): array {
		return [
			self::DAILY_CLEANUP    => DAY_IN_SECONDS,
			self::HOURLY_ROLLUP    => HOUR_IN_SECONDS,
			self::HOURLY_TELEMETRY => HOUR_IN_SECONDS,
			self::DAILY_AUDIT      => DAY_IN_SECONDS,
		];
	}

	/**
	 * Programa las tareas que falten. Solo se llama en admin o al activar (consulta la base).
	 */
	public function schedule(): void {
		$useActionScheduler = self::actionSchedulerReady();
		$offset             = 5 * MINUTE_IN_SECONDS;
		foreach ( self::recurring() as $hook => $interval ) {
			if ( $useActionScheduler ) {
				if ( false === as_has_scheduled_action( $hook, [], self::GROUP ) ) {
					as_schedule_recurring_action( time() + $offset, $interval, $hook, [], self::GROUP );
				}
				// Si antes se usó WP-Cron, se limpia para no ejecutar dos veces.
				wp_clear_scheduled_hook( $hook );
			} elseif ( false === wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + $offset, DAY_IN_SECONDS === $interval ? 'daily' : 'hourly', $hook );
			}
			// Escalonadas: no corren todas en el mismo minuto.
			$offset += 2 * MINUTE_IN_SECONDS;
		}
	}

	/**
	 * Quita las tareas programadas del sitio actual.
	 */
	public function unschedule(): void {
		self::unscheduleAll();
	}

	public static function unscheduleAll(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) && self::actionSchedulerReady() ) {
			as_unschedule_all_actions( '', [], self::GROUP );
		}
		foreach ( array_keys( self::recurring() ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Action Scheduler cargado e inicializado (sus funciones solo se pueden usar tras su init).
	 */
	public static function actionSchedulerReady(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_has_scheduled_action' )
			&& class_exists( 'ActionScheduler' )
			&& \ActionScheduler::is_initialized();
	}
}
