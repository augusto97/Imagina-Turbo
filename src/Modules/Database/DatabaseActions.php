<?php
/**
 * Acciones de limpieza de la base de datos (docs/02 módulo 10). Cada una guarda antes las filas que
 * borra o cambia en itb_db_backups (un lote restaurable) y deja un evento en el registro. Nunca
 * borra tablas. Trabajan por tandas para no bloquear la base en sitios grandes.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Database;

use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Repository\DbBackupRepository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- acciones explícitas del admin con respaldo previo.

/**
 * Limpiezas y restauración.
 *
 * @phpstan-type Outcome array{action: string, batch: string, affected: int, backed_up: int, more: bool}
 */
final class DatabaseActions {

	public const ACTIONS = [ 'transients', 'action_scheduler', 'revisions', 'autoload' ];

	/** Filas como máximo por ejecución. */
	public const BATCH = 1000;

	/** Tablas que una restauración puede tocar (sin prefijo) y su clave para "update". */
	public const RESTORABLE = [
		'options'                 => 'option_name',
		'posts'                   => 'ID',
		'postmeta'                => 'meta_id',
		'actionscheduler_actions' => 'action_id',
		'actionscheduler_logs'    => 'log_id',
	];

	public function __construct(
		private readonly \wpdb $db,
		private readonly DbBackupRepository $backups,
		private readonly DatabaseAudit $audit,
		private readonly EventLog $events,
	) {
	}

	/**
	 * Transients expirados (los que siguen en wp_options).
	 *
	 * @return Outcome
	 */
	public function transients(): array {
		$batch    = self::batchId();
		$sql      = $this->db->prepare(
			'SELECT option_name FROM %i WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value < %d LIMIT %d',
			$this->db->options,
			$this->db->esc_like( '_transient_timeout_' ) . '%',
			$this->db->esc_like( '_site_transient_timeout_' ) . '%',
			time(),
			self::BATCH
		);
		$timeouts = null === $sql ? [] : array_map( 'strval', (array) $this->db->get_col( $sql ) );
		$names    = [];
		foreach ( $timeouts as $timeout ) {
			$names[] = $timeout;
			$names[] = str_replace( '_timeout_', '_', $timeout );
		}
		$rows   = $this->optionRows( $names );
		$saved  = $this->backups->save( $batch, 'transients', 'options', 'insert', $rows );
		$delete = count( $rows ) === $saved ? $this->deleteIn( $this->db->options, 'option_name', array_column( $rows, 'option_name' ) ) : 0;
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $this->finish( 'transients', $batch, $delete, $saved, count( $timeouts ) >= self::BATCH );
	}

	/**
	 * Acciones de Action Scheduler terminadas hace más de 30 días, con sus registros.
	 *
	 * @return Outcome
	 */
	public function actionScheduler(): array {
		$batch   = self::batchId();
		$actions = $this->audit->asTable( 'actions' );
		if ( null === $actions ) {
			return $this->finish( 'action_scheduler', $batch, 0, 0, false );
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DatabaseAudit::AS_DAYS * DAY_IN_SECONDS );
		// Terminadas hace más de 30 días (la fecha más reciente entre la programada y el último intento).
		$sql    = $this->db->prepare( "SELECT * FROM %i WHERE status IN ('complete','failed','canceled') AND GREATEST(scheduled_date_gmt, last_attempt_gmt) < %s ORDER BY action_id LIMIT %d", $actions, $cutoff, self::BATCH );
		$rows   = null === $sql ? [] : (array) $this->db->get_results( $sql, ARRAY_A );
		$ids    = array_map( 'intval', array_column( $rows, 'action_id' ) );
		$saved  = $this->backups->save( $batch, 'action_scheduler', 'actionscheduler_actions', 'insert', $rows );
		$logs   = $this->audit->asTable( 'logs' );
		$logRow = [];
		if ( null !== $logs && [] !== $ids ) {
			$logRow = $this->selectIn( $logs, 'action_id', $ids );
			$saved += $this->backups->save( $batch, 'action_scheduler', 'actionscheduler_logs', 'insert', $logRow );
		}
		$deleted = 0;
		if ( count( $rows ) + count( $logRow ) === $saved ) {
			if ( null !== $logs ) {
				$this->deleteIn( $logs, 'action_id', $ids );
			}
			$deleted = $this->deleteIn( $actions, 'action_id', $ids );
		}
		return $this->finish( 'action_scheduler', $batch, $deleted, $saved, count( $rows ) >= self::BATCH );
	}

