<?php
/**
 * Hub simulado para pruebas (solo en pruebas): registro y telemetría con verificación de firma.
 *
 * Uso: php -S 127.0.0.1:8099 tests/e2e/mock-hub.php
 * Variables: ITB_HUB_DIR (dónde guardar lo recibido; por defecto el temporal del sistema),
 *            ITB_HUB_FAIL=1 (responder 503 a todo, para probar el backoff).
 *
 * Guarda en ITB_HUB_DIR: sites.json (site_id → site_key), nonces.json (anti-replay) y
 * telemetry-<n>.json (cada lote recibido, ya descomprimido) para inspeccionarlo.
 *
 * Flota: GET /v1/fleet/policy devuelve ITB_HUB_DIR/policy.json (204 si no existe) con la respuesta
 * firmada. POST /__mock/fleet/push {"dry_run": bool} recorre los sitios registrados y llama a su
 * /fleet/remote firmado (lote); guarda fleet-push-<n>.json. Como el sitio llama al hub mientras el hub
 * espera su respuesta, hay que arrancarlo con PHP_CLI_SERVER_WORKERS=4.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/src/Hub/Signer.php';

use ImaginaTurbo\Hub\Signer;

$dir = getenv( 'ITB_HUB_DIR' ) ?: sys_get_temp_dir() . '/itb-mock-hub';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0700, true );
}

/**
 * @param array<string, mixed> $data Cuerpo.
 */
function itb_hub_reply( int $status, array $data ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $data );
}

/**
 * @return array<string, mixed>
 */
function itb_hub_read( string $file ): array {
	$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : [];
	return is_array( $data ) ? $data : [];
}

if ( '1' === getenv( 'ITB_HUB_FAIL' ) || is_file( $dir . '/fail' ) ) {
	itb_hub_reply( 503, [ 'error' => 'mantenimiento' ] );
	return;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
$body   = (string) file_get_contents( 'php://input' );

if ( 'POST' === $method && '/v1/sites/register' === $path ) {
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) || ! is_string( $data['site_url'] ?? null ) ) {
		itb_hub_reply( 400, [ 'error' => 'site_url requerido' ] );
		return;
	}
	$sites            = itb_hub_read( $dir . '/sites.json' );
	$siteId           = 'site_' . bin2hex( random_bytes( 6 ) );
	$key              = bin2hex( random_bytes( 32 ) );
	$sites[ $siteId ] = [
		'key'     => $key,
		'url'     => $data['site_url'],
		'license' => $_SERVER['HTTP_X_ITB_LICENSE'] ?? '',
	];
	file_put_contents( $dir . '/sites.json', json_encode( $sites ) );
	itb_hub_reply(
		201,
		[
			'site_id'  => $siteId,
			'site_key' => $key,
		]
	);
	return;
}

