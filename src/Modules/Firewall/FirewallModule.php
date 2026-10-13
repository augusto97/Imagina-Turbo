<?php
/**
 * Firewall (docs/03, sección 6). Riesgo moderado, apagado por defecto.
 *
 * No añade hooks en el frontend: las reglas viven en Cloudflare (se proponen en el plan de la zona y se
 * aplican desde el admin) y, en Apache, en un bloque del .htaccess que se escribe a petición. La
 * verificación del origen cerrado usa las muestras que el perfilador ya guarda.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Firewall;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;

/**
 * Registro del módulo (sin hooks).
 */
final class FirewallModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
	}
}
