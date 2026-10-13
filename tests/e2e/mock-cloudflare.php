<?php
/**
 * Simulador mínimo de la API v4 de Cloudflare para las pruebas E2E del módulo Cloudflare.
 *
 * Uso: ITB_CF_MOCK_DIR=/tmp/cf php -S 127.0.0.1:8098 tests/e2e/mock-cloudflare.php
 * y en wp-config.php: define( 'ITB_CF_API_BASE', 'http://127.0.0.1:8098/client/v4' );
 *
 * Implementa lo que usa el plugin, con la forma documentada (verificada el 2026-10-13):
 * zonas, verificación de tokens, rulesets de fase (entrypoint 404 si no existe, PUT, reglas con ref
 * y position, ?dry_run=true con cupos por plan), ajustes de zona, Smart Tiered Cache, purga (con el
 * límite de Free: 5 por minuto, 429 y código 1134) y GraphQL con datos fijos.
 *
 * Control (sin autenticación, solo para la prueba):
 * - POST /__mock/reset   {token, zone_name, plan, rules?: {phase: [regla...]}, settings?: {...}}
 * - GET  /__mock/state   estado completo
 * - POST /__mock/edit    {phase, ref, changes: {...}} cambia una regla "a mano" (deriva)
 * - POST /__mock/fail    {match: "PATCH /settings/early_hints", times: 10} las próximas N peticiones que coincidan fallan con 500
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

$dir = getenv( 'ITB_CF_MOCK_DIR' ) ?: sys_get_temp_dir() . '/itb-cf-mock';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0700, true );
}
$file = $dir . '/state.json';

const PLAN_LIMITS = [
	'free'       => [ 'http_request_cache_settings' => 10, 'http_request_transform' => 10, 'http_request_firewall_custom' => 5, 'http_ratelimit' => 1 ],
	'pro'        => [ 'http_request_cache_settings' => 25, 'http_request_transform' => 25, 'http_request_firewall_custom' => 20, 'http_ratelimit' => 2 ],
	'business'   => [ 'http_request_cache_settings' => 50, 'http_request_transform' => 50, 'http_request_firewall_custom' => 100, 'http_ratelimit' => 5 ],
	'enterprise' => [ 'http_request_cache_settings' => 300, 'http_request_transform' => 300, 'http_request_firewall_custom' => 1000, 'http_ratelimit' => 100 ],
];

const PHASE_ACTIONS = [
	'http_request_cache_settings'  => [ 'set_cache_settings' ],
	'http_request_transform'       => [ 'rewrite' ],
	'http_request_firewall_custom' => [ 'block', 'managed_challenge', 'js_challenge', 'challenge', 'skip', 'log' ],
	'http_ratelimit'               => [ 'block', 'managed_challenge', 'js_challenge', 'challenge', 'log' ],
];

function load_state( string $file ): array {
	$raw = is_file( $file ) ? file_get_contents( $file ) : false;
	$s   = is_string( $raw ) ? json_decode( $raw, true ) : null;
	return is_array( $s ) ? $s : [];
}

function save_state( string $file, array $state ): void {
	file_put_contents( $file . '.tmp', json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	rename( $file . '.tmp', $file );
}

function reply( int $status, $result, array $errors = [], array $headers = [] ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	foreach ( $headers as $k => $v ) {
		header( $k . ': ' . $v );
	}
	echo json_encode(
		[
			'success'  => [] === $errors && $status < 400,
			'errors'   => $errors,
			'messages' => [],
			'result'   => $result,
		],
		JSON_UNESCAPED_SLASHES
	);
	exit;
}

function fail( int $status, int $code, string $message, array $headers = [] ): void {
	reply( $status, null, [ [ 'code' => $code, 'message' => $message ] ], $headers );
}

function new_id(): string {
	return bin2hex( random_bytes( 16 ) );
}

function now(): string {
	return gmdate( 'Y-m-d\TH:i:s.000000\Z' );
}

/**
 * Validación de una regla como la haría la API (lo esencial).
 */
