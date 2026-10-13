<?php
/**
 * POST /safety/kill-switch, /safety/bypass-cookie, /safety/diag-cookie, /safety/safe-mode/exit.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Core\Safety\AdminBypassCookie;
use ImaginaTurbo\Core\Safety\KillSwitch;
use ImaginaTurbo\Core\Safety\SafeMode;
use ImaginaTurbo\Support\Filesystem;

/**
 * Seguridad operativa.
 */
final class SafetyController extends Controller {

	public function registerRoutes(): void {
		$this->route(
			'/safety/kill-switch',
			\WP_REST_Server::CREATABLE,
			[ $this, 'killSwitch' ],
			[
				'enabled' => [
					'type'     => 'boolean',
					'required' => true,
				],
			]
		);
		$this->route(
			'/safety/bypass-cookie',
			\WP_REST_Server::CREATABLE,
			[ $this, 'bypassCookie' ],
			[
				'enabled' => [
					'type'     => 'boolean',
					'required' => true,
				],
				'hours'   => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 8,
					'default' => 1,
				],
			]
		);
		$this->route(
			'/safety/diag-cookie',
			\WP_REST_Server::CREATABLE,
			[ $this, 'diagCookie' ],
			[
				'enabled' => [
					'type'     => 'boolean',
					'required' => true,
				],
				'hours'   => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 8,
					'default' => 1,
				],
			]
		);
		$this->route( '/safety/safe-mode/exit', \WP_REST_Server::CREATABLE, [ $this, 'exitSafeMode' ] );
	}

	public function killSwitch( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$enabled = (bool) $request->get_param( 'enabled' );
		if ( ! $enabled && KillSwitch::constantActive() ) {
			return $this->error( 'itb_constant', __( 'El kill switch está definido en wp-config.php (ITB_DISABLE). Quítalo de ahí.', 'imagina-turbo' ), 409 );
		}
		Filesystem::ensureDataDir( Runtime::$dataDir );
		$state = KillSwitch::setFile( Runtime::$dataDir, $enabled );
		if ( $state !== $enabled ) {
			return $this->error( 'itb_fs', __( 'No se pudo cambiar el archivo del kill switch. Revisa los permisos de wp-content/imagina-turbo/.', 'imagina-turbo' ), 500 );
		}
		$this->container->get( EventLog::class )->warning(
			'core',
			'kill_switch',
			$enabled ? __( 'Kill switch activado desde el admin.', 'imagina-turbo' ) : __( 'Kill switch desactivado desde el admin.', 'imagina-turbo' ),
			[ 'user' => get_current_user_id() ]
		);
		return $this->ok( $this->container->get( Diagnostics::class )->safety() );
	}

	public function bypassCookie( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->signedCookie(
			$request,
			AdminBypassCookie::NAME,
			'bypass_cookie',
			__( 'Cookie de bypass emitida para el administrador actual.', 'imagina-turbo' ),
			__( 'Cookie de bypass retirada.', 'imagina-turbo' )
		);
	}

	public function diagCookie( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->signedCookie(
			$request,
			AdminBypassCookie::DIAG_NAME,
			'diag_cookie',
			__( 'Cookie de diagnóstico emitida: este navegador recibe Server-Timing y todas sus visitas se miden.', 'imagina-turbo' ),
			__( 'Cookie de diagnóstico retirada.', 'imagina-turbo' )
		);
	}

	/**
	 * Emite o retira una cookie firmada para el administrador actual (HttpOnly, hasta 8 h).
	 */
	private function signedCookie( \WP_REST_Request $request, string $name, string $eventType, string $onMessage, string $offMessage ): \WP_REST_Response|\WP_Error {
		$enabled = (bool) $request->get_param( 'enabled' );
		$key     = AdminBypassCookie::key( $name );
		if ( null === $key ) {
			return $this->error( 'itb_salts', __( 'Faltan AUTH_KEY y AUTH_SALT en wp-config.php: no se puede firmar la cookie.', 'imagina-turbo' ), 409 );
		}

		$path   = defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? (string) constant( 'COOKIE_DOMAIN' ) : '';

		if ( $enabled ) {
			$expires = time() + (int) $request->get_param( 'hours' ) * HOUR_IN_SECONDS;
			$value   = AdminBypassCookie::create( get_current_user_id(), $expires, $key );
		} else {
			$expires = time() - YEAR_IN_SECONDS;
			$value   = '';
		}

		if ( ! headers_sent() ) {
			setcookie(
				$name,
				$value,
				[
					'expires'  => $expires,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}

		$this->container->get( EventLog::class )->info(
			'core',
			$eventType,
			$enabled ? $onMessage : $offMessage,
			[
				'user'    => get_current_user_id(),
				'expires' => $enabled ? $expires : null,
			]
		);

		return $this->ok(
			[
				'active'  => $enabled,
				'expires' => $enabled ? $expires : null,
			]
		);
	}

	public function exitSafeMode(): \WP_REST_Response|\WP_Error {
		$safeMode = $this->container->get( SafeMode::class );
		if ( ! $safeMode->isActive() ) {
			return $this->ok( $this->container->get( Diagnostics::class )->safety() );
		}
		if ( ! $safeMode->leave() ) {
			return $this->error( 'itb_fs', __( 'No se pudo borrar la bandera de modo seguro.', 'imagina-turbo' ), 500 );
		}
		Runtime::$safeMode = false;
		$result            = $this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->warning(
			'core',
			'safe_mode_exit',
			__( 'Salida manual del modo seguro.', 'imagina-turbo' ),
			[
				'user'   => get_current_user_id(),
				'config' => $result['ok'],
			]
		);
		return $this->ok( $this->container->get( Diagnostics::class )->safety() );
	}
}
