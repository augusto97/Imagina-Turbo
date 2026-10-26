<?php
/**
 * Verificador externo (0.20.0): GET /verifier, PUT /verifier, GET /verifier/health, POST
 * /verifier/runs, GET /verifier/runs/{id}, POST /verifier/baseline.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Verifier\VerifierService;

/**
 * Configuración, prueba de conexión, corridas y referencia visual.
 */
final class VerifierController extends Controller {

	public function registerRoutes(): void {
		$this->route( '/verifier', \WP_REST_Server::READABLE, [ $this, 'status' ] );
		$this->route(
			'/verifier',
			'PUT',
			[ $this, 'save' ],
			[
				'url'     => [
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 300,
				],
				'site'    => [
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 100,
				],
				'new_key' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route( '/verifier/health', \WP_REST_Server::READABLE, fn(): \WP_REST_Response|\WP_Error => $this->relay( $this->service()->request( 'GET', '/v1/health' ) ) );
		$this->route(
			'/verifier/runs',
			\WP_REST_Server::CREATABLE,
			[ $this, 'run' ],
			[
				'lighthouse' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route(
			'/verifier/runs/(?P<id>\d{8}-[a-f0-9]{10})',
			\WP_REST_Server::READABLE,
			fn( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error => $this->relay( $this->service()->request( 'GET', '/v1/runs/' . (string) $r->get_param( 'id' ) ) )
		);
		$this->route(
			'/verifier/baseline',
			\WP_REST_Server::CREATABLE,
			fn( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error => $this->relay( $this->service()->request( 'POST', '/v1/baseline', [ 'run_id' => (string) $r->get_param( 'run_id' ) ] ) ),
			[
				'run_id' => [
					'type'     => 'string',
					'required' => true,
					'pattern'  => '^\d{8}-[a-f0-9]{10}$',
				],
			]
		);
	}

	private function service(): VerifierService {
		return $this->container->get( VerifierService::class );
	}

	/**
	 * Configuración, páginas que se probarían y últimas corridas (si responde).
	 */
	public function status(): \WP_REST_Response {
		$service = $this->service();
		$runs    = null;
		$error   = '';
		if ( $service->configured() ) {
			$reply = $service->request( 'GET', '/v1/runs?limit=10' );
			$runs  = is_array( $reply['body']['runs'] ?? null ) ? $reply['body']['runs'] : null;
			$error = $reply['error'];
		}
		return $this->ok(
			$service->status() + [
				'pages'      => VerifierService::defaultPages(),
				'site_url'   => untrailingslashit( home_url() ),
				'runs'       => $runs,
				'error'      => $error,
				'sites_json' => $this->sitesJson(),
			]
		);
	}

	/**
	 * Entrada lista para sites.json del verificador (sin la clave, que solo se muestra al crearla).
	 *
	 * @return array<string, mixed>
	 */
	private function sitesJson(): array {
		$s = VerifierService::settings();
		return [
			'id'      => $s['site'],
			'url'     => untrailingslashit( home_url() ),
			'pages'   => VerifierService::defaultPages(),
			'nightly' => true,
		];
	}

	public function save( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service()->save( (string) $request->get_param( 'url' ), (string) $request->get_param( 'site' ), true === $request->get_param( 'new_key' ) );
		if ( ! $result['ok'] ) {
			return $this->error( 'itb_verifier', $result['error'], 400 );
		}
		$this->container->get( EventLog::class )->info(
			'verifier',
			'settings',
			__( 'Configuración del verificador actualizada.', 'imagina-turbo' ),
			[
				'user'    => get_current_user_id(),
				'new_key' => '' !== $result['key'],
			]
		);
		$status = $this->status()->get_data();
		return $this->ok( ( is_array( $status ) ? $status : [] ) + [ 'new_key' => $result['key'] ] );
	}

	/**
	 * Corrida con las páginas clave del sitio.
	 */
	public function run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$reply = $this->service()->request(
			'POST',
			'/v1/runs',
			[
				'pages'      => VerifierService::defaultPages(),
				'lighthouse' => true === $request->get_param( 'lighthouse' ),
			]
		);
		if ( '' === $reply['error'] ) {
			$this->container->get( EventLog::class )->info( 'verifier', 'run', __( 'Corrida del verificador pedida.', 'imagina-turbo' ), [ 'user' => get_current_user_id() ] );
		}
		return $this->relay( $reply );
	}

	/**
	 * Respuesta del verificador tal cual o el error (502 si no responde).
	 *
	 * @param array{status: int, body: array<mixed>|null, error: string} $reply Respuesta.
	 */
	private function relay( array $reply ): \WP_REST_Response|\WP_Error {
		if ( '' !== $reply['error'] || null === $reply['body'] ) {
			return $this->error( 'itb_verifier', '' !== $reply['error'] ? $reply['error'] : __( 'El verificador no respondió con JSON.', 'imagina-turbo' ), $reply['status'] >= 400 && $reply['status'] < 500 ? 400 : 502 );
		}
		return $this->ok( $reply['body'] );
	}
}
