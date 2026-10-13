<?php
/**
 * Base de los controladores REST.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Container;

/**
 * Utilidades comunes.
 */
abstract class Controller {

	public function __construct( protected readonly Container $container ) {
	}

	abstract public function registerRoutes(): void;

	/**
	 * Registra una ruta con el permiso del admin.
	 *
	 * @param non-falsy-string                    $route    Ruta.
	 * @param string                              $methods  Métodos.
	 * @param callable                            $callback Manejador.
	 * @param array<string, array<string, mixed>> $args     Esquema de argumentos.
	 */
	protected function route( string $route, string $methods, callable $callback, array $args = [] ): void {
		register_rest_route(
			RestRegistrar::NAMESPACE,
			$route,
			[
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => [ RestRegistrar::class, 'canManage' ],
				'args'                => $args,
			]
		);
	}

	/**
	 * Respuesta sin caché.
	 *
	 * @param mixed $data Datos.
	 */
	protected function ok( mixed $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * @param array<string, mixed> $data Datos extra.
	 */
	protected function error( string $code, string $message, int $status, array $data = [] ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( [ 'status' => $status ], $data ) );
	}
}
