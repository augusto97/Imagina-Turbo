<?php
/**
 * Auditoría de la base de datos (docs/02 módulo 10). Solo lectura y solo en el admin, a petición.
 * El resultado se guarda en la opción itb_db_audit (autoload = no).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- consultas de diagnóstico en el admin, a petición.

/**
 * Consultas de auditoría.
 *
 * @phpstan-type Owner array{kind: string, plugin: string}
 */
final class DatabaseAudit {

	public const OPTION = 'itb_db_audit';

	/** Antigüedad a partir de la cual una acción terminada de Action Scheduler sobra. */
	public const AS_DAYS = 30;

	/** Revisiones que se conservan por contenido si no hay ajuste. */
	public const DEFAULT_KEEP = 5;

	/** Opciones del núcleo que nunca se atribuyen a un plugin (nombres exactos y prefijos). */
	public const CORE = [ 'siteurl', 'home', 'blogname', 'active_plugins', 'cron', 'rewrite_rules', 'template', 'stylesheet', 'sidebars_widgets', 'widget_', 'theme_mods_', 'user_roles', '_transient_', '_site_transient_', 'can_compress_scripts', 'recently_activated', 'uninstall_plugins', 'auto_update_' ];

	public function __construct( private readonly \wpdb $db ) {
	}

	/**
	 * Auditoría completa.
	 *
	 * @param int $keep Revisiones a conservar por contenido.
	 * @return array<string, mixed>
	 */
	public function run( int $keep ): array {
		$plugins = self::plugins();
		$result  = [
			'generated_at'     => time(),
			'autoload'         => $this->autoload( $plugins ),
			'inactive_options' => $this->inactiveOptions( $plugins ),
			'transients'       => [
				'expired'      => $this->expiredTransients(),
				'object_cache' => true === wp_using_ext_object_cache(),
			],
			'action_scheduler' => $this->actionScheduler(),
			'revisions'        => $this->revisions( $keep ),
			'orphan_postmeta'  => $this->orphanPostmeta(),
			'hpos'             => $this->hpos(),
			'postmeta_index'   => $this->postmetaIndex(),
			'tables'           => $this->tables(),
		];
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/**
	 * Valores de autoload que WordPress carga en cada request.
	 *
	 * @return list<string>
	 */
	public static function autoloadValues(): array {
		return function_exists( 'wp_autoload_values_to_autoload' ) ? array_values( array_map( 'strval', wp_autoload_values_to_autoload() ) ) : [ 'yes' ];
	}

	/**
	 * Plugins instalados: carpeta => [nombre, activo].
	 *
	 * @return array<string, array{name: string, active: bool}>
	 */
	public static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', [] );
		$out    = [];
		foreach ( get_plugins() as $file => $data ) {
			$folder = str_contains( (string) $file, '/' ) ? strstr( (string) $file, '/', true ) : basename( (string) $file, '.php' );
			if ( false === $folder ) {
				continue;
			}
			$out[ $folder ] = [
				'name'   => (string) ( $data['Name'] ?? $folder ),
				'active' => ( $out[ $folder ]['active'] ?? false ) || in_array( $file, $active, true ),
			];
		}
		return $out;
	}

	/**
	 * Prefijos de opción de una carpeta de plugin (mi-plugin → mi-plugin, mi_plugin).
	 *
	 * @return list<string>
	 */
	public static function prefixes( string $folder ): array {
		$folder = strtolower( $folder );
		$folder = (string) preg_replace( '/-(lite|free|pro|premium)$/', '', $folder );
		if ( strlen( $folder ) < 4 ) {
			return [];
		}
		return array_values( array_unique( [ $folder, str_replace( '-', '_', $folder ) ] ) );
	}

