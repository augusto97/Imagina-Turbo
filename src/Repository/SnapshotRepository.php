<?php
/**
 * Acceso a la tabla {prefix}itb_cf_snapshots: estado de la zona antes de cada cambio (regla 4).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Repository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- tabla propia, sin caché de objetos a propósito.

/**
 * Snapshots de Cloudflare.
 */
final class SnapshotRepository {

	/** Se conservan los últimos N por zona. */
	public const KEEP = 50;

	public function __construct( private readonly \wpdb $db ) {
	}

	public function table(): string {
		return $this->db->prefix . 'itb_cf_snapshots';
	}

	/**
	 * Guarda un snapshot y devuelve su id (0 si falló).
	 *
	 * @param array<string, mixed> $payload Estado (reglas propias por fase y ajustes tocados).
	 */
	public function insert( string $zoneId, string $reason, array $payload ): int {
		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			return 0;
		}
		$ok = $this->db->insert(
			$this->table(),
			[
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'zone_id'    => substr( $zoneId, 0, 64 ),
				'reason'     => substr( $reason, 0, 100 ),
				'payload'    => $json,
			],
			[ '%s', '%s', '%s', '%s' ]
		);
		if ( false === $ok ) {
			return 0;
		}
		$id = (int) $this->db->insert_id;
		$this->prune( $zoneId );
		return $id;
	}

	/**
	 * @return array{id: int, created_at: string, zone_id: string, reason: string, payload: array<string, mixed>}|null
	 */
	public function find( int $id ): ?array {
		$sql = $this->db->prepare( 'SELECT id, created_at, zone_id, reason, payload FROM %i WHERE id = %d', $this->table(), $id );
		$row = null === $sql ? null : $this->db->get_row( $sql, ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$payload = json_decode( (string) $row['payload'], true );
		return [
			'id'         => (int) $row['id'],
			'created_at' => (string) $row['created_at'],
			'zone_id'    => (string) $row['zone_id'],
			'reason'     => (string) $row['reason'],
			'payload'    => is_array( $payload ) ? $payload : [],
		];
	}

	/**
	 * Lista sin el contenido.
	 *
	 * @return list<array{id: int, created_at: string, zone_id: string, reason: string}>
	 */
	public function latest( string $zoneId, int $limit = 20 ): array {
		$sql  = $this->db->prepare( 'SELECT id, created_at, zone_id, reason FROM %i WHERE zone_id = %s ORDER BY id DESC LIMIT %d', $this->table(), $zoneId, max( 1, min( 100, $limit ) ) );
		$rows = null === $sql ? [] : $this->db->get_results( $sql, ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = [
				'id'         => (int) $row['id'],
				'created_at' => (string) $row['created_at'],
				'zone_id'    => (string) $row['zone_id'],
				'reason'     => (string) $row['reason'],
			];
		}
		return $out;
	}

	private function prune( string $zoneId ): void {
		$sql = $this->db->prepare( 'SELECT id FROM %i WHERE zone_id = %s ORDER BY id DESC LIMIT 1 OFFSET %d', $this->table(), $zoneId, self::KEEP - 1 );
		$cut = null === $sql ? null : $this->db->get_var( $sql );
		if ( null !== $cut ) {
			$delete = $this->db->prepare( 'DELETE FROM %i WHERE zone_id = %s AND id < %d', $this->table(), $zoneId, (int) $cut );
			if ( null !== $delete ) {
				$this->db->query( $delete ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
			}
		}
	}
}
