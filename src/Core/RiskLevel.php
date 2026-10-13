<?php
/**
 * Nivel de riesgo de un módulo o ajuste.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

/**
 * Seguro / moderado / invasivo. Solo lo seguro puede venir activo por defecto (regla 3).
 */
enum RiskLevel: string {

	case Safe     = 'safe';
	case Moderate = 'moderate';
	case Invasive = 'invasive';

	/**
	 * Etiqueta para la interfaz.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Safe     => __( 'Seguro', 'imagina-turbo' ),
			self::Moderate => __( 'Moderado', 'imagina-turbo' ),
			self::Invasive => __( 'Invasivo', 'imagina-turbo' ),
		};
	}

	/**
	 * Puede venir activo sin verificación previa.
	 */
	public function allowsDefaultOn(): bool {
		return self::Safe === $this;
	}
}
