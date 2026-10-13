<?php
/**
 * Agregados horarios del perfilador ({prefix}itb_metrics_hourly). Retención: 30 días.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Repository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- tabla propia, sin caché de objetos a propósito.

/**
 * Acceso a los agregados.
 */
final class MetricsRepository {

	public const RETENTION_DAYS = 30;

	public function __construct( private readonly \wpdb $db ) {
	}

	public function table(): string {
		return $this->db->prefix . 'itb_metrics_hourly';
	}

	/**
	 * Inserta o reemplaza el agregado de una hora/ruta (idempotente: repetir el rollup no duplica).
	 *
	 * @param array{hour: string, request_type: string, route_key: string, n: int, ms_p50: int, ms_p95: int, mem_p50: int, mem_p95: int, queries_avg: int} $row Fila.
	 */
	public function upsert( array $row ): bool {
		$sql = $this->db->prepare(
			'INSERT INTO %i (hour, request_type, route_key, n, ms_p50, ms_p95, mem_p50, mem_p95, queries_avg) VALUES (%s, %s, %s, %d, %d, %d, %d, %d, %d)
			ON DUPLICATE KEY UPDATE n = VALUES(n), ms_p50 = VALUES(ms_p50), ms_p95 = VALUES(ms_p95), mem_p50 = VALUES(mem_p50), mem_p95 = VALUES(mem_p95), queries_avg = VALUES(queries_avg)',
			$this->table(),
			$row['hour'],
			$row['request_type'],
			$row['route_key'],
			$row['n'],
			$row['ms_p50'],
			$row['ms_p95'],
			$row['mem_p50'],
			$row['mem_p95'],
			$row['queries_avg']
		);
		return null !== $sql && false !== $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
	}

	/**
	 * Agregados de un periodo, combinados por ruta (p50 ponderado por n, p95 máximo).
	 *
	 * @return list<array{request_type: string, route_key: string, n: int, ms_p50: int, ms_p95: int, mem_p95: int, queries_avg: int}>
	 */
	public function routes( int $days, string $requestType = '', int $limit = 50 ): array {
		$sql = $this->db->prepare(
			"SELECT request_type, route_key, SUM(n) AS n, ROUND(SUM(ms_p50 * n) / SUM(n)) AS ms_p50, MAX(ms_p95) AS ms_p95, MAX(mem_p95) AS mem_p95, ROUND(SUM(queries_avg * n) / SUM(n)) AS queries_avg
			FROM %i WHERE hour >= %s AND (%s = '' OR request_type = %s) GROUP BY request_type, route_key ORDER BY SUM(ms_p50 * n) DESC LIMIT %d",
			$this->table(),
			gmdate( 'Y-m-d H:00:00', time() - max( 1, $days ) * DAY_IN_SECONDS ),
			$requestType,
			$requestType,
			max( 1, min( 200, $limit ) )
		);
		if ( null === $sql ) {
			return [];
		}
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = [
				'request_type' => (string) $row['request_type'],
				'route_key'    => (string) $row['route_key'],
				'n'            => (int) $row['n'],
				'ms_p50'       => (int) $row['ms_p50'],
				'ms_p95'       => (int) $row['ms_p95'],
				'mem_p95'      => (int) $row['mem_p95'],
				'queries_avg'  => (int) $row['queries_avg'],
			];
		}
		return $out;
	}

	/**
	 * Filas de un intervalo de horas (para la telemetría del hub).
	 *
	 * @return list<array{hour: string, request_type: string, route_key: string, n: int, ms_p50: int, ms_p95: int, mem_p50: int, mem_p95: int, queries_avg: int}>
	 */
	public function between( string $fromUtc, string $toUtc ): array {
		$sql = $this->db->prepare(
			'SELECT hour, request_type, route_key, n, ms_p50, ms_p95, mem_p50, mem_p95, queries_avg FROM %i WHERE hour >= %s AND hour < %s ORDER BY hour LIMIT 5000',
			$this->table(),
			$fromUtc,
			$toUtc
		);
		if ( null === $sql ) {
			return [];
		}
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = [
				'hour'         => (string) $row['hour'],
				'request_type' => (string) $row['request_type'],
				'route_key'    => (string) $row['route_key'],
				'n'            => (int) $row['n'],
				'ms_p50'       => (int) $row['ms_p50'],
				'ms_p95'       => (int) $row['ms_p95'],
				'mem_p50'      => (int) $row['mem_p50'],
				'mem_p95'      => (int) $row['mem_p95'],
				'queries_avg'  => (int) $row['queries_avg'],
			];
		}
		return $out;
	}

	public function deleteOlderThan( int $days ): int {
		$sql = $this->db->prepare( 'DELETE FROM %i WHERE hour < %s', $this->table(), gmdate( 'Y-m-d H:00:00', time() - $days * DAY_IN_SECONDS ) );
		if ( null === $sql ) {
			return 0;
		}
		$deleted = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		return is_int( $deleted ) ? $deleted : 0;
	}
}
