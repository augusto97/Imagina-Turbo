<?php
/**
 * Informe del RUM a partir de los contadores (sin datos de visitantes).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Rum;

/**
 * Percentiles desde histogramas y repartos de navegación.
 *
 * @phpstan-type Row array{template: string, device: string, metric: string, bucket: int|string, n: int|string}
 * @phpstan-type Vitals array{template: string, device: string, n: int, lcp: int|null, inp: int|null, cls: float|null, ttfb: int|null, prerendered: int, prefetched: int, bfcache: int, back_forward: int}
 */
final class RumReport {

	/** Ancho del intervalo de cada métrica (igual que RumCollector::rows). */
	public const STEPS = [
		'lcp'  => 25,
		'inp'  => 10,
		'ttfb' => 25,
		'cls'  => 10,
	];

	/** Muestras mínimas para mostrar un p75. */
	public const MIN_SAMPLES = 10;

	/**
	 * p75 de un histograma (intervalo => cantidad), en la unidad de la métrica (CLS: milésimas).
	 *
	 * @param array<int, int> $buckets Histograma.
	 */
	public static function p75( array $buckets, int $step ): ?int {
		$total = array_sum( $buckets );
		if ( $total < self::MIN_SAMPLES ) {
			return null;
		}
		ksort( $buckets );
		$target = 0.75 * $total;
		$seen   = 0;
		foreach ( $buckets as $bucket => $n ) {
			$seen += $n;
			if ( $seen >= $target ) {
				// Punto medio del intervalo.
				return (int) round( ( $bucket + 0.5 ) * $step );
			}
		}
		return null;
	}

	/**
	 * @param list<Row> $rows Filas agrupadas de itb_rum_hist.
	 * @return list<Vitals>
	 */
	public static function vitals( array $rows ): array {
		$groups = [];
		foreach ( $rows as $row ) {
			$key = $row['template'] . '|' . $row['device'];
			$groups[ $key ][ $row['metric'] ][ (int) $row['bucket'] ] = (int) $row['n'];
		}
		$out = [];
		foreach ( $groups as $key => $metrics ) {
			[ $template, $device ] = explode( '|', $key, 2 );
			$nav                   = $metrics['nav'] ?? [];
			$cls                   = self::p75( $metrics['cls'] ?? [], self::STEPS['cls'] );
			$out[]                 = [
				'template'     => $template,
				'device'       => $device,
				'n'            => array_sum( $nav ),
				'lcp'          => self::p75( $metrics['lcp'] ?? [], self::STEPS['lcp'] ),
				'inp'          => self::p75( $metrics['inp'] ?? [], self::STEPS['inp'] ),
				'cls'          => null === $cls ? null : round( $cls / 1000, 3 ),
				'ttfb'         => self::p75( $metrics['ttfb'] ?? [], self::STEPS['ttfb'] ),
				'prerendered'  => $nav[ RumCollector::NAV['p'] ] ?? 0,
				'prefetched'   => $nav[ RumCollector::NAV['f'] ] ?? 0,
				'bfcache'      => $nav[ RumCollector::NAV['B'] ] ?? 0,
				'back_forward' => $nav[ RumCollector::NAV['b'] ] ?? 0,
			];
		}
		usort( $out, static fn( array $a, array $b ): int => $b['n'] <=> $a['n'] );
		return $out;
	}

	/**
	 * Resumen de navegación: qué parte se sirvió especulada y cuántas vueltas atrás usaron el bfcache.
	 *
	 * @param list<Row> $rows Filas agrupadas de itb_rum_hist.
	 * @return array{navigations: int, speculated_pct: float|null, prerendered: int, prefetched: int, bfcache_pct: float|null, bfcache: int, back_forward: int, reloads: int}
	 */
	public static function navigation( array $rows ): array {
		$nav = array_fill( 0, 6, 0 );
		foreach ( $rows as $row ) {
			if ( 'nav' === $row['metric'] && isset( $nav[ (int) $row['bucket'] ] ) ) {
				$nav[ (int) $row['bucket'] ] += (int) $row['n'];
			}
		}
		$forward = $nav[0] + $nav[4] + $nav[5];
		$back    = $nav[2] + $nav[3];
		return [
			'navigations'    => $forward,
			'speculated_pct' => $forward > 0 ? round( 100 * ( $nav[4] + $nav[5] ) / $forward, 1 ) : null,
			'prerendered'    => $nav[4],
			'prefetched'     => $nav[5],
			'bfcache_pct'    => $back > 0 ? round( 100 * $nav[3] / $back, 1 ) : null,
			'bfcache'        => $nav[3],
			'back_forward'   => $nav[2],
			'reloads'        => $nav[1],
		];
	}
}
