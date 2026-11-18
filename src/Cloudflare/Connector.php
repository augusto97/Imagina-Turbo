<?php
/**
 * Conectar un token: verificarlo, encontrar la zona del sitio y sondear permisos de lectura.
 *
 * - Token de usuario: GET /user/tokens/verify. Token de cuenta (cfat_, o sin prefijo si es anterior a
 *   2026): GET /accounts/{account_id}/tokens/verify, con la cuenta que trae la zona.
 * - Zona: GET /zones?name=… probando el host y sus dominios padre (www.tienda.com → tienda.com).
 * - Plan: plan.legacy_id (obsoleto en la API; desconocido = Free). Lo definitivo lo dice dry_run.
 * - Permisos de edición de reglas: una regla inerte validada con ?dry_run=true (Cloudflare la valida y
 *   no la guarda, igual que antes de aplicar). Solo un rechazo de autorización cuenta como «falta».
 * - El enlace para crear el token solo puede rellenar las claves cortas documentadas para tokens de
 *   usuario (zone, zone_settings, analytics, cache…); las de WAF, Cache Rules y Transform Rules no
 *   existen ahí y Cloudflare descarta en silencio las que no conoce: se agregan a mano.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Verificación y descubrimiento.
 */
final class Connector {

	/** Permiso de edición que necesita cada fase de reglas (nombres del panel de Cloudflare). */
	public const PHASE_PERMISSIONS = [
		Phases::CACHE     => 'Zone → Cache Rules → Edit',
		Phases::TRANSFORM => 'Zone → Transform Rules → Edit',
		Phases::HEADERS   => 'Zone → Transform Rules → Edit',
		Phases::WAF       => 'Zone → Zone WAF → Edit',
		Phases::RATELIMIT => 'Zone → Zone WAF → Edit',
	];

	/** Clave del estado de cada permiso de edición sondeado y la fase con la que se prueba. */
	public const EDIT_PROBES = [
		'cache_rules_edit' => Phases::CACHE,
		'transform_edit'   => Phases::TRANSFORM,
		'waf_edit'         => Phases::WAF,
		'ratelimit_edit'   => Phases::RATELIMIT,
	];

	/** Expresión que nunca coincide (dominio .invalid): la regla de prueba sería inerte aunque se guardara. */
	private const PROBE_EXPRESSION = '(http.host eq "itb-permission-check.invalid")';

	public function __construct( private readonly Client $client ) {
	}

	/**
	 * @return array{ok: bool, error: string, zone: array{id: string, name: string, account_id: string, plan: string, plan_name: string, status: string}|null, token_status: string}
	 */
	public function connect( string $token, string $host ): array {
		$api  = $this->client->withToken( $token );
		$zone = null;
		$last = null;
		// Con el simulador de pruebas (ITB_CF_API_BASE) se admite el host tal cual, aunque sea una IP.
		$names = Client::BASE === Client::base() ? self::candidates( $host ) : array_values( array_unique( array_merge( [ strtolower( $host ) ], self::candidates( $host ) ) ) );
		foreach ( $names as $name ) {
			$response = $api->get( '/zones', [ 'name' => $name ] );
			if ( ! $response->ok() ) {
				$last = $response;
				break;
			}
			$rows = $response->resultArray();
			if ( isset( $rows[0] ) && is_array( $rows[0] ) ) {
				$zone = self::zone( $rows[0] );
				break;
			}
		}

		if ( null === $zone ) {
			return [
				'ok'           => false,
				'error'        => null !== $last
					? self::explain( $last )
					/* translators: %s: host del sitio. */
					: sprintf( __( 'El token es válido pero no da acceso a ninguna zona para %s. Limítalo a la zona de este sitio.', 'imagina-turbo' ), $host ),
				'zone'         => null,
				'token_status' => '',
			];
		}

		// Verificación: primero como token de usuario; si no lo es, como token de cuenta.
		$status = '';
		if ( ! str_starts_with( $token, 'cfat_' ) ) {
			$verify = $api->get( '/user/tokens/verify' );
			$status = $verify->ok() ? (string) ( $verify->resultArray()['status'] ?? '' ) : '';
		}
		if ( '' === $status && '' !== $zone['account_id'] ) {
			$verify = $api->get( '/accounts/' . rawurlencode( $zone['account_id'] ) . '/tokens/verify' );
			$status = $verify->ok() ? (string) ( $verify->resultArray()['status'] ?? '' ) : '';
		}
		if ( 'active' !== $status ) {
			return [
				'ok'           => false,
				'error'        => '' === $status ? __( 'Cloudflare no pudo verificar el token.', 'imagina-turbo' ) : sprintf(
					/* translators: %s: estado del token (disabled, expired). */
					__( 'El token no está activo (%s).', 'imagina-turbo' ),
					$status
				),
				'zone'         => $zone,
				'token_status' => $status,
			];
		}

		return [
			'ok'           => true,
			'error'        => '',
			'zone'         => $zone,
			'token_status' => $status,
		];
	}

