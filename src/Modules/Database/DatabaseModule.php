<?php
/**
 * Base de datos (docs/02 módulo 10). Riesgo moderado, apagado por defecto.
 *
 * No hace nada en los requests: la auditoría está siempre disponible en el admin, y el interruptor
 * del módulo es lo que habilita las acciones (manuales y programadas) desde la REST y la tarea diaria.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Database;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;

/**
 * Registro del módulo (sin hooks).
 */
final class DatabaseModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
	}
}
