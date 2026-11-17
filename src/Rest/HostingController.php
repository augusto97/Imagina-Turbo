<?php
/**
 * Perfil de hosting (0.25.0): GET /hosting, PUT /hosting, POST /hosting/loopback.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Environment\Detector;
use ImaginaTurbo\Core\Environment\HostingProfile;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\CacheCoordinator\CacheModule;
use ImaginaTurbo\Modules\CacheCoordinator\Warmer;

/**
 * Detección, ajuste manual y prueba de loopback.
 */
final class HostingController extends Controller {

	public function registerRoutes(): void {
		$this->route( '/hosting', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$this->route(
			'/hosting',
			'PUT',
			[ $this, 'update' ],
			[
				'profile' => [
					'type'     => 'string',
					'enum'     => HostingProfile::PROFILES,
					'required' => true,
				],
			]
		);
		$this->route( '/hosting/loopback', \WP_REST_Server::CREATABLE, [ $this, 'loopback' ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$detected = HostingProfile::detect();
		$setting  = (string) $this->container->get( Settings::class )->get( 'hosting_profile', 'auto' );
		$loopback = HostingProfile::lastLoopback();
		$advice   = [];
		foreach ( is_array( $loopback['checks'] ?? null ) ? $loopback['checks'] : [] as $check ) {
			$status = is_array( $check ) && is_string( $check['status'] ?? null ) ? $check['status'] : 'ok';
			if ( 'ok' !== $status && ! isset( $advice[ $status ] ) ) {
				$advice[ $status ] = HostingProfile::advice( $status, is_string( $loopback['server_ip'] ?? null ) ? $loopback['server_ip'] : '' );
			}
		}
		$cache  = CacheModule::settings();
		$warmer = $this->container->get( Warmer::class )->state();
		return [
			'detected'  => $detected,
			'setting'   => $setting,
			'effective' => HostingProfile::effective( $setting, $detected['shared'] ),
			'server'    => Detector::server( $_SERVER )['software'],
			'loopback'  => $loopback,
			'affects'   => HostingProfile::affects(),
			'advice'    => $advice,
			'cron'      => [
				'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'last'     => (int) get_option( HostingProfile::CRON_OPTION, 0 ),
				'edge'     => null !== Runtime::$config && Runtime::$config->moduleEnabled( 'cache_coordinator' ) && 'headers' === $cache['mode'] && $cache['edge_html'],
				'steps'    => HostingProfile::cronSteps( $detected['panel'] ),
			],
			'warmer'    => [
				'shared'       => $warmer['shared'],
				'limited_at'   => $warmer['limited_at'],
				'limited_code' => $warmer['limited_code'],
			],
		];
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		$profile = (string) $request->get_param( 'profile' );
		$this->container->get( Settings::class )->update( [ 'hosting_profile' => $profile ] );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info(
			'hosting',
			'profile',
			__( 'Perfil de hosting cambiado.', 'imagina-turbo' ),
			[
				'user'    => get_current_user_id(),
				'profile' => $profile,
			]
		);
		return $this->ok( $this->status() );
	}

	public function loopback(): \WP_REST_Response|\WP_Error {
		// Cuatro peticiones al propio sitio: como mucho una prueba por minuto.
		if ( false !== get_transient( 'itb_loopback_lock' ) ) {
			return $this->error( 'itb_loopback_busy', __( 'Espera un minuto antes de repetir la prueba.', 'imagina-turbo' ), 429 );
		}
		set_transient( 'itb_loopback_lock', 1, MINUTE_IN_SECONDS );
		$report = HostingProfile::loopback();
		$this->container->get( EventLog::class )->log(
			'hosting',
			'loopback',
			$report['ok'] ? EventLog::INFO : EventLog::WARNING,
			$report['ok'] ? __( 'El sitio puede llamarse a sí mismo.', 'imagina-turbo' ) : __( 'El sitio no puede llamarse a sí mismo en todas las pruebas.', 'imagina-turbo' ),
			[
				'user'   => get_current_user_id(),
				'checks' => array_map( static fn( array $c ): string => $c['id'] . ':' . $c['status'] . ':' . $c['code'], $report['checks'] ),
			]
		);
		return $this->ok( $this->status() );
	}
}
