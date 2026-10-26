<?php
/**
 * Navegación instantánea, RUM y pistas de frontend: GET /navigation/status, PUT /navigation/settings,
 * GET /navigation/report, POST /navigation/insights, POST /navigation/lazy-documents/scan,
 * PUT /navigation/budgets y POST /navigation/budgets/check (0.19.0).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\AssetManager\AssetScanner;
use ImaginaTurbo\Modules\CacheCoordinator\FragmentCache;
use ImaginaTurbo\Modules\FrontendHints\FrontendHintsModule;
use ImaginaTurbo\Modules\Rum\Budgets;
use ImaginaTurbo\Modules\Rum\RumService;
use ImaginaTurbo\Modules\Speculation\PrefetchProbe;
use ImaginaTurbo\Repository\SampleRepository;

/**
 * Ajustes, informe del RUM y causas de bfcache.
 */
final class NavigationController extends Controller {

	private const BOOLS = [ 'spec_predictive', 'spec_fallback', 'view_transitions', 'hints_lcp', 'hints_fonts', 'hints_facades', 'lazy_elements' ];

	/** Apagados por defecto. */
	private const OFF_BY_DEFAULT = [ 'view_transitions', 'lazy_elements' ];

	/** Última detección de documentos de Elementor (0.17.0). */
	private const LAZY_TRANSIENT = 'itb_lazy_docs';

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
			'fonts_manual'   => [
				'type'     => 'array',
				'items'    => [
					'type'      => 'string',
					'maxLength' => 300,
				],
				'maxItems' => 10,
			],
			'lazy_render'    => [
				'type'  => 'array',
				'items' => [ 'type' => 'object' ],
			],
			'lazy_documents' => [
				'type'     => 'array',
				'items'    => [
					'type'       => 'object',
					'properties' => [
						'id'     => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						'mode'   => [
							'type' => 'string',
							'enum' => [ 'v', 'i' ],
						],
						'height' => [
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 3000,
						],
					],
				],
				'maxItems' => 30,
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
		$this->route( '/navigation/lazy-documents/scan', \WP_REST_Server::CREATABLE, [ $this, 'scanLazyDocuments' ] );
		$budget = [];
		foreach ( Budgets::DEFAULTS as $metric => $default ) {
			$budget[ $metric ] = [
				'type'    => 'number',
				'minimum' => 0,
			];
		}
		$this->route(
			'/navigation/budgets',
			'PUT',
			[ $this, 'updateBudgets' ],
			[
				'budgets' => [
					'type'                 => 'object',
					'required'             => true,
					'properties'           => $budget,
					'additionalProperties' => false,
				],
			]
		);
		$this->route( '/navigation/budgets/check', \WP_REST_Server::CREATABLE, [ $this, 'checkBudgets' ] );
	}

	/**
	 * Guarda los presupuestos (0.19.0) y vuelve a comparar con lo medido.
	 */
	public function updateBudgets( \WP_REST_Request $request ): \WP_REST_Response {
		$budgets = Budgets::normalize( (array) $request->get_param( 'budgets' ) );
		$this->container->get( Settings::class )->update( [ 'budgets' => $budgets ] );
		$this->container->get( EventLog::class )->info(
			'rum',
			'budgets',
			__( 'Presupuestos de rendimiento actualizados.', 'imagina-turbo' ),
			[
				'user'    => get_current_user_id(),
				'budgets' => $budgets,
			]
		);
		$this->container->get( RumService::class )->budgets( $budgets );
		return $this->ok( $this->status() );
	}

	/**
	 * Compara ya, sin esperar a la tarea diaria.
	 */
	public function checkBudgets(): \WP_REST_Response {
		$this->container->get( RumService::class )->budgets( Budgets::normalize( (array) $this->container->get( Settings::class )->get( 'budgets', [] ) ) );
		return $this->ok( $this->status() );
	}

