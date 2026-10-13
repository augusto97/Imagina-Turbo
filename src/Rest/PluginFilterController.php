<?php
/**
 * Carga condicional: GET /plugin-filter/status, POST /plugin-filter/rules,
 * POST /plugin-filter/rules/{id}/step|recheck|disable, DELETE /plugin-filter/rules/{id}.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\EarlyLoader;
use ImaginaTurbo\Core\Environment\Detector;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Integrations\ImaginaSearch;
use ImaginaTurbo\Modules\PluginFilter\CandidateFinder;
use ImaginaTurbo\Modules\PluginFilter\ExperimentRunner;
use ImaginaTurbo\Modules\PluginFilter\RulePolicy;
use ImaginaTurbo\Modules\PluginFilter\RuleStore;
use ImaginaTurbo\Repository\SampleRepository;

/**
 * Reglas, candidatos y experimentos.
 *
 * @phpstan-import-type FilterRule from RuleStore
 */
final class PluginFilterController extends Controller {

	private const ID = '(?P<id>[a-f0-9]{12})';

	public function registerRoutes(): void {
		$this->route( '/plugin-filter/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$this->route(
			'/plugin-filter/rules',
			\WP_REST_Server::CREATABLE,
			[ $this, 'create' ],
			[
				'key'         => [
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 120,
				],
				'plugins'     => [
					'type'     => 'array',
					'items'    => [
						'type'      => 'string',
						'maxLength' => 200,
					],
					'required' => true,
					'maxItems' => 100,
				],
				'all_methods' => [ 'type' => 'boolean' ],
				'probes'      => [
					'type'     => 'array',
					'maxItems' => 5,
				],
			]
		);
		$this->route( '/plugin-filter/rules/' . self::ID . '/step', \WP_REST_Server::CREATABLE, [ $this, 'step' ] );
		$this->route( '/plugin-filter/rules/' . self::ID . '/recheck', \WP_REST_Server::CREATABLE, [ $this, 'recheck' ] );
		$this->route( '/plugin-filter/rules/' . self::ID . '/disable', \WP_REST_Server::CREATABLE, [ $this, 'disable' ] );
		$this->route( '/plugin-filter/rules/' . self::ID, \WP_REST_Server::DELETABLE, [ $this, 'delete' ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$active  = $this->container->get( Detector::class )->activePlugins();
		$enabled = false;
		$forced  = '';
		foreach ( $this->container->get( Diagnostics::class )->modules() as $module ) {
			if ( 'plugin_filter' === ( $module['id'] ?? null ) ) {
				$enabled = true === ( $module['enabled'] ?? false );
				$reasons = is_array( $module['forcedOff'] ?? null ) ? array_filter( $module['forcedOff'], 'is_string' ) : [];
				$forced  = implode( ', ', $reasons );
			}
		}
		$rules = array_values( $this->store()->all() );
		$self  = plugin_basename( Runtime::$pluginDir . '/imagina-turbo.php' );
		$names = self::pluginNames();
		return [
			'enabled'        => $enabled,
			'forced_off'     => $forced,
			'plugins'        => array_map(
				static fn( string $file ): array => [
					'file' => $file,
					'name' => $names[ $file ] ?? $file,
				],
				$active
			),
			'self'           => $self,
			'rules'          => array_map( fn( array $rule ): array => $this->present( $rule, $active, $self ), $rules ),
			'candidates'     => CandidateFinder::find( $this->container->get( SampleRepository::class )->recent( 48 ), $active, $self, array_column( $rules, 'key' ) ),
			'imagina_search' => [
				'active' => null !== ImaginaSearch::plugin( $active ),
				'key'    => 'Rest:' . ImaginaSearch::REST_NAMESPACE,
			],
			'faults_pending' => is_file( Runtime::$dataDir . '/' . EarlyLoader::FILTER_FAULTS ),
			'required_pairs' => RulePolicy::REQUIRED_PAIRS,
		];
	}

	/**
	 * Regla con lo que la pantalla necesita.
	 *
	 * @param FilterRule   $rule   Regla.
	 * @param list<string> $active Plugins activos.
	 * @return array<string, mixed>
	 */
	private function present( array $rule, array $active, string $self ): array {
		return $rule + [
			'label'     => CandidateFinder::label( $rule['key'] ),
			'inactive'  => array_values( array_diff( $rule['plugins'], $active ) ),
			'protected' => RulePolicy::protectedPlugins( $rule['key'], $active, $self ),
		];
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( null === Runtime::$config || ! Runtime::$config->moduleEnabled( 'plugin_filter' ) ) {
			return $this->error( 'itb_filter_disabled', __( 'Activa la carga condicional para probar reglas.', 'imagina-turbo' ), 409 );
		}
		$key     = (string) $request->get_param( 'key' );
		$blocked = RulePolicy::blockedReason( $key );
		if ( '' !== $blocked ) {
			return $this->error( 'itb_filter_key', $blocked, 400 );
		}
		$active    = $this->container->get( Detector::class )->activePlugins();
		$self      = plugin_basename( Runtime::$pluginDir . '/imagina-turbo.php' );
		$protected = RulePolicy::protectedPlugins( $key, $active, $self );
		$plugins   = array_values( array_unique( array_filter( (array) $request->get_param( 'plugins' ), 'is_string' ) ) );
		$invalid   = array_values( array_merge( array_diff( $plugins, $active ), array_intersect( $plugins, $protected ) ) );
		if ( [] === $plugins || [] !== $invalid ) {
			return $this->error( 'itb_filter_plugins', [] === $plugins ? __( 'Elige al menos un plugin.', 'imagina-turbo' ) : __( 'Estos plugins no están activos o no se pueden quitar en este endpoint: ', 'imagina-turbo' ) . implode( ', ', $invalid ), 400 );
		}
		$probes = RulePolicy::sanitizeProbes( $request->get_param( 'probes' ) );
		if ( [] === $probes ) {
			$probes = RulePolicy::defaultProbes( $key, (string) Runtime::$config->get( 'rest_prefix', 'wp-json' ) );
		}
		$allMethods = $request->get_param( 'all_methods' );
		$rule       = RuleStore::normalize(
			[
				'key'         => $key,
				'plugins'     => $plugins,
				'all_methods' => is_bool( $allMethods ) ? $allMethods : RulePolicy::defaultAllMethods( $key ),
				'probes'      => $probes,
				'status'      => 'testing',
				'created_at'  => time(),
			]
		);
		if ( null === $rule ) {
			return $this->error( 'itb_filter_key', __( 'Regla no válida.', 'imagina-turbo' ), 400 );
		}
		$this->store()->save( $rule );
		$compiled = $this->container->get( ConfigCompiler::class )->compile();
		if ( ! $compiled['ok'] ) {
			return $this->error( 'itb_compile', implode( ' ', $compiled['errors'] ), 500 );
		}
		// El experimento se ejecuta en otros requests: tienen que leer la config nueva.
		Runtime::$config = CompiledConfig::load( $compiled['file'] ) ?? Runtime::$config;
		$this->container->get( EventLog::class )->info(
			'plugin_filter',
			'experiment',
			__( 'Experimento de carga condicional creado.', 'imagina-turbo' ),
			[
				'user'     => get_current_user_id(),
				'endpoint' => $key,
				'plugins'  => $plugins,
			]
		);
		return $this->ok( $this->present( $rule, $active, $self ) );
	}

	public function step( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( (string) $request->get_param( 'id' ), false );
	}

	public function recheck( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run( (string) $request->get_param( 'id' ), true );
	}

	private function run( string $id, bool $recheck ): \WP_REST_Response|\WP_Error {
		if ( null === Runtime::$config || ! Runtime::$config->moduleEnabled( 'plugin_filter' ) ) {
			return $this->error( 'itb_filter_disabled', __( 'Activa la carga condicional para probar reglas.', 'imagina-turbo' ), 409 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- el paso hace hasta 40 peticiones loopback.
		}
		$runner = $this->container->get( ExperimentRunner::class );
		$rule   = $recheck ? $runner->recheck( $id ) : $runner->step( $id );
		if ( null === $rule ) {
			return $this->error( 'itb_filter_rule', __( 'La regla no existe.', 'imagina-turbo' ), 404 );
		}
		$active = $this->container->get( Detector::class )->activePlugins();
		return $this->ok( $this->present( $rule, $active, plugin_basename( Runtime::$pluginDir . '/imagina-turbo.php' ) ) );
	}

	public function disable( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$rule = $this->store()->get( (string) $request->get_param( 'id' ) );
		if ( null === $rule ) {
			return $this->error( 'itb_filter_rule', __( 'La regla no existe.', 'imagina-turbo' ), 404 );
		}
		$rule['status'] = 'disabled';
		$rule['reason'] = __( 'Desactivada a mano.', 'imagina-turbo' );
		$this->store()->save( $rule );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info(
			'plugin_filter',
			'disabled',
			__( 'Regla de carga condicional desactivada.', 'imagina-turbo' ),
			[
				'user'     => get_current_user_id(),
				'endpoint' => $rule['key'],
			]
		);
		return $this->ok( $this->status() );
	}

	public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$rule = $this->store()->get( (string) $request->get_param( 'id' ) );
		if ( null === $rule ) {
			return $this->error( 'itb_filter_rule', __( 'La regla no existe.', 'imagina-turbo' ), 404 );
		}
		$this->store()->delete( $rule['id'] );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info(
			'plugin_filter',
			'deleted',
			__( 'Regla de carga condicional eliminada.', 'imagina-turbo' ),
			[
				'user'     => get_current_user_id(),
				'endpoint' => $rule['key'],
			]
		);
		return $this->ok( $this->status() );
	}

	/**
	 * Nombre visible de cada plugin.
	 *
	 * @return array<string, string>
	 */
	private static function pluginNames(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = [];
		foreach ( get_plugins() as $file => $data ) {
			$out[ (string) $file ] = is_string( $data['Name'] ?? null ) && '' !== $data['Name'] ? $data['Name'] : (string) $file;
		}
		return $out;
	}

	private function store(): RuleStore {
		return $this->container->get( RuleStore::class );
	}
}
