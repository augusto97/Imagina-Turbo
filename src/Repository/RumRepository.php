<?php
/**
 * Contadores del RUM ({prefix}itb_rum_hist e itb_rum_events). Retención: 30 días.
 *
 * Las escrituras las hace RumCollector desde el loader MU; aquí solo se lee y se limpia.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Repository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- tablas propias, sin caché de objetos a propósito.

/**
 * Lectura agregada.
 */
final class RumRepository {

	public const RETENTION_DAYS = 30;

	public function __construct( private readonly \wpdb $db ) {
	}

	/**
	 * Histogramas sumados de los últimos días.
	 *
	 * @return list<array{template: string, device: string, metric: string, bucket: int|string, n: int|string}>
	 */
	public function histogram( int $days ): array {
		$sql  = $this->db->prepare(
			'SELECT template, device, metric, bucket, SUM(n) AS n FROM %i WHERE hour >= %s GROUP BY template, device, metric, bucket',
			$this->db->prefix . 'itb_rum_hist',
			gmdate( 'Y-m-d H:00:00', time() - max( 1, $days ) * DAY_IN_SECONDS )
		);
		$rows = null === $sql ? null : $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		/** @var list<array{template: string, device: string, metric: string, bucket: int|string, n: int|string}> */
		return is_array( $rows ) ? array_values( $rows ) : [];
	}

	/**
	 * Eventos de un tipo (lcp, next, nrr) sumados, con el mapa de visitantes combinado (BIT_OR). Sin
	 * $byDevice, móvil y escritorio se suman en una fila (device "*").
	 *
	 * @return list<array{template: string, device: string, value: string, n: int|string, bits: string}>
	 */
	public function events( string $kind, int $days, int $limit = 500, bool $byDevice = true ): array {
		$since = gmdate( 'Y-m-d', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$limit = max( 1, min( 2000, $limit ) );
		$table = $this->db->prefix . 'itb_rum_events';
		$sql   = $byDevice
			? $this->db->prepare( 'SELECT template, device, value, SUM(n) AS n, CAST(BIT_OR(ip_bits) AS CHAR) AS bits FROM %i WHERE kind = %s AND day >= %s GROUP BY template, device, value ORDER BY n DESC LIMIT %d', $table, $kind, $since, $limit )
			: $this->db->prepare( "SELECT template, '*' AS device, value, SUM(n) AS n, CAST(BIT_OR(ip_bits) AS CHAR) AS bits FROM %i WHERE kind = %s AND day >= %s GROUP BY template, value ORDER BY n DESC LIMIT %d", $table, $kind, $since, $limit );
		$rows  = null === $sql ? null : $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
		/** @var list<array{template: string, device: string, value: string, n: int|string, bits: string}> */
		return is_array( $rows ) ? array_values( $rows ) : [];
	}

	public function deleteOlderThan( int $days ): void {
		foreach ( [
			'DELETE FROM %i WHERE hour < %s' => [ 'itb_rum_hist', gmdate( 'Y-m-d H:00:00', time() - $days * DAY_IN_SECONDS ) ],
			'DELETE FROM %i WHERE day < %s'  => [ 'itb_rum_events', gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ],
		] as $query => [ $table, $before ] ) {
			$sql = $this->db->prepare( $query, $this->db->prefix . $table, $before );
			if ( null !== $sql ) {
				$this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- preparado arriba.
			}
		}
	}
}
