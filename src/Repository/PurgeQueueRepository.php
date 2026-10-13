<?php
/**
 * Cola de purgas y de recalentado ({prefix}itb_purge_queue). Cada elemento es único: repetir una
 * purga dentro de la ventana de agrupación no agrega filas (INSERT IGNORE sobre el hash).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Repository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- tabla propia, sin caché de objetos a propósito.

/**
 * Acceso a la cola.
 */
final class PurgeQueueRepository {

	public const KIND_TAG  = 't';
	public const KIND_URL  = 'u';
	public const KIND_ALL  = 'a';
	public const KIND_WARM = 'w';

	public function __construct( private readonly \wpdb $db ) {
	}

	public function table(): string {
		return $this->db->prefix . 'itb_purge_queue';
	}

	/**
	 * Agrega elementos (los repetidos se ignoran).
	 *
	 * @param list<string> $items Elementos (tags sin prefijo o URLs).
	 */
	public function add( string $kind, array $items ): int {
		$added = 0;
		$now   = gmdate( 'Y-m-d H:i:s' );
		foreach ( array_chunk( array_values( array_unique( $items ) ), 200 ) as $chunk ) {
			$values = [];
			$args   = [ $this->table() ];
			foreach ( $chunk as $item ) {
				$values[] = '(%s, %s, %s, %s)';
				array_push( $args, $kind, md5( $kind . '|' . $item ), $item, $now );
			}
			$sql = $this->db->prepare( 'INSERT IGNORE INTO %i (kind, item_hash, item, created_at) VALUES ' . implode( ', ', $values ), $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo marcadores.
			if ( null !== $sql ) {
				$result = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
				$added += is_int( $result ) ? $result : 0;
			}
		}
		return $added;
	}

	/**
	 * Saca hasta $limit elementos de los tipos dados (los borra de la cola).
	 *
	 * @param list<string> $kinds Tipos.
	 * @return list<array{kind: string, item: string}>
	 */
	public function drain( array $kinds, int $limit ): array {
		$kinds = array_values( array_intersect( $kinds, [ self::KIND_TAG, self::KIND_URL, self::KIND_ALL, self::KIND_WARM ] ) );
		if ( [] === $kinds ) {
			return [];
		}
		$placeholders = implode( ', ', array_fill( 0, count( $kinds ), '%s' ) );
		$sql          = $this->db->prepare(
			"SELECT id, kind, item FROM %i WHERE kind IN ({$placeholders}) ORDER BY id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo marcadores.
			array_merge( [ $this->table() ], $kinds, [ max( 1, $limit ) ] )
		);
		if ( null === $sql ) {
			return [];
		}
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		if ( ! is_array( $rows ) || [] === $rows ) {
			return [];
		}
		$ids = array_map( static fn( array $r ): int => (int) $r['id'], $rows );
		$del = $this->db->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( [ $this->table() ], $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- solo marcadores.
		if ( null !== $del ) {
			$this->db->query( $del ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		}
		return array_map(
			static fn( array $r ): array => [
				'kind' => (string) $r['kind'],
				'item' => (string) $r['item'],
			],
			$rows
		);
	}

	/**
	 * Elementos pendientes por tipo.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$sql = $this->db->prepare( 'SELECT kind, COUNT(*) AS n FROM %i GROUP BY kind', $this->table() );
		$out = [
			self::KIND_TAG  => 0,
			self::KIND_URL  => 0,
			self::KIND_ALL  => 0,
			self::KIND_WARM => 0,
		];
		if ( null === $sql ) {
			return $out;
		}
		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (string) $row['kind'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Vacía un tipo (por ejemplo, el recalentado al pausarlo).
	 */
	public function clear( string $kind ): int {
		$sql = $this->db->prepare( 'DELETE FROM %i WHERE kind = %s', $this->table(), $kind );
		if ( null === $sql ) {
			return 0;
		}
		$deleted = $this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		return is_int( $deleted ) ? $deleted : 0;
	}
}
