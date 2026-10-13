<?php
/**
 * Agregado horario: muestras crudas → metrics_hourly (p50/p95 de tiempo y memoria, consultas).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Profiler;

use ImaginaTurbo\Repository\MetricsRepository;
use ImaginaTurbo\Repository\SampleRepository;

/**
 * Tarea horaria (Action Scheduler).
 */
final class Rollup {

	public const HOOK = \ImaginaTurbo\Support\Jobs::HOURLY_ROLLUP;

	/** Opción con la última hora agregada (autoload = no). */
	public const STATE_OPTION = 'itb_profiler_rollup';

	public function __construct(
		private readonly SampleRepository $samples,
		private readonly MetricsRepository $metrics,
	) {
	}

	/**
	 * Agrega todas las horas completas pendientes (como máximo las 48 h que viven las muestras).
	 *
	 * @return int Horas procesadas.
	 */
	public function run( ?int $now = null ): int {
		$now         = $now ?? time();
		$currentHour = $now - ( $now % HOUR_IN_SECONDS );
		$oldest      = $currentHour - SampleRepository::RETENTION_HOURS * HOUR_IN_SECONDS;
		$last        = get_option( self::STATE_OPTION, 0 );
		$from        = max( $oldest, ( is_numeric( $last ) ? (int) $last : 0 ) + HOUR_IN_SECONDS );

		$done = 0;
		for ( $hour = $from; $hour < $currentHour; $hour += HOUR_IN_SECONDS ) {
			$label = gmdate( 'Y-m-d H:00:00', $hour );
			foreach ( self::aggregate( $this->samples->forHour( $label ) ) as $row ) {
				$this->metrics->upsert( array_merge( [ 'hour' => $label ], $row ) );
			}
			update_option( self::STATE_OPTION, $hour, false );
			++$done;
		}
		return $done;
	}

	/**
	 * Agrupa por tipo y ruta y calcula percentiles (rango más cercano).
	 *
	 * @param list<array{request_type: string, route_key: string, ms: int, mem_kb: int, queries: int}> $samples Muestras de una hora.
	 * @return list<array{request_type: string, route_key: string, n: int, ms_p50: int, ms_p95: int, mem_p50: int, mem_p95: int, queries_avg: int}>
	 */
	public static function aggregate( array $samples ): array {
		$groups = [];
		foreach ( $samples as $sample ) {
			$key                     = $sample['request_type'] . "\0" . $sample['route_key'];
			$groups[ $key ]['type']  = $sample['request_type'];
			$groups[ $key ]['route'] = $sample['route_key'];
			$groups[ $key ]['ms'][]  = $sample['ms'];
			$groups[ $key ]['mem'][] = $sample['mem_kb'];
			$groups[ $key ]['q'][]   = $sample['queries'];
		}
		$out = [];
		foreach ( $groups as $group ) {
			$out[] = [
				'request_type' => $group['type'],
				'route_key'    => $group['route'],
				'n'            => count( $group['ms'] ),
				'ms_p50'       => self::percentile( $group['ms'], 50 ),
				'ms_p95'       => self::percentile( $group['ms'], 95 ),
				'mem_p50'      => self::percentile( $group['mem'], 50 ),
				'mem_p95'      => self::percentile( $group['mem'], 95 ),
				'queries_avg'  => (int) round( array_sum( $group['q'] ) / count( $group['q'] ) ),
			];
		}
		return $out;
	}

	/**
	 * Percentil por rango más cercano.
	 *
	 * @param list<int|float> $values Valores (no vacío).
	 */
	public static function percentile( array $values, int $p ): int {
		if ( [] === $values ) {
			return 0;
		}
		sort( $values );
		$rank = (int) ceil( $p / 100 * count( $values ) );
		return (int) round( $values[ max( 0, min( count( $values ) - 1, $rank - 1 ) ) ] );
	}
}
