<?php
/**
 * Carga condicional de plugins (docs/02 módulo 4). Riesgo invasivo, apagado por defecto.
 *
 * El filtro de active_plugins lo instala el loader MU (EarlyFilter), antes de que carguen los plugins.
 * Aquí solo se atienden los requests de experimento: nunca se guardan en la caché de página y, con
 * Imagina Search, no cuentan para su límite de búsquedas por IP.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Plugin;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Integrations\ImaginaSearch;

/**
 * Registro del módulo.
 */
final class PluginFilterModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
		if ( null === Runtime::$experiment ) {
			return;
		}
		add_action( 'init', [ Plugin::class, 'noPageCache' ], 0 );
		ImaginaSearch::liftRateLimit();
	}
}
