<?php
/**
 * Registro de módulos: calcula su estado y arranca los activos.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Request\RequestContext;

/**
 * Ningún módulo carga código si está desactivado: en el frontend solo se consulta la config compilada
 * y el mapa de clases.
 */
final class ModuleRegistry {

	/** @var list<string> */
	private array $booted = [];

	/**
	 * @var array<string, class-string<ModuleInterface>>
	 */
	private readonly array $classMap;

	/**
	 * @param array<string, class-string<ModuleInterface>>|null $classMap Clases por id (null = catálogo).
	 */
	public function __construct( ?array $classMap = null ) {
		$this->classMap = $classMap ?? ModuleCatalog::classMap();
	}

	/**
	 * Estado final de cada módulo.
	 *
	 * @param array<string, bool>         $requested Interruptores guardados en los ajustes (id => bool).
	 * @param array<string, list<string>> $forcedOff Módulos apagados por solapamiento (id => motivos).
	 * @return array<string, array{enabled: bool, requested: bool, available: bool, forcedOff: list<string>}>
	 */
	public static function states( ModuleCatalog $catalog, array $requested, array $forcedOff ): array {
		$states = [];
		foreach ( $catalog->all() as $id => $definition ) {
			$wants         = $requested[ $id ] ?? $definition->defaultEnabled;
			$reasons       = $forcedOff[ $id ] ?? [];
			$states[ $id ] = [
				'enabled'   => $wants && $definition->isAvailable() && [] === $reasons,
				'requested' => $wants,
				'available' => $definition->isAvailable(),
				'forcedOff' => $reasons,
			];
		}
		return $states;
	}

	/**
	 * Arranca los módulos activos en la config compilada. Un módulo que falla no tumba el sitio.
	 *
	 * @return list<string> Ids arrancados.
	 */
	public function boot( Container $container, CompiledConfig $config, RequestContext $request ): array {
		foreach ( $config->modules() as $id => $enabled ) {
			if ( ! $enabled || ! isset( $this->classMap[ $id ] ) || in_array( $id, $this->booted, true ) ) {
				continue;
			}
			$class = $this->classMap[ $id ];
			try {
				$module = new $class();
				$module->register( $container, $request );
				$this->booted[] = $id;
			} catch ( \Throwable $e ) {
				error_log( sprintf( '[imagina-turbo] El módulo %s no pudo arrancar: %s', $id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- sin base de datos en el frontend.
			}
		}
		return $this->booted;
	}

	/**
	 * @return list<string>
	 */
	public function booted(): array {
		return $this->booted;
	}
}
