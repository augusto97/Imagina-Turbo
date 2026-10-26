<?php
/**
 * Módulo Cloudflare (fase 2b). Riesgo moderado, apagado por defecto.
 *
 * El sitio nunca depende de Cloudflare para servir una página (regla 7). Activo, habilita aplicar
 * cambios en la zona y la purga en el edge desde la cola del coordinador de caché; las tareas horarias
 * (analíticas, deriva y salud de Zaraz) corren por Action Scheduler.
 *
 * En el frontend solo actúa si Zaraz reemplaza a Google Analytics o a Meta (0.18.0, ZarazBridge):
 * apaga esas etiquetas en las visitas que llegan por Cloudflare y pasa los eventos de WooCommerce.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Cloudflare;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Integrations\ZarazBridge;

/**
 * Registro del módulo.
 */
final class CloudflareModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
		$takeover = ZarazBridge::compiled();
		if ( $takeover['ga4'] || $takeover['meta'] ) {
			( new ZarazBridge( $takeover ) )->register( in_array( $request->type, [ RequestType::FrontendHtml, RequestType::Cart, RequestType::Checkout ], true ) );
		}
	}
}
