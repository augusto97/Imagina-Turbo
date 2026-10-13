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

itb_hub_reply( 404, [ 'error' => 'ruta desconocida' ] );
