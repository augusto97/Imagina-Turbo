<?php
/**
 * Accesos del conector (tabla itb_connector_tokens).
 *
 * Cada fila es una conexión de Claude: el access token (1 hora) y el refresh token (30 días desde la
 * última renovación) se guardan solo como hash SHA-256. Cada renovación entrega un refresh token nuevo
 * y recuerda el anterior: si alguien presenta uno ya usado, el acceso entero se revoca (OAuth 2.1,
 * rotación para clientes públicos).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Connector;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- tabla propia (prefijo + nombre fijo), valores siempre con prepare; sin caché de objetos a propósito (son credenciales).

/**
 * Lectura y escritura de los accesos.
 *
 * @phpstan-type Grant array{id: int, user_id: int, client_id: string, client_name: string, scope: string, resource: string, access_expires: int, refresh_expires: int, created_at: int, last_used_at: int, last_ip: string, revoked: int}
 */
final class TokenStore {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'itb_connector_tokens';
	}

	/**
	 * Crea un acceso. Devuelve los tokens en claro (la única vez que existen así).
	 *
	 * @return array{access_token: string, refresh_token: string, id: int}|null
	 */
	public function create( int $userId, string $clientId, string $clientName, string $scope, string $resource, string $ip ): ?array {
		global $wpdb;
		$this->prune();
		$active = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE revoked = 0 AND refresh_expires > %d', time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla propio.
		if ( $active >= Connector::MAX_CONNECTIONS ) {
			// El más antiguo sin uso reciente deja sitio: no se bloquea a quien conecta de nuevo.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->table() . ' SET revoked = 1 WHERE revoked = 0 ORDER BY last_used_at ASC, id ASC LIMIT %d', $active - Connector::MAX_CONNECTIONS + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla propio.
		}
		$access  = Connector::secret( 'itbat_' );
		$refresh = Connector::secret( 'itbrt_' );
		$now     = time();
		$ok      = $wpdb->insert(
			$this->table(),
			[
				'user_id'         => $userId,
				'client_id'       => substr( $clientId, 0, 255 ),
				'client_name'     => substr( $clientName, 0, 100 ),
				'scope'           => $scope,
				'resource'        => substr( $resource, 0, 255 ),
				'access_hash'     => Connector::hash( $access ),
				'access_expires'  => $now + Connector::ACCESS_TTL,
				'refresh_hash'    => Connector::hash( $refresh ),
				'refresh_expires' => $now + Connector::REFRESH_TTL,
				'created_at'      => $now,
				'last_used_at'    => $now,
				'last_ip'         => substr( $ip, 0, 45 ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s' ]
		);
		if ( false === $ok ) {
			return null;
		}
		return [
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'id'            => (int) $wpdb->insert_id,
		];
	}

	/**
	 * Acceso de un access token válido (no revocado ni caducado).
	 *
	 * @return Grant|null
	 */
	public function byAccess( string $token ): ?array {
		global $wpdb;
		if ( ! str_starts_with( $token, 'itbat_' ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE access_hash = %s AND revoked = 0 AND access_expires > %d LIMIT 1', Connector::hash( $token ), time() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla propio.
		return is_array( $row ) ? self::grant( $row ) : null;
	}

	/**
	 * Renueva con un refresh token: tokens nuevos, o null. Un refresh token ya usado revoca el acceso.
	 *
	 * @return array{access_token: string, refresh_token: string, grant: Grant}|null
	 */
	public function rotate( string $refresh, string $clientId, string $ip ): ?array {
		global $wpdb;
		if ( ! str_starts_with( $refresh, 'itbrt_' ) ) {
			return null;
		}
		$hash = Connector::hash( $refresh );
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE refresh_hash = %s LIMIT 1', $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla propio.
		if ( ! is_array( $row ) ) {
			// ¿Un refresh token ya rotado? Alguien lo copió: se corta ese acceso.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->table() . ' SET revoked = 1 WHERE prev_refresh_hash = %s', $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla propio.
			return null;
		}
		$grant = self::grant( $row );
		if ( 1 === $grant['revoked'] || $grant['refresh_expires'] <= time() || $grant['client_id'] !== $clientId || ! Connector::userCan( $grant['user_id'] ) ) {
			return null;
		}
		$access     = Connector::secret( 'itbat_' );
		$newRefresh = Connector::secret( 'itbrt_' );
		$now        = time();
		$updated    = $wpdb->update(
			$this->table(),
			[
				'access_hash'       => Connector::hash( $access ),
				'access_expires'    => $now + Connector::ACCESS_TTL,
				'refresh_hash'      => Connector::hash( $newRefresh ),
				'prev_refresh_hash' => $hash,
				'refresh_expires'   => $now + Connector::REFRESH_TTL,
				'last_used_at'      => $now,
				'last_ip'           => substr( $ip, 0, 45 ),
			],
			[
				'id'           => $grant['id'],
				'refresh_hash' => $hash,
			],
			[ '%s', '%d', '%s', '%s', '%d', '%d', '%s' ],
			[ '%d', '%s' ]
		);
		// Dos renovaciones a la vez con el mismo token: solo gana una.
		if ( 1 !== $updated ) {
			return null;
		}
		return [
			'access_token'  => $access,
			'refresh_token' => $newRefresh,
			'grant'         => $grant,
		];
	}

	public function touch( int $id, string $ip ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			[
				'last_used_at' => time(),
				'last_ip'      => substr( $ip, 0, 45 ),
			],
			[ 'id' => $id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Revoca un acceso por id (admin) o por token (endpoint de revocación).
	 */
	public function revoke( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->table(), [ 'revoked' => 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
	}

	public function revokeToken( string $token ): void {
		global $wpdb;
		$hash = Connector::hash( $token );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->table() . ' SET revoked = 1 WHERE access_hash = %s OR refresh_hash = %s', $hash, $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla propio.
	}

	public function revokeAll(): int {
		global $wpdb;
		return (int) $wpdb->query( 'UPDATE ' . $this->table() . ' SET revoked = 1 WHERE revoked = 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla propio.
	}

	/**
	 * Accesos activos para el admin (sin hashes).
	 *
	 * @return list<Grant>
	 */
	public function active(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE revoked = 0 AND refresh_expires > %d ORDER BY last_used_at DESC LIMIT 50', time() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla propio.
		return array_values( array_map( [ self::class, 'grant' ], is_array( $rows ) ? $rows : [] ) );
	}

	/**
	 * Borra los accesos revocados o caducados hace más de 30 días.
	 */
	public function prune(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE refresh_expires < %d', time() - 30 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla propio.
	}

	/**
	 * @param array<string, mixed> $row Fila.
	 * @return Grant
	 */
	private static function grant( array $row ): array {
		return [
			'id'              => (int) ( $row['id'] ?? 0 ),
			'user_id'         => (int) ( $row['user_id'] ?? 0 ),
			'client_id'       => (string) ( $row['client_id'] ?? '' ),
			'client_name'     => (string) ( $row['client_name'] ?? '' ),
			'scope'           => (string) ( $row['scope'] ?? '' ),
			'resource'        => (string) ( $row['resource'] ?? '' ),
			'access_expires'  => (int) ( $row['access_expires'] ?? 0 ),
			'refresh_expires' => (int) ( $row['refresh_expires'] ?? 0 ),
			'created_at'      => (int) ( $row['created_at'] ?? 0 ),
			'last_used_at'    => (int) ( $row['last_used_at'] ?? 0 ),
			'last_ip'         => (string) ( $row['last_ip'] ?? '' ),
			'revoked'         => (int) ( $row['revoked'] ?? 0 ),
		];
	}
}
