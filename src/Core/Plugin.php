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
use ImaginaTurbo\Cloudflare\Client as CloudflareClient;
use ImaginaTurbo\Cloudflare\CloudflareService;
use ImaginaTurbo\Cloudflare\SiteContext;
use ImaginaTurbo\Cloudflare\State as CloudflareState;
use ImaginaTurbo\Cloudflare\TokenVault;
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
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\CacheAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\LiteSpeedAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\NullAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\WpRocketAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\CacheModule;
use ImaginaTurbo\Modules\CacheCoordinator\Cacheability;
use ImaginaTurbo\Fleet\FleetService;
use ImaginaTurbo\Modules\AssetManager\AssetInventory;
use ImaginaTurbo\Modules\AssetManager\AssetRules;
use ImaginaTurbo\Modules\AssetManager\AssetScanner;
use ImaginaTurbo\Modules\Database\DatabaseActions;
use ImaginaTurbo\Modules\Database\DatabaseAudit;
use ImaginaTurbo\Modules\Database\DatabaseService;
use ImaginaTurbo\Repository\DbBackupRepository;
use ImaginaTurbo\Modules\CacheCoordinator\DelayJsExclusions;
use ImaginaTurbo\Modules\CacheCoordinator\Edge\EdgePurger;
use ImaginaTurbo\Modules\CacheCoordinator\Edge\NullEdgePurger;
use ImaginaTurbo\Modules\CacheCoordinator\NonceRefresh;
use ImaginaTurbo\Modules\CacheCoordinator\Purger;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeWorker;
use ImaginaTurbo\Modules\CacheCoordinator\TagBuilder;
use ImaginaTurbo\Modules\CacheCoordinator\Warmer;
use ImaginaTurbo\Modules\PluginFilter\ExperimentRunner;
use ImaginaTurbo\Modules\PluginFilter\FilterWatch;
use ImaginaTurbo\Modules\PluginFilter\LoopbackClient;
use ImaginaTurbo\Modules\PluginFilter\RuleStore;
use ImaginaTurbo\Modules\Profiler\CacheabilityAudit;
use ImaginaTurbo\Modules\Rum\Budgets;
use ImaginaTurbo\Modules\Rum\RumService;
use ImaginaTurbo\Repository\RumRepository;
use ImaginaTurbo\Modules\Profiler\Rollup;
use ImaginaTurbo\Modules\Profiler\SampleWriter;
use ImaginaTurbo\Repository\EventRepository;
use ImaginaTurbo\Repository\MetricsRepository;
use ImaginaTurbo\Repository\PurgeQueueRepository;
use ImaginaTurbo\Repository\SampleRepository;
use ImaginaTurbo\Repository\SnapshotRepository;
use ImaginaTurbo\Integrations\Abilities;
use ImaginaTurbo\Integrations\ZarazBridge;
use ImaginaTurbo\Rest\RestRegistrar;
use ImaginaTurbo\Support\Jobs;
use ImaginaTurbo\Verifier\VerifierService;
use ImaginaTurbo\Modules\Turnstile\Turnstile;

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
		add_action( Jobs::PURGE_FLUSH, fn() => $this->whenCache( fn() => $this->container->get( PurgeWorker::class )->run() ) );
		add_action( Jobs::WARM, fn() => $this->whenCache( fn() => $this->container->get( Warmer::class )->run() ) );
		add_action( Jobs::WARM_SEED, fn() => $this->whenCache( fn() => $this->container->get( Warmer::class )->seed() ) );
		add_action(
			Jobs::HOURLY_CLOUDFLARE,
			fn() => $this->whenActive(
				function (): void {
					if ( null !== Runtime::$config && Runtime::$config->moduleEnabled( 'cloudflare' ) ) {
						$cloudflare = $this->container->get( CloudflareService::class );
						$cloudflare->hourly();
						// Si alguien quitó de Zaraz lo que reemplaza a las etiquetas del sitio, vuelven (0.18.0).
						$takeover = ZarazBridge::compiled();
						if ( ( $takeover['ga4'] || $takeover['meta'] ) && false === $cloudflare->zarazHealthy( $takeover ) ) {
							ZarazBridge::setTakeover(
								$this->container,
								[
									'ga4'  => false,
									'meta' => false,
								],
								'zaraz_missing'
							);
							$this->container->get( EventLog::class )->warning( 'cloudflare', 'zaraz_missing', __( 'Zaraz ya no tiene Google Analytics o Meta, está apagado o la zona de Cloudflare está en pausa: las etiquetas del sitio vuelven a imprimirse.', 'imagina-turbo' ) );
						}
					}
				}
			)
		);
		add_action(
			Jobs::HOURLY_FILTER_WATCH,
			fn() => $this->whenActive(
				function (): void {
					if ( null !== Runtime::$config && Runtime::$config->moduleEnabled( 'plugin_filter' ) ) {
						$this->container->get( FilterWatch::class )->hourly( $this->container->get( ExperimentRunner::class ) );
					}
				}
			)
		);
		add_action(
			Jobs::DAILY_RUM,
			fn() => $this->whenActive(
				function (): void {
					if ( null !== Runtime::$config && Runtime::$config->moduleEnabled( 'rum' ) ) {
						$rum = $this->container->get( RumService::class );
						$rum->daily();
						$rum->budgets( Budgets::normalize( (array) $this->container->get( Settings::class )->get( 'budgets', [] ) ) );
					}
				}
			)
		);
		add_action(
			Jobs::DAILY_DB,
			fn() => $this->whenActive(
				function (): void {
					$this->container->get( DatabaseService::class )->daily( null !== Runtime::$config && Runtime::$config->moduleEnabled( 'database' ) );
				}
			)
		);
		add_action(
			Jobs::DAILY_FLEET,
			fn() => $this->whenActive(
				function (): void {
					if ( $this->container->get( HubClient::class )->isRegistered() ) {
						$this->container->get( FleetService::class )->sync();
					}
				}
			)
		);
		add_action( Jobs::DAILY_VERIFIER, fn() => $this->whenActive( fn() => $this->container->get( VerifierService::class )->daily() ) );
		add_action( 'rest_api_init', fn() => $this->container->get( RestRegistrar::class )->register() );
		// Habilidades para asistentes de IA (Abilities API, 0.15.0): fuera del HTML público, sin costo ahí.
		if ( RequestType::FrontendHtml !== $type ) {
			( new Abilities( $this->container->get( EventLog::class ), (bool) $this->container->get( Settings::class )->get( 'ai_write', false ) ) )->register();
		}

		if ( ! $type->isPotentiallyCacheable() && RequestType::StaticMiss !== $type ) {
			$this->registerRecompileTriggers();
		}

		if ( is_admin() ) {
			$this->container->get( Menu::class )->register();
			add_action( 'admin_init', [ $this, 'adminMaintenance' ] );
			add_filter( 'plugin_action_links_' . $this->basename, [ $this, 'actionLinks' ] );
			add_action( 'admin_notices', [ $this, 'cloudflareFootprintNotice' ] );
		}
		if ( RequestType::Cron === $type ) {
			add_action( 'init', [ $this, 'notifySafeMode' ], 20 );
		}

		// Store API y wc-ajax responden por sesión (carrito, direcciones). LSCache, con sus valores por
		// defecto, cachea la REST y servía el carrito de un visitante a otros: se le indica que no.
		// Protección de privacidad: corre siempre, incluso con el kill switch (solo emite un hook).
		if ( RequestType::StoreApi === $type || RequestType::WcAjax === $type ) {
			add_action( 'init', [ self::class, 'noPageCache' ], 0 );
		}

		// Kill switch o modo seguro: solo queda el admin del plugin.
		if ( null !== Runtime::$killReason || Runtime::$safeMode ) {
			return;
		}

		if ( null !== Runtime::$config ) {
			// Coexistencia: exclusiones de delay JS de la matriz para el plugin de caché del sitio. También
			// en el carrito, el pago y la cuenta (0.20.0): el plugin de caché retrasa su JS igual aunque no
			// las guarde, y sin marcar los datos en línea de los scripts que no retrasa (woocommerce_params)
			// el verificador encontró «woocommerce_params is not defined» en Mi cuenta.
			if ( in_array( $type, [ RequestType::FrontendHtml, RequestType::Cart, RequestType::Checkout, RequestType::Account ], true ) ) {
				DelayJsExclusions::register();
			}
			// Inventario de assets (petición loopback firmada desde el admin).
			if ( RequestType::FrontendHtml === $type && isset( $_COOKIE['itb_assets_inv'] ) ) {
				AssetInventory::register();
			}
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
		// Una regla que provocó un fatal se apaga en el siguiente request de admin, sin esperar a la tarea.
		if ( null !== Runtime::$config && Runtime::$config->moduleEnabled( 'plugin_filter' ) ) {
			$this->container->get( FilterWatch::class )->processFaults();
		}
	}

	/**
	 * La respuesta de este request no se guarda en la caché de página (LSCache, WP Rocket y similares).
	 */
	public static function noPageCache(): void {
		do_action( 'litespeed_control_set_nocache', 'imagina-turbo: respuesta por sesión' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LSCache.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante estándar de los plugins de caché.
		}
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
		$this->container->get( RumRepository::class )->deleteOlderThan( RumRepository::RETENTION_DAYS );
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

	/**
	 * Tarea del coordinador de caché: si el módulo está apagado, se vacía la cola en lugar de procesarla.
	 *
	 * @param callable(): mixed $task Tarea.
	 */
	private function whenCache( callable $task ): void {
		$this->whenActive(
			function () use ( $task ): void {
				if ( null !== Runtime::$config && Runtime::$config->moduleEnabled( 'cache_coordinator' ) ) {
					$task();
					return;
				}
				$queue = $this->container->get( PurgeQueueRepository::class );
				foreach ( [ PurgeQueueRepository::KIND_ALL, PurgeQueueRepository::KIND_TAG, PurgeQueueRepository::KIND_URL, PurgeQueueRepository::KIND_WARM ] as $kind ) {
					$queue->clear( $kind );
				}
			}
		);
	}

	public function loadTextdomain(): void {
		load_plugin_textdomain( 'imagina-turbo', false, dirname( $this->basename ) . '/languages' );
	}

	/**
	 * @param array<int|string, string> $links Enlaces.
	 * @return array<int|string, string>
	 */
	public function actionLinks( array $links ): array {
		$footprint = $this->container->get( CloudflareState::class )->footprint();
		if ( isset( $links['deactivate'] ) && ( $footprint['rules'] > 0 || $footprint['settings'] > 0 ) ) {
			// Confirmación antes de desactivar si quedan cosas del plugin en Cloudflare.
			$message             = __( 'Imagina Turbo todavía tiene reglas o ajustes en Cloudflare. Si lo desactivas, se quedan en la zona. ¿Desactivar igual? (Para quitarlos: Imagina Turbo → Cloudflare → Quitar todo).', 'imagina-turbo' );
			$links['deactivate'] = str_replace( '<a ', '<a onclick="return confirm(' . esc_attr( (string) wp_json_encode( $message ) ) . ');" ', $links['deactivate'] );
		}
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ), esc_html__( 'Panel', 'imagina-turbo' ) )
		);
		return $links;
	}

	/**
	 * En la pantalla de plugins: aviso si quedan reglas o ajustes del plugin en Cloudflare.
	 */
	public function cloudflareFootprintNotice(): void {
		global $pagenow;
		if ( 'plugins.php' !== $pagenow || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$footprint = $this->container->get( CloudflareState::class )->footprint();
		if ( 0 === $footprint['rules'] && 0 === $footprint['settings'] ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: 1: reglas, 2: ajustes */
					__( 'Imagina Turbo tiene %1$d reglas y %2$d ajustes cambiados en Cloudflare. Antes de desactivarlo o borrarlo, quítalos: si no, se quedan en la zona.', 'imagina-turbo' ),
					$footprint['rules'],
					$footprint['settings']
				)
			),
			esc_url( admin_url( 'admin.php?page=' . Menu::SLUG . '#/cloudflare' ) ),
			esc_html__( 'Ir a Cloudflare', 'imagina-turbo' )
		);
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
		$c->set(
			PurgeQueueRepository::class,
			static function (): PurgeQueueRepository {
				global $wpdb;
				return new PurgeQueueRepository( $wpdb );
			}
		);
		$c->set(
			TagBuilder::class,
			static function (): TagBuilder {
				$tags = Runtime::$config?->get( 'tags', [] );
				return new TagBuilder( is_array( $tags ) && is_string( $tags['prefix'] ?? null ) ? $tags['prefix'] : '' );
			}
		);
		$c->set(
			CacheAdapter::class,
			static function ( Container $c ): CacheAdapter {
				$cache = CacheModule::settings();
				return match ( $cache['adapter'] ) {
					'litespeed' => new LiteSpeedAdapter( $c->get( TagBuilder::class ) ),
					'wp-rocket' => new WpRocketAdapter( $cache['server'], 'headers' === $cache['mode'] ? Cacheability::edgeHeader( true, $cache['nonce_refresh'] ? $cache['edge_ttl'] : min( $cache['edge_ttl'], Cacheability::MAX_TTL_WITHOUT_NONCE_REFRESH ), $cache['swr'], $cache['sie'] ) : '' ),
					default     => new NullAdapter(),
				};
			}
		);
		$c->set( TokenVault::class, static fn(): TokenVault => new TokenVault() );
		$c->set( CloudflareState::class, static fn(): CloudflareState => new CloudflareState() );
		$c->set(
			CloudflareClient::class,
			static function ( Container $c ): CloudflareClient {
				$vault = $c->get( TokenVault::class );
				return new CloudflareClient( static fn(): ?string => $vault->read(), $c->get( EventLog::class ) );
			}
		);
		$c->set(
			SnapshotRepository::class,
			static function (): SnapshotRepository {
				global $wpdb;
				return new SnapshotRepository( $wpdb );
			}
		);
		// Fábrica aparte: la flota necesita un servicio nuevo después de recompilar la config.
		$cloudflareFactory = static function ( Container $c ) use ( $dir ): CloudflareService {
			$config = Runtime::$config;
			return new CloudflareService(
				$c->get( TokenVault::class ),
				$c->get( CloudflareState::class ),
				$c->get( CloudflareClient::class ),
				SiteContext::fromRuntime( $config, $c->get( Settings::class ), $dir . '/data/compat-matrix-v1.json' ),
				$c->get( SnapshotRepository::class ),
				$c->get( EventLog::class ),
				$dir . '/data/cloudflare-limits.json',
				null !== $config && $config->moduleEnabled( 'cloudflare' ),
				[
					'enabled'   => null !== $config && $config->moduleEnabled( 'firewall' ),
					'rules'     => (array) $c->get( Settings::class )->get( 'firewall_rules', [] ),
					'ratelimit' => (bool) $c->get( Settings::class )->get( 'firewall_ratelimit', true ),
					'views'     => (bool) $c->get( Settings::class )->get( 'shield_facets_views', true ),
				]
			);
		};
		$c->set( CloudflareService::class, $cloudflareFactory );
		$c->set(
			FleetService::class,
			static fn( Container $c ): FleetService => new FleetService(
				$c->get( Settings::class ),
				$c->get( ModuleCatalog::class ),
				$c->get( HubClient::class ),
				$c->get( ConfigCompiler::class ),
				$c->get( EventLog::class ),
				static fn(): CloudflareService => $cloudflareFactory( $c )
			)
		);
		$c->set( VerifierService::class, static fn( Container $c ): VerifierService => new VerifierService( $c->get( EventLog::class ) ) );
		$c->set( Turnstile::class, static fn( Container $c ): Turnstile => new Turnstile( Turnstile::vault(), $c->get( EventLog::class ) ) );
		// Purga en el edge: Cloudflare si el módulo está activo y conectado; si no, nada.
		$c->set(
			EdgePurger::class,
			static fn( Container $c ): EdgePurger => null !== Runtime::$config && Runtime::$config->moduleEnabled( 'cloudflare' ) && null === Runtime::$killReason && ! Runtime::$safeMode
				? $c->get( CloudflareService::class )->purger()
				: new NullEdgePurger()
		);
		$c->set( Purger::class, static fn( Container $c ): Purger => new Purger( $c->get( CacheAdapter::class ), $c->get( TagBuilder::class ), $c->get( PurgeQueueRepository::class ) ) );
		$c->set(
			Warmer::class,
			static function ( Container $c ): Warmer {
				$cache = CacheModule::settings();
				$paths = Runtime::$config?->get( 'paths', [] );
				$block = [];
				foreach ( is_array( $paths ) ? $paths : [] as $list ) {
					foreach ( is_array( $list ) ? $list : [] as $path ) {
						if ( is_string( $path ) ) {
							$block[] = $path;
						}
					}
				}
				return new Warmer( $c->get( PurgeQueueRepository::class ), $cache['preload_exclude'], $block, $cache['sitemaps'] );
			}
		);
		$c->set(
			PurgeWorker::class,
			static fn( Container $c ): PurgeWorker => new PurgeWorker(
				$c->get( PurgeQueueRepository::class ),
				$c->get( CacheAdapter::class ),
				$c->get( EdgePurger::class ),
				$c->get( TagBuilder::class ),
				$c->get( Warmer::class ),
				$c->get( EventLog::class ),
				CacheModule::settings()['warm']
			)
		);
		$c->set(
			NonceRefresh::class,
			static function () use ( $dir ): NonceRefresh {
				$file = is_file( $dir . '/assets/js/itb-nonce.min.js' ) ? 'itb-nonce.min.js' : 'itb-nonce.js';
				return new NonceRefresh( plugins_url( 'assets/js/' . $file, $dir . '/imagina-turbo.php' ) . '?ver=' . ITB_VERSION, rest_url( RestRegistrar::NAMESPACE . '/nonce' ) );
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
		$c->set(
			RumRepository::class,
			static function (): RumRepository {
				global $wpdb;
				return new RumRepository( $wpdb );
			}
		);
		$c->set(
			RumService::class,
			static function ( Container $c ): RumService {
				$excluded = Runtime::$config?->get( 'speculation', [] );
				$excluded = is_array( $excluded ) && is_array( $excluded['exclude'] ?? null ) ? array_values( array_filter( $excluded['exclude'], 'is_string' ) ) : [];
				return new RumService(
					$c->get( RumRepository::class ),
					$c->get( EventLog::class ),
					$excluded,
					static function () use ( $c ): void {
						$c->get( ConfigCompiler::class )->compile();
					}
				);
			}
		);
		$c->set( RuleStore::class, static fn(): RuleStore => new RuleStore() );
		$c->set(
			ExperimentRunner::class,
			static fn( Container $c ): ExperimentRunner => new ExperimentRunner(
				$c->get( RuleStore::class ),
				\Closure::fromCallable( [ LoopbackClient::class, 'request' ] ),
				$dataDir,
				$c->get( EventLog::class ),
				static function () use ( $c ): void {
					$c->get( ConfigCompiler::class )->compile();
				}
			)
		);
		$c->set(
			FilterWatch::class,
			static fn( Container $c ): FilterWatch => new FilterWatch(
				$c->get( RuleStore::class ),
				$dataDir,
				$c->get( EventLog::class ),
				static function () use ( $c ): void {
					$c->get( ConfigCompiler::class )->compile();
				}
			)
		);
		$c->set( AssetRules::class, static fn(): AssetRules => new AssetRules() );
		$c->set( AssetScanner::class, static fn( Container $c ): AssetScanner => new AssetScanner( $c->get( AssetRules::class ) ) );
		$c->set(
			DbBackupRepository::class,
			static function (): DbBackupRepository {
				global $wpdb;
				return new DbBackupRepository( $wpdb );
			}
		);
		$c->set(
			DatabaseAudit::class,
			static function (): DatabaseAudit {
				global $wpdb;
				return new DatabaseAudit( $wpdb );
			}
		);
		$c->set(
			DatabaseActions::class,
			static function ( Container $c ): DatabaseActions {
				global $wpdb;
				return new DatabaseActions( $wpdb, $c->get( DbBackupRepository::class ), $c->get( DatabaseAudit::class ), $c->get( EventLog::class ) );
			}
		);
		$c->set( DatabaseService::class, static fn( Container $c ): DatabaseService => new DatabaseService( $c->get( Settings::class ), $c->get( DatabaseActions::class ), $c->get( DbBackupRepository::class ) ) );
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
