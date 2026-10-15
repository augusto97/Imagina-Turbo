<?php
/**
 * Informes del perfilador a partir de las muestras crudas (últimas 24/48 h).
 *
 * Funciones puras sobre filas de route_samples: el controlador REST solo las consulta y las pasa.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Profiler;

use ImaginaTurbo\Modules\Firewall\OriginLock;

/**
 * Resumen, ranking por plugin, fases y rutas.
 */
final class Report {

	/**
	 * @param list<array<string, mixed>> $rows Filas de SampleRepository::recent().
	 * @return array<string, mixed>
	 */
	public static function summary( array $rows ): array {
		$n = count( $rows );
		if ( 0 === $n ) {
			return [
				'samples'         => 0,
				'ms_avg'          => 0,
				'ms_p50'          => 0,
				'ms_p95'          => 0,
				'mem_avg_mb'      => 0,
				'queries_avg'     => 0,
				'overhead_us_avg' => 0,
				'cacheable_pct'   => 0,
				'via_cf_pct'      => 0,
				'by_type'         => [],
			];
		}
		$ms     = array_map( static fn( array $r ): int => (int) $r['ms'], $rows );
		$byType = [];
		foreach ( $rows as $row ) {
			$type            = (string) $row['request_type'];
			$byType[ $type ] = ( $byType[ $type ] ?? 0 ) + 1;
		}
		arsort( $byType );

		return [
			'samples'         => $n,
			'ms_avg'          => (int) round( array_sum( $ms ) / $n ),
			'ms_p50'          => Rollup::percentile( $ms, 50 ),
			'ms_p95'          => Rollup::percentile( $ms, 95 ),
			'mem_avg_mb'      => round( array_sum( array_map( static fn( array $r ): int => (int) $r['mem_kb'], $rows ) ) / $n / 1024, 1 ),
			'queries_avg'     => (int) round( array_sum( array_map( static fn( array $r ): int => (int) $r['queries'], $rows ) ) / $n ),
			'overhead_us_avg' => (int) round( array_sum( array_map( static fn( array $r ): int => (int) $r['overhead_us'], $rows ) ) / $n ),
			'cacheable_pct'   => round( 100 * count( array_filter( $rows, static fn( array $r ): bool => 1 === (int) $r['cacheable'] ) ) / $n, 1 ),
			'via_cf_pct'      => round( 100 * count( array_filter( $rows, static fn( array $r ): bool => OriginLock::viaCloudflare( (int) $r['via_cf'] ) ) ) / $n, 1 ),
			'by_type'         => $byType,
		];
	}

	/**
	 * Ranking de componentes: costo medio por request (los requests donde no aparece cuentan como 0).
	 *
	 * @param list<array<string, mixed>> $rows Filas.
	 * @return list<array{component: string, load_ms: float, hook_ms: float, total_ms: float, kb: float, share_pct: float, seen_pct: float}>
	 */
	public static function plugins( array $rows ): array {
		$n = count( $rows );
		if ( 0 === $n ) {
			return [];
		}
		$acc     = [];
		$totalMs = 0;
		foreach ( $rows as $row ) {
			$totalMs += (int) $row['ms'];
			$costs    = json_decode( (string) ( $row['plugins_cost'] ?? '' ), true );
			foreach ( is_array( $costs ) ? $costs : [] as $cost ) {
				if ( ! is_array( $cost ) || ! is_string( $cost['c'] ?? null ) ) {
					continue;
				}
				$c                  = $cost['c'];
				$acc[ $c ]        ??= [
					'load' => 0.0,
					'hook' => 0.0,
					'kb'   => 0.0,
					'seen' => 0,
				];
				$acc[ $c ]['load'] += (float) ( $cost['l'] ?? 0 );
				$acc[ $c ]['hook'] += (float) ( $cost['h'] ?? 0 );
				$acc[ $c ]['kb']   += (float) ( $cost['k'] ?? 0 );
				++$acc[ $c ]['seen'];
			}
		}
		$avgRequest = $totalMs / $n;
		$out        = [];
		foreach ( $acc as $component => $sum ) {
			$total = ( $sum['load'] + $sum['hook'] ) / $n;
			$out[] = [
				'component' => (string) $component,
				'load_ms'   => round( $sum['load'] / $n, 2 ),
				'hook_ms'   => round( $sum['hook'] / $n, 2 ),
				'total_ms'  => round( $total, 2 ),
				'kb'        => round( $sum['kb'] / $n, 1 ),
				'share_pct' => $avgRequest > 0 ? round( 100 * $total / $avgRequest, 1 ) : 0.0,
				'seen_pct'  => round( 100 * $sum['seen'] / $n, 1 ),
			];
		}
		usort( $out, static fn( array $a, array $b ): int => $b['total_ms'] <=> $a['total_ms'] );
		return $out;
	}

