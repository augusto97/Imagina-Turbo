<?php
/**
 * Ajustes rápidos: GET /tweaks/status, PUT /tweaks/settings, GET /tweaks/perfmatters,
 * POST /tweaks/perfmatters/import.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\Tweaks\PerfmattersMigration;
use ImaginaTurbo\Modules\Tweaks\TweakCatalog;

/**
 * Ajustes rápidos y migración desde Perfmatters.
 */
final class TweaksController extends Controller {

	public function registerRoutes(): void {
		$this->route( '/tweaks/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$this->route(
			'/tweaks/settings',
			'PUT',
			[ $this, 'update' ],
			[
				'tweaks'    => [ 'type' => 'object' ],
				'revisions' => [
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				],
				'autosave'  => [
					'type'    => 'integer',
					'minimum' => 30,
					'maximum' => DAY_IN_SECONDS,
				],
				'confirm'   => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route( '/tweaks/perfmatters', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->perfmatters() ) );
		$this->route(
			'/tweaks/perfmatters/import',
			\WP_REST_Server::CREATABLE,
			[ $this, 'import' ],
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
	private function status(): array {
		$settings = $this->container->get( Settings::class );
		$stored   = $settings->get( 'tweaks', [] );
		$state    = TweakCatalog::resolve( is_array( $stored ) ? $stored : [] );
		$enabled  = false;
		$forced   = '';
		foreach ( $this->container->get( Diagnostics::class )->modules() as $module ) {
			if ( 'tweaks' === ( $module['id'] ?? null ) ) {
				$enabled = true === ( $module['enabled'] ?? false );
				$reasons = is_array( $module['forcedOff'] ?? null ) ? array_filter( $module['forcedOff'], 'is_string' ) : [];
				$forced  = implode( ', ', $reasons );
			}
		}
		$list = [];
		foreach ( TweakCatalog::all() as $id => $tweak ) {
			$list[] = [
				'id'          => $id,
				'label'       => $tweak['label'],
				'description' => $tweak['description'],
				'risk'        => $tweak['risk'],
				'default'     => $tweak['default'],
				'enabled'     => $state[ $id ],
			];
		}
		return [
			'enabled'     => $enabled,
			'forced_off'  => $forced,
			'tweaks'      => $list,
			'revisions'   => (int) $settings->get( 'tweak_revisions', 5 ),
			'autosave'    => (int) $settings->get( 'tweak_autosave', 120 ),
			'perfmatters' => is_array( get_option( PerfmattersMigration::OPTION, null ) ),
		];
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$changes = [];
		$tweaks  = $request->get_param( 'tweaks' );
		if ( is_array( $tweaks ) ) {
			$catalog = TweakCatalog::all();
			$current = $this->container->get( Settings::class )->get( 'tweaks', [] );
			$current = TweakCatalog::resolve( is_array( $current ) ? $current : [] );
			$map     = [];
			foreach ( $tweaks as $id => $on ) {
				if ( is_string( $id ) && isset( $catalog[ $id ] ) && is_bool( $on ) ) {
					// Encender algo no seguro exige confirmación (regla 3).
					if ( $on && ! $current[ $id ] && 'safe' !== $catalog[ $id ]['risk'] && true !== $request->get_param( 'confirm' ) ) {
						return $this->error( 'itb_confirm', __( 'Confirma el ajuste: no es de riesgo seguro.', 'imagina-turbo' ), 428, [ 'tweak' => $id ] );
					}
					$map[ $id ] = $on;
				}
			}
			$changes['tweaks'] = $map + $current;
		}
		foreach ( [
			'revisions' => 'tweak_revisions',
			'autosave'  => 'tweak_autosave',
		] as $param => $key ) {
			if ( null !== $request->get_param( $param ) ) {
				$changes[ $key ] = $request->get_param( $param );
			}
		}
		$this->container->get( Settings::class )->update( $changes );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info(
			'tweaks',
			'settings',
			__( 'Ajustes rápidos actualizados.', 'imagina-turbo' ),
			[
				'user'    => get_current_user_id(),
				'changes' => $changes,
			]
		);
		return $this->ok( $this->status() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function perfmatters(): array {
		$options = get_option( PerfmattersMigration::OPTION, null );
		if ( ! is_array( $options ) ) {
			return [
				'found'    => false,
				'active'   => false,
				'mapped'   => [],
				'unmapped' => [],
			];
		}
		$map = PerfmattersMigration::map( $options );
		return [
			'found'    => true,
			'active'   => function_exists( 'is_plugin_active' ) ? is_plugin_active( 'perfmatters/perfmatters.php' ) : in_array( 'perfmatters/perfmatters.php', (array) get_option( 'active_plugins', [] ), true ),
			'mapped'   => $map['mapped'],
			'unmapped' => $map['unmapped'],
		];
	}

	public function import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma la importación.', 'imagina-turbo' ), 428 );
		}
		$options = get_option( PerfmattersMigration::OPTION, null );
		if ( ! is_array( $options ) ) {
			return $this->error( 'itb_no_perfmatters', __( 'No hay ajustes de Perfmatters en este sitio.', 'imagina-turbo' ), 404 );
		}
		$settings = $this->container->get( Settings::class );
		$map      = PerfmattersMigration::map( $options );
		$changes  = $map['changes'];
		if ( isset( $changes['tweaks'] ) && is_array( $changes['tweaks'] ) ) {
			$current           = $settings->get( 'tweaks', [] );
			$changes['tweaks'] = $changes['tweaks'] + TweakCatalog::resolve( is_array( $current ) ? $current : [] );
		}
		$settings->update( $changes );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->warning(
			'tweaks',
			'perfmatters_import',
			__( 'Ajustes importados desde Perfmatters.', 'imagina-turbo' ),
			[
				'user'   => get_current_user_id(),
				'mapped' => $map['mapped'],
			]
		);
		return $this->ok( array_merge( $this->status(), [ 'imported' => count( $map['mapped'] ) ] ) );
	}
}