function validate_rule( string $phase, array $rule, string $plan ): ?string {
	if ( ! is_string( $rule['expression'] ?? null ) || '' === trim( $rule['expression'] ) ) {
		return 'expression is required';
	}
	if ( ! in_array( $rule['action'] ?? '', PHASE_ACTIONS[ $phase ] ?? [], true ) ) {
		return 'action not allowed in phase';
	}
	if ( 'log' === ( $rule['action'] ?? '' ) && 'enterprise' !== $plan && 'http_request_firewall_custom' === $phase ) {
		return 'not entitled: log action';
	}
	if ( str_contains( $rule['expression'], ' matches ' ) && in_array( $plan, [ 'free', 'pro' ], true ) ) {
		return 'not entitled: regex';
	}
	if ( strlen( $rule['expression'] ) > 4096 ) {
		return 'expression too long';
	}
	if ( 'http_request_cache_settings' === $phase && isset( $rule['action_parameters']['cache_key']['custom_key']['query_string']['exclude']['list'] ) && 'enterprise' !== $plan ) {
		return 'not entitled: custom cache key query string';
	}
	if ( 'http_ratelimit' === $phase && ! is_array( $rule['ratelimit'] ?? null ) ) {
		return 'ratelimit is required';
	}
	$bare = (string) preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/', '""', $rule['expression'] );
	if ( substr_count( $bare, '(' ) !== substr_count( $bare, ')' ) ) {
		return 'filter parsing error: unbalanced parentheses';
	}
	return null;
}

function stored_rule( array $rule, ?array $previous = null ): array {
	$id  = $previous['id'] ?? new_id();
	$out = [
		'id'           => $id,
		'version'      => (string) ( (int) ( $previous['version'] ?? 0 ) + 1 ),
		'action'       => $rule['action'],
		'expression'   => $rule['expression'],
		'description'  => $rule['description'] ?? '',
		'last_updated' => now(),
		'ref'          => $rule['ref'] ?? ( $previous['ref'] ?? $id ),
		'enabled'      => $rule['enabled'] ?? true,
	];
	if ( isset( $rule['action_parameters'] ) ) {
		$out['action_parameters'] = $rule['action_parameters'];
	}
	if ( isset( $rule['ratelimit'] ) ) {
		$out['ratelimit'] = $rule['ratelimit'];
	}
	return $out;
}

function ruleset_view( array $rs, string $phase ): array {
	return [
		'id'           => $rs['id'],
		'name'         => 'default',
		'kind'         => 'zone',
		'phase'        => $phase,
		'version'      => (string) ( $rs['version'] ?? 1 ),
		'last_updated' => $rs['last_updated'] ?? now(),
		'rules'        => $rs['rules'],
	];
}

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
parse_str( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY ), $query );
$body  = json_decode( (string) file_get_contents( 'php://input' ), true );
$body  = is_array( $body ) ? $body : [];
$state = load_state( $file );

// Control.
if ( '/__mock/reset' === $uri ) {
	$plan  = $body['plan'] ?? 'free';
	$state = [
		'token'    => $body['token'] ?? '',
		'kind'     => $body['kind'] ?? 'user',
		'zone'     => [
			'id'      => $body['zone_id'] ?? '023e105f4ecef8ad9ca31a8372d0c353',
			'name'    => $body['zone_name'] ?? 'example.com',
			'status'  => 'active',
			'account' => [ 'id' => '01a7362d577a6c3019a474fd6f485823', 'name' => 'Cuenta de prueba' ],
			'plan'    => [ 'id' => 'p', 'name' => ucfirst( $plan ) . ' Website', 'legacy_id' => $plan ],
		],
		'settings' => array_merge(
			[
				'http3'                              => 'off',
				'early_hints'                        => 'off',
				'rocket_loader'                      => 'on',
				'browser_cache_ttl'                  => 14400,
				'ssl'                                => 'flexible',
				'always_use_https'                   => 'off',
				'tiered_cache_smart_topology_enable' => 'off',
			],
			$body['settings'] ?? []
		),
		'rulesets' => [],
		'purges'   => [],
		'calls'    => [],
	];
	foreach ( $body['rules'] ?? [] as $phase => $rules ) {
		$state['rulesets'][ $phase ] = [
			'id'    => new_id(),
			'rules' => array_map( 'stored_rule', $rules ),
		];
	}
	save_state( $file, $state );
	reply( 200, $state );
}
if ( '/__mock/state' === $uri ) {
	reply( 200, $state );
}
if ( '/__mock/edit' === $uri ) {
	foreach ( $state['rulesets'][ $body['phase'] ]['rules'] ?? [] as $i => $rule ) {
		if ( $rule['ref'] === ( $body['ref'] ?? '' ) ) {
			$state['rulesets'][ $body['phase'] ]['rules'][ $i ] = array_merge( $rule, $body['changes'] ?? [], [ 'version' => (string) ( (int) $rule['version'] + 1 ), 'last_updated' => now() ] );
		}
	}
	save_state( $file, $state );
	reply( 200, $state );
}

