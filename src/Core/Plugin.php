<?php
/**
 * Arranque del plugin principal.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

use ImaginaTurbo\Admin\AssetsLoader;
use ImaginaTurbo\Admin\Menu;
use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Config\SitePaths;
use ImaginaTurbo\Core\Environment\Detector;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Request\RequestClassifier;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Safety\SafeMode;
use ImaginaTurbo\Core\Safety\SafeModeNotifier;
use ImaginaTurbo\Hub\HubClient;
use ImaginaTurbo\Hub\TelemetryBuilder;
use ImaginaTurbo\Licensing\ImaginaUpdater;
use ImaginaTurbo\Modules\Profiler\CacheabilityAudit;
use ImaginaTurbo\Modules\Profiler\Rollup;
use ImaginaTurbo\Modules\Profiler\SampleWriter;
use ImaginaTurbo\Repository\EventRepository;
use ImaginaTurbo\Repository\MetricsRepository;
use ImaginaTurbo\Repository\SampleRepository;
use ImaginaTurbo\Rest\RestRegistrar;
use ImaginaTurbo\Support\Jobs;

/**
 * Cablea servicios y hooks según el tipo de request.
 */
final class Plugin {

	private static ?self $instance = null;

	private readonly Container $container;

	private readonly string $dir;

	private readonly string $basename;

	/**
	 * Arranca el plugin una sola vez.
	 *
	 * @param string $file Ruta del archivo principal.
	 */
	public static function boot( string $file ): self {
		if ( null !== self::$instance ) {
			return self::$instance;
		}
		$dir = dirname( $file );

		// Sin loader MU (no instalado o mu-plugins sin permisos): la etapa temprana corre aquí,
		// ya sin las funciones que necesitan ejecutarse antes de los plugins.
		if ( ! Runtime::$earlyRan ) {
			EarlyLoader::boot( $dir );
		}
		Runtime::$mainBooted = true;

		self::$instance = new self( $file, $dir );
		self::$instance->register();
		return self::$instance;
	}

	public static function instance(): ?self {
		return self::$instance;
	}

