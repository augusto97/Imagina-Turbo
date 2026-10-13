<?php
/**
 * Acceso a la tabla {prefix}itb_db_backups: filas que una acción de base de datos borró o cambió.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Repository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- tabla propia y tablas del núcleo en acciones explícitas del admin.

/**
 * Respaldos por lote.
 */
final class DbBackupRepository {

	/** Días que se guarda un lote. */
	public const RETENTION_DAYS = 30;

	public function __construct( private readonly \wpdb $db ) {
	}

	public function table(): string {
		return $this->db->prefix . 'itb_db_backups';
	}

	/**
	 * Guarda filas de un lote.
	 *
	 * @param list<array<string, mixed>> $rows Filas tal como están en la tabla.
	 * @return int Filas guardadas.
	 */
	public function save( string $batch, string $action, string $table, string $op, array $rows ): int {
		$saved = 0;
		$now   = gmdate( 'Y-m-d H:i:s' );
		foreach ( $rows as $row ) {
			$json = wp_json_encode( $row );
			if ( false === $json ) {
				continue;
			}
			$ok = $this->db->insert(
				$this->table(),
				[
					'batch'      => $batch,
					'created_at' => $now,
					'action'     => $action,
					'tbl'        => $table,
					'op'         => $op,
					'row_data'   => $json,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
			if ( false !== $ok ) {
				++$saved;
			}
		}
		return $saved;
	}

	/**
	 * Filas de un lote sin restaurar.
	 *
	 * @return list<array{id: int, tbl: string, op: string, row: array<string, mixed>}>
	 */
	public function rows( string $batch ): array {
		$sql  = $this->db->prepare( 'SELECT id, tbl, op, row_data FROM %i WHERE batch = %s AND restored_at IS NULL ORDER BY id', $this->table(), $batch );
		$rows = null === $sql ? [] : $this->db->get_results( $sql, ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$data  = json_decode( (string) $row['row_data'], true );
			$out[] = [
				'id'  => (int) $row['id'],
				'tbl' => (string) $row['tbl'],
				'op'  => (string) $row['op'],
				'row' => is_array( $data ) ? $data : [],
			];
		}
		return $out;
	}

	public function markRestored( string $batch ): void {
		$sql = $this->db->prepare( 'UPDATE %i SET restored_at = %s WHERE batch = %s AND restored_at IS NULL', $this->table(), gmdate( 'Y-m-d H:i:s' ), $batch );
		if ( null !== $sql ) {
			$this->db->query( $sql );
		}
	}

	/**
	 * Lotes recientes.
	 *
	 * @return list<array{batch: string, created_at: string, action: string, rows: int, restored: bool}>
	 */
	public function batches( int $limit = 30 ): array {
		$sql  = $this->db->prepare( 'SELECT batch, MIN(created_at) AS created_at, MIN(action) AS action, COUNT(*) AS n, MAX(restored_at IS NOT NULL) AS restored FROM %i GROUP BY batch ORDER BY MIN(id) DESC LIMIT %d', $this->table(), $limit );
		$rows = null === $sql ? [] : $this->db->get_results( $sql, ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = [
				'batch'      => (string) $row['batch'],
				'created_at' => (string) $row['created_at'],
				'action'     => (string) $row['action'],
				'rows'       => (int) $row['n'],
				'restored'   => 1 === (int) $row['restored'],
			];
		}
		return $out;
	}

	public function deleteOlderThan( int $days ): void {
		$sql = $this->db->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
		if ( null !== $sql ) {
			$this->db->query( $sql );
		}
	}
}
