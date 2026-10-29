<?php
/**
 * Diferencia entre la zona y lo recomendado (docs/03, sección 8: "Proponer").
 *
 * Por regla propia (ref con el prefijo del sitio):
 * - no existe → crear (si el plan admite otra regla en la fase);
 * - existe y cambió desde nuestro último cambio (hash de la regla devuelta por la API) → "modificada
 *   a mano": no se sobrescribe sin que el usuario la elija;
 * - existe y difiere de lo deseado → modificar; si no, sin cambios;
 * - existe pero ya no se recomienda (o su requisito dejó de cumplirse) → eliminar.
 * Las reglas del cliente nunca se tocan.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Cálculo del plan.
 */
final class Planner {

	/**
	 * @param list<array{rule: Rule, risk: string, title: string, description: string, blocked: string}> $rules    Reglas deseadas.
	 * @param list<string>                                                                               $phases   Fases cuyas reglas propias gestiona este plan.
	 * @param list<array{id: string, label: string, recommended: mixed, risk: string, read_only: bool, note: string, managed?: bool}> $settings Ajustes recomendados.
	 */
	public function __construct(
		private readonly array $rules,
		private readonly array $phases,
		private readonly array $settings,
		private readonly string $refPrefix,
		private readonly PlanLimits $limits,
		private readonly State $state,
	) {
	}

	/**
	 * @param array{phases: array<string, array{readable: bool, exists: bool, id: string, rules: list<array<string, mixed>>, error: string}>, settings: array<string, array{readable: bool, value: mixed, editable: bool, error: string}>} $zone Lectura de la zona.
	 * @return list<array{id: string, kind: string, title: string, description: string, action: string, risk: string, reason: string, current: mixed, desired: mixed, phase: string, ref: string, setting: string}>
	 */
	public function plan( array $zone ): array {
		$changes = [];
		$counts  = [];
		foreach ( $this->phases as $phase ) {
			$counts[ $phase ] = count( $zone['phases'][ $phase ]['rules'] ?? [] );
		}

		$desiredRefs = [];
		foreach ( $this->rules as $item ) {
			$rule                      = $item['rule'];
			$desiredRefs[ $rule->ref ] = true;
			$phase                     = $zone['phases'][ $rule->phase ] ?? null;
			$base                      = [
				'id'          => 'rule:' . $rule->ref,
				'kind'        => 'rule',
				'title'       => $item['title'],
				'description' => $item['description'],
				'risk'        => $item['risk'],
				'desired'     => $rule->body(),
				'phase'       => $rule->phase,
				'ref'         => $rule->ref,
				'setting'     => '',
			];
			if ( null === $phase || ! $phase['readable'] ) {
				$changes[] = $base + [
					'action'  => 'blocked',
					'reason'  => __( 'No se pudieron leer las reglas de esta fase (permiso del token o plan).', 'imagina-turbo' ),
					'current' => null,
				];
				continue;
			}
			$current = self::findByRef( $phase['rules'], $rule->ref );

			if ( '' !== $item['blocked'] ) {
				$changes[] = $base + ( null === $current ? [
					'action'  => 'blocked',
					'reason'  => $item['blocked'],
					'current' => null,
				] : [
					'action'  => 'delete',
					'reason'  => $item['blocked'] . ' ' . __( 'Por eso se propone retirar la regla que ya existe.', 'imagina-turbo' ),
					'current' => $current,
					'risk'    => 'safe',
				] );
				continue;
			}

			if ( null === $current ) {
				$limit = $this->limits->rulesFor( $rule->phase );
				if ( $limit > 0 && $counts[ $rule->phase ] >= $limit ) {
					$changes[] = $base + [
						'action'  => 'blocked',
						/* translators: %d: número de reglas. */
						'reason'  => sprintf( __( 'La fase ya tiene %d reglas, el máximo del plan. Libera una para crear esta.', 'imagina-turbo' ), $counts[ $rule->phase ] ),
						'current' => null,
					];
					continue;
				}
				++$counts[ $rule->phase ];
				$changes[] = $base + [
					'action'  => 'create',
					'reason'  => '',
					'current' => null,
				];
				continue;
			}

			$stored = $this->state->appliedHash( $rule->ref );
			if ( null !== $stored && Rule::fromApi( $rule->phase, $current )->hash() !== $stored ) {
				$changes[] = $base + [
					'action'  => 'drifted',
					'reason'  => __( 'Alguien cambió esta regla en el panel de Cloudflare después de que Imagina Turbo la aplicara. No se sobrescribe salvo que la elijas.', 'imagina-turbo' ),
					'current' => $current,
				];
				continue;
			}
			$changes[] = $base + [
				'action'  => self::contains( $current, $rule->body() ) ? 'none' : 'update',
				'reason'  => '',
				'current' => $current,
			];
		}

		// Reglas propias que ya no se recomiendan.
		foreach ( $this->phases as $phase ) {
			foreach ( $zone['phases'][ $phase ]['rules'] ?? [] as $raw ) {
				$ref = is_string( $raw['ref'] ?? null ) ? $raw['ref'] : '';
				if ( '' === $ref || ! str_starts_with( $ref, $this->refPrefix ) || isset( $desiredRefs[ $ref ] ) ) {
					continue;
				}
				$changes[] = [
					'id'          => 'rule:' . $ref,
					'kind'        => 'rule',
					'title'       => is_string( $raw['description'] ?? null ) ? $raw['description'] : $ref,
					'description' => '',
					'action'      => 'delete',
					'risk'        => 'safe',
					'reason'      => __( 'Regla propia que esta versión ya no recomienda.', 'imagina-turbo' ),
					'current'     => $raw,
					'desired'     => null,
					'phase'       => $phase,
					'ref'         => $ref,
					'setting'     => '',
				];
			}
		}

		foreach ( $this->settings as $setting ) {
			if ( $setting['read_only'] ) {
				continue;
			}
			$current = $zone['settings'][ $setting['id'] ] ?? null;
			$base    = [
				'id'          => 'setting:' . $setting['id'],
				'kind'        => 'setting',
				'title'       => $setting['label'],
				'description' => $setting['note'],
				'risk'        => $setting['risk'],
				'desired'     => $setting['recommended'],
				'phase'       => '',
				'ref'         => '',
				'setting'     => $setting['id'],
			];
			// Sin gestionar (0.21.0): se muestra, pero el plan nunca lo cambia.
			if ( false === ( $setting['managed'] ?? true ) ) {
				$changes[] = array_merge(
					$base,
					[
						'action'  => 'none',
						'reason'  => __( 'Sin gestionar: Imagina Turbo no lo cambia.', 'imagina-turbo' ),
						'current' => null !== $current && $current['readable'] ? $current['value'] : null,
						'desired' => null,
					]
				);
				continue;
			}
			if ( null === $current || ! $current['readable'] ) {
				$changes[] = $base + [
					'action'  => 'blocked',
					'reason'  => __( 'No se pudo leer el ajuste (permiso del token o plan).', 'imagina-turbo' ),
					'current' => null,
				];
				continue;
			}
			if ( self::same( $current['value'], $setting['recommended'] ) ) {
				$changes[] = $base + [
					'action'  => 'none',
					'reason'  => '',
					'current' => $current['value'],
				];
				continue;
			}
			$changes[] = $base + ( $current['editable'] ? [
				'action'  => 'update',
				'reason'  => '',
				'current' => $current['value'],
			] : [
				'action'  => 'blocked',
				'reason'  => __( 'Cloudflare no permite cambiar este ajuste en esta zona.', 'imagina-turbo' ),
				'current' => $current['value'],
			] );
		}
		return $changes;
	}

