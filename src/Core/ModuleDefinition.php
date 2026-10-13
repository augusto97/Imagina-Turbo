<?php
/**
 * Metadatos de un módulo.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

/**
 * Valor inmutable con los datos que el admin y el compilador necesitan de un módulo.
 */
final class ModuleDefinition {

	/**
	 * @param string                               $id             Id estable (clave en ajustes y config).
	 * @param string                               $label          Nombre visible.
	 * @param string                               $description    Descripción en español.
	 * @param RiskLevel                            $risk           Nivel de riesgo.
	 * @param bool                                 $defaultEnabled Activo por defecto (solo si es seguro).
	 * @param int                                  $phase          Fase del roadmap en la que se implementa.
	 * @param class-string<ModuleInterface>|null   $class          Clase; null si aún no está implementado.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $description,
		public readonly RiskLevel $risk,
		public readonly bool $defaultEnabled,
		public readonly int $phase,
		public readonly ?string $class = null,
	) {
	}

	/**
	 * Hay código para este módulo en esta versión.
	 */
	public function isAvailable(): bool {
		return null !== $this->class;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'id'             => $this->id,
			'label'          => $this->label,
			'description'    => $this->description,
			'risk'           => $this->risk->value,
			'riskLabel'      => $this->risk->label(),
			'defaultEnabled' => $this->defaultEnabled,
			'phase'          => $this->phase,
			'available'      => $this->isAvailable(),
		];
	}
}
