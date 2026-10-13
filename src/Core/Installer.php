<?php
/**
 * Activación, desactivación, actualización de esquema y desinstalación.
 *
 * Objetivo: activar/desactivar no deja residuos que afecten al sitio, y desinstalar no deja nada.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Safety\KillSwitch;
use ImaginaTurbo\Core\Safety\SafeMode;
use ImaginaTurbo\Support\Filesystem;
use ImaginaTurbo\Support\Jobs;

/**
 * Ciclo de vida del plugin.
 */
final class Installer {

	public const DB_VERSION        = 1;
	public const DB_VERSION_OPTION = 'itb_db_version';
	public const CAPABILITY        = 'itb_manage';

	/** Opciones propias (todas se borran al desinstalar). */
	public const OPTIONS = [ Settings::OPTION, self::DB_VERSION_OPTION, 'itb_cf_token', 'itb_cf_state', 'itb_site_key' ];

	/** Tablas propias sin prefijo. */
	public const TABLES = [ 'itb_events' ];

	public function __construct(
		private readonly Settings $settings,
		private readonly ConfigCompiler $compiler,
		private readonly MuLoaderInstaller $muLoader,
		private readonly EventLog $events,
		private readonly Jobs $jobs,
		private readonly string $dataDir,
	) {
	}

	/**
	 * Activación en el sitio actual.
	 */
	public function activate(): void {
		Filesystem::ensureDataDir( $this->dataDir );
		self::createTables();
		$this->settings->ensureInitialized();
		self::addCapability();

		$loader = $this->muLoader->install();
		$result = $this->compiler->compile();
		$this->jobs->schedule();

		$this->events->info(
			'core',
			'activate',
			__( 'Plugin activado.', 'imagina-turbo' ),
			[
				'loader' => $loader,
				'config' => $result['ok'],
				'errors' => $result['errors'],
			]
		);
	}

	/**
	 * Desactivación: quita todo lo que actúa sobre el sitio (loader, config compilada, banderas,
	 * tareas programadas). Conserva ajustes, tablas y el archivo de kill switch puesto a mano.
	 */
	public function deactivate(): void {
		$this->events->info( 'core', 'deactivate', __( 'Plugin desactivado.', 'imagina-turbo' ) );
		$this->jobs->unschedule();
		$this->muLoader->uninstall();
		$this->compiler->delete();

		foreach ( [ SafeMode::FLAG, SafeMode::COUNTER ] as $name ) {
			$file = $this->dataDir . '/' . $name;
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		self::removeDataDirIfUnused( $this->dataDir );
	}

	/**
	 * Crea o actualiza tablas si cambió la versión del esquema.
	 */
	public static function maybeUpgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) !== self::DB_VERSION ) {
			self::createTables();
		}
	}

	/**
	 * Crea las tablas con dbDelta.
	 */
	public static function createTables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . 'itb_events';

		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  module varchar(40) NOT NULL,
  type varchar(40) NOT NULL,
  severity tinyint(4) NOT NULL DEFAULT 0,
  message varchar(255) NOT NULL,
  context longtext NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY module_type (module,type)
) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	public static function addCapability(): void {
		$role = get_role( 'administrator' );
		if ( null !== $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	/**
	 * Desinstalación completa (llamada desde uninstall.php). En multisite recorre todos los sitios.
	 */
	public static function uninstall( string $pluginDir ): void {
		if ( is_multisite() ) {
			$siteIds = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);
			foreach ( $siteIds as $siteId ) {
				switch_to_blog( (int) $siteId );
				self::uninstallSite();
				restore_current_blog();
			}
		} else {
			self::uninstallSite();
		}

		( new MuLoaderInstaller( $pluginDir, WPMU_PLUGIN_DIR ) )->uninstall();

		$dataDir = EarlyLoader::dataDir();
		if ( is_dir( $dataDir ) && defined( 'WP_CONTENT_DIR' ) ) {
			Filesystem::removeDir( $dataDir, WP_CONTENT_DIR );
		}
	}

	/**
	 * Borra datos del sitio actual: tablas, opciones, capacidad, tareas.
	 */
	private static function uninstallSite(): void {
		global $wpdb;
		foreach ( self::TABLES as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla propio y fijo.
		}
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( self::CAPABILITY ) ) {
				$role->remove_cap( self::CAPABILITY );
			}
		}
		Jobs::unscheduleAll();
		$file = CompiledConfig::path( EarlyLoader::dataDir(), get_current_blog_id(), is_multisite() );
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Borra el directorio de datos si solo quedan los archivos de protección.
	 * Si el administrador dejó el archivo de kill switch, se respeta.
	 */
	private static function removeDataDirIfUnused( string $dataDir ): void {
		if ( ! is_dir( $dataDir ) || is_file( $dataDir . '/' . KillSwitch::FILE ) ) {
			return;
		}
		$entries = scandir( $dataDir );
		if ( false === $entries ) {
			return;
		}
		$rest = array_diff( $entries, [ '.', '..', 'index.php', '.htaccess', 'web.config' ] );
		foreach ( $rest as $entry ) {
			// Configs compiladas de otros sitios de la red siguen en uso.
			if ( str_starts_with( $entry, 'config' ) ) {
				return;
			}
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			Filesystem::removeDir( $dataDir, WP_CONTENT_DIR );
		}
	}
}
