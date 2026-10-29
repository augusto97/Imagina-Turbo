<?php
/**
 * Cloudflare: GET /cloudflare/status, POST|DELETE /cloudflare/connect, GET /cloudflare/zone,
 * GET /cloudflare/plan, POST /cloudflare/apply, GET /cloudflare/snapshots,
 * POST /cloudflare/snapshots/{id}/restore, GET /cloudflare/analytics, GET /cloudflare/zaraz,
 * POST /cloudflare/zaraz/scan|apply|revert, PUT /cloudflare/zone-prefs.
 *
 * Aplicar y restaurar exigen el módulo activo, un dominio permitido y confirmación explícita.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Cloudflare\CloudflareService;
use ImaginaTurbo\Cloudflare\Templates;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Integrations\AnalyticsTags;
use ImaginaTurbo\Integrations\ZarazBridge;
use ImaginaTurbo\Modules\PluginFilter\LoopbackClient;

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
		$this->route(
			'/cloudflare/zone-prefs',
			'PUT',
			[ $this, 'zonePrefs' ],
			[
				'prefs' => [
					'type'     => 'object',
					'required' => true,
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
		$this->route( '/cloudflare/zaraz', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->zarazStatus() ) );
		$this->route( '/cloudflare/zaraz/scan', \WP_REST_Server::CREATABLE, [ $this, 'zarazScan' ] );
		$this->route(
			'/cloudflare/zaraz/apply',
			\WP_REST_Server::CREATABLE,
			[ $this, 'zarazApply' ],
			[
				'ga4'        => [
					'type'    => 'boolean',
					'default' => false,
				],
				'meta'       => [
					'type'    => 'boolean',
					'default' => false,
				],
				'meta_token' => [
					'type'      => 'string',
					'maxLength' => 500,
					'default'   => '',
				],
				'confirm'    => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route(
			'/cloudflare/zaraz/revert',
			\WP_REST_Server::CREATABLE,
			[ $this, 'zarazRevert' ],
			[
				'confirm' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function zarazStatus(): array {
		// Del ajuste, no de la config cargada al empezar el request (que no ve un cambio recién hecho).
		$takeover = $this->container->get( Settings::class )->get( 'zaraz_takeover', [] );
		$takeover = is_array( $takeover ) ? $takeover : [];
		return $this->service()->zarazStatus(
			[
				'ga4'  => true === ( $takeover['ga4'] ?? false ),
				'meta' => true === ( $takeover['meta'] ?? false ),
			]
		) + [ 'cloudflare_module' => null !== Runtime::$config && Runtime::$config->moduleEnabled( 'cloudflare' ) ];
	}

	/**
	 * Busca los ids de analítica en el HTML de la portada (cualquier origen: tema, fragmentos, plugins).
	 */
	public function zarazScan(): \WP_REST_Response|\WP_Error {
		$reply = LoopbackClient::request( 'GET', '/?itb_nc=' . strtolower( wp_generate_password( 12, false ) ), '', '' );
		if ( is_string( $reply ) ) {
			return $this->error( 'itb_loopback', $reply, 502 );
		}
		update_option(
			'itb_zaraz_scan',
			[
				'at'  => time(),
				'ids' => AnalyticsTags::scanHtml( $reply['body'] ),
			],
			false
		);
		return $this->ok( $this->zarazStatus() );
	}

	public function zarazApply( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->canWrite( $request );
		if ( null !== $check ) {
			return $check;
		}
		$ga4  = true === $request->get_param( 'ga4' );
		$meta = true === $request->get_param( 'meta' );
		if ( ! $ga4 && ! $meta ) {
			return $this->error( 'itb_zaraz_none', __( 'Elige Google Analytics, Meta o los dos.', 'imagina-turbo' ), 400 );
		}
		$result = $this->service()->zarazApply( $ga4, $meta, trim( (string) $request->get_param( 'meta_token' ) ) );
		if ( ! $result['ok'] ) {
			return $this->error( 'itb_zaraz', $result['error'], 400 );
		}
		// Las etiquetas del sitio se apagan solo después de que Zaraz quedó configurado.
		ZarazBridge::setTakeover(
			$this->container,
			[
				'ga4'  => $ga4,
				'meta' => $meta,
			],
			'zaraz'
		);
		return $this->ok( [ 'result' => $result ] + $this->zarazStatus() );
	}

	/**
	 * Las etiquetas del sitio vuelven primero (aunque Cloudflare no responda); después se quitan las
	 * herramientas propias de Zaraz.
	 */
	public function zarazRevert( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma: las etiquetas del sitio vuelven y se quitan de Zaraz las herramientas de Imagina Turbo.', 'imagina-turbo' ), 428 );
		}
		ZarazBridge::setTakeover(
			$this->container,
			[
				'ga4'  => false,
				'meta' => false,
			],
			'zaraz_revert'
		);
		$result = $this->service()->zarazRevert();
		return $this->ok( [ 'result' => $result ] + $this->zarazStatus() );
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

	/**
	 * Valor elegido para cada ajuste de la zona (0.21.0): una de sus opciones o "keep" (no tocarlo). Solo
	 * se guarda lo que difiere de lo recomendado. No toca Cloudflare: el cambio aparece en el plan.
	 */
	public function zonePrefs( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input    = (array) $request->get_param( 'prefs' );
		$settings = $this->container->get( Settings::class );
		$prefs    = Settings::zonePrefs( (array) $settings->get( 'cf_zone_prefs', [] ) );
		$defs     = array_column( Templates::settings(), null, 'id' );
		foreach ( $input as $id => $value ) {
			$def = is_string( $id ) ? ( $defs[ $id ] ?? null ) : null;
			if ( null === $def || $def['read_only'] ) {
				return $this->error( 'itb_cf_pref', __( 'Ajuste de Cloudflare desconocido o de solo lectura.', 'imagina-turbo' ), 400 );
			}
			if ( Templates::KEEP !== $value && ! in_array( $value, $def['options'], true ) ) {
				/* translators: %s: ajuste. */
				return $this->error( 'itb_cf_pref', sprintf( __( 'Valor no válido para %s.', 'imagina-turbo' ), $def['label'] ), 400 );
			}
			if ( $value === $def['default'] ) {
				unset( $prefs[ $id ] );
			} else {
				$prefs[ $id ] = $value;
			}
		}
		$settings->update( [ 'cf_zone_prefs' => $prefs ] );
		$this->container->get( EventLog::class )->info(
			'cloudflare',
			'zone_prefs',
			__( 'Preferencias de ajustes de Cloudflare guardadas. Revisa el plan para aplicarlas.', 'imagina-turbo' ),
			[
				'user'  => get_current_user_id(),
				'prefs' => $prefs,
			]
		);
		return $this->ok( [ 'prefs' => $prefs ] );
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
