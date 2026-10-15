<?php
/**
 * Base de datos (docs/02 módulo 10). Riesgo moderado, apagado por defecto.
 *
 * La auditoría está siempre disponible en el admin, y el interruptor del módulo es lo que habilita las
 * acciones (manuales y programadas) desde la REST y la tarea diaria.
 *
 * 0.11.0: retención de Action Scheduler. Por defecto guarda 31 días de acciones terminadas con sus
 * registros (ActionScheduler_QueueCleaner, filtro action_scheduler_retention_period, verificado en la
 * copia de WooCommerce 10.x); en una tienda real eran 10.000 acciones y 30.000 registros. Con un valor,
 * el propio limpiador de Action Scheduler borra lo terminado hace más de N días. Un add_filter por
 * request, nada más.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Database;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Runtime;

/**
 * Registro del módulo.
 */
final class DatabaseModule implements ModuleInterface {

	/** Mínimo de días: menos deja sin rastro las acciones que alguien quiera revisar. */
	public const AS_MIN_DAYS = 3;

	public function register( Container $container, RequestContext $request ): void {
		$days = self::retentionDays();
		if ( $days > 0 ) {
			add_filter( 'action_scheduler_retention_period', [ self::class, 'retention' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de Action Scheduler.
		}
	}

	public static function retentionDays(): int {
		$db   = Runtime::$config?->get( 'database', [] );
		$days = is_array( $db ) && is_int( $db['as_retention'] ?? null ) ? $db['as_retention'] : 0;
		return $days > 0 ? max( self::AS_MIN_DAYS, min( 30, $days ) ) : 0;
	}

	/**
	 * @param mixed $seconds Retención de Action Scheduler.
	 * @return mixed
	 */
	public static function retention( mixed $seconds ): mixed {
		$days = self::retentionDays();
		return $days > 0 ? $days * DAY_IN_SECONDS : $seconds;
	}
}
