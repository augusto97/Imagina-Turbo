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
		];
		foreach ( $controllers as $controller ) {
			$controller->registerRoutes();
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