	private function __construct( private readonly string $file, string $dir ) {
		$this->dir       = $dir;
		$this->basename  = plugin_basename( $file );
		$this->container = $this->buildContainer();
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * Tipo de request: el del loader o, si no hubo config, una clasificación con valores por defecto.
	 */
	public static function request(): Request\RequestContext {
		if ( null === Runtime::$request ) {
			Runtime::$request = ( new RequestClassifier( [] ) )->classifyCurrent();
		}
		return Runtime::$request;
	}

	/**
	 * Registra hooks. En el frontend, lo mínimo.
	 */
	private function register(): void {
		register_activation_hook( $this->file, [ $this, 'onActivate' ] );
		register_deactivation_hook( $this->file, [ $this, 'onDeactivate' ] );

		$type = self::request()->type;

		// Action Scheduler solo donde se usa (admin, admin-ajax para su ejecutor asíncrono, cron, CLI).
		// En sitios con WooCommerce ya viene cargado por él en todos los requests.
		if ( in_array( $type, [ RequestType::Admin, RequestType::AdminAjax, RequestType::Cron, RequestType::Cli ], true ) ) {
			$as = $this->dir . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
			if ( is_file( $as ) ) {
				require_once $as;
			}
		}

		add_action( 'init', [ $this, 'loadTextdomain' ] );
		add_action( Jobs::DAILY_CLEANUP, [ $this, 'dailyCleanup' ] );
		// Las tareas tienen manejador siempre (si no, Action Scheduler las marca como fallidas);
		// con el kill switch o el modo seguro no hacen nada.
		add_action( Jobs::HOURLY_ROLLUP, fn() => $this->whenActive( fn() => $this->container->get( Rollup::class )->run() ) );
		add_action( Jobs::HOURLY_TELEMETRY, fn() => $this->whenActive( fn() => $this->sendTelemetry( false ) ) );
		add_action( Jobs::DAILY_AUDIT, fn() => $this->whenActive( fn() => $this->container->get( CacheabilityAudit::class )->run() ) );
		add_action( 'rest_api_init', fn() => $this->container->get( RestRegistrar::class )->register() );

		if ( ! $type->isPotentiallyCacheable() && RequestType::StaticMiss !== $type ) {
			$this->registerRecompileTriggers();
		}

		if ( is_admin() ) {
			$this->container->get( Menu::class )->register();
			add_action( 'admin_init', [ $this, 'adminMaintenance' ] );
			add_filter( 'plugin_action_links_' . $this->basename, [ $this, 'actionLinks' ] );
		}
		if ( RequestType::Cron === $type ) {
			add_action( 'init', [ $this, 'notifySafeMode' ], 20 );
		}

		// Kill switch o modo seguro: solo queda el admin del plugin.
		if ( null !== Runtime::$killReason || Runtime::$safeMode ) {
			return;
		}

		if ( null !== Runtime::$config ) {
			( new ModuleRegistry() )->boot( $this->container, Runtime::$config, self::request() );
		}
	}

	/**
	 * Hooks que cambian la config compilada.
	 */
	private function registerRecompileTriggers(): void {
		$schedule = function (): void {
			$this->container->get( ConfigCompiler::class )->schedule();
		};
		foreach ( [ 'activated_plugin', 'deactivated_plugin', 'switch_theme', 'upgrader_process_complete', 'update_option_permalink_structure', 'update_option_home', 'update_option_siteurl', 'update_option_woocommerce_cart_page_id', 'update_option_woocommerce_checkout_page_id', 'update_option_woocommerce_myaccount_page_id', 'update_option_whl_page' ] as $hook ) {
			add_action( $hook, $schedule );
		}
	}

	/**
	 * Mantenimiento en admin: esquema, loader, config, tareas y aviso de modo seguro.
	 */
	public function adminMaintenance(): void {
		if ( wp_doing_ajax() || ( ! current_user_can( Installer::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) ) {
			return;
		}
		Installer::maybeUpgrade();
		Installer::addCapability();

		$loader = $this->container->get( MuLoaderInstaller::class );
		if ( ! $loader->isCurrent() ) {
			$loader->install();
		}

		$config = CompiledConfig::load( $this->container->get( ConfigCompiler::class )->file() );
		if ( ! Runtime::$safeMode && ( null === $config || ITB_VERSION !== $config->pluginVersion() ) ) {
			$this->container->get( ConfigCompiler::class )->schedule();
		}

		$this->container->get( Jobs::class )->schedule();
		$this->notifySafeMode();
	}

	public function notifySafeMode(): void {
		$this->container->get( SafeModeNotifier::class )->maybeNotify();
	}

	/**
	 * Tarea diaria: retención de eventos (180 días), muestras (48 h) y agregados (30 días).
	 */
	public function dailyCleanup(): void {
		$this->container->get( EventLog::class )->purge();
		$this->container->get( SampleRepository::class )->deleteOlderThan( SampleRepository::RETENTION_HOURS );
		$this->container->get( MetricsRepository::class )->deleteOlderThan( MetricsRepository::RETENTION_DAYS );
	}

	/**
	 * Telemetría al hub con el agregado al día (idempotente: si ya corrió esta hora no hace nada).
	 *
	 * @return array{sent: bool, reason: string}
	 */
	public function sendTelemetry( bool $force ): array {
		$hub = $this->container->get( HubClient::class );
		if ( ! $hub->isRegistered() ) {
			return [
				'sent'   => false,
				'reason' => 'not_registered',
			];
		}
		$this->container->get( Rollup::class )->run();
		return $hub->sendTelemetry( $force );
	}

	/**
	 * Ejecuta una tarea solo si el plugin no está apagado (kill switch o modo seguro).
	 *
	 * @param callable(): mixed $task Tarea.
	 */
	private function whenActive( callable $task ): void {
		if ( null !== Runtime::$killReason || Runtime::$safeMode ) {
			return;
		}
		try {
			$task();
		} catch ( \Throwable $e ) {
			$this->container->get( EventLog::class )->error( 'core', 'job', __( 'Una tarea programada falló.', 'imagina-turbo' ), [ 'error' => substr( $e->getMessage(), 0, 300 ) ] );
		}
	}

	public function loadTextdomain(): void {
		load_plugin_textdomain( 'imagina-turbo', false, dirname( $this->basename ) . '/languages' );
	}

	/**
	 * @param array<int|string, string> $links Enlaces.
	 * @return array<int|string, string>
	 */
	public function actionLinks( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ), esc_html__( 'Panel', 'imagina-turbo' ) )
		);
		return $links;
	}

	/**
	 * @param bool|null $networkWide Activación de red.
	 */
	public function onActivate( $networkWide = false ): void {
		$this->container->get( Installer::class )->activate();
	}

	public function onDeactivate(): void {
		$this->container->get( ConfigCompiler::class )->halt();
		$this->container->get( Installer::class )->deactivate();
	}

	/**
	 * Servicios.
	 */
	private function buildContainer(): Container {
		$c       = new Container();
		$dir     = $this->dir;
		$dataDir = Runtime::$dataDir;

		$c->set( Settings::class, static fn(): Settings => new Settings() );
		$c->set( Detector::class, static fn(): Detector => new Detector() );
		$c->set( SitePaths::class, static fn(): SitePaths => new SitePaths() );
		$c->set( ModuleCatalog::class, static fn(): ModuleCatalog => new ModuleCatalog() );
		$c->set(
			ConfigCompiler::class,
			static fn( Container $c ): ConfigCompiler => new ConfigCompiler(
				$c->get( Settings::class ),
				$c->get( Detector::class ),
				$c->get( SitePaths::class ),
				$c->get( ModuleCatalog::class ),
				$dataDir,
				$dir . '/data/compat-matrix-v1.json',
				ITB_VERSION
			)
		);
		$c->set(
			EventRepository::class,
			static function (): EventRepository {
				global $wpdb;
				return new EventRepository( $wpdb );
			}
		);
		$c->set( EventLog::class, static fn( Container $c ): EventLog => new EventLog( $c->get( EventRepository::class ) ) );
		$c->set(
			SampleRepository::class,
			static function (): SampleRepository {
				global $wpdb;
				return new SampleRepository( $wpdb );
			}
		);
		$c->set(
			MetricsRepository::class,
			static function (): MetricsRepository {
				global $wpdb;
				return new MetricsRepository( $wpdb );
			}
		);
		$c->set( SampleWriter::class, static fn( Container $c ): SampleWriter => new SampleWriter( $c->get( SampleRepository::class ) ) );
		$c->set( Rollup::class, static fn( Container $c ): Rollup => new Rollup( $c->get( SampleRepository::class ), $c->get( MetricsRepository::class ) ) );
		$c->set( CacheabilityAudit::class, static fn( Container $c ): CacheabilityAudit => new CacheabilityAudit( $c->get( EventLog::class ) ) );
		$c->set(
			TelemetryBuilder::class,
			static fn( Container $c ): TelemetryBuilder => new TelemetryBuilder(
				$c->get( Detector::class ),
				$c->get( MetricsRepository::class ),
				$c->get( EventRepository::class ),
				$c->get( Settings::class ),
				$c->get( ModuleCatalog::class )
			)
		);
		$c->set(
			HubClient::class,
			static fn( Container $c ): HubClient => new HubClient(
				$c->get( Settings::class ),
				$c->get( TelemetryBuilder::class ),
				$c->get( ImaginaUpdater::class ),
				$c->get( EventLog::class )
			)
		);
		$c->set( MuLoaderInstaller::class, static fn(): MuLoaderInstaller => new MuLoaderInstaller( $dir, WPMU_PLUGIN_DIR ) );
		$c->set( Jobs::class, static fn(): Jobs => new Jobs() );
		$c->set(
			Installer::class,
			static fn( Container $c ): Installer => new Installer(
				$c->get( Settings::class ),
				$c->get( ConfigCompiler::class ),
				$c->get( MuLoaderInstaller::class ),
				$c->get( EventLog::class ),
				$c->get( Jobs::class ),
				$dataDir
			)
		);
		$c->set( SafeMode::class, static fn(): SafeMode => new SafeMode( $dataDir, $dir ) );
		$c->set( SafeModeNotifier::class, static fn( Container $c ): SafeModeNotifier => new SafeModeNotifier( $c->get( SafeMode::class ), $c->get( EventLog::class ) ) );
		$c->set( ImaginaUpdater::class, static fn(): ImaginaUpdater => new ImaginaUpdater() );
		$c->set( Diagnostics::class, static fn( Container $c ): Diagnostics => new Diagnostics( $c, $dir . '/data/compat-matrix-v1.json' ) );
		$c->set( RestRegistrar::class, static fn( Container $c ): RestRegistrar => new RestRegistrar( $c ) );
		$c->set( AssetsLoader::class, static fn(): AssetsLoader => new AssetsLoader( $dir, plugins_url( '', $dir . '/imagina-turbo.php' ) ) );
		$c->set( Menu::class, static fn( Container $c ): Menu => new Menu( $c->get( AssetsLoader::class ) ) );

		return $c;
	}
}