	/**
	 * Permisos de lectura que se pueden comprobar sin cambiar nada.
	 *
	 * @return array<string, bool>
	 */
	public function probe( string $zoneId ): array {
		$zone     = '/zones/' . rawurlencode( $zoneId );
		$settings = $this->client->get( $zone . '/settings/http3' );
		$rules    = $this->client->get( Phases::entrypoint( $zoneId, Phases::CACHE ) );
		$waf      = $this->client->get( Phases::entrypoint( $zoneId, Phases::WAF ) );
		$graphql  = $this->client->post(
			'/graphql',
			[
				'query'     => 'query($z:string!){viewer{zones(filter:{zoneTag:$z}){zoneTag}}}',
				'variables' => [ 'z' => $zoneId ],
			]
		);
		return [
			'zone_read'      => true,
			'settings_read'  => $settings->ok(),
			// 404 = la fase todavía no tiene ruleset de entrada; el permiso existe.
			'rulesets_read'  => $rules->ok() || 404 === $rules->status,
			'waf_read'       => $waf->ok() || 404 === $waf->status,
			'analytics_read' => $graphql->ok(),
		];
	}

	/**
	 * Permisos de edición de reglas, sin cambiar nada: por cada fase, una regla inerte (desactivada y con
	 * una expresión que no coincide) validada con ?dry_run=true sobre el ruleset de entrada, o creando
	 * ese ruleset si todavía no existe. Un rechazo de autorización es «falta»; cualquier otro resultado
	 * (válida, cupo lleno, plan) quiere decir que el permiso existe. Un error de red no decide nada.
	 *
	 * @return array<string, bool> Solo lo que se pudo determinar.
	 */
	public function probeEdit( string $zoneId ): array {
		$api = $this->client->quiet();
		$out = [];
		foreach ( self::EDIT_PROBES as $key => $phase ) {
			$entry = $api->get( Phases::entrypoint( $zoneId, $phase ) );
			$rule  = self::probeRule( $phase );
			$id    = is_string( $entry->resultArray()['id'] ?? null ) ? $entry->resultArray()['id'] : '';
			if ( $entry->unauthorized() ) {
				$out[ $key ] = false;
				continue;
			}
			if ( $entry->ok() && '' !== $id ) {
				$check = $api->request( 'POST', '/zones/' . rawurlencode( $zoneId ) . '/rulesets/' . rawurlencode( $id ) . '/rules', $rule, [ 'dry_run' => 'true' ] );
			} elseif ( 404 === $entry->status ) {
				$check = $api->request( 'PUT', Phases::entrypoint( $zoneId, $phase ), [ 'rules' => [ $rule ] ], [ 'dry_run' => 'true' ] );
			} else {
				continue;
			}
			if ( $check->unauthorized() ) {
				$out[ $key ] = false;
			} elseif ( 0 !== $check->status && $check->status < 500 ) {
				$out[ $key ] = true;
			}
		}
		return $out;
	}

	/**
	 * Regla de prueba para cada fase: desactivada y con una expresión que nunca coincide.
	 *
	 * @return array<string, mixed>
	 */
	public static function probeRule( string $phase ): array {
		$rule = [
			'description' => 'Imagina Turbo: prueba de permisos (dry_run, no se guarda)',
			'expression'  => self::PROBE_EXPRESSION,
			'enabled'     => false,
		];
		return $rule + match ( $phase ) {
			Phases::CACHE     => [
				'action'            => 'set_cache_settings',
				'action_parameters' => [ 'cache' => false ],
			],
			Phases::TRANSFORM => [
				'action'            => 'rewrite',
				'action_parameters' => [ 'uri' => [ 'path' => [ 'value' => '/' ] ] ],
			],
			Phases::RATELIMIT => [
				'action'    => 'block',
				'ratelimit' => [
					'characteristics'     => [ 'cf.colo.id', 'ip.src' ],
					'period'              => 10,
					'requests_per_period' => 50,
					'mitigation_timeout'  => 10,
				],
			],
			default           => [ 'action' => 'block' ],
		};
	}