	/**
	 * A quién pertenece una opción: núcleo, plugin activo, plugin inactivo o desconocido. Si coincide
	 * con un plugin activo y uno inactivo a la vez, se trata como activo (nunca se toca).
	 *
	 * @param array<string, array{name: string, active: bool}> $plugins Plugins instalados.
	 * @return Owner
	 */
	public static function owner( string $option, array $plugins ): array {
		$name = strtolower( $option );
		// {prefijo}user_roles: los roles del sitio.
		if ( str_ends_with( $name, '_user_roles' ) ) {
			return [
				'kind'   => 'core',
				'plugin' => '',
			];
		}
		foreach ( self::CORE as $core ) {
			if ( $name === $core || ( str_ends_with( $core, '_' ) && str_starts_with( $name, $core ) ) ) {
				return [
					'kind'   => 'core',
					'plugin' => '',
				];
			}
		}
		$inactive = '';
		foreach ( $plugins as $folder => $plugin ) {
			foreach ( self::prefixes( (string) $folder ) as $prefix ) {
				if ( $name === $prefix || str_starts_with( $name, $prefix . '_' ) || str_starts_with( $name, $prefix . '-' ) ) {
					if ( $plugin['active'] ) {
						return [
							'kind'   => 'active',
							'plugin' => (string) $folder,
						];
					}
					$inactive = (string) $folder;
				}
			}
		}
		return '' !== $inactive ? [
			'kind'   => 'inactive',
			'plugin' => $inactive,
		] : [
			'kind'   => 'unknown',
			'plugin' => '',
		];
	}

	/**
	 * Tamaño del autoload y las 20 opciones más pesadas.
	 *
	 * @param array<string, array{name: string, active: bool}> $plugins Plugins instalados.
	 * @return array{count: int, bytes: int, top: list<array{name: string, bytes: int, owner: string, plugin: string}>}
	 */
	private function autoload( array $plugins ): array {
		$values = self::autoloadValues();
		$in     = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		$sql    = $this->db->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes FROM %i WHERE autoload IN ({$in})", $this->db->options, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- marcadores generados.
		$total  = null === $sql ? null : $this->db->get_row( $sql, ARRAY_A );
		$sql    = $this->db->prepare( "SELECT option_name, LENGTH(option_value) AS bytes FROM %i WHERE autoload IN ({$in}) ORDER BY bytes DESC LIMIT 20", $this->db->options, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- marcadores generados.
		$rows   = null === $sql ? [] : $this->db->get_results( $sql, ARRAY_A );
		$top    = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$owner = self::owner( (string) $row['option_name'], $plugins );
			$top[] = [
				'name'   => (string) $row['option_name'],
				'bytes'  => (int) $row['bytes'],
				'owner'  => $owner['kind'],
				'plugin' => $owner['plugin'],
			];
		}
		return [
			'count' => is_array( $total ) ? (int) $total['n'] : 0,
			'bytes' => is_array( $total ) ? (int) $total['bytes'] : 0,
			'top'   => $top,
		];
	}

	/**
	 * Opciones de plugins instalados pero inactivos (las de autoload primero).
	 *
	 * @param array<string, array{name: string, active: bool}> $plugins Plugins instalados.
	 * @return array{count: int, bytes: int, autoloaded: int, items: list<array{name: string, bytes: int, autoload: bool, plugin: string}>}
	 */
	private function inactiveOptions( array $plugins ): array {
		$values = self::autoloadValues();
		$items  = [];
		$count  = 0;
		$bytes  = 0;
		$auto   = 0;
		foreach ( $plugins as $folder => $plugin ) {
			if ( $plugin['active'] ) {
				continue;
			}
			foreach ( self::prefixes( (string) $folder ) as $prefix ) {
				$sql  = $this->db->prepare( 'SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM %i WHERE option_name LIKE %s LIMIT 500', $this->db->options, $this->db->esc_like( $prefix ) . '%' );
				$rows = null === $sql ? [] : $this->db->get_results( $sql, ARRAY_A );
				foreach ( is_array( $rows ) ? $rows : [] as $row ) {
					$name = (string) $row['option_name'];
					if ( isset( $items[ $name ] ) || 'inactive' !== self::owner( $name, $plugins )['kind'] ) {
						continue;
					}
					$autoload       = in_array( (string) $row['autoload'], $values, true );
					$items[ $name ] = [
						'name'     => $name,
						'bytes'    => (int) $row['bytes'],
						'autoload' => $autoload,
						'plugin'   => (string) $folder,
					];
					++$count;
					$bytes += (int) $row['bytes'];
					$auto  += $autoload ? 1 : 0;
				}
			}
		}
		$items = array_values( $items );
		usort( $items, static fn( array $a, array $b ): int => [ $b['autoload'], $b['bytes'] ] <=> [ $a['autoload'], $a['bytes'] ] );
		return [
			'count'      => $count,
			'bytes'      => $bytes,
			'autoloaded' => $auto,
			'items'      => array_slice( $items, 0, 100 ),
		];
	}

