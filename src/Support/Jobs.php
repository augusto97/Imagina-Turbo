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

	/**
	 * Programa las tareas si faltan. Solo se llama en admin o al activar (consulta la base).
	 */
	public function schedule(): void {
		if ( self::actionSchedulerReady() ) {
			if ( false === as_has_scheduled_action( self::DAILY_CLEANUP, [], self::GROUP ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::DAILY_CLEANUP, [], self::GROUP );
			}
			// Si antes se usó WP-Cron, se limpia para no ejecutar dos veces.
			wp_clear_scheduled_hook( self::DAILY_CLEANUP );
			return;
		}
		if ( false === wp_next_scheduled( self::DAILY_CLEANUP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_CLEANUP );
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
		wp_clear_scheduled_hook( self::DAILY_CLEANUP );
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
