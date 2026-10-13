<?php
/**
 * Cloudflare: GET /cloudflare/status, POST|DELETE /cloudflare/connect, GET /cloudflare/zone,
 * GET /cloudflare/plan, POST /cloudflare/apply, GET /cloudflare/snapshots,
 * POST /cloudflare/snapshots/{id}/restore, GET /cloudflare/analytics.
 *
 * Aplicar y restaurar exigen el módulo activo, un dominio permitido y confirmación explícita.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Cloudflare\CloudflareService;
use ImaginaTurbo\Core\Runtime;

/**
 * Rutas de Cloudflare.
 */
final class CloudflareController extends Controller {

	public function registerRoutes(): void {
		$this->route( '/cloudflare/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->service()->status() ) );
		$this->route(
			'/cloudflare/connect',
			\WP_REST_Server::CREATABLE,
			[ $this, 'connect' ],
			[
				'token' => [
					'type'      => 'string',
					'required'  => true,
					'minLength' => 30,
					'maxLength' => 200,
				],
			]
		);
		$this->route(
			'/cloudflare/connect',
			\WP_REST_Server::DELETABLE,
			[ $this, 'disconnect' ],
			[
				'confirm' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route(
			'/cloudflare/remove-all',
			\WP_REST_Server::CREATABLE,
			[ $this, 'removeAll' ],
			[
				'confirm' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route( '/cloudflare/zone', \WP_REST_Server::READABLE, [ $this, 'zone' ] );
		$this->route( '/cloudflare/plan', \WP_REST_Server::READABLE, [ $this, 'plan' ] );
		$this->route(
			'/cloudflare/apply',
			\WP_REST_Server::CREATABLE,
			[ $this, 'apply' ],
			[
				'ids'     => [
					'type'     => 'array',
					'items'    => [
						'type'    => 'string',
						'pattern' => '^(rule|setting):[a-z0-9_\-]{1,80}$',
					],
					'maxItems' => 50,
					'required' => true,
				],
				'confirm' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route( '/cloudflare/snapshots', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->service()->snapshots() ) );
		$this->route(
			'/cloudflare/snapshots/(?P<id>\d+)/restore',
			\WP_REST_Server::CREATABLE,
			[ $this, 'restore' ],
			[
				'confirm' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route( '/cloudflare/analytics', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->service()->analytics() ) );
	}

	public function connect( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service()->connect( (string) $request->get_param( 'token' ) );
		if ( ! $result['ok'] ) {
			return $this->error( 'itb_cf_connect', $result['error'], 400 );
		}
		return $this->ok( $this->service()->status() );
	}

	public function disconnect( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$footprint = $this->service()->footprint();
		// Sin el token ya no se pueden quitar las reglas propias desde aquí.
		if ( ( $footprint['rules'] > 0 || $footprint['settings'] > 0 ) && true !== $request->get_param( 'confirm' ) ) {
			return $this->error(
				'itb_cf_footprint',
				__( 'Imagina Turbo todavía tiene reglas o ajustes en esta zona. Quítalos antes con "Quitar todo lo de Imagina Turbo"; si desconectas igual, se quedan en Cloudflare y habrá que borrarlos a mano (empiezan por itb-).', 'imagina-turbo' ),
				428,
				[ 'footprint' => $footprint ]
			);
		}
		$this->service()->disconnect();
		return $this->ok( $this->service()->status() );
	}

	/**
	 * Quita todo lo del plugin de la zona. No exige el módulo activo: es justo lo que se hace antes
	 * de apagarlo o desinstalarlo.
	 */
	public function removeAll( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->connected() ) {
			return $this->notConnected();
		}
		$guard = $this->service()->guard();
		if ( '' !== $guard ) {
			return $this->error( 'itb_cf_guard', $guard, 409 );
		}
		if ( true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma: se borran las reglas de Imagina Turbo y los ajustes vuelven a su valor anterior (con snapshot previo).', 'imagina-turbo' ), 428 );
		}
		return $this->ok( $this->service()->removeAll() );
	}

	public function zone(): \WP_REST_Response|\WP_Error {
		if ( ! $this->connected() ) {
			return $this->notConnected();
		}
		return $this->ok( $this->service()->zone() );
	}

	public function plan(): \WP_REST_Response|\WP_Error {
		if ( ! $this->connected() ) {
			return $this->notConnected();
		}
		return $this->ok( $this->service()->plan() );
	}

	public function apply( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->canWrite( $request );
		if ( null !== $check ) {
			return $check;
		}
		$ids = array_values( array_filter( (array) $request->get_param( 'ids' ), 'is_string' ) );
		return $this->ok( $this->service()->apply( $ids ) );
	}

	public function restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Revertir no exige el módulo activo: es lo que se hace al apagarlo.
		$check = $this->canWrite( $request, false );
		if ( null !== $check ) {
			return $check;
		}
		return $this->ok( $this->service()->restore( (int) $request->get_param( 'id' ) ) );
	}

	private function canWrite( \WP_REST_Request $request, bool $needsModule = true ): ?\WP_Error {
		$service = $this->service();
		if ( ! $this->connected() ) {
			return $this->notConnected();
		}
		if ( $needsModule && ( null === Runtime::$config || ! Runtime::$config->moduleEnabled( 'cloudflare' ) ) ) {
			return $this->error( 'itb_cf_disabled', __( 'Activa el módulo Cloudflare para aplicar cambios.', 'imagina-turbo' ), 409 );
		}
		$guard = $service->guard();
		if ( '' !== $guard ) {
			return $this->error( 'itb_cf_guard', $guard, 409 );
		}
		if ( true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma el cambio en Cloudflare.', 'imagina-turbo' ), 428 );
		}
		return null;
	}

	private function connected(): bool {
		$status = $this->service()->status();
		return null !== $status['zone'] && is_array( $status['token'] ) && 'ok' === $status['token']['state'];
	}

	private function notConnected(): \WP_Error {
		return $this->error( 'itb_cf_not_connected', __( 'Cloudflare no está conectado o el token ya no se puede leer.', 'imagina-turbo' ), 409 );
	}

	private function service(): CloudflareService {
		return $this->container->get( CloudflareService::class );
	}
}
