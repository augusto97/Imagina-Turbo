<?php
/**
 * Perfilador y auditoría: GET /profiler/summary, /profiler/plugins, /profiler/routes;
 * PUT /profiler/settings; POST /profiler/intensive; GET y POST /diagnostics/audit.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\PlatformAudit;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Modules\Profiler\CacheabilityAudit;
use ImaginaTurbo\Modules\Profiler\QueryReport;
use ImaginaTurbo\Modules\Profiler\Report;
use ImaginaTurbo\Modules\Profiler\Rollup;
use ImaginaTurbo\Modules\PluginFilter\RuleStore;
use ImaginaTurbo\Repository\MetricsRepository;
use ImaginaTurbo\Repository\RumRepository;
use ImaginaTurbo\Repository\SampleRepository;

/**
 * Lecturas del perfilador (todas sobre tablas propias, solo en el admin) y sus controles.
 */
final class ProfilerController extends Controller {

	/** Periodos: los de horas salen de las muestras crudas; los de días, de los agregados horarios. */
	private const PERIODS = [ '1h', '24h', '48h', '7d', '30d' ];

	public function registerRoutes(): void {
		$filters = [
			'period' => [
				'type'    => 'string',
				'enum'    => self::PERIODS,
				'default' => '24h',
			],
			'type'   => [
				'type'    => 'string',
				'enum'    => array_merge( [ '' ], array_map( static fn( RequestType $t ): string => $t->value, RequestType::cases() ) ),
				'default' => '',
			],
		];
		$this->route( '/profiler/summary', \WP_REST_Server::READABLE, [ $this, 'summary' ], $filters );
		$this->route( '/profiler/plugins', \WP_REST_Server::READABLE, [ $this, 'plugins' ], $filters );
		$this->route( '/profiler/routes', \WP_REST_Server::READABLE, [ $this, 'routes' ], $filters );
		$this->route( '/profiler/per-visit', \WP_REST_Server::READABLE, [ $this, 'perVisit' ] );
		$this->route(
			'/profiler/workers',
			\WP_REST_Server::READABLE,
			fn(): \WP_REST_Response => $this->ok(
				[
					'hours'    => 48,
					'rate'     => (float) $this->sampling()['rate'],
					'children' => self::phpChildren(),
				] + Report::workers( $this->container->get( SampleRepository::class )->recent( 48 ), (float) $this->sampling()['rate'], 48 )
			)
		);
		$this->route(
			'/profiler/settings',
			'PUT',
			[ $this, 'updateSettings' ],
			[
				'sample_rate' => [
					'type'     => 'number',
					'minimum'  => 0,
					'maximum'  => 1,
					'required' => true,
				],
			]
		);
		$this->route(
			'/profiler/intensive',
			\WP_REST_Server::CREATABLE,
			[ $this, 'intensive' ],
			[
				'enabled' => [
					'type'     => 'boolean',
					'required' => true,
				],
			]
		);
		$this->route( '/diagnostics/audit', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->container->get( CacheabilityAudit::class )->last() ) );
		$this->route( '/diagnostics/audit', \WP_REST_Server::CREATABLE, [ $this, 'runAudit' ] );
		$this->route( '/diagnostics/platform', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( PlatformAudit::current() ) );
	}

	/**
	 * Resumen, fases, auditoría pasiva y estado del muestreo. Los periodos en días usan las muestras de 48 h
	 * para lo que no existe agregado (fases, auditoría pasiva).
	 */
	public function summary( \WP_REST_Request $request ): \WP_REST_Response {
		$hours = self::sampleHours( (string) $request->get_param( 'period' ) );
		$rows  = $this->container->get( SampleRepository::class )->recent( $hours, (string) $request->get_param( 'type' ) );

		$latest = $this->container->get( SampleRepository::class )->latestDiagnostic();

		return $this->ok(
			[
				'period'       => (string) $request->get_param( 'period' ),
				'sample_hours' => $hours,
				'sampling'     => $this->sampling(),
				'sources'      => $this->container->get( SampleRepository::class )->countBySource( $hours ),
				'summary'      => Report::summary( $rows ),
				'phases'       => Report::phases( $rows ),
				'passive'      => Report::passiveAudit( $rows ),
				'latest_diag'  => null === $latest ? null : [
					'created_at'   => (string) $latest['created_at'],
					'request_type' => (string) $latest['request_type'],
					'route_key'    => (string) $latest['route_key'],
					'ms'           => (int) $latest['ms'],
					'mem_kb'       => (int) $latest['mem_kb'],
					'queries'      => (int) $latest['queries'],
					'overhead_us'  => (int) $latest['overhead_us'],
					'phases'       => json_decode( (string) $latest['phases'], true ),
					'plugins'      => Report::plugins( [ $latest ] ),
					'db_queries'   => get_option( QueryReport::OPTION, null ),
				],
				'rollup'       => [
					'last_hour' => self::lastRollup(),
				],
			]
		);
	}

	public function plugins( \WP_REST_Request $request ): \WP_REST_Response {
		$hours = self::sampleHours( (string) $request->get_param( 'period' ) );
		$rows  = $this->container->get( SampleRepository::class )->recent( $hours, (string) $request->get_param( 'type' ) );
		return $this->ok(
			[
				'sample_hours' => $hours,
				'samples'      => count( $rows ),
				'items'        => Report::plugins( $rows ),
			]
		);
	}

	/**
	 * Peticiones dinámicas de los visitantes (48 h de muestras) con su frecuencia y costo por día.
	 */
	public function perVisit(): \WP_REST_Response {
		$hours   = 48;
		$rate    = (float) $this->sampling()['rate'];
		$rows    = $this->container->get( SampleRepository::class )->recent( $hours );
		$rumRate = (float) $this->container->get( Settings::class )->get( 'rum_rate', 0.05 );
		$views   = null;
		$beacons = 0;
		if ( $rumRate > 0 ) {
			foreach ( $this->container->get( RumRepository::class )->histogram( 2 ) as $row ) {
				// Navegaciones hacia adelante (normal, prerender, prefetch): una por página vista.
				if ( 'nav' === $row['metric'] && in_array( (int) $row['bucket'], [ 0, 4, 5 ], true ) ) {
					$beacons += (int) $row['n'];
				}
			}
			$views = $beacons >= 20 ? $beacons / $rumRate / 2 : null;
		}
		$rules = [];
		foreach ( ( new RuleStore() )->all() as $rule ) {
			$rules[ $rule['key'] ] = $rule['status'];
		}
		return $this->ok(
			[
				'hours'         => $hours,
				'rate'          => $rate,
				'views_per_day' => null === $views ? null : (int) round( $views ),
				'rum_beacons'   => $beacons,
				'items'         => Report::perVisit( $rows, $rate, $hours, $views, $rules ),
			]
		);
	}

	public function routes( \WP_REST_Request $request ): \WP_REST_Response {
		$period = (string) $request->get_param( 'period' );
		$type   = (string) $request->get_param( 'type' );
		if ( str_ends_with( $period, 'd' ) ) {
			$items  = $this->container->get( MetricsRepository::class )->routes( (int) $period, $type );
			$source = 'hourly';
		} else {
			$items  = Report::routes( $this->container->get( SampleRepository::class )->recent( self::sampleHours( $period ), $type ) );
			$source = 'samples';
		}
		return $this->ok(
			[
				'source' => $source,
				'items'  => $items,
			]
		);
	}

	public function updateSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$rate = (float) $request->get_param( 'sample_rate' );
		$this->container->get( Settings::class )->update( [ 'profiler_sample_rate' => $rate ] );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info(
			'profiler',
			'settings',
			/* translators: %s: porcentaje */
			sprintf( __( 'Muestreo del perfilador: %s %%.', 'imagina-turbo' ), self::percent( $rate ) ),
			[ 'user' => get_current_user_id() ]
		);
		return $this->ok( $this->sampling() );
	}

	/**
	 * Muestreo intensivo: todos los requests durante 15 minutos (para sitios con poco tráfico o antes de comparar).
	 */
	public function intensive( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$enabled = (bool) $request->get_param( 'enabled' );
		if ( $enabled && ! $this->profilerEnabled() ) {
			return $this->error( 'itb_profiler_off', __( 'El perfilador está desactivado: actívalo en Módulos primero.', 'imagina-turbo' ), 409 );
		}
		$until = $enabled ? time() + Settings::INTENSIVE_SECONDS : 0;
		$this->container->get( Settings::class )->update( [ 'profiler_intensive_until' => $until ] );
		$result = $this->container->get( ConfigCompiler::class )->compile();
		if ( ! $result['ok'] ) {
			return $this->error( 'itb_compile', __( 'No se pudo recompilar la configuración.', 'imagina-turbo' ), 500 );
		}
		$this->container->get( EventLog::class )->info(
			'profiler',
			'intensive',
			$enabled ? __( 'Muestreo intensivo activado durante 15 minutos.', 'imagina-turbo' ) : __( 'Muestreo intensivo detenido.', 'imagina-turbo' ),
			[ 'user' => get_current_user_id() ]
		);
		return $this->ok( $this->sampling() );
	}

	public function runAudit(): \WP_REST_Response {
		return $this->ok( $this->container->get( CacheabilityAudit::class )->run() );
	}

	/**
	 * @return array{enabled: bool, rate: float, intensive_until: int, intensive_seconds: int}
	 */
	private function sampling(): array {
		$settings = $this->container->get( Settings::class );
		$rate     = $settings->get( 'profiler_sample_rate', Settings::DEFAULT_SAMPLE_RATE );
		$until    = $settings->get( 'profiler_intensive_until', 0 );
		$until    = is_int( $until ) && $until > time() ? $until : 0;
		return [
			'enabled'           => $this->profilerEnabled(),
			'rate'              => is_float( $rate ) || is_int( $rate ) ? (float) $rate : Settings::DEFAULT_SAMPLE_RATE,
			'intensive_until'   => $until,
			'intensive_seconds' => Settings::INTENSIVE_SECONDS,
		];
	}

	private function profilerEnabled(): bool {
		$modules = $this->container->get( Diagnostics::class )->modules();
		foreach ( $modules as $module ) {
			if ( is_array( $module ) && 'profiler' === ( $module['id'] ?? null ) ) {
				return true === ( $module['enabled'] ?? false );
			}
		}
		return false;
	}

	/**
	 * Horas de muestras crudas para un periodo (las muestras viven 48 h).
	 */
	/**
	 * Procesos PHP del servidor si se pueden saber: LSAPI_CHILDREN (LiteSpeed) o pm.max_children de
	 * PHP-FPM no están al alcance de PHP en general; LiteSpeed sí expone su variable de entorno.
	 */
	private static function phpChildren(): ?int {
		$value = getenv( 'LSAPI_CHILDREN' );
		return is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 ? (int) $value : null;
	}

	private static function sampleHours( string $period ): int {
		return match ( $period ) {
			'1h'    => 1,
			'24h'   => 24,
			default => SampleRepository::RETENTION_HOURS,
		};
	}

	private static function lastRollup(): ?string {
		$last = get_option( Rollup::STATE_OPTION, 0 );
		return is_numeric( $last ) && (int) $last > 0 ? gmdate( 'c', (int) $last ) : null;
	}

	private static function percent( float $rate ): string {
		return rtrim( rtrim( number_format( $rate * 100, 2, ',', '' ), '0' ), ',' );
	}
}
