<?php
/**
 * Contrato de los módulos.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

use ImaginaTurbo\Core\Request\RequestContext;

/**
 * Un módulo solo se instancia si está activo en la config compilada y aplica al tipo de request.
 * Sus metadatos (riesgo, valores por defecto, solapamientos) viven en ModuleCatalog para que un
 * módulo desactivado no cargue ni su clase.
 */
interface ModuleInterface {

	/**
	 * Registra hooks. No debe hacer trabajo costoso: solo add_action/add_filter.
	 */
	public function register( Container $container, RequestContext $request ): void;
}