	/**
	 * Revisiones que sobran: se conservan las $keep más recientes de cada contenido.
	 *
	 * @return Outcome
	 */
	public function revisions( int $keep ): array {
		$batch   = self::batchId();
		$keep    = max( 1, $keep );
		$sql     = $this->db->prepare( 'SELECT post_parent FROM %i WHERE post_type = %s GROUP BY post_parent HAVING COUNT(*) > %d LIMIT 500', $this->db->posts, 'revision', $keep );
		$parents = null === $sql ? [] : array_map( 'intval', (array) $this->db->get_col( $sql ) );
		$ids     = [];
		foreach ( $parents as $parent ) {
			$sql = $this->db->prepare( 'SELECT ID FROM %i WHERE post_type = %s AND post_parent = %d ORDER BY post_date DESC, ID DESC', $this->db->posts, 'revision', $parent );
			$all = null === $sql ? [] : array_map( 'intval', (array) $this->db->get_col( $sql ) );
			$ids = array_merge( $ids, array_slice( $all, $keep ) );
			if ( count( $ids ) >= self::BATCH ) {
				break;
			}
		}
		$ids   = array_values( array_slice( $ids, 0, self::BATCH ) );
		$posts = [] === $ids ? [] : $this->selectIn( $this->db->posts, 'ID', $ids );
		$meta  = [] === $ids ? [] : $this->selectIn( $this->db->postmeta, 'post_id', $ids );
		$saved = $this->backups->save( $batch, 'revisions', 'posts', 'insert', $posts ) + $this->backups->save( $batch, 'revisions', 'postmeta', 'insert', $meta );
		$done  = 0;
		if ( count( $posts ) + count( $meta ) === $saved ) {
			foreach ( $ids as $id ) {
				if ( wp_delete_post_revision( $id ) ) {
					++$done;
				}
			}
		}
		return $this->finish( 'revisions', $batch, $done, $saved, count( $ids ) >= self::BATCH );
	}

	/**
	 * Autoload desactivado en opciones de plugins inactivos (solo las que la auditoría atribuye a uno).
	 *
	 * @param list<string> $names Opciones elegidas en el admin.
	 * @return Outcome|\WP_Error
	 */
	public function autoload( array $names ): array|\WP_Error {
		$batch   = self::batchId();
		$plugins = DatabaseAudit::plugins();
		$names   = array_values( array_unique( array_filter( $names, 'is_string' ) ) );
		foreach ( $names as $name ) {
			if ( 'inactive' !== DatabaseAudit::owner( $name, $plugins )['kind'] ) {
				/* translators: %s: opción */
				return new \WP_Error( 'itb_option', sprintf( __( '%s no es de un plugin inactivo: no se toca.', 'imagina-turbo' ), $name ), [ 'status' => 400 ] );
			}
		}
		$values = DatabaseAudit::autoloadValues();
		$rows   = array_values( array_filter( $this->optionRows( array_slice( $names, 0, 200 ), false ), static fn( array $r ): bool => in_array( (string) $r['autoload'], $values, true ) ) );
		$saved  = $this->backups->save( $batch, 'autoload', 'options', 'update', $rows );
		$done   = 0;
		if ( count( $rows ) === $saved && [] !== $rows ) {
			$changed = array_column( $rows, 'option_name' );
			if ( function_exists( 'wp_set_option_autoload_values' ) ) {
				wp_set_option_autoload_values( array_fill_keys( $changed, false ) );
			} else {
				foreach ( $changed as $name ) {
					$this->db->update( $this->db->options, [ 'autoload' => 'no' ], [ 'option_name' => $name ] );
				}
			}
			$done = count( $changed );
		}
		wp_cache_delete( 'alloptions', 'options' );
		return $this->finish( 'autoload', $batch, $done, $saved, false );
	}

