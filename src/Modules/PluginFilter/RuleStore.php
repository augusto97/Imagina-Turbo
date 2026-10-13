<?php
/**
 * Reglas de carga condicional (opción itb_filter_rules, autoload = no). Pocas reglas por sitio: una
 * opción basta y el frontend nunca la lee (va compilada a la config).
 *
 * Estados: testing (experimento en curso), active (promovida), failed (el experimento encontró una
 * diferencia), disabled (apagada a mano o por la vigilancia).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

/**
 * Lectura y escritura.
 *
 * @phpstan-type Probe array{method: string, path: string, body: string}
 * @phpstan-type Side array{n: int, ms: float, kb: float, errors: int}
 * @phpstan-type Evidence array{pairs: int, required: int, modes: array<int, string>, baseline: Side, filtered: Side, hidden: int, mismatch: array{probe: int, field: string, baseline: string, filtered: string}|null}
 * @phpstan-type FilterRule array{id: string, key: string, plugins: list<string>, all_methods: bool, probes: list<Probe>, status: string, reason: string, created_at: int, updated_at: int, checked_at: int, evidence: Evidence}
 */
final class RuleStore {

	public const OPTION = 'itb_filter_rules';

	public const STATUSES = [ 'testing', 'active', 'failed', 'disabled' ];

	public static function id( string $key ): string {
		return substr( hash( 'sha256', $key ), 0, 12 );
	}

	/**
	 * @return array<string, FilterRule>
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, [] );
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $rule ) {
			$rule = is_array( $rule ) ? self::normalize( $rule ) : null;
			if ( null !== $rule ) {
				$out[ $rule['id'] ] = $rule;
			}
		}
		return $out;
	}

	/**
	 * @return FilterRule|null
	 */
	public function get( string $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * @param FilterRule $rule Regla.
	 */
	public function save( array $rule ): void {
		$all                = $this->all();
		$rule['updated_at'] = time();
		$all[ $rule['id'] ] = $rule;
		update_option( self::OPTION, $all, false );
	}

	public function delete( string $id ): void {
		$all = $this->all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * @return Evidence
	 */
	public static function emptyEvidence(): array {
		$side = [
			'n'      => 0,
			'ms'     => 0.0,
			'kb'     => 0.0,
			'errors' => 0,
		];
		return [
			'pairs'    => 0,
			'required' => RulePolicy::REQUIRED_PAIRS,
			'modes'    => [],
			'baseline' => $side,
			'filtered' => $side,
			'hidden'   => 0,
			'mismatch' => null,
		];
	}

	/**
	 * @param array<mixed> $rule Datos crudos.
	 * @return FilterRule|null
	 */
	public static function normalize( array $rule ): ?array {
		$key = is_string( $rule['key'] ?? null ) ? $rule['key'] : '';
		if ( '' === $key ) {
			return null;
		}
		$evidence = is_array( $rule['evidence'] ?? null ) ? $rule['evidence'] + self::emptyEvidence() : self::emptyEvidence();
		/** @var Evidence $evidence */
		return [
			'id'          => self::id( $key ),
			'key'         => $key,
			'plugins'     => array_values( array_filter( is_array( $rule['plugins'] ?? null ) ? $rule['plugins'] : [], 'is_string' ) ),
			'all_methods' => true === ( $rule['all_methods'] ?? false ),
			'probes'      => RulePolicy::sanitizeProbes( $rule['probes'] ?? [] ),
			'status'      => in_array( $rule['status'] ?? '', self::STATUSES, true ) ? (string) $rule['status'] : 'disabled',
			'reason'      => is_string( $rule['reason'] ?? null ) ? $rule['reason'] : '',
			'created_at'  => is_int( $rule['created_at'] ?? null ) ? $rule['created_at'] : time(),
			'updated_at'  => is_int( $rule['updated_at'] ?? null ) ? $rule['updated_at'] : time(),
			'checked_at'  => is_int( $rule['checked_at'] ?? null ) ? $rule['checked_at'] : 0,
			'evidence'    => $evidence,
		];
	}

	/**
	 * Lo que va a la config compilada: reglas activas (filter) y las que se pueden probar con la cookie
	 * de experimento (testing y active, para la revisión periódica). Solo plugins que siguen activos.
	 *
	 * @param list<string> $active Plugins activos.
	 * @return array{filter: array<string, array{p: list<string>, w: bool}>, experiments: array<string, array{k: string, p: list<string>, w: bool}>}
	 */
	public function compile( array $active ): array {
		$filter      = [];
		$experiments = [];
		foreach ( $this->all() as $rule ) {
			if ( '' !== RulePolicy::blockedReason( $rule['key'] ) ) {
				continue;
			}
			$plugins = array_values( array_intersect( $rule['plugins'], $active ) );
			if ( [] === $plugins ) {
				continue;
			}
			if ( 'active' === $rule['status'] ) {
				$filter[ $rule['key'] ] = [
					'p' => $plugins,
					'w' => $rule['all_methods'],
				];
			}
			if ( in_array( $rule['status'], [ 'testing', 'active' ], true ) ) {
				$experiments[ $rule['id'] ] = [
					'k' => $rule['key'],
					'p' => $plugins,
					'w' => $rule['all_methods'],
				];
			}
		}
		return [
			'filter'      => $filter,
			'experiments' => $experiments,
		];
	}
}