	public function expiredTransients(): int {
		$sql = $this->db->prepare(
			'SELECT COUNT(*) FROM %i WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value < %d',
			$this->db->options,
			$this->db->esc_like( '_transient_timeout_' ) . '%',
			$this->db->esc_like( '_site_transient_timeout_' ) . '%',
			time()
		);
		return null === $sql ? 0 : (int) $this->db->get_var( $sql );
	}

	/**
	 * Tablas de Action Scheduler (si existen).
	 */
	public function asTable( string $name ): ?string {
		$table = $this->db->prefix . 'actionscheduler_' . $name;
		$sql   = $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) );
		return null !== $sql && $table === $this->db->get_var( $sql ) ? $table : null;
	}

	/**
	 * @return array{available: bool, old: int, complete: int, failed: int, canceled: int, pending: int, total: int, logs: int}
	 */
	private function actionScheduler(): array {
		$actions = $this->asTable( 'actions' );
		$empty   = [
			'available' => false,
			'old'       => 0,
			'complete'  => 0,
			'failed'    => 0,
			'canceled'  => 0,
			'pending'   => 0,
			'total'     => 0,
			'logs'      => 0,
		];
		if ( null === $actions ) {
			return $empty;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::AS_DAYS * DAY_IN_SECONDS );
		$sql    = $this->db->prepare( 'SELECT status, COUNT(*) AS n, SUM(GREATEST(scheduled_date_gmt, last_attempt_gmt) < %s) AS old FROM %i GROUP BY status', $cutoff, $actions );
		$rows   = null === $sql ? [] : $this->db->get_results( $sql, ARRAY_A );
		$out    = array_merge( $empty, [ 'available' => true ] );
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$status        = (string) $row['status'];
			$out['total'] += (int) $row['n'];
			if ( in_array( $status, [ 'complete', 'failed', 'canceled' ], true ) ) {
				$out[ $status ] = (int) $row['old'];
				$out['old']    += (int) $row['old'];
			} elseif ( 'pending' === $status ) {
				$out['pending'] = (int) $row['n'];
			}
		}
		$logs = $this->asTable( 'logs' );
		if ( null !== $logs ) {
			$sql         = $this->db->prepare( 'SELECT COUNT(*) FROM %i', $logs );
			$out['logs'] = null === $sql ? 0 : (int) $this->db->get_var( $sql );
		}
		return $out;
	}

	/**
	 * @return array{total: int, excess: int, posts: int, keep: int, wp_limit: int}
	 */
	private function revisions( int $keep ): array {
		$sql   = $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s', $this->db->posts, 'revision' );
		$total = null === $sql ? 0 : (int) $this->db->get_var( $sql );
		$sql   = $this->db->prepare( 'SELECT COUNT(*) AS posts, COALESCE(SUM(n - %d), 0) AS excess FROM ( SELECT COUNT(*) AS n FROM %i WHERE post_type = %s GROUP BY post_parent HAVING n > %d ) t', $keep, $this->db->posts, 'revision', $keep );
		$row   = null === $sql ? null : $this->db->get_row( $sql, ARRAY_A );
		return [
			'total'    => $total,
			'excess'   => is_array( $row ) ? (int) $row['excess'] : 0,
			'posts'    => is_array( $row ) ? (int) $row['posts'] : 0,
			'keep'     => $keep,
			// Lo que dice wp-config.php (WP_POST_REVISIONS): -1 = sin límite.
			'wp_limit' => self::wpLimit(),
		];
	}

	/**
	 * WP_POST_REVISIONS: true o ausente = sin límite (-1), false = 0.
	 */
	public static function wpLimit(): int {
		if ( ! defined( 'WP_POST_REVISIONS' ) ) {
			return -1;
		}
		$value = constant( 'WP_POST_REVISIONS' );
		return true === $value ? -1 : max( -1, (int) $value );
	}

	private function orphanPostmeta(): int {
		$sql = $this->db->prepare( 'SELECT COUNT(*) FROM %i m LEFT JOIN %i p ON p.ID = m.post_id WHERE p.ID IS NULL', $this->db->postmeta, $this->db->posts );
		return null === $sql ? 0 : (int) $this->db->get_var( $sql );
	}

	/**
	 * Estado de HPOS de WooCommerce.
	 *
	 * @return array{woo: bool, enabled: bool, sync: bool, posts_orders: int, recommendation: string}
	 */
	private function hpos(): array {
		$woo = class_exists( 'WooCommerce' );
		if ( ! $woo ) {
			return [
				'woo'            => false,
				'enabled'        => false,
				'sync'           => false,
				'posts_orders'   => 0,
				'recommendation' => '',
			];
		}
		$enabled = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );
		$sync    = 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' );
		$sql     = $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s', $this->db->posts, 'shop_order' );
		$orders  = null === $sql ? 0 : (int) $this->db->get_var( $sql );
		$advice  = '';
		if ( ! $enabled ) {
			$advice = __( 'Los pedidos se guardan en wp_posts. Activa HPOS (WooCommerce → Ajustes → Avanzado → Funciones) tras comprobar la compatibilidad de tus extensiones.', 'imagina-turbo' );
		} elseif ( $sync ) {
			$advice = __( 'HPOS activo con sincronización: cada pedido se escribe dos veces. Desactiva la sincronización cuando confirmes que ningún plugin lee los pedidos de wp_posts.', 'imagina-turbo' );
		}
		return [
			'woo'            => true,
			'enabled'        => $enabled,
			'sync'           => $sync,
			'posts_orders'   => $orders,
			'recommendation' => $advice,
		];
	}

	/**
	 * Índices de wp_postmeta (solo recomendación, nunca se cambian).
	 *
	 * @return array{rows: int, indexes: list<string>, recommendation: string}
	 */
	private function postmetaIndex(): array {
		$sql     = $this->db->prepare( 'SHOW INDEX FROM %i', $this->db->postmeta );
		$rows    = null === $sql ? [] : $this->db->get_results( $sql, ARRAY_A );
		$indexes = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$indexes[ (string) $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
		}
		$names = [];
		$combo = false;
		foreach ( $indexes as $name => $columns ) {
			ksort( $columns );
			$columns = array_values( $columns );
			$names[] = $name . ' (' . implode( ', ', $columns ) . ')';
			if ( 'post_id' === ( $columns[0] ?? '' ) && 'meta_key' === ( $columns[1] ?? '' ) ) {
				$combo = true;
			}
		}
		$sql    = $this->db->prepare( 'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->db->postmeta );
		$count  = null === $sql ? 0 : (int) $this->db->get_var( $sql );
		$advice = '';
		if ( ! $combo && $count > 200000 ) {
			$advice = sprintf(
				/* translators: %s: tabla */
				__( 'Con tantas filas, un índice compuesto acelera las lecturas de metadatos: ALTER TABLE %s ADD INDEX post_id_meta_key (post_id, meta_key(191)). Hazlo con un respaldo y fuera de horas de tráfico.', 'imagina-turbo' ),
				$this->db->postmeta
			);
		}
		return [
			'rows'           => $count,
			'indexes'        => $names,
			'recommendation' => $advice,
		];
	}

	/**
	 * Las 15 tablas más grandes de esta instalación.
	 *
	 * @return list<array{name: string, rows: int, bytes: int}>
	 */
	private function tables(): array {
		$sql  = $this->db->prepare( 'SELECT TABLE_NAME AS name, TABLE_ROWS AS n, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s ORDER BY bytes DESC LIMIT 15', $this->db->esc_like( $this->db->prefix ) . '%' );
		$rows = null === $sql ? [] : $this->db->get_results( $sql, ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = [
				'name'  => (string) $row['name'],
				'rows'  => (int) $row['n'],
				'bytes' => (int) $row['bytes'],
			];
		}
		return $out;
	}
}
