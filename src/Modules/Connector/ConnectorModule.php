<?php
/**
 * Módulo «Conector de Claude» (0.22.0): enruta las direcciones del conector.
 *
 * Solo trabaja cuando el request es una de sus rutas: una comparación de cadenas en los demás. Atiende
 * en wp_loaded (prioridad 0), con WordPress, WooCommerce y las taxonomías ya cargados (las herramientas
 * los usan) y antes de la plantilla, y termina el request ahí.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Connector;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;

/**
 * Rutas del conector.
 */
final class ConnectorModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
		$route = self::route( $request->path );
		if ( null === $route ) {
			return;
		}
		add_action(
			'wp_loaded',
			static function () use ( $container, $route ): void {
				self::serve( $container, $route );
			},
			0
		);
	}

	/**
	 * Ruta del conector para una ruta relativa a home, o null.
	 */
	public static function route( string $path ): ?string {
		$path   = '/' . trim( strtolower( $path ), '/' );
		$issuer = Connector::ISSUER_PATH;
		$mcp    = Connector::MCP_PATH;
		$map    = [
			$mcp                                           => 'mcp',
			$issuer . '/authorize'                         => 'authorize',
			$issuer . '/token'                             => 'token',
			$issuer . '/revoke'                            => 'revoke',
			'/.well-known/oauth-protected-resource' . $mcp => 'resource',
			'/.well-known/oauth-protected-resource'        => 'resource',
			'/.well-known/oauth-authorization-server' . $issuer => 'server',
			'/.well-known/openid-configuration' . $issuer  => 'server',
			$issuer . '/.well-known/openid-configuration'  => 'server',
			$issuer . '/.well-known/oauth-authorization-server' => 'server',
		];
		return $map[ $path ] ?? null;
	}

	private static function serve( Container $container, string $route ): void {
		$tokens = new TokenStore();
		$events = $container->get( EventLog::class );
		switch ( $route ) {
			case 'resource':
				Connector::noStore();
				OAuthServer::json( 200, OAuthServer::resourceMetadata() );
				// No break: json() termina el request.
			case 'server':
				Connector::noStore();
				OAuthServer::json( 200, OAuthServer::serverMetadata() );
				// No break: json() termina el request.
			case 'authorize':
				( new OAuthServer( $tokens, $events ) )->authorize();
				break;
			case 'token':
				self::postOnly();
				( new OAuthServer( $tokens, $events ) )->token();
				break;
			case 'revoke':
				self::postOnly();
				( new OAuthServer( $tokens, $events ) )->revoke();
				break;
			case 'mcp':
				( new McpServer( $tokens, new Tools( $events, new ChangeSet( $container ) ) ) )->handle();
				break;
		}
	}

	private static function postOnly(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) {
			Connector::noStore();
			if ( ! headers_sent() ) {
				header( 'Allow: POST' );
			}
			OAuthServer::json( 405, [ 'error' => 'invalid_request' ] );
		}
	}
}