	/** Orden real de las fases en un request (las de admin y frontend se intercalan aquí). */
	private const PHASE_ORDER = [ 'loader', 'muplugins_loaded', 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'admin_init', 'template_redirect', 'shutdown' ];

	/**
	 * Duración media de cada fase (sobre las muestras que la tienen), en el orden del request.
	 *
	 * @param list<array<string, mixed>> $rows Filas.
	 * @return list<array{phase: string, ms: float, seen_pct: float}>
	 */
	public static function phases( array $rows ): array {
		$n     = count( $rows );
		$sum   = [];
		$count = [];
		foreach ( $rows as $row ) {
			$phases = json_decode( (string) ( $row['phases'] ?? '' ), true );
			foreach ( is_array( $phases ) ? $phases : [] as $phase => $ms ) {
				if ( ! is_string( $phase ) || ! is_numeric( $ms ) ) {
					continue;
				}
				$sum[ $phase ]   = ( $sum[ $phase ] ?? 0.0 ) + (float) $ms;
				$count[ $phase ] = ( $count[ $phase ] ?? 0 ) + 1;
			}
		}
		$order = array_flip( self::PHASE_ORDER );
		$names = array_keys( $sum );
		usort( $names, static fn( string $a, string $b ): int => ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 ) );
		$out = [];
		foreach ( $names as $phase ) {
			$out[] = [
				'phase'    => $phase,
				'ms'       => round( $sum[ $phase ] / $count[ $phase ], 2 ),
				'seen_pct' => $n > 0 ? round( 100 * $count[ $phase ] / $n, 1 ) : 0.0,
			];
		}
		return $out;
	}

	/**
	 * Rutas más pesadas a partir de muestras crudas (para periodos de horas).
	 *
	 * @param list<array<string, mixed>> $rows Filas.
	 * @return list<array{request_type: string, route_key: string, n: int, ms_p50: int, ms_p95: int, mem_p95: int, queries_avg: int}>
	 */
	public static function routes( array $rows, int $limit = 50 ): array {
		$samples = array_map(
			static fn( array $r ): array => [
				'request_type' => (string) $r['request_type'],
				'route_key'    => (string) $r['route_key'],
				'ms'           => (int) $r['ms'],
				'mem_kb'       => (int) $r['mem_kb'],
				'queries'      => (int) $r['queries'],
			],
			$rows
		);
		$out     = array_map(
			static fn( array $r ): array => [
				'request_type' => $r['request_type'],
				'route_key'    => $r['route_key'],
				'n'            => $r['n'],
				'ms_p50'       => $r['ms_p50'],
				'ms_p95'       => $r['ms_p95'],
				'mem_p95'      => $r['mem_p95'],
				'queries_avg'  => $r['queries_avg'],
			],
			Rollup::aggregate( $samples )
		);
		usort( $out, static fn( array $a, array $b ): int => ( $b['ms_p50'] * $b['n'] ) <=> ( $a['ms_p50'] * $a['n'] ) );
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Auditoría pasiva: cookies enviadas y Cache-Control privado en requests que deberían ser cacheables.
	 *
	 * @param list<array<string, mixed>> $rows Filas.
	 * @return array{cookies: array<string, int>, private_cache: int, eligible: int}
	 */
	public static function passiveAudit( array $rows ): array {
		$cookies  = [];
		$private  = 0;
		$eligible = 0;
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['request_type'], [ 'FrontendHtml', 'Feed' ], true ) || 200 !== (int) $row['status'] ) {
				continue;
			}
			++$eligible;
			foreach ( array_filter( explode( ',', (string) ( $row['set_cookies'] ?? '' ) ) ) as $cookie ) {
				$cookies[ $cookie ] = ( $cookies[ $cookie ] ?? 0 ) + 1;
			}
			if ( 1 === preg_match( '/\b(private|no-store)\b/', (string) ( $row['cache_control'] ?? '' ) ) ) {
				++$private;
			}
		}
		arsort( $cookies );
		return [
			'cookies'       => $cookies,
			'private_cache' => $private,
			'eligible'      => $eligible,
		];
	}

	/** Tipos de request que dispara una página ya cargada (AJAX y APIs). */
	public const DYNAMIC = [ 'AdminAjax', 'WcAjax', 'Rest', 'StoreApi' ];

	/** Muestras mínimas de un endpoint para estimar su frecuencia. */
	public const PER_VISIT_MIN_SAMPLES = 3;

	/** Desde esta proporción (peticiones por página vista) se considera "en cada visita". */
	public const PER_VISIT_SHARE = 0.3;

	/**
	 * Peticiones que hacen los visitantes después de cargar la página (AJAX, REST, Store API), con su
	 * frecuencia y su costo estimados a partir del muestreo aleatorio. Una página en caché no llega a PHP,
	 * pero estas peticiones sí: son las que mantienen ocupado el servidor aunque todo esté en caché (el
	 * menú por AJAX de WoodMart costaba 0,75–1,6 s en cada visita).
	 *
	 * @param list<array<string, mixed>>               $rows        Muestras (recent()).
	 * @param float                                    $rate        Tasa de muestreo aleatorio.
	 * @param int                                      $hours       Horas que cubren las muestras.
	 * @param float|null                               $viewsPerDay Páginas vistas por día (RUM), o null.
	 * @param array<string, string>                    $rules       Clave "Tipo:endpoint" => estado de su regla de carga condicional.
	 * @return list<array{request_type: string, route_key: string, samples: int, per_day: int, ms_avg: int, cpu_s_day: int, per_view: float|null, per_visit: bool, rule: string, hint: string}>
	 */
	public static function perVisit( array $rows, float $rate, int $hours, ?float $viewsPerDay, array $rules = [] ): array {
		if ( $rate <= 0 || $hours <= 0 ) {
			return [];
		}
		$groups = [];
		foreach ( $rows as $row ) {
			if ( Collector::SOURCE_RANDOM !== (int) $row['source'] || 1 !== (int) ( $row['anon'] ?? 0 ) || ! in_array( $row['request_type'], self::DYNAMIC, true ) ) {
				continue;
			}
			$key                     = $row['request_type'] . ':' . $row['route_key'];
			$groups[ $key ]['type']  = (string) $row['request_type'];
			$groups[ $key ]['route'] = (string) $row['route_key'];
			$groups[ $key ]['ms'][]  = (int) $row['ms'];
		}
		$out = [];
		foreach ( $groups as $key => $g ) {
			$n = count( $g['ms'] );
			if ( $n < self::PER_VISIT_MIN_SAMPLES ) {
				continue;
			}
			$perDay  = $n / $rate * 24 / $hours;
			$msAvg   = array_sum( $g['ms'] ) / $n;
			$perView = null !== $viewsPerDay && $viewsPerDay > 0 ? round( $perDay / $viewsPerDay, 2 ) : null;
			$out[]   = [
				'request_type' => $g['type'],
				'route_key'    => $g['route'],
				'samples'      => $n,
				'per_day'      => (int) round( $perDay ),
				'ms_avg'       => (int) round( $msAvg ),
				'cpu_s_day'    => (int) round( $perDay * $msAvg / 1000 ),
				'per_view'     => $perView,
				'per_visit'    => null !== $perView && $perView >= self::PER_VISIT_SHARE,
				'rule'         => $rules[ (string) $key ] ?? '',
				'hint'         => self::perVisitHint( $g['type'], $g['route'] ),
			];
		}
		usort( $out, static fn( array $a, array $b ): int => $b['cpu_s_day'] <=> $a['cpu_s_day'] );
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Qué hacer con un endpoint conocido; para el resto, el camino general.
	 */
	public static function perVisitHint( string $type, string $route ): string {
		if ( 'WcAjax' === $type && 'get_refreshed_fragments' === $route ) {
			return __( 'Fragmentos del carrito de WooCommerce: activa el módulo «Carrito por Store API» de Imagina Turbo, que pinta el contador solo cuando hay carrito.', 'imagina-turbo' );
		}
		if ( 'AdminAjax' === $type && str_starts_with( $route, 'woodmart_load_html_dropdowns' ) ) {
			return __( 'Menús desplegables de WoodMart cargados por AJAX. El navegador los guarda tras la primera visita; si sigue pesando, crea una regla de carga condicional para esta acción (cargar solo los plugins que necesita).', 'imagina-turbo' );
		}
		if ( 'StoreApi' === $type ) {
			return __( 'Store API de WooCommerce: es normal con carrito (contador, bloques del carrito). Si aparece en visitas sin carrito, revisa qué script la llama.', 'imagina-turbo' );
		}
		if ( 'AdminAjax' === $type && 'heartbeat' === $route ) {
			return __( 'Heartbeat de WordPress en el frontend: algún plugin lo carga para visitantes sin sesión. Busca cuál encola el script «heartbeat» y desactívalo ahí.', 'imagina-turbo' );
		}
		return __( 'Busca qué script de la página la pide (pestaña Red del navegador) y si el plugin tiene un ajuste para no hacerlo en cada visita. Si es necesaria, una regla de carga condicional reduce lo que carga cada petición.', 'imagina-turbo' );
	}

	/** Desde aquí una petición retiene un trabajador lo bastante como para hacer cola con poco tráfico. */
	public const SLOW_MS = 5000;

	/**
	 * Trabajadores PHP ocupados (ley de Little: tiempo de PHP por segundo de reloj), estimados con el
	 * muestreo aleatorio. Cuando la media o el pico se acercan al número de procesos PHP del servidor,
	 * las peticiones hacen cola y aparecen los 502/503 aunque cada una sea "rápida".
	 *
	 * @param list<array<string, mixed>> $rows  Muestras (recent()).
	 * @param float                      $rate  Tasa de muestreo aleatorio.
	 * @param int                        $hours Horas que cubren las muestras.
	 * @return array{avg: float, peak: float, peak_hour: string, top: list<array{request_type: string, route_key: string, share_pct: float, ms_p95: int, slow: int}>}
	 */
	public static function workers( array $rows, float $rate, int $hours ): array {
		$empty = [
			'avg'       => 0.0,
			'peak'      => 0.0,
			'peak_hour' => '',
			'top'       => [],
		];
		if ( $rate <= 0 || $hours <= 0 ) {
			return $empty;
		}
		$total  = 0;
		$byHour = [];
		$routes = [];
		foreach ( $rows as $row ) {
			if ( Collector::SOURCE_RANDOM !== (int) $row['source'] ) {
				continue;
			}
			$ms               = (int) $row['ms'];
			$total           += $ms;
			$hour             = substr( (string) $row['created_at'], 0, 13 );
			$byHour[ $hour ]  = ( $byHour[ $hour ] ?? 0 ) + $ms;
			$key              = $row['request_type'] . ':' . $row['route_key'];
			$routes[ $key ][] = $ms;
		}
		if ( 0 === $total ) {
			return $empty;
		}
		arsort( $byHour );
		$peakHour = (string) array_key_first( $byHour );
		$top      = [];
		foreach ( $routes as $key => $values ) {
			[ $type, $route ] = explode( ':', (string) $key, 2 );
			$top[]            = [
				'request_type' => $type,
				'route_key'    => $route,
				'share_pct'    => round( 100 * array_sum( $values ) / $total, 1 ),
				'ms_p95'       => Rollup::percentile( $values, 95 ),
				'slow'         => count( array_filter( $values, static fn( int $ms ): bool => $ms >= self::SLOW_MS ) ),
			];
		}
		usort( $top, static fn( array $a, array $b ): int => $b['share_pct'] <=> $a['share_pct'] );
		return [
			'avg'       => round( $total / $rate / ( $hours * 3600000 ), 2 ),
			'peak'      => round( $byHour[ $peakHour ] / $rate / 3600000, 2 ),
			'peak_hour' => $peakHour,
			'top'       => array_slice( $top, 0, 8 ),
		];
	}
}
