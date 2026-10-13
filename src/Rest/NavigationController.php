<?php
/**
 * Navegación instantánea, RUM y pistas de frontend: GET /navigation/status, PUT /navigation/settings,
 * GET /navigation/report, POST /navigation/insights.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\Rum\RumService;
use ImaginaTurbo\Repository\SampleRepository;

/**
 * Ajustes, informe del RUM y causas de bfcache.
 */
final class NavigationController extends Controller {

	private const BOOLS = [ 'spec_predictive', 'spec_fallback', 'view_transitions', 'hints_lcp', 'hints_facades' ];

	public function registerRoutes(): void {
		$this->route( '/navigation/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$args = [
			'spec_mode'      => [
				'type' => 'string',
				'enum' => [ 'prefetch', 'prerender' ],
			],
			'spec_eagerness' => [
				'type' => 'string',
				'enum' => [ 'conservative', 'moderate', 'eager' ],
			],
			'rum_rate'       => [
				'type'    => 'number',
				'minimum' => 0,
				'maximum' => 1,
			],
			'confirm'        => [
				'type'    => 'boolean',
				'default' => false,
			],
		];
		foreach ( self::BOOLS as $key ) {
			$args[ $key ] = [ 'type' => 'boolean' ];
		}
		$this->route( '/navigation/settings', 'PUT', [ $this, 'update' ], $args );
		$this->route(
			'/navigation/report',
			\WP_REST_Server::READABLE,
			fn( \WP_REST_Request $r ): \WP_REST_Response => $this->ok( $this->container->get( RumService::class )->report( 30 === (int) $r->get_param( 'days' ) ? 30 : 7 ) ),
			[
				'days' => [
					'type' => 'integer',
					'enum' => [ 7, 30 ],
				],
			]
		);
		$this->route( '/navigation/insights', \WP_REST_Server::CREATABLE, fn(): \WP_REST_Response => $this->ok( $this->container->get( RumService::class )->daily() ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$settings = $this->container->get( Settings::class );
		$modules  = [];
		foreach ( $this->container->get( Diagnostics::class )->modules() as $module ) {
			if ( in_array( $module['id'] ?? '', [ 'speculation', 'rum', 'frontend_hints' ], true ) ) {
				$modules[ (string) $module['id'] ] = [
					'enabled'    => true === ( $module['enabled'] ?? false ),
					'forced_off' => implode( ', ', is_array( $module['forcedOff'] ?? null ) ? array_filter( $module['forcedOff'], 'is_string' ) : [] ),
				];
			}
		}
		$values = [
			'spec_mode'      => (string) $settings->get( 'spec_mode', 'prefetch' ),
			'spec_eagerness' => (string) $settings->get( 'spec_eagerness', 'moderate' ),
			'rum_rate'       => (float) $settings->get( 'rum_rate', 0.05 ),
		];
		foreach ( self::BOOLS as $key ) {
			$values[ $key ] = (bool) $settings->get( $key, 'view_transitions' !== $key );
		}
		return [
			'modules'   => $modules,
			'settings'  => $values,
			'core_api'  => function_exists( 'wp_get_speculation_rules' ),
			'permalink' => '' !== (string) get_option( 'permalink_structure' ),
			'hub_rum'   => '' !== (string) $settings->get( 'hub_url', '' ),
			'report'    => $this->container->get( RumService::class )->report( 7 ),
			'insights'  => RumService::stored(),
			'no_store'  => $this->noStore(),
		];
	}

	/**
	 * Páginas del frontend que salieron con Cache-Control: no-store (lo propio que impide el bfcache).
	 *
	 * @return list<array{route: string, n: int}>
	 */
	private function noStore(): array {
		$out = [];
		foreach ( $this->container->get( SampleRepository::class )->recent( 48, 'FrontendHtml' ) as $row ) {
			if ( str_contains( (string) ( $row['cache_control'] ?? '' ), 'no-store' ) ) {
				$route         = (string) ( $row['route_key'] ?? '' );
				$out[ $route ] = ( $out[ $route ] ?? 0 ) + 1;
			}
		}
		arsort( $out );
		$list = [];
		foreach ( array_slice( $out, 0, 10, true ) as $route => $n ) {
			$list[] = [
				'route' => (string) $route,
				'n'     => $n,
			];
		}
		return $list;
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$settings = $this->container->get( Settings::class );
		// Prerender ejecuta el JavaScript de páginas que quizá nadie vea (analítica, píxeles): moderado.
		if ( 'prerender' === $request->get_param( 'spec_mode' ) && 'prerender' !== $settings->get( 'spec_mode', 'prefetch' ) && true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma el prerender: ejecuta el JavaScript de las páginas antes de que se abran.', 'imagina-turbo' ), 428 );
		}
		$changes = [];
		foreach ( array_merge( [ 'spec_mode', 'spec_eagerness', 'rum_rate' ], self::BOOLS ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$changes[ $key ] = $request->get_param( $key );
			}
		}
		$settings->update( $changes );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info(
			'speculation',
			'settings',
			__( 'Ajustes de navegación y RUM actualizados.', 'imagina-turbo' ),
			[
				'user'    => get_current_user_id(),
				'changes' => $changes,
			]
		);
		return $this->ok( $this->status() );
	}
}
