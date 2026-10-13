<?php
/**
 * Informes del perfilador a partir de las muestras crudas (últimas 24/48 h).
 *
 * Funciones puras sobre filas de route_samples: el controlador REST solo las consulta y las pasa.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Profiler;

use ImaginaTurbo\Modules\Firewall\OriginLock;

/**
 * Resumen, ranking por plugin, fases y rutas.
 */
final class Report {

	/**
	 * @param list<array<string, mixed>> $rows Filas de SampleRepository::recent().
	 * @return array<string, mixed>
	 */
	public static function summary( array $rows ): array {
		$n = count( $rows );
		if ( 0 === $n ) {
			return [
				'samples'         => 0,
				'ms_avg'          => 0,
				'ms_p50'          => 0,
				'ms_p95'          => 0,
				'mem_avg_mb'      => 0,
				'queries_avg'     => 0,
				'overhead_us_avg' => 0,
				'cacheable_pct'   => 0,
				'via_cf_pct'      => 0,
				'by_type'         => [],
			];
		}
		$ms     = array_map( static fn( array $r ): int => (int) $r['ms'], $rows );
		$byType = [];
		foreach ( $rows as $row ) {
			$type            = (string) $row['request_type'];
			$byType[ $type ] = ( $byType[ $type ] ?? 0 ) + 1;
		}
		arsort( $byType );

		return [
			'samples'         => $n,
			'ms_avg'          => (int) round( array_sum( $ms ) / $n ),
			'ms_p50'          => Rollup::percentile( $ms, 50 ),
			'ms_p95'          => Rollup::percentile( $ms, 95 ),
			'mem_avg_mb'      => round( array_sum( array_map( static fn( array $r ): int => (int) $r['mem_kb'], $rows ) ) / $n / 1024, 1 ),
			'queries_avg'     => (int) round( array_sum( array_map( static fn( array $r ): int => (int) $r['queries'], $rows ) ) / $n ),
			'overhead_us_avg' => (int) round( array_sum( array_map( static fn( array $r ): int => (int) $r['overhead_us'], $rows ) ) / $n ),
			'cacheable_pct'   => round( 100 * count( array_filter( $rows, static fn( array $r ): bool => 1 === (int) $r['cacheable'] ) ) / $n, 1 ),
			'via_cf_pct'      => round( 100 * count( array_filter( $rows, static fn( array $r ): bool => OriginLock::viaCloudflare( (int) $r['via_cf'] ) ) ) / $n, 1 ),
			'by_type'         => $byType,
		];
	}

	/**
	 * Ranking de componentes: costo medio por request (los requests donde no aparece cuentan como 0).
	 *
	 * @param list<array<string, mixed>> $rows Filas.
	 * @return list<array{component: string, load_ms: float, hook_ms: float, total_ms: float, kb: float, share_pct: float, seen_pct: float}>
	 */
	public static function plugins( array $rows ): array {
		$n = count( $rows );
		if ( 0 === $n ) {
			return [];
		}
		$acc     = [];
		$totalMs = 0;
		foreach ( $rows as $row ) {
			$totalMs += (int) $row['ms'];
			$costs    = json_decode( (string) ( $row['plugins_cost'] ?? '' ), true );
			foreach ( is_array( $costs ) ? $costs : [] as $cost ) {
				if ( ! is_array( $cost ) || ! is_string( $cost['c'] ?? null ) ) {
					continue;
				}
				$c                  = $cost['c'];
				$acc[ $c ]        ??= [
					'load' => 0.0,
					'hook' => 0.0,
					'kb'   => 0.0,
					'seen' => 0,
				];
				$acc[ $c ]['load'] += (float) ( $cost['l'] ?? 0 );
				$acc[ $c ]['hook'] += (float) ( $cost['h'] ?? 0 );
				$acc[ $c ]['kb']   += (float) ( $cost['k'] ?? 0 );
				++$acc[ $c ]['seen'];
			}
		}
		$avgRequest = $totalMs / $n;
		$out        = [];
		foreach ( $acc as $component => $sum ) {
			$total = ( $sum['load'] + $sum['hook'] ) / $n;
			$out[] = [
				'component' => (string) $component,
				'load_ms'   => round( $sum['load'] / $n, 2 ),
				'hook_ms'   => round( $sum['hook'] / $n, 2 ),
				'total_ms'  => round( $total, 2 ),
				'kb'        => round( $sum['kb'] / $n, 1 ),
				'share_pct' => $avgRequest > 0 ? round( 100 * $total / $avgRequest, 1 ) : 0.0,
				'seen_pct'  => round( 100 * $sum['seen'] / $n, 1 ),
			];
		}
		usort( $out, static fn( array $a, array $b ): int => $b['total_ms'] <=> $a['total_ms'] );
		return $out;
	}