	/**
	 * Mensaje para cuando falta un permiso: cuál y dónde agregarlo.
	 */
	public static function missingPermission( string $permission ): string {
		/* translators: %s: permiso de Cloudflare (por ejemplo «Zone → Zone WAF → Edit»). */
		return sprintf( __( 'El token no tiene «%s». Edítalo en Cloudflare → Mi perfil → API Tokens y agrega el permiso; el token no cambia.', 'imagina-turbo' ), $permission );
	}

	/**
	 * Error de una escritura: si fue por permiso, el mensaje accionable; si no, el de Cloudflare.
	 *
	 * @param string $permission Permiso que necesitaba la operación.
	 */
	public static function writeError( Response $response, string $permission ): string {
		return $response->unauthorized() ? self::missingPermission( $permission ) : $response->message();
	}

	/**
	 * Permisos de edición que el último sondeo encontró ausentes, sin repetir.
	 *
	 * @param array<string, bool> $permissions Estado guardado.
	 * @return list<string>
	 */
	public static function missingEdit( array $permissions ): array {
		$missing = [];
		foreach ( self::EDIT_PROBES as $key => $phase ) {
			if ( false === ( $permissions[ $key ] ?? null ) ) {
				$missing[] = self::PHASE_PERMISSIONS[ $phase ];
			}
		}
		return array_values( array_unique( $missing ) );
	}

	/**
	 * www.tienda.com.co → [www.tienda.com.co, tienda.com.co, com.co]. Sin IP ni "localhost".
	 *
	 * @return list<string>
	 */
	public static function candidates( string $host ): array {
		$host = strtolower( trim( $host, '.' ) );
		if ( '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) || ! str_contains( $host, '.' ) ) {
			return [];
		}
		$labels = explode( '.', $host );
		$out    = [];
		$total  = count( $labels );
		for ( $i = 0; $i <= $total - 2; $i++ ) {
			$out[] = implode( '.', array_slice( $labels, $i ) );
		}
		return $out;
	}

	/**
	 * @param array<mixed> $raw Zona de la API.
	 * @return array{id: string, name: string, account_id: string, plan: string, plan_name: string, status: string}
	 */
	public static function zone( array $raw ): array {
		$plan    = is_array( $raw['plan'] ?? null ) ? $raw['plan'] : [];
		$account = is_array( $raw['account'] ?? null ) ? $raw['account'] : [];
		return [
			'id'         => is_string( $raw['id'] ?? null ) ? $raw['id'] : '',
			'name'       => is_string( $raw['name'] ?? null ) ? strtolower( $raw['name'] ) : '',
			'account_id' => is_string( $account['id'] ?? null ) ? $account['id'] : '',
			'plan'       => PlanLimits::normalize( is_string( $plan['legacy_id'] ?? null ) ? $plan['legacy_id'] : '' ),
			'plan_name'  => is_string( $plan['name'] ?? null ) ? $plan['name'] : '',
			'status'     => is_string( $raw['status'] ?? null ) ? $raw['status'] : '',
		];
	}

	/**
	 * Mensaje en español para los errores típicos al conectar.
	 */
	public static function explain( Response $response ): string {
		if ( 400 === $response->status || 401 === $response->status || 403 === $response->status ) {
			return __( 'Cloudflare rechazó el token. Revisa que esté copiado completo y que tenga el permiso «Zona: leer» sobre la zona de este sitio.', 'imagina-turbo' );
		}
		if ( 0 === $response->status ) {
			/* translators: %s: error de red. */
			return sprintf( __( 'No se pudo contactar con Cloudflare: %s', 'imagina-turbo' ), $response->message() );
		}
		/* translators: %s: error de la API. */
		return sprintf( __( 'Cloudflare respondió con un error: %s', 'imagina-turbo' ), $response->message() );
	}
}