if ( '/__mock/fail' === $uri ) {
	$state['fail']       = (string) ( $body['match'] ?? '' );
	$state['fail_times'] = (int) ( $body['times'] ?? 10 );
	save_state( $file, $state );
	reply( 200, true );
}

// Autenticación.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ( '' === ( $state['token'] ?? '' ) || 'Bearer ' . $state['token'] !== $auth ) {
	fail( 403, 10000, 'Authentication error' );
}

$state['calls'][] = $method . ' ' . $uri . ( isset( $query['dry_run'] ) ? '?dry_run=' . $query['dry_run'] : '' );
$state['calls']   = array_slice( $state['calls'], -300 );
save_state( $file, $state );

$base   = '/client/v4';
$path   = str_starts_with( $uri, $base ) ? substr( $uri, strlen( $base ) ) : $uri;
$zoneId = $state['zone']['id'];
$plan   = $state['zone']['plan']['legacy_id'];
$dry    = 'true' === ( $query['dry_run'] ?? '' );

if ( 'GET' === $method && '/user/tokens/verify' === $path ) {
	if ( 'account' === $state['kind'] ) {
		fail( 400, 1000, 'Invalid API Token' );
	}
	reply( 200, [ 'id' => 'tok123', 'status' => 'active' ] );
}
if ( 'GET' === $method && preg_match( '#^/accounts/([a-f0-9]+)/tokens/verify$#', $path, $m ) ) {
	if ( $m[1] !== $state['zone']['account']['id'] ) {
		fail( 404, 7003, 'No route for the URI' );
	}
	reply( 200, [ 'id' => 'tok456', 'status' => 'active' ] );
}
if ( 'GET' === $method && '/zones' === $path ) {
	reply( 200, ( $query['name'] ?? '' ) === $state['zone']['name'] ? [ $state['zone'] ] : [] );
}

if ( ! preg_match( '#^/zones/([a-f0-9]{32})(/.*)?$#', $path, $m ) && '/graphql' !== $path ) {
	fail( 404, 7003, 'No route for the URI' );
}
if ( '/graphql' !== $path && $m[1] !== $zoneId ) {
	fail( 403, 9109, 'Unauthorized to access requested resource' );
}
$rest = $m[2] ?? '';

if ( '' !== ( $state['fail'] ?? '' ) && $method . ' ' . $rest === $state['fail'] && ! $dry ) {
	$state['fail_times'] = (int) ( $state['fail_times'] ?? 1 ) - 1;
	if ( $state['fail_times'] <= 0 ) {
		$state['fail'] = '';
	}
	save_state( $file, $state );
	fail( 500, 10001, 'Internal error (simulado)' );
}

// Ajustes.
if ( preg_match( '#^/settings/([a-z0-9_]+)$#', $rest, $s ) || '/cache/tiered_cache_smart_topology_enable' === $rest ) {
	$id = $s[1] ?? 'tiered_cache_smart_topology_enable';
	if ( ! array_key_exists( $id, $state['settings'] ) ) {
		fail( 404, 1003, 'Invalid or missing zone setting' );
	}
	if ( 'PATCH' === $method ) {
		if ( ! array_key_exists( 'value', $body ) ) {
			fail( 400, 1007, 'Invalid value' );
		}
		if ( in_array( $id, [ 'ssl' ], true ) ) {
			fail( 400, 1016, 'The plugin must never change SSL' );
		}
		$state['settings'][ $id ] = $body['value'];
		save_state( $file, $state );
	}
	reply( 200, [ 'id' => $id, 'value' => $state['settings'][ $id ], 'editable' => true, 'modified_on' => now() ] );
}