	/**
	 * @param list<array<string, mixed>> $rules Reglas de la fase.
	 * @return array<string, mixed>|null
	 */
	public static function findByRef( array $rules, string $ref ): ?array {
		foreach ( $rules as $rule ) {
			if ( ( $rule['ref'] ?? null ) === $ref ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Lo deseado está contenido en lo actual (Cloudflare añade campos propios: id, version…).
	 * Las listas se comparan enteras.
	 */
	public static function contains( mixed $actual, mixed $wanted ): bool {
		if ( is_array( $wanted ) && ! array_is_list( $wanted ) ) {
			if ( ! is_array( $actual ) ) {
				return false;
			}
			foreach ( $wanted as $key => $value ) {
				if ( ! array_key_exists( $key, $actual ) || ! self::contains( $actual[ $key ], $value ) ) {
					return false;
				}
			}
			return true;
		}
		if ( is_array( $wanted ) ) {
			if ( ! is_array( $actual ) || ! array_is_list( $actual ) || count( $actual ) !== count( $wanted ) ) {
				return false;
			}
			foreach ( $wanted as $i => $value ) {
				if ( ! self::contains( $actual[ $i ], $value ) ) {
					return false;
				}
			}
			return true;
		}
		return self::same( $actual, $wanted );
	}

	public static function same( mixed $a, mixed $b ): bool {
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return $a === $b;
		}
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			// 0 y "0", 600 y "600": la API no siempre conserva el tipo numérico.
			return (string) $a === (string) $b;
		}
		return $a === $b;
	}
}