	/** Orden real de las fases en un request (las de admin y frontend se intercalan aquí). */
	private const PHASE_ORDER = [ 'loader', 'muplugins_loaded', 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'admin_init', 'template_redirect', 'shutdown' ];

	/**
	 * Duración media de cada fase (sobre las muestras que la tienen), en el orden del request.
	 *
	 * @param list<array<string, mixed>> $rows Filas.
	 * @return list<array{phase: string, ms: float, seen_pct: float}>
	 */
	public static function phases( array $rows ): array {
		$n     = count( $rows );
		$sum   = [];
		$count = [];
		foreach ( $rows as $row ) {
			$phases = json_decode( (string) ( $row['phases'] ?? '' ), true );
			foreach ( is_array( $phases ) ? $phases : [] as $phase => $ms ) {
				if ( ! is_string( $phase ) || ! is_numeric( $ms ) ) {
					continue;
				}
				$sum[ $phase ]   = ( $sum[ $phase ] ?? 0.0 ) + (float) $ms;
				$count[ $phase ] = ( $count[ $phase ] ?? 0 ) + 1;
			}
		}
		$order = array_flip( self::PHASE_ORDER );
		$names = array_keys( $sum );
		usort( $names, static fn( string $a, string $b ): int => ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 ) );
		$out = [];
		foreach ( $names as $phase ) {
			$out[] = [
				'phase'    => $phase,
				'ms'       => round( $sum[ $phase ] / $count[ $phase ], 2 ),
				'seen_pct' => $n > 0 ? round( 100 * $count[ $phase ] / $n, 1 ) : 0.0,
			];
		}
		return $out;
	}

	/**
	 * Rutas más pesadas a partir de muestras crudas (para periodos de horas).
	 *
	 * @param list<array<string, mixed>> $rows Filas.
	 * @return list<array{request_type: string, route_key: string, n: int, ms_p50: int, ms_p95: int, mem_p95: int, queries_avg: int}>
	 */
	public static function routes( array $rows, int $limit = 50 ): array {
		$samples = array_map(
			static fn( array $r ): array => [
				'request_type' => (string) $r['request_type'],
				'route_key'    => (string) $r['route_key'],
				'ms'           => (int) $r['ms'],
				'mem_kb'       => (int) $r['mem_kb'],
				'queries'      => (int) $r['queries'],
			],
			$rows
		);
		$out     = array_map(
			static fn( array $r ): array => [
				'request_type' => $r['request_type'],
				'route_key'    => $r['route_key'],
				'n'            => $r['n'],
				'ms_p50'       => $r['ms_p50'],
				'ms_p95'       => $r['ms_p95'],
				'mem_p95'      => $r['mem_p95'],
				'queries_avg'  => $r['queries_avg'],
			],
			Rollup::aggregate( $samples )
		);
		usort( $out, static fn( array $a, array $b ): int => ( $b['ms_p50'] * $b['n'] ) <=> ( $a['ms_p50'] * $a['n'] ) );
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Auditoría pasiva: cookies enviadas y Cache-Control privado en requests que deberían ser cacheables.
	 *
	 * @param list<array<string, mixed>> $rows Filas.
	 * @return array{cookies: array<string, int>, private_cache: int, eligible: int}
	 */
	public static function passiveAudit( array $rows ): array {
		$cookies  = [];
		$private  = 0;
		$eligible = 0;
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['request_type'], [ 'FrontendHtml', 'Feed' ], true ) || 200 !== (int) $row['status'] ) {
				continue;
			}
			++$eligible;
			foreach ( array_filter( explode( ',', (string) ( $row['set_cookies'] ?? '' ) ) ) as $cookie ) {
				$cookies[ $cookie ] = ( $cookies[ $cookie ] ?? 0 ) + 1;
			}
			if ( 1 === preg_match( '/\b(private|no-store)\b/', (string) ( $row['cache_control'] ?? '' ) ) ) {
				++$private;
			}
		}
		arsort( $cookies );
		return [
			'cookies'       => $cookies,
			'private_cache' => $private,
			'eligible'      => $eligible,
		];
	}
}
