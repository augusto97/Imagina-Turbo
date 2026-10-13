<?php
/**
 * Módulo Cloudflare (fase 2b). Riesgo moderado, apagado por defecto.
 *
 * No hace nada en el frontend: el sitio nunca depende de Cloudflare para servir una página (regla 7).
 * Activo, habilita aplicar cambios en la zona y la purga en el edge desde la cola del coordinador de
 * caché; las tareas horarias (analíticas y deriva) corren por Action Scheduler.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Cloudflare;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;

/**
 * Registro del módulo (sin hooks).
 */
final class CloudflareModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
	}
}