	/**
	 * Restaura un lote: vuelve a insertar las filas borradas (las que ya existan se dejan) y vuelve a
	 * poner los valores cambiados.
	 *
	 * @return array{batch: string, restored: int, skipped: int}|\WP_Error
	 */
	public function restore( string $batch ): array|\WP_Error {
		$rows = $this->backups->rows( $batch );
		if ( [] === $rows ) {
			return new \WP_Error( 'itb_batch', __( 'No hay nada que restaurar en ese lote.', 'imagina-turbo' ), [ 'status' => 404 ] );
		}
		$restored = 0;
		$skipped  = 0;
		$suppress = $this->db->suppress_errors( true );
		foreach ( $rows as $item ) {
			$key = self::RESTORABLE[ $item['tbl'] ] ?? null;
			if ( null === $key || [] === $item['row'] ) {
				++$skipped;
				continue;
			}
			$table = $this->db->prefix . $item['tbl'];
			$row   = array_map( static fn( $v ): string|int|float|null => is_scalar( $v ) ? ( is_bool( $v ) ? (int) $v : $v ) : null, $item['row'] );
			if ( 'update' === $item['op'] ) {
				$id = $row[ $key ] ?? null;
				unset( $row[ $key ] );
				$ok = null !== $id && false !== $this->db->update( $table, $row, [ $key => $id ] );
			} else {
				$ok = false !== $this->db->insert( $table, $row );
			}
			if ( $ok ) {
				++$restored;
			} else {
				++$skipped;
			}
		}
		$this->db->suppress_errors( $suppress );
		$this->backups->markRestored( $batch );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'posts' );
		}
		$this->events->warning(
			'database',
			'restore',
			__( 'Lote de base de datos restaurado.', 'imagina-turbo' ),
			[
				'batch'    => $batch,
				'restored' => $restored,
				'skipped'  => $skipped,
				'user'     => get_current_user_id(),
			]
		);
		return [
			'batch'    => $batch,
			'restored' => $restored,
			'skipped'  => $skipped,
		];
	}

	/**
	 * Filas completas de wp_options por nombre.
	 *
	 * @param list<string> $names Nombres.
	 * @return list<array<string, mixed>>
	 */
	private function optionRows( array $names, bool $full = true ): array {
		if ( [] === $names ) {
			return [];
		}
		$rows = $this->selectIn( $this->db->options, 'option_name', $names );
		if ( ! $full ) {
			$rows = array_map(
				static fn( array $r ): array => [
					'option_name' => $r['option_name'],
					'autoload'    => $r['autoload'],
				],
				$rows
			);
		}
		return $rows;
	}

	/**
	 * @param list<int|string> $values Valores.
	 * @return list<array<string, mixed>>
	 */
	private function selectIn( string $table, string $column, array $values ): array {
		$out = [];
		foreach ( array_chunk( $values, 200 ) as $chunk ) {
			$marks = implode( ',', array_fill( 0, count( $chunk ), is_int( $chunk[0] ) ? '%d' : '%s' ) );
			$sql   = $this->db->prepare( "SELECT * FROM %i WHERE %i IN ({$marks})", $table, $column, ...$chunk ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- marcadores generados.
			$rows  = null === $sql ? [] : $this->db->get_results( $sql, ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param list<int|string> $values Valores.
	 */
	private function deleteIn( string $table, string $column, array $values ): int {
		$deleted = 0;
		foreach ( array_chunk( $values, 200 ) as $chunk ) {
			$marks    = implode( ',', array_fill( 0, count( $chunk ), is_int( $chunk[0] ) ? '%d' : '%s' ) );
			$sql      = $this->db->prepare( "DELETE FROM %i WHERE %i IN ({$marks})", $table, $column, ...$chunk ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- marcadores generados.
			$deleted += null === $sql ? 0 : (int) $this->db->query( $sql );
		}
		return $deleted;
	}

	/**
	 * @return Outcome
	 */
	private function finish( string $action, string $batch, int $affected, int $saved, bool $more ): array {
		if ( $affected > 0 || $saved > 0 ) {
			$this->events->info(
				'database',
				$action,
				/* translators: 1: acción, 2: filas */
				sprintf( __( 'Base de datos: %1$s (%2$d filas, con respaldo).', 'imagina-turbo' ), $action, $affected ),
				[
					'batch'     => $batch,
					'affected'  => $affected,
					'backed_up' => $saved,
					'user'      => get_current_user_id(),
				]
			);
		}
		return [
			'action'    => $action,
			'batch'     => $affected > 0 ? $batch : '',
			'affected'  => $affected,
			'backed_up' => $saved,
			'more'      => $more,
		];
	}

	public static function batchId(): string {
		return substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
	}
}
