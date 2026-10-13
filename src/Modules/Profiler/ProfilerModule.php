<?php
/**
 * Módulo Perfilador (fase 1).
 *
 * La decisión de muestreo y la medición temprana viven en el loader (EarlyLoader + Collector).
 * Aquí solo se registra lo que necesita WordPress cargado: guardar la muestra y Server-Timing para
 * el diagnóstico. El agregado horario lo registra el plugin siempre (ver Plugin::register), para que
 * la tarea programada tenga manejador aunque el módulo esté apagado.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Profiler;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;

/**
 * Registro de hooks del perfilador.
 */
final class ProfilerModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
		// El recolector solo existe en requests muestreados; sin él no se carga ni su clase.
		if ( ! class_exists( Collector::class, false ) ) {
			return;
		}
		$collector = Collector::active();
		if ( null === $collector ) {
			return;
		}

		add_action( 'shutdown', static fn() => $container->get( SampleWriter::class )->write( $collector, $request ), PHP_INT_MAX );

		if ( Collector::SOURCE_DIAG === $collector->source ) {
			( new ServerTiming( $collector ) )->register();
		}
	}
}
