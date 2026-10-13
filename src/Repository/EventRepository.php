<?php
/**
 * Acceso a la tabla {prefix}itb_events.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Repository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- tabla propia, sin caché de objetos a propósito.

/**
 * Consultas de eventos.
 */
final class EventRepository {

	private const MAX_PER_PAGE = 200;

	public function __construct( private readonly \wpdb $db ) {
	}

	public function table(): string {
		return $this->db->prefix . 'itb_events';
	}

	/**
	 * Inserta un evento. Devuelve el id o 0 si falló.
	 *
	 * @param array{module: string, type: string, severity: int, message: string, context: string|null, created_at: string} $row Fila.
	 */
	public function insert( array $row ): int {
		$ok = $this->db->insert(
			$this->table(),
			$row,
			[ '%s', '%s', '%d', '%s', '%s', '%s' ]
		);
		return false === $ok ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Lista paginada con filtros. El SQL es literal: los filtros vacíos se neutralizan con OR.
	 *
	 * @param array{module?: string, type?: string, severity?: int, search?: string, since?: string} $filters Filtros.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function query( array $filters, int $page = 1, int $perPage = 50 ): array {
		$page    = max( 1, $page );
		$perPage = max( 1, min( self::MAX_PER_PAGE, $perPage ) );

		$module   = $filters['module'] ?? '';
		$type     = $filters['type'] ?? '';
		$severity = $filters['severity'] ?? 0;
		$search   = isset( $filters['search'] ) && '' !== $filters['search'] ? '%' . $this->db->esc_like( $filters['search'] ) . '%' : '';
		$since    = $filters['since'] ?? '1970-01-01 00:00:00';
		$where    = [ $this->table(), $module, $module, $type, $type, $severity, $search, $search, $since ];

		$countSql = $this->db->prepare(
			"SELECT COUNT(*) FROM %i WHERE (%s = '' OR module = %s) AND (%s = '' OR type = %s) AND severity >= %d AND (%s = '' OR message LIKE %s) AND created_at >= %s",
			$where
		);
		$listSql  = $this->db->prepare(
			"SELECT id, created_at, module, type, severity, message, context FROM %i WHERE (%s = '' OR module = %s) AND (%s = '' OR type = %s) AND severity >= %d AND (%s = '' OR message LIKE %s) AND created_at >= %s ORDER BY id DESC LIMIT %d OFFSET %d",
			array_merge( $where, [ $perPage, ( $page - 1 ) * $perPage ] )
		);
		if ( null === $countSql || null === $listSql ) {
			return [
				'items' => [],
				'total' => 0,
			];
		}

		$total = (int) $this->db->get_var( $countSql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		$rows  = $this->db->get_results( $listSql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.

		$items = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$context = null;
			if ( is_string( $row['context'] ?? null ) && '' !== $row['context'] ) {
				$decoded = json_decode( $row['context'], true );
				$context = is_array( $decoded ) ? $decoded : null;
			}
			$items[] = [
				'id'         => (int) $row['id'],
				'created_at' => (string) $row['created_at'],
				'module'     => (string) $row['module'],
				'type'       => (string) $row['type'],
				'severity'   => (int) $row['severity'],
				'message'    => (string) $row['message'],
				'context'    => $context,
			];
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Módulos distintos registrados (para el filtro del admin).
	 *
	 * @return list<string>
	 */
	public function modules(): array {
		$sql = $this->db->prepare( 'SELECT DISTINCT module FROM %i ORDER BY module LIMIT 100', $this->table() );
		if ( null === $sql ) {
			return [];
		}
		$rows = $this->db->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		return array_values( array_map( 'strval', $rows ) );
	}

	/**
	 * Borra eventos anteriores a una fecha (UTC, Y-m-d H:i:s). Devuelve cuántos borró.
	 */
	public function deleteOlderThan( string $dateUtc ): int {
		$sql = $this->db->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table(), $dateUtc );
		if ( null === $sql ) {
			return 0;
		}
		$deleted = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		return is_int( $deleted ) ? $deleted : 0;
	}
}
