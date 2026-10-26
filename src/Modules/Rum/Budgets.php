<?php
/**
 * Presupuestos de rendimiento por sitio (0.19.0).
 *
 * Límites para el p75 del RUM de 7 días: Core Web Vitals (LCP, INP, CLS), TTFB y el peso de la carga
 * (peticiones, KB de JS, CSS y total). La tarea diaria compara, registra en el log lo que pasa a estar
 * por encima o vuelve a estar dentro, y guarda el resultado para el admin y la telemetría del hub.
 * Los presupuestos son del sitio y también puede fijarlos una plantilla de la flota (`budgets`).
 *
 * Por defecto: los umbrales «bueno» de Core Web Vitals, TTFB de 800 ms (el de web.dev) y un peso de
 * tienda ligera. Un límite en 0 no se vigila.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Rum;

/**
 * Normalización, evaluación y estado guardado.
 *
 * @phpstan-import-type Vitals from RumReport
 * @phpstan-type Breach array{template: string, device: string, metric: string, value: float|int, budget: float|int}
 * @phpstan-type State array{checked_at: int, budgets: array<string, float|int>, site: list<array<string, mixed>>, breaches: list<Breach>}
 */
final class Budgets {

	public const OPTION = 'itb_budgets_state';

	/** Visitas mínimas de una plantilla para avisar (el p75 ya exige 10 muestras por métrica). */
	public const MIN_VISITS = 20;

	/** Casos en el mensaje del log (255 caracteres); la lista completa va en el contexto del evento. */
	private const MAX_LISTED = 3;

	/** Límites por defecto. */
	public const DEFAULTS = [
		'lcp'  => 2500,
		'inp'  => 200,
		'cls'  => 0.1,
		'ttfb' => 800,
		'req'  => 80,
		'js'   => 400,
		'css'  => 150,
		'kb'   => 2500,
	];

	/** Máximos admitidos al guardar. */
	private const MAX = [
		'lcp'  => 30000,
		'inp'  => 5000,
		'cls'  => 1,
		'ttfb' => 10000,
		'req'  => 1000,
		'js'   => 3000,
		'css'  => 1000,
		'kb'   => 20000,
	];

	/**
	 * Presupuestos completos a partir de lo guardado (lo que falta toma el valor por defecto).
	 *
	 * @param array<mixed> $input Ajuste `budgets`.
	 * @return array<string, float|int>
	 */
	public static function normalize( array $input ): array {
		$out = [];
		foreach ( self::DEFAULTS as $metric => $default ) {
			$value = $input[ $metric ] ?? $default;
			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				$value = $default;
			}
			$value          = max( 0, min( self::MAX[ $metric ], $value ) );
			$out[ $metric ] = 'cls' === $metric ? round( (float) $value, 3 ) : (int) round( (float) $value );
		}
		return $out;
	}

	/**
	 * Métricas por encima de su presupuesto.
	 *
	 * @param list<Vitals>              $vitals  p75 por plantilla (o del sitio, plantilla "*") y dispositivo.
	 * @param array<string, float|int> $budgets Presupuestos normalizados.
	 * @return list<Breach>
	 */
	public static function evaluate( array $vitals, array $budgets ): array {
		$out = [];
		foreach ( $vitals as $row ) {
			if ( $row['n'] < self::MIN_VISITS ) {
				continue;
			}
			foreach ( $budgets as $metric => $budget ) {
				$value = $row[ $metric ] ?? null;
				if ( $budget > 0 && ( is_int( $value ) || is_float( $value ) ) && $value > $budget ) {
					$out[] = [
						'template' => $row['template'],
						'device'   => $row['device'],
						'metric'   => $metric,
						'value'    => $value,
						'budget'   => $budget,
					];
				}
			}
		}
		return $out;
	}

	/**
	 * Lo que entró y lo que salió respecto de la vez anterior (por plantilla, dispositivo y métrica).
	 *
	 * @param list<Breach> $before Anterior.
	 * @param list<Breach> $after  Actual.
	 * @return array{new: list<Breach>, resolved: list<Breach>}
	 */
	public static function diff( array $before, array $after ): array {
		$key  = static fn( array $b ): string => $b['template'] . '|' . $b['device'] . '|' . $b['metric'];
		$old  = array_map( $key, $before );
		$cur  = array_map( $key, $after );
		$news = array_values( array_filter( $after, static fn( array $b ): bool => ! in_array( $key( $b ), $old, true ) ) );
		$gone = array_values( array_filter( $before, static fn( array $b ): bool => ! in_array( $key( $b ), $cur, true ) ) );
		return [
			'new'      => $news,
			'resolved' => $gone,
		];
	}

	/**
	 * Resumen legible para el log: «LCP en single:product (móvil): 3,1 s > 2,5 s; …».
	 *
	 * @param list<Breach> $breaches Excesos.
	 */
	public static function describe( array $breaches ): string {
		$parts = [];
		foreach ( array_slice( $breaches, 0, self::MAX_LISTED ) as $b ) {
			$where   = '*' === $b['template'] ? __( 'todo el sitio', 'imagina-turbo' ) : $b['template'];
			$device  = 'd' === $b['device'] ? __( 'escritorio', 'imagina-turbo' ) : __( 'móvil', 'imagina-turbo' );
			$parts[] = sprintf( '%s en %s (%s): %s > %s', self::label( $b['metric'] ), $where, $device, self::format( $b['metric'], $b['value'] ), self::format( $b['metric'], $b['budget'] ) );
		}
		$more = count( $breaches ) - count( $parts );
		/* translators: %d: cantidad de excesos no listados. */
		return implode( '; ', $parts ) . ( $more > 0 ? ' ' . sprintf( __( 'y %d más', 'imagina-turbo' ), $more ) : '' );
	}

	public static function label( string $metric ): string {
		return match ( $metric ) {
			'lcp'   => 'LCP',
			'inp'   => 'INP',
			'cls'   => 'CLS',
			'ttfb'  => 'TTFB',
			'req'   => __( 'Peticiones', 'imagina-turbo' ),
			'js'    => 'JS',
			'css'   => 'CSS',
			'kb'    => __( 'Peso total', 'imagina-turbo' ),
			default => $metric,
		};
	}

	public static function format( string $metric, float|int $value ): string {
		return match ( $metric ) {
			'lcp', 'inp', 'ttfb' => $value >= 1000 ? number_format( $value / 1000, 1, ',', '.' ) . ' s' : (int) $value . ' ms',
			'cls'                => number_format( (float) $value, 3, ',', '.' ),
			'req'                => (string) (int) $value,
			default              => (int) $value . ' KB',
		};
	}

	/**
	 * Último resultado guardado.
	 *
	 * @return State
	 */
	public static function stored(): array {
		$data = get_option( self::OPTION, [] );
		$data = is_array( $data ) ? $data : [];
		/** @var State */
		return [
			'checked_at' => is_int( $data['checked_at'] ?? null ) ? $data['checked_at'] : 0,
			'budgets'    => is_array( $data['budgets'] ?? null ) ? $data['budgets'] : self::DEFAULTS,
			'site'       => is_array( $data['site'] ?? null ) ? array_values( $data['site'] ) : [],
			'breaches'   => is_array( $data['breaches'] ?? null ) ? array_values( $data['breaches'] ) : [],
		];
	}
}
