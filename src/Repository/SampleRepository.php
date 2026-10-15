<?php
/**
 * Muestras crudas del perfilador ({prefix}itb_route_samples). Retención: 48 h.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Repository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- tabla propia, sin caché de objetos a propósito.

/**
 * Acceso a las muestras.
 */
final class SampleRepository {

	public const RETENTION_HOURS = 48;

	/** Máximo de muestras que se leen para un informe (las más recientes). */
	public const REPORT_LIMIT = 3000;

	public function __construct( private readonly \wpdb $db ) {
	}

	public function table(): string {
		return $this->db->prefix . 'itb_route_samples';
	}

	/**
	 * Inserta una muestra. Nunca muestra errores de base de datos en la página.
	 *
	 * @param array<string, int|string|null> $row Fila.
	 */
	public function insert( array $row ): bool {
		$previous = $this->db->suppress_errors( true );
		try {
			return false !== $this->db->insert( $this->table(), $row );
		} finally {
			$this->db->suppress_errors( $previous );
		}
	}

	/**
	 * Muestras recientes para informes (sin las de diagnóstico, que sesgan por ser de un admin).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $hours, string $requestType = '', int $limit = self::REPORT_LIMIT ): array {
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $hours ) * HOUR_IN_SECONDS );
		$sql   = $this->db->prepare(
			"SELECT created_at, request_type, route_key, status, ms, mem_kb, queries, cacheable, via_cf, source, anon, overhead_us, set_cookies, cache_control, phases, plugins_cost FROM %i WHERE created_at >= %s AND source < 2 AND (%s = '' OR request_type = %s) ORDER BY id DESC LIMIT %d",
			$this->table(),
			$since,
			$requestType,
			$requestType,
			max( 1, min( self::REPORT_LIMIT, $limit ) )
		);
		if ( null === $sql ) {
			return [];
		}
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		return is_array( $rows ) ? array_values( $rows ) : [];
	}

	/**
	 * Muestras de una hora concreta (para el agregado horario).
	 *
	 * @return list<array{request_type: string, route_key: string, ms: int, mem_kb: int, queries: int}>
	 */
	public function forHour( string $hourStartUtc ): array {
		$sql = $this->db->prepare(
			'SELECT request_type, route_key, ms, mem_kb, queries FROM %i WHERE created_at >= %s AND created_at < DATE_ADD(%s, INTERVAL 1 HOUR) AND source < 2',
			$this->table(),
			$hourStartUtc,
			$hourStartUtc
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
				'ms'           => (int) $row['ms'],
				'mem_kb'       => (int) $row['mem_kb'],
				'queries'      => (int) $row['queries'],
			];
		}
		return $out;
	}

	/**
	 * Conteo por origen en las últimas horas.
	 *
	 * @return array{random: int, intensive: int, diag: int}
	 */
	public function countBySource( int $hours ): array {
		$sql = $this->db->prepare(
			'SELECT source, COUNT(*) AS n FROM %i WHERE created_at >= %s GROUP BY source',
			$this->table(),
			gmdate( 'Y-m-d H:i:s', time() - max( 1, $hours ) * HOUR_IN_SECONDS )
		);
		$out = [
			'random'    => 0,
			'intensive' => 0,
			'diag'      => 0,
		];
		if ( null === $sql ) {
			return $out;
		}
		$rows  = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		$names = [
			0 => 'random',
			1 => 'intensive',
			2 => 'diag',
		];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$name = $names[ (int) $row['source'] ] ?? null;
			if ( null !== $name ) {
				$out[ $name ] = (int) $row['n'];
			}
		}
		return $out;
	}

	/**
	 * La muestra de diagnóstico más reciente (para "último request diagnosticado").
	 *
	 * @return array<string, mixed>|null
	 */
	public function latestDiagnostic(): ?array {
		$sql = $this->db->prepare( 'SELECT * FROM %i WHERE source = 2 ORDER BY id DESC LIMIT 1', $this->table() );
		if ( null === $sql ) {
			return null;
		}
		$row = $this->db->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		return is_array( $row ) ? $row : null;
	}

	public function deleteOlderThan( int $hours ): int {
		$sql = $this->db->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table(), gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ) );
		if ( null === $sql ) {
			return 0;
		}
		$deleted = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		return is_int( $deleted ) ? $deleted : 0;
	}
}