	/**
	 * Detecta los documentos de Elementor de las páginas de muestra (0.17.0).
	 */
	public function scanLazyDocuments(): \WP_REST_Response {
		$result = $this->container->get( AssetScanner::class )->lazyDocuments() + [ 'generated_at' => time() ];
		set_transient( self::LAZY_TRANSIENT, $result, DAY_IN_SECONDS );
		return $this->ok( $this->status() );
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
			'lazy_render'    => Settings::lazyRender( (array) $settings->get( 'lazy_render', [] ) ),
			'lazy_documents' => Settings::lazyDocuments( (array) $settings->get( 'lazy_documents', [] ) ),
			'fonts_manual'   => Settings::fontPaths( (array) $settings->get( 'fonts_manual', [] ), home_url( '/' ) ),
			'spec_mode'      => (string) $settings->get( 'spec_mode', 'prefetch' ),
			'spec_eagerness' => (string) $settings->get( 'spec_eagerness', 'moderate' ),
			'rum_rate'       => (float) $settings->get( 'rum_rate', 0.05 ),
		];
		foreach ( self::BOOLS as $key ) {
			$values[ $key ] = (bool) $settings->get( $key, ! in_array( $key, self::OFF_BY_DEFAULT, true ) );
		}
		return [
			'modules'        => $modules,
			'settings'       => $values,
			'core_api'       => function_exists( 'wp_get_speculation_rules' ),
			'lazy_presets'   => FrontendHintsModule::lazyRenderPresets( (string) get_template() ),
			'theme'          => (string) get_template(),
			'prefetch_probe' => PrefetchProbe::run(),
			'permalink'      => '' !== (string) get_option( 'permalink_structure' ),
			'hub_rum'        => '' !== (string) $settings->get( 'hub_url', '' ),
			'report'         => $this->container->get( RumService::class )->report( 7 ),
			'insights'       => RumService::stored(),
			'budgets'        => [
				'values'   => Budgets::normalize( (array) $settings->get( 'budgets', [] ) ),
				'defaults' => Budgets::DEFAULTS,
				'state'    => Budgets::stored(),
			],
			'no_store'       => $this->noStore(),
			'elementor'      => defined( 'ELEMENTOR_VERSION' ),
			'lazy_detected'  => is_array( get_transient( self::LAZY_TRANSIENT ) ) ? get_transient( self::LAZY_TRANSIENT ) : null,
		];
	}

	/**
	 * Muestra de una página anónima con estado 200 que salió con no-store.
	 *
	 * @param array<string, mixed> $row Muestra.
	 */
	public static function ownNoStore( array $row ): bool {
		return 1 === (int) ( $row['anon'] ?? 0 ) && 200 === (int) ( $row['status'] ?? 0 ) && str_contains( (string) ( $row['cache_control'] ?? '' ), 'no-store' );
	}

	/**
	 * Páginas del frontend que salieron con Cache-Control: no-store para visitantes anónimos (lo propio
	 * que impide el bfcache en Firefox; Chrome sí las guarda y las descarta si cambian las cookies).
	 * Desde 0.13.1 no cuenta las visitas con sesión (WordPress les envía no-store desde la 6.3) ni los
	 * 404 (desde la 6.8): eso es del core y es correcto.
	 *
	 * @return list<array{route: string, n: int}>
	 */
	private function noStore(): array {
		$out = [];
		foreach ( $this->container->get( SampleRepository::class )->recent( 48, 'FrontendHtml' ) as $row ) {
			if ( self::ownNoStore( $row ) ) {
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
		// Lo diferido no está en el DOM para scripts, la búsqueda del navegador ni Google: moderado.
		if ( true === $request->get_param( 'lazy_elements' ) && ! (bool) $settings->get( 'lazy_elements', false ) && true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma los elementos diferidos: hasta que se muestran no están en el DOM para otros scripts, la búsqueda del navegador ni Google.', 'imagina-turbo' ), 428 );
		}
		$changes = [];
		foreach ( array_merge( [ 'spec_mode', 'spec_eagerness', 'rum_rate', 'lazy_render', 'fonts_manual', 'lazy_documents' ], self::BOOLS ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$changes[ $key ] = $request->get_param( $key );
			}
		}
		$wasLazy = (bool) $settings->get( 'lazy_elements', false );
		$settings->update( $changes );
		$this->container->get( ConfigCompiler::class )->compile();
		// La caché de elementos de Elementor guarda el HTML de cada documento: al activar los elementos
		// diferidos se vacía para que los marcadores aparezcan ya (al apagarlos quedan como comentarios).
		if ( isset( $changes['lazy_elements'] ) && (bool) $changes['lazy_elements'] !== $wasLazy && defined( 'ELEMENTOR_VERSION' ) ) {
			delete_post_meta_by_key( '_elementor_element_cache' );
		}
		// Los menús guardados en la caché de fragmentos llevan el envoltorio (o no): copias nuevas.
		if ( isset( $changes['lazy_elements'] ) || isset( $changes['lazy_documents'] ) ) {
			FragmentCache::flush();
		}
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
