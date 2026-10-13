<?php
/**
 * Conectar un token: verificarlo, encontrar la zona del sitio y sondear permisos de lectura.
 *
 * - Token de usuario: GET /user/tokens/verify. Token de cuenta (cfat_, o sin prefijo si es anterior a
 *   2026): GET /accounts/{account_id}/tokens/verify, con la cuenta que trae la zona.
 * - Zona: GET /zones?name=… probando el host y sus dominios padre (www.tienda.com → tienda.com).
 * - Plan: plan.legacy_id (obsoleto en la API; desconocido = Free). Lo definitivo lo dice dry_run.
 * - Permisos de escritura no se pueden sondear sin escribir: los valida dry_run al aplicar.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Verificación y descubrimiento.
 */
final class Connector {

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
			'analytics_read' => $graphql->ok(),
		];
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
