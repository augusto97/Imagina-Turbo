<?php
/**
 * Ajustes, módulos, recompilación y exportación/importación.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\ModuleCatalog;
use ImaginaTurbo\Core\RiskLevel;

/**
 * Escrituras de configuración.
 */
final class SettingsController extends Controller {

	public function registerRoutes(): void {
		$this->route(
			'/modules/(?P<id>[a-z_]{2,40})',
			'PUT',
			[ $this, 'updateModule' ],
			[
				'enabled' => [
					'type'     => 'boolean',
					'required' => true,
				],
				'confirm' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
		$this->route(
			'/settings',
			'PUT',
			[ $this, 'updateSettings' ],
			[
				'debug'           => [ 'type' => 'boolean' ],
				'staging_domains' => [
					'type'     => 'array',
					'items'    => [
						'type'      => 'string',
						'maxLength' => 253,
					],
					'maxItems' => 100,
				],
			]
		);
		$this->route( '/settings', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->publicSettings() ) );
		$this->route( '/config/recompile', \WP_REST_Server::CREATABLE, [ $this, 'recompile' ] );
		$this->route( '/settings/export', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->container->get( Settings::class )->export() ) );
		$this->route(
			'/settings/import',
			\WP_REST_Server::CREATABLE,
			[ $this, 'import' ],
			[
				'data' => [
					'type'     => 'object',
					'required' => true,
				],
			]
		);
	}

	public function updateModule( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id         = (string) $request->get_param( 'id' );
		$enabled    = (bool) $request->get_param( 'enabled' );
		$definition = $this->container->get( ModuleCatalog::class )->get( $id );

		if ( null === $definition ) {
			return $this->error( 'itb_unknown_module', __( 'Módulo desconocido.', 'imagina-turbo' ), 404 );
		}
		if ( $enabled && ! $definition->isAvailable() ) {
			/* translators: %d: número de fase */
			return $this->error( 'itb_unavailable', sprintf( __( 'Este módulo llega en la fase %d; todavía no está disponible.', 'imagina-turbo' ), $definition->phase ), 409 );
		}
		$forced = $this->container->get( Diagnostics::class )->overlap()['forced_off'][ $id ] ?? [];
		if ( $enabled && [] !== $forced ) {
			return $this->error( 'itb_overlap', implode( ' ', $forced ), 409, [ 'reasons' => $forced ] );
		}
		// Regla 3: nada sensible se activa por fe. Lo moderado o invasivo exige confirmación explícita y queda registrado.
		if ( $enabled && RiskLevel::Safe !== $definition->risk && true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Este módulo no es seguro por defecto: confirma que lo verificaste.', 'imagina-turbo' ), 428 );
		}

		$this->container->get( Settings::class )->update( [ 'modules' => [ $id => $enabled ] ] );
		$result = $this->container->get( ConfigCompiler::class )->compile();

		$this->container->get( EventLog::class )->log(
			$id,
			$enabled ? 'enable' : 'disable',
			RiskLevel::Safe === $definition->risk ? EventLog::INFO : EventLog::WARNING,
			/* translators: 1: módulo, 2: estado */
			sprintf( __( 'Módulo %1$s %2$s.', 'imagina-turbo' ), $definition->label, $enabled ? __( 'activado', 'imagina-turbo' ) : __( 'desactivado', 'imagina-turbo' ) ),
			[
				'user'         => get_current_user_id(),
				'risk'         => $definition->risk->value,
				'verification' => RiskLevel::Safe === $definition->risk ? 'not_required' : 'manual',
			]
		);

		return $this->ok(
			[
				'modules' => $this->container->get( Diagnostics::class )->modules(),
				'config'  => $result,
			]
		);
	}

	public function updateSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$changes = [];
		foreach ( [ 'debug', 'staging_domains' ] as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$changes[ $key ] = $request->get_param( $key );
			}
		}
		$this->container->get( Settings::class )->update( $changes );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info( 'core', 'settings', __( 'Ajustes actualizados.', 'imagina-turbo' ), [ 'keys' => array_keys( $changes ) ] );
		return $this->ok( $this->publicSettings() );
	}

	public function recompile(): \WP_REST_Response {
		$result = $this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->log(
			'core',
			'recompile',
			$result['ok'] ? EventLog::INFO : EventLog::ERROR,
			$result['ok'] ? __( 'Configuración recompilada.', 'imagina-turbo' ) : __( 'Error al recompilar la configuración.', 'imagina-turbo' ),
			[
				'hash'     => $result['hash'],
				'errors'   => $result['errors'],
				'warnings' => $result['warnings'],
			]
		);
		return $this->ok(
			[
				'result' => $result,
				'status' => $this->container->get( ConfigCompiler::class )->status(),
			],
			$result['ok'] ? 200 : 500
		);
	}

	public function import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$this->container->get( Settings::class )->import( $request->get_param( 'data' ) );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error( 'itb_import', $e->getMessage(), 400 );
		}
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->warning( 'core', 'import', __( 'Ajustes importados.', 'imagina-turbo' ), [ 'user' => get_current_user_id() ] );
		return $this->ok( $this->publicSettings() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function publicSettings(): array {
		$settings = $this->container->get( Settings::class )->all();
		return [
			'debug'           => $settings['debug'],
			'staging_domains' => $settings['staging_domains'],
			'modules'         => $settings['modules'],
		];
	}
}
