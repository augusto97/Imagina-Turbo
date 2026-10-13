<?php
/**
 * Resultado de la clasificación de un request.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Request;

/**
 * Valor inmutable: tipo, endpoint (acción, namespace REST o endpoint wc-ajax) y ruta normalizada.
 */
final class RequestContext {

	/**
	 * @param RequestType $type     Tipo de request.
	 * @param string      $endpoint Acción de admin-ajax, endpoint de wc-ajax o namespace REST. Vacío si no aplica.
	 * @param string      $path     Ruta relativa a home, decodificada, en minúsculas, empezando por "/".
	 * @param string      $method   Método HTTP en mayúsculas.
	 */
	public function __construct(
		public readonly RequestType $type,
		public readonly string $endpoint,
		public readonly string $path,
		public readonly string $method,
	) {
	}

	/**
	 * Clave usada en la config compilada para las reglas por endpoint ("WcAjax:add_to_cart").
	 */
	public function ruleKey(): string {
		return '' === $this->endpoint ? $this->type->value : $this->type->value . ':' . $this->endpoint;
	}
}