// Rulesets.
if ( preg_match( '#^/rulesets/phases/([a-z_]+)/entrypoint$#', $rest, $p ) ) {
	$phase = $p[1];
	if ( ! isset( PHASE_ACTIONS[ $phase ] ) ) {
		fail( 400, 20003, 'unknown phase' );
	}
	if ( 'GET' === $method ) {
		if ( ! isset( $state['rulesets'][ $phase ] ) ) {
			fail( 404, 10003, 'could not find entrypoint ruleset in the ' . $phase . ' phase' );
		}
		reply( 200, ruleset_view( $state['rulesets'][ $phase ], $phase ) );
	}
	if ( 'PUT' === $method ) {
		$rules = $body['rules'] ?? [];
		if ( count( $rules ) > PLAN_LIMITS[ $plan ][ $phase ] ) {
			fail( 400, 20217, 'exceeded maximum number of rules in the phase' );
		}
		foreach ( $rules as $rule ) {
			$error = validate_rule( $phase, $rule, $plan );
			if ( null !== $error ) {
				fail( 400, 20021, $error );
			}
		}
		if ( $dry ) {
			reply( 200, null );
		}
		$previous = [];
		foreach ( $state['rulesets'][ $phase ]['rules'] ?? [] as $r ) {
			$previous[ $r['ref'] ] = $r;
		}
		$state['rulesets'][ $phase ] = [
			'id'           => $state['rulesets'][ $phase ]['id'] ?? new_id(),
			'version'      => (int) ( $state['rulesets'][ $phase ]['version'] ?? 0 ) + 1,
			'last_updated' => now(),
			'rules'        => array_map( static fn( $r ) => stored_rule( $r, isset( $r['ref'] ) ? ( $previous[ $r['ref'] ] ?? null ) : null ), $rules ),
		];
		save_state( $file, $state );
		reply( 200, ruleset_view( $state['rulesets'][ $phase ], $phase ) );
	}
	fail( 405, 10000, 'method not allowed' );
}

if ( preg_match( '#^/rulesets/([a-f0-9]{32})/rules(?:/([a-f0-9]{32}))?$#', $rest, $r ) ) {
	$phase = null;
	foreach ( $state['rulesets'] as $ph => $rs ) {
		if ( $rs['id'] === $r[1] ) {
			$phase = $ph;
		}
	}
	if ( null === $phase ) {
		fail( 404, 10003, 'ruleset not found' );
	}
	$rules  = $state['rulesets'][ $phase ]['rules'];
	$ruleId = $r[2] ?? '';
	$index  = null;
	foreach ( $rules as $i => $rule ) {
		if ( $rule['id'] === $ruleId ) {
			$index = $i;
		}
	}

	if ( 'POST' === $method && '' === $ruleId ) {
		if ( count( $rules ) >= PLAN_LIMITS[ $plan ][ $phase ] ) {
			fail( 400, 20217, 'exceeded maximum number of rules in the phase' );
		}
		$error = validate_rule( $phase, $body, $plan );
		if ( null !== $error ) {
			fail( 400, 20021, $error );
		}
		foreach ( $rules as $rule ) {
			if ( isset( $body['ref'] ) && $rule['ref'] === $body['ref'] ) {
				fail( 400, 20022, 'ref must be unique within the ruleset' );
			}
		}
		$pos = count( $rules );
		if ( isset( $body['position']['index'] ) ) {
			$pos = (int) $body['position']['index'] - 1;
			if ( $pos < 0 || $pos > count( $rules ) ) {
				fail( 400, 20023, 'position index out of range' );
			}
		} elseif ( isset( $body['position']['before'] ) ) {
			$pos = 0;
			foreach ( $rules as $i => $rule ) {
				if ( $rule['id'] === $body['position']['before'] ) {
					$pos = $i;
				}
			}
		} elseif ( isset( $body['position']['after'] ) && '' !== $body['position']['after'] ) {
			foreach ( $rules as $i => $rule ) {
				if ( $rule['id'] === $body['position']['after'] ) {
					$pos = $i + 1;
				}
			}
		}
		if ( $dry ) {
			reply( 200, null );
		}
		unset( $body['position'] );
		array_splice( $rules, $pos, 0, [ stored_rule( $body ) ] );
	} elseif ( 'PATCH' === $method && null !== $index ) {
		$error = validate_rule( $phase, $body, $plan );
		if ( null !== $error ) {
			fail( 400, 20021, $error );
		}
		if ( $dry ) {
			reply( 200, null );
		}
		unset( $body['position'] );
		$rules[ $index ] = stored_rule( $body, $rules[ $index ] );
	} elseif ( 'DELETE' === $method && null !== $index ) {
		if ( $dry ) {
			reply( 200, null );
		}
		array_splice( $rules, $index, 1 );
	} else {
		fail( 404, 10003, 'rule not found' );
	}
	$state['rulesets'][ $phase ]['rules']        = array_values( $rules );
	$state['rulesets'][ $phase ]['version']      = (int) ( $state['rulesets'][ $phase ]['version'] ?? 1 ) + 1;
	$state['rulesets'][ $phase ]['last_updated'] = now();
	save_state( $file, $state );
	reply( 200, ruleset_view( $state['rulesets'][ $phase ], $phase ) );
}

