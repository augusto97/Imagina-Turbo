<?php
/**
 * Analíticas del edge por GraphQL (docs/03, sección 7).
 *
 * httpRequestsAdaptiveGroups está disponible en todos los planes (retención ≥ 31 días desde
 * 2026-10). Se consultan las últimas 24 h del host del sitio, solo tráfico de visitantes
 * (requestSource "eyeball": sin purgas ni health checks), agrupado por estado de caché, y las rutas
 * que más llegan al origen. Se guarda en una opción y se renueva cada hora por tarea programada.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Consulta y caché de analíticas.
 */
final class Analytics {

	public const OPTION = 'itb_cf_analytics';

	public const FIREWALL_OPTION = 'itb_cf_firewall';

	public const WINDOW_HOURS = 24;

	/** Estados que se sirvieron desde la caché del edge. */
	public const CACHED = [ 'hit', 'stale', 'updating', 'revalidated' ];

	/** Estados que llegaron al origen. */
	public const ORIGIN = [ 'miss', 'expired', 'bypass', 'dynamic' ];

	public function __construct( private readonly Client $client, private readonly string $zoneId, private readonly string $host ) {
	}

	/**
	 * @return array{available: bool, error: string, fetched_at: int, window_hours: int, requests: int, cached: int, ratio: float|null, by_status: list<array{status: string, requests: int}>, top_uncached: list<array{path: string, requests: int}>}
	 */
	public function refresh(): array {
		$until = gmdate( 'Y-m-d\TH:i:s\Z' );
		$since = gmdate( 'Y-m-d\TH:i:s\Z', time() - self::WINDOW_HOURS * HOUR_IN_SECONDS );
		$vars  = [
			'z' => $this->zoneId,
			's' => $since,
			'u' => $until,
			'h' => $this->host,
		];

		$byStatus = $this->client->post(
			'/graphql',
			[
				'query'     => 'query($z:string!,$s:Time!,$u:Time!,$h:string!){viewer{zones(filter:{zoneTag:$z}){httpRequestsAdaptiveGroups(limit:20,filter:{datetime_geq:$s,datetime_lt:$u,requestSource:"eyeball",clientRequestHTTPHost:$h}){count dimensions{cacheStatus}}}}}',
				'variables' => $vars,
			]
		);
		if ( ! $byStatus->ok() ) {
			return $this->store( self::empty( $byStatus->message() ) );
		}
		$topPaths = $this->client->post(
			'/graphql',
			[
				'query'     => 'query($z:string!,$s:Time!,$u:Time!,$h:string!,$c:[string!]){viewer{zones(filter:{zoneTag:$z}){httpRequestsAdaptiveGroups(limit:15,orderBy:[count_DESC],filter:{datetime_geq:$s,datetime_lt:$u,requestSource:"eyeball",clientRequestHTTPHost:$h,cacheStatus_in:$c}){count dimensions{clientRequestPath}}}}}',
				'variables' => $vars + [ 'c' => self::ORIGIN ],
			]
		);
		return $this->store( self::parse( $byStatus->resultArray(), $topPaths->ok() ? $topPaths->resultArray() : [] ) );
	}

	/**
	 * Eventos del firewall (firewallEventsAdaptive, todos los planes) de las últimas 24 h, agrupados por
	 * regla y acción. Sin datos de visitantes: solo conteos, ruta y país más frecuentes.
	 *
	 * @return array{available: bool, error: string, fetched_at: int, total: int, events: list<array{rule: string, action: string, count: int, top_path: string, top_country: string}>}
	 */
	public function firewall(): array {
		$response = $this->client->post(
			'/graphql',
			[
				'query'     => 'query($z:string!,$s:Time!,$u:Time!){viewer{zones(filter:{zoneTag:$z}){firewallEventsAdaptive(limit:1000,orderBy:[datetime_DESC],filter:{datetime_geq:$s,datetime_lt:$u}){action source ruleId description clientRequestPath clientCountryName}}}}',
				'variables' => [
					'z' => $this->zoneId,
					's' => gmdate( 'Y-m-d\TH:i:s\Z', time() - self::WINDOW_HOURS * HOUR_IN_SECONDS ),
					'u' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				],
			]
		);
		$data     = $response->ok() ? self::parseFirewall( $response->resultArray() ) : [
			'available'  => false,
			'error'      => $response->message(),
			'fetched_at' => time(),
			'total'      => 0,
			'events'     => [],
		];
		update_option( self::FIREWALL_OPTION, $data, false );
		return $data;
	}

