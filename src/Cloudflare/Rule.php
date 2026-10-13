<?php
/**
 * Regla propia de un ruleset de fase (Rulesets API).
 *
 * Se identifica por `ref` (estable, con prefijo itb-) y por la descripción prefijada
 * "[imagina-turbo] …". El hash solo cubre lo que gestionamos (expresión, acción, parámetros,
 * activada, descripción): los campos que pone Cloudflare (id, version, last_updated) no cuentan.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Valor inmutable.
 */
final class Rule {

	public const REF_PREFIX = 'itb-';

	public const DESCRIPTION_PREFIX = '[imagina-turbo] ';

	/**
	 * @param array<string, mixed> $parameters action_parameters.
	 */
	public function __construct(
		public readonly string $phase,
		public readonly string $ref,
		public readonly string $description,
		public readonly string $expression,
		public readonly string $action,
		public readonly array $parameters = [],
		public readonly bool $enabled = true,
	) {
	}

	/**
	 * Regla tal como la devuelve la API.
	 *
	 * @param array<mixed> $raw Regla.
	 */
	public static function fromApi( string $phase, array $raw ): self {
		return new self(
			$phase,
			is_string( $raw['ref'] ?? null ) ? $raw['ref'] : '',
			is_string( $raw['description'] ?? null ) ? $raw['description'] : '',
			is_string( $raw['expression'] ?? null ) ? $raw['expression'] : '',
			is_string( $raw['action'] ?? null ) ? $raw['action'] : '',
			is_array( $raw['action_parameters'] ?? null ) ? $raw['action_parameters'] : [],
			false !== ( $raw['enabled'] ?? true )
		);
	}

	public static function isOwn( array $raw ): bool {
		$ref = $raw['ref'] ?? '';
		return is_string( $ref ) && str_starts_with( $ref, self::REF_PREFIX );
	}

	/**
	 * Cuerpo para crear o modificar la regla.
	 *
	 * @return array<string, mixed>
	 */
	public function body(): array {
		$body = [
			'ref'         => $this->ref,
			'description' => $this->description,
			'expression'  => $this->expression,
			'action'      => $this->action,
			'enabled'     => $this->enabled,
		];
		if ( [] !== $this->parameters ) {
			$body['action_parameters'] = $this->parameters;
		}
		return $body;
	}

	public function hash(): string {
		return substr( hash( 'sha256', (string) wp_json_encode( self::canonical( $this->body() ) ) ), 0, 16 );
	}

	/**
	 * Ordena claves de forma recursiva (listas se respetan) para comparar sin depender del orden.
	 *
	 * @param mixed $value Valor.
	 * @return mixed
	 */
	public static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ self::class, 'canonical' ], $value );
		}
		ksort( $value );
		return array_map( [ self::class, 'canonical' ], $value );
	}
}
