<?php
/**
 * Registro de las rutas REST del admin (namespace imagina-turbo/v1).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\Installer;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\CacheAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\CacheModule;

/**
 * Crea los controladores y registra sus rutas.
 */
final class RestRegistrar {

	public const NAMESPACE = 'imagina-turbo/v1';

	public function __construct( private readonly Container $container ) {
	}

	public function register(): void {
		$controllers = [
			new StatusController( $this->container ),
			new EventsController( $this->container ),
			new SafetyController( $this->container ),
			new SettingsController( $this->container ),
			new ProfilerController( $this->container ),
			new HubController( $this->container ),
			new CacheController( $this->container ),
			new CloudflareController( $this->container ),
			new WooController( $this->container ),
			new ShieldController( $this->container ),
			new FirewallController( $this->container ),
			new PluginFilterController( $this->container ),
			new NavigationController( $this->container ),
			new TweaksController( $this->container ),
			new AssetsController( $this->container ),
			new DatabaseController( $this->container ),
			new FleetController( $this->container ),
			new VerifierController( $this->container ),
			new TurnstileController( $this->container ),
		];
		foreach ( $controllers as $controller ) {
			$controller->registerRoutes();
		}

		// Público: solo con el coordinador de caché activo y el refresco de nonces encendido.
		if ( null !== Runtime::$config && Runtime::$config->moduleEnabled( 'cache_coordinator' ) && CacheModule::settings()['nonce_refresh'] && null === Runtime::$killReason && ! Runtime::$safeMode ) {
			( new NonceController( $this->container->get( CacheAdapter::class ) ) )->registerRoutes();
		}
	}

	/**
	 * Todas las rutas del admin exigen la capacidad propia o manage_options. El nonce wp_rest lo
	 * verifica el core: sin él, la petición llega como usuario anónimo y falla aquí.
	 */
	public static function canManage(): bool {
		return current_user_can( Installer::CAPABILITY ) || current_user_can( 'manage_options' );
	}
}