	/**
	 * @param array<mixed> $data Respuesta de GraphQL.
	 * @return array{available: bool, error: string, fetched_at: int, total: int, events: list<array{rule: string, action: string, count: int, top_path: string, top_country: string}>}
	 */
	public static function parseFirewall( array $data ): array {
		$zones  = $data['viewer']['zones'] ?? null;
		$list   = is_array( $zones ) && isset( $zones[0]['firewallEventsAdaptive'] ) && is_array( $zones[0]['firewallEventsAdaptive'] ) ? $zones[0]['firewallEventsAdaptive'] : [];
		$groups = [];
		foreach ( $list as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$rule   = is_string( $event['description'] ?? null ) && '' !== $event['description'] ? $event['description'] : ( is_string( $event['source'] ?? null ) ? $event['source'] : '?' );
			$action = is_string( $event['action'] ?? null ) ? $event['action'] : '?';
			$key    = $rule . '|' . $action;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [
					'rule'      => substr( $rule, 0, 120 ),
					'action'    => $action,
					'count'     => 0,
					'paths'     => [],
					'countries' => [],
				];
			}
			++$groups[ $key ]['count'];
			$path                                    = is_string( $event['clientRequestPath'] ?? null ) ? substr( $event['clientRequestPath'], 0, 120 ) : '';
			$country                                 = is_string( $event['clientCountryName'] ?? null ) ? $event['clientCountryName'] : '';
			$groups[ $key ]['paths'][ $path ]        = ( $groups[ $key ]['paths'][ $path ] ?? 0 ) + 1;
			$groups[ $key ]['countries'][ $country ] = ( $groups[ $key ]['countries'][ $country ] ?? 0 ) + 1;
		}
		$events = [];
		foreach ( $groups as $g ) {
			arsort( $g['paths'] );
			arsort( $g['countries'] );
			$events[] = [
				'rule'        => $g['rule'],
				'action'      => $g['action'],
				'count'       => $g['count'],
				'top_path'    => (string) array_key_first( $g['paths'] ),
				'top_country' => (string) array_key_first( $g['countries'] ),
			];
		}
		usort( $events, static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] );
		return [
			'available'  => true,
			'error'      => '',
			'fetched_at' => time(),
			'total'      => count( $list ),
			'events'     => array_slice( $events, 0, 20 ),
		];
	}

	/**
	 * Lo guardado (sin llamar a la API).
	 *
	 * @return array{available: bool, error: string, fetched_at: int, window_hours: int, requests: int, cached: int, ratio: float|null, by_status: list<array{status: string, requests: int}>, top_uncached: list<array{path: string, requests: int}>}
	 */
	public static function stored(): array {
		$data = get_option( self::OPTION, null );
		if ( ! is_array( $data ) || ! is_int( $data['fetched_at'] ?? null ) ) {
			return self::empty( '' );
		}
		/** @var array{available: bool, error: string, fetched_at: int, window_hours: int, requests: int, cached: int, ratio: float|null, by_status: list<array{status: string, requests: int}>, top_uncached: list<array{path: string, requests: int}>} $data */
		return $data;
	}

	/**
	 * @param array<mixed> $statusData Respuesta de la consulta por estado.
	 * @param array<mixed> $pathData   Respuesta de la consulta por ruta.
	 * @return array{available: bool, error: string, fetched_at: int, window_hours: int, requests: int, cached: int, ratio: float|null, by_status: list<array{status: string, requests: int}>, top_uncached: list<array{path: string, requests: int}>}
	 */
	public static function parse( array $statusData, array $pathData ): array {
		$groups   = self::groups( $statusData );
		$byStatus = [];
		$total    = 0;
		$cached   = 0;
		foreach ( $groups as $group ) {
			$count  = is_int( $group['count'] ?? null ) ? $group['count'] : 0;
			$status = is_string( $group['dimensions']['cacheStatus'] ?? null ) ? strtolower( $group['dimensions']['cacheStatus'] ) : 'unknown';
			$total += $count;
			if ( in_array( $status, self::CACHED, true ) ) {
				$cached += $count;
			}
			$byStatus[ $status ] = ( $byStatus[ $status ] ?? 0 ) + $count;
		}
		arsort( $byStatus );
		$paths = [];
		foreach ( self::groups( $pathData ) as $group ) {
			$path = $group['dimensions']['clientRequestPath'] ?? null;
			if ( is_string( $path ) ) {
				$paths[] = [
					'path'     => substr( $path, 0, 200 ),
					'requests' => is_int( $group['count'] ?? null ) ? $group['count'] : 0,
				];
			}
		}
		return [
			'available'    => true,
			'error'        => '',
			'fetched_at'   => time(),
			'window_hours' => self::WINDOW_HOURS,
			'requests'     => $total,
			'cached'       => $cached,
			'ratio'        => $total > 0 ? round( $cached / $total, 4 ) : null,
			'by_status'    => array_map(
				static fn( string $s, int $n ): array => [
					'status'   => $s,
					'requests' => $n,
				],
				array_keys( $byStatus ),
				array_values( $byStatus )
			),
			'top_uncached' => $paths,
		];
	}

	/**
	 * @param array<mixed> $data Datos de GraphQL ({viewer: {zones: [{httpRequestsAdaptiveGroups: [...]}]}}).
	 * @return list<array<string, mixed>>
	 */
	private static function groups( array $data ): array {
		$zones = $data['viewer']['zones'] ?? null;
		$list  = is_array( $zones ) && isset( $zones[0]['httpRequestsAdaptiveGroups'] ) && is_array( $zones[0]['httpRequestsAdaptiveGroups'] ) ? $zones[0]['httpRequestsAdaptiveGroups'] : [];
		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * @return array{available: bool, error: string, fetched_at: int, window_hours: int, requests: int, cached: int, ratio: float|null, by_status: list<array{status: string, requests: int}>, top_uncached: list<array{path: string, requests: int}>}
	 */
	private static function empty( string $error ): array {
		return [
			'available'    => false,
			'error'        => $error,
			'fetched_at'   => '' === $error ? 0 : time(),
			'window_hours' => self::WINDOW_HOURS,
			'requests'     => 0,
			'cached'       => 0,
			'ratio'        => null,
			'by_status'    => [],
			'top_uncached' => [],
		];
	}

	/**
	 * @param array{available: bool, error: string, fetched_at: int, window_hours: int, requests: int, cached: int, ratio: float|null, by_status: list<array{status: string, requests: int}>, top_uncached: list<array{path: string, requests: int}>} $data Datos.
	 * @return array{available: bool, error: string, fetched_at: int, window_hours: int, requests: int, cached: int, ratio: float|null, by_status: list<array{status: string, requests: int}>, top_uncached: list<array{path: string, requests: int}>}
	 */
	private function store( array $data ): array {
		update_option( self::OPTION, $data, false );
		return $data;
	}
}