// Purga.
if ( 'POST' === $method && '/purge_cache' === $rest ) {
	$keys = array_values( array_intersect( array_keys( $body ), [ 'tags', 'files', 'hosts', 'prefixes', 'purge_everything' ] ) );
	if ( 1 !== count( $keys ) ) {
		fail( 400, 1092, 'Exactly one purge selector is required' );
	}
	$recent = array_filter( $state['purges'], static fn( $p ) => $p['at'] > time() - 60 );
	if ( 'free' === $plan && count( $recent ) >= 5 ) {
		fail( 429, 1134, 'Unable to purge, rate limit reached. Please wait and try again.', [ 'Retry-After' => '60' ] );
	}
	if ( is_array( $body[ $keys[0] ] ) && count( $body[ $keys[0] ] ) > 100 ) {
		fail( 400, 1095, 'Too many items in a single request' );
	}
	$state['purges'][] = [ 'at' => time(), 'body' => $body ];
	save_state( $file, $state );
	reply( 200, [ 'id' => $zoneId ] );
}

// GraphQL: datos fijos con la forma de httpRequestsAdaptiveGroups.
if ( '/graphql' === $path ) {
	$vars = $body['variables'] ?? [];
	if ( str_contains( (string) ( $body['query'] ?? '' ), 'firewallEventsAdaptive' ) ) {
		$events = [];
		foreach ( [ [ '[imagina-turbo] Firewall: ataques en la URL', 'managed_challenge', '/?id=../../etc/passwd', 12 ], [ '[imagina-turbo] Firewall: superficie de WordPress', 'managed_challenge', '/xmlrpc.php', 30 ], [ '', 'block', '/wp-login.php', 5 ] ] as [ $description, $action, $path, $n ] ) {
			for ( $i = 0; $i < $n; $i++ ) {
				$events[] = [ 'action' => $action, 'source' => '' === $description ? 'ratelimit' : 'firewallCustom', 'ruleId' => md5( $description ), 'description' => $description, 'clientRequestPath' => $path, 'clientCountryName' => 0 === $i % 3 ? 'RU' : 'CN' ];
			}
		}
		header( 'Content-Type: application/json' );
		echo json_encode( [ 'data' => [ 'viewer' => [ 'zones' => [ [ 'firewallEventsAdaptive' => $events ] ] ] ], 'errors' => null ] );
		exit;
	}
	if ( str_contains( (string) ( $body['query'] ?? '' ), 'zoneTag}}}' ) || ! str_contains( (string) ( $body['query'] ?? '' ), 'httpRequestsAdaptiveGroups' ) ) {
		echo json_encode( [ 'data' => [ 'viewer' => [ 'zones' => [ [ 'zoneTag' => $vars['z'] ?? '' ] ] ] ], 'errors' => null ] );
		exit;
	}
	$groups = isset( $vars['c'] )
		? [ [ 'count' => 420, 'dimensions' => [ 'clientRequestPath' => '/carrito/' ] ], [ 'count' => 130, 'dimensions' => [ 'clientRequestPath' => '/?s=camisa' ] ] ]
		: [ [ 'count' => 8800, 'dimensions' => [ 'cacheStatus' => 'hit' ] ], [ 'count' => 900, 'dimensions' => [ 'cacheStatus' => 'dynamic' ] ], [ 'count' => 300, 'dimensions' => [ 'cacheStatus' => 'miss' ] ] ];
	header( 'Content-Type: application/json' );
	echo json_encode( [ 'data' => [ 'viewer' => [ 'zones' => [ [ 'httpRequestsAdaptiveGroups' => $groups ] ] ] ], 'errors' => null ] );
	exit;
}

fail( 404, 7003, 'No route for the URI' );