// Beacons de RUM directos desde el navegador: sin firma; el hub valida el sitio y el origen.
if ( 'POST' === $method && '/v1/rum' === $path ) {
	$sites  = itb_hub_read( $dir . '/sites.json' );
	$siteId = (string) ( $_GET['site'] ?? '' );
	$origin = (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' );
	if ( ! isset( $sites[ $siteId ] ) || ( '' !== $origin && parse_url( $origin, PHP_URL_HOST ) !== parse_url( (string) $sites[ $siteId ]['url'], PHP_URL_HOST ) ) ) {
		itb_hub_reply( 403, [ 'error' => 'sitio u origen no válido' ] );
		return;
	}
	if ( strlen( $body ) > 2048 || ! is_array( json_decode( $body, true ) ) ) {
		itb_hub_reply( 400, [ 'error' => 'beacon no válido' ] );
		return;
	}
	file_put_contents( $dir . '/rum-' . $siteId . '.jsonl', $body . "\n", FILE_APPEND );
	header( 'Access-Control-Allow-Origin: ' . ( '' !== $origin ? $origin : '*' ) );
	http_response_code( 204 );
	return;
}

if ( 'POST' === $method && '/v1/telemetry' === $path ) {
	$sites  = itb_hub_read( $dir . '/sites.json' );
	$siteId = (string) ( $_SERVER['HTTP_X_ITB_SITE'] ?? '' );
	if ( ! isset( $sites[ $siteId ]['key'] ) ) {
		itb_hub_reply( 401, [ 'error' => 'sitio desconocido' ] );
		return;
	}
	$nonce = (string) ( $_SERVER['HTTP_X_ITB_NONCE'] ?? '' );
	$ok    = Signer::verify(
		(string) $sites[ $siteId ]['key'],
		$method,
		$path,
		(string) ( $_SERVER['HTTP_X_ITB_TIMESTAMP'] ?? '' ),
		$nonce,
		$body,
		(string) ( $_SERVER['HTTP_X_ITB_SIGNATURE'] ?? '' )
	);
	if ( ! $ok ) {
		itb_hub_reply( 401, [ 'error' => 'firma inválida' ] );
		return;
	}
	$nonces = itb_hub_read( $dir . '/nonces.json' );
	$nonces = array_filter( $nonces, static fn( $t ): bool => is_int( $t ) && $t > time() - Signer::MAX_SKEW );
	if ( isset( $nonces[ $nonce ] ) ) {
		itb_hub_reply( 409, [ 'error' => 'nonce repetido' ] );
		return;
	}
	$nonces[ $nonce ] = time();
	file_put_contents( $dir . '/nonces.json', json_encode( $nonces ) );

	$json = 'gzip' === strtolower( (string) ( $_SERVER['HTTP_CONTENT_ENCODING'] ?? '' ) ) ? gzdecode( $body ) : $body;
	$data = is_string( $json ) ? json_decode( $json, true ) : null;
	if ( ! is_array( $data ) || ( $data['site_id'] ?? null ) !== $siteId ) {
		itb_hub_reply( 400, [ 'error' => 'lote inválido' ] );
		return;
	}
	$n = count( glob( $dir . '/telemetry-*.json' ) ?: [] ) + 1;
	file_put_contents( $dir . '/telemetry-' . $n . '.json', json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	itb_hub_reply( 202, [ 'accepted' => true ] );
	return;
}

/**
 * Verifica una petición firmada del sitio (firma, ventana y nonce). Devuelve el site_id o null.
 */
function itb_hub_verify( string $dir, string $method, string $path, string $body ): ?string {
	$sites  = itb_hub_read( $dir . '/sites.json' );
	$siteId = (string) ( $_SERVER['HTTP_X_ITB_SITE'] ?? '' );
	if ( ! isset( $sites[ $siteId ]['key'] ) ) {
		return null;
	}
	$nonce = (string) ( $_SERVER['HTTP_X_ITB_NONCE'] ?? '' );
	if ( ! Signer::verify( (string) $sites[ $siteId ]['key'], $method, $path, (string) ( $_SERVER['HTTP_X_ITB_TIMESTAMP'] ?? '' ), $nonce, $body, (string) ( $_SERVER['HTTP_X_ITB_SIGNATURE'] ?? '' ) ) ) {
		return null;
	}
	$nonces = itb_hub_read( $dir . '/nonces.json' );
	$nonces = array_filter( $nonces, static fn( $t ): bool => is_int( $t ) && $t > time() - Signer::MAX_SKEW );
	if ( isset( $nonces[ $nonce ] ) ) {
		return null;
	}
	$nonces[ $nonce ] = time();
	file_put_contents( $dir . '/nonces.json', json_encode( $nonces ) );
	return $siteId;
}

if ( 'GET' === $method && '/v1/fleet/policy' === $path ) {
	$siteId = itb_hub_verify( $dir, $method, $path, $body );
	if ( null === $siteId ) {
		itb_hub_reply( 401, [ 'error' => 'firma inválida' ] );
		return;
	}
	if ( ! is_file( $dir . '/policy.json' ) ) {
		http_response_code( 204 );
		return;
	}
	$policy = (string) file_get_contents( $dir . '/policy.json' );
	$sites  = itb_hub_read( $dir . '/sites.json' );
	$ts     = time();
	// Firma de la respuesta: atada a la petición (su nonce) y al sitio (su clave).
	$sig = is_file( $dir . '/bad-signature' ) ? str_repeat( '0', 64 ) : Signer::sign( (string) $sites[ $siteId ]['key'], 'RESPONSE', $path, $ts, (string) $_SERVER['HTTP_X_ITB_NONCE'], $policy );
	header( 'X-ITB-Timestamp: ' . $ts );
	header( 'X-ITB-Signature: ' . $sig );
	header( 'Content-Type: application/json' );
	echo $policy;
	return;
}

// Lote de flota: el hub llama a cada sitio (estado o aplicar), como haría el hub real.
if ( 'POST' === $method && '/__mock/fleet/push' === $path ) {
	$input   = json_decode( $body, true );
	$dry     = is_array( $input ) && true === ( $input['dry_run'] ?? false );
	$replay  = is_array( $input ) && true === ( $input['replay'] ?? false );
	$policy  = json_decode( (string) @file_get_contents( $dir . '/policy.json' ), true );
	$version = is_array( $policy ) ? (int) ( $policy['version'] ?? 0 ) : 0;
	$results = [];
	foreach ( itb_hub_read( $dir . '/sites.json' ) as $siteId => $site ) {
		$url                = rtrim( (string) $site['url'], '/' ) . '/wp-json/imagina-turbo/v1/fleet/remote';
		$payload            = (string) json_encode(
			[
				'action'         => $dry ? 'status' : 'apply',
				'policy_version' => $version,
			]
		);
		$headers            = Signer::headers( (string) $siteId, (string) $site['key'], (string) ( $site['license'] ?? '' ), 'POST', (string) parse_url( $url, PHP_URL_PATH ), $payload );
		$send               = static function () use ( $url, $headers, $payload ): array {
			$lines = [ 'Content-Type: application/json' ];
			foreach ( $headers as $name => $value ) {
				$lines[] = $name . ': ' . $value;
			}
			$ch = curl_init( $url );
			curl_setopt_array(
				$ch,
				[
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => $payload,
					CURLOPT_HTTPHEADER     => $lines,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 60,
				]
			);
			$response = (string) curl_exec( $ch );
			$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
			return [
				'status'   => $code,
				'response' => json_decode( $response, true ) ?? $response,
			];
		};
		$results[ $siteId ] = $send();
		if ( $replay ) {
			// Misma petición otra vez (mismo nonce): el sitio debe rechazarla.
			$results[ $siteId ]['replay'] = $send()['status'];
		}
	}
	$n = count( glob( $dir . '/fleet-push-*.json' ) ?: [] ) + 1;
	file_put_contents( $dir . '/fleet-push-' . $n . '.json', json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	itb_hub_reply( 200, $results );
	return;
}

itb_hub_reply( 404, [ 'error' => 'ruta desconocida' ] );
