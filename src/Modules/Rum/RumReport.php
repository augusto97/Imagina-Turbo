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
 * @phpstan-type Vitals array{template: string, device: string, n: int, lcp: int|null, inp: int|null, cls: float|null, ttfb: int|null, req: int|null, js: int|null, css: int|null, kb: int|null, prerendered: int, prefetched: int, bfcache: int, back_forward: int}
 * @phpstan-type Breakdown array{device: string, n: int, ttfb: int|null, conn: int|null, wait_cached: int|null, wait_generated: int|null, cached_pct: float|null, cached_n: int}
 */
final class RumReport {

	/** Ancho del intervalo de cada métrica (igual que RumCollector::rows). */
	public const STEPS = [
		'lcp'  => 25,
		'inp'  => 10,
		'ttfb' => 25,
		'cls'  => 10,
		'conn' => 25,
		'wait' => 25,
		'req'  => 5,
		'js'   => 10,
		'css'  => 5,
		'kb'   => 50,
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
	 * @param bool      $site Todo el sitio por dispositivo (plantilla "*") en vez de por plantilla.
	 * @return list<Vitals>
	 */
	public static function vitals( array $rows, bool $site = false ): array {
		$groups = [];
		foreach ( $rows as $row ) {
			$key    = ( $site ? '*' : $row['template'] ) . '|' . $row['device'];
			$bucket = (int) $row['bucket'];
			// Sumado: con $site, el mismo intervalo llega de varias plantillas.
			$groups[ $key ][ $row['metric'] ][ $bucket ] = ( $groups[ $key ][ $row['metric'] ][ $bucket ] ?? 0 ) + (int) $row['n'];
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
				'req'          => self::p75( $metrics['req'] ?? [], self::STEPS['req'] ),
				'js'           => self::p75( $metrics['js'] ?? [], self::STEPS['js'] ),
				'css'          => self::p75( $metrics['css'] ?? [], self::STEPS['css'] ),
				'kb'           => self::p75( $metrics['kb'] ?? [], self::STEPS['kb'] ),
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
	 * Desglose del TTFB por dispositivo (0.14.0): p75 de la conexión (redirecciones, DNS, TCP, TLS) y de
	 * la espera del servidor, separada según la visita recibió una copia guardada o una página generada
	 * para ella, y qué parte de las visitas recibió una copia. Dice si el TTFB es de la red del visitante
	 * (la arregla un nodo cercano) o del servidor (la arreglan más aciertos de caché).
	 *
	 * @param list<Row> $rows Filas agrupadas de itb_rum_hist.
	 * @return list<Breakdown>
	 */
	public static function ttfbBreakdown( array $rows ): array {
		$groups = [];
		foreach ( $rows as $row ) {
			if ( in_array( $row['metric'], [ 'ttfb', 'conn', 'wait', 'waith', 'waitm', 'hit' ], true ) ) {
				$bucket = (int) $row['bucket'];
				$groups[ $row['device'] ][ $row['metric'] ][ $bucket ] = ( $groups[ $row['device'] ][ $row['metric'] ][ $bucket ] ?? 0 ) + (int) $row['n'];
			}
		}
		$out = [];
		foreach ( [ 'm', 'd' ] as $device ) {
			$m = $groups[ $device ] ?? [];
			if ( [] === $m ) {
				continue;
			}
			$hit   = $m['hit'] ?? [];
			$total = array_sum( $hit );
			$out[] = [
				'device'         => $device,
				'n'              => array_sum( $m['ttfb'] ?? [] ),
				'ttfb'           => self::p75( $m['ttfb'] ?? [], self::STEPS['ttfb'] ),
				'conn'           => self::p75( $m['conn'] ?? [], self::STEPS['conn'] ),
				'wait_cached'    => self::p75( $m['waith'] ?? [], self::STEPS['wait'] ),
				'wait_generated' => self::p75( $m['waitm'] ?? [], self::STEPS['wait'] ),
				'cached_pct'     => $total >= self::MIN_SAMPLES ? round( 100 * ( $hit[1] ?? 0 ) / $total, 1 ) : null,
				'cached_n'       => $total,
			];
		}
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
