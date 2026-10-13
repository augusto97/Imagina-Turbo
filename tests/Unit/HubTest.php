<?php
/**
 * Hub: firma canónica, ventana anti-replay, backoff y validación de la URL.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Safety\AdminBypassCookie;
use ImaginaTurbo\Hub\HubClient;
use ImaginaTurbo\Hub\Signer;

final class HubTest extends TestCase {

	private const KEY = 'clave-del-sitio-0123456789abcdef0123456789abcdef';

	public function test_signature_roundtrip(): void {
		$now     = 1_760_000_000;
		$nonce   = str_repeat( 'ab', 16 );
		$body    = (string) gzencode( '{"a":1}' );
		$headers = Signer::headers( 'site_1', self::KEY, 'lic', 'POST', '/v1/telemetry', $body, $now, $nonce );

		$this->assertSame( 'site_1', $headers['X-ITB-Site'] );
		$this->assertSame( (string) $now, $headers['X-ITB-Timestamp'] );
		$this->assertTrue( Signer::verify( self::KEY, 'post', '/v1/telemetry', (string) $now, $nonce, $body, $headers['X-ITB-Signature'], $now + 10 ) );
	}

	public function test_signature_covers_method_path_body_and_time(): void {
		$now   = 1_760_000_000;
		$nonce = str_repeat( 'cd', 16 );
		$sig   = Signer::sign( self::KEY, 'POST', '/v1/telemetry', $now, $nonce, 'cuerpo' );

		$this->assertFalse( Signer::verify( self::KEY, 'POST', '/v1/sites/register', (string) $now, $nonce, 'cuerpo', $sig, $now ), 'otra ruta' );
		$this->assertFalse( Signer::verify( self::KEY, 'GET', '/v1/telemetry', (string) $now, $nonce, 'cuerpo', $sig, $now ), 'otro método' );
		$this->assertFalse( Signer::verify( self::KEY, 'POST', '/v1/telemetry', (string) $now, $nonce, 'cuerpo2', $sig, $now ), 'otro cuerpo' );
		$this->assertFalse( Signer::verify( 'otra-clave', 'POST', '/v1/telemetry', (string) $now, $nonce, 'cuerpo', $sig, $now ), 'otra clave' );
		$this->assertFalse( Signer::verify( self::KEY, 'POST', '/v1/telemetry', (string) $now, $nonce, 'cuerpo', $sig, $now + Signer::MAX_SKEW + 1 ), 'fuera de la ventana' );
		$this->assertFalse( Signer::verify( self::KEY, 'POST', '/v1/telemetry', (string) $now, 'corto', 'cuerpo', $sig, $now ), 'nonce mal formado' );
		$this->assertFalse( Signer::verify( self::KEY, 'POST', '/v1/telemetry', '12x', $nonce, 'cuerpo', $sig, $now ), 'timestamp no numérico' );
		$this->assertStringContainsString( "\n" . hash( 'sha256', 'cuerpo' ), Signer::canonical( 'post', '/p', 1, 'n', 'cuerpo' ) );
	}

	public function test_backoff_is_exponential_and_capped(): void {
		$this->assertSame( HOUR_IN_SECONDS, HubClient::backoff( 1 ) );
		$this->assertSame( 2 * HOUR_IN_SECONDS, HubClient::backoff( 2 ) );
		$this->assertSame( 4 * HOUR_IN_SECONDS, HubClient::backoff( 3 ) );
		$this->assertSame( 6 * HOUR_IN_SECONDS, HubClient::backoff( 4 ) );
		$this->assertSame( 6 * HOUR_IN_SECONDS, HubClient::backoff( 40 ) );
	}

	public function test_sendable_until_waits_for_the_hourly_rollup(): void {
		$now  = 1_760_000_000 - ( 1_760_000_000 % HOUR_IN_SECONDS ) + 1200; // 20 min dentro de la hora.
		$hour = $now - ( $now % HOUR_IN_SECONDS );
		$this->assertSame( $hour, HubClient::sendableUntil( $now, $hour - HOUR_IN_SECONDS ), 'la hora anterior ya está agregada' );
		$this->assertSame( $hour - HOUR_IN_SECONDS, HubClient::sendableUntil( $now, $hour - 2 * HOUR_IN_SECONDS ), 'falta agregar la anterior: se espera' );
		$this->assertSame( $hour, HubClient::sendableUntil( $now, $hour - 10 * HOUR_IN_SECONDS ), 'agregado parado: no bloquea' );
		$this->assertSame( $hour, HubClient::sendableUntil( $now, 0 ), 'sin agregado nunca: no bloquea' );
		$this->assertSame( $hour, HubClient::sendableUntil( $now, false ) );
	}

	public function test_hub_url_validation(): void {
		$this->assertSame( 'https://hub.imaginawp.com', Settings::sanitizeHubUrl( ' https://hub.imaginawp.com/ ' ) );
		$this->assertSame( 'https://hub.example.com:8443/api', Settings::sanitizeHubUrl( 'https://hub.example.com:8443/api/' ) );
		$this->assertSame( 'http://127.0.0.1:8099', Settings::sanitizeHubUrl( 'http://127.0.0.1:8099' ) );
		$this->assertSame( 'http://localhost', Settings::sanitizeHubUrl( 'http://localhost' ) );
		$this->assertSame( '', Settings::sanitizeHubUrl( 'http://hub.example.com' ), 'http fuera de localhost' );
		$this->assertSame( '', Settings::sanitizeHubUrl( 'https://user:pass@hub.example.com' ) );
		$this->assertSame( '', Settings::sanitizeHubUrl( 'https://hub.example.com/?x=1' ) );
		$this->assertSame( '', Settings::sanitizeHubUrl( 'ftp://hub.example.com' ) );
		$this->assertSame( '', Settings::sanitizeHubUrl( 'javascript:alert(1)' ) );
		$this->assertSame( '', Settings::sanitizeHubUrl( 'https://hub.example.com/a b' ) );
	}

	public function test_profiler_settings_are_sanitized(): void {
		$out = Settings::sanitize(
			[
				'profiler_sample_rate'     => 7,
				'profiler_intensive_until' => '123',
				'hub_url'                  => 'http://evil.example.com',
			]
		);
		$this->assertSame( 1.0, $out['profiler_sample_rate'] );
		$this->assertSame( 0, $out['profiler_intensive_until'] );
		$this->assertSame( '', $out['hub_url'] );
		$this->assertSame( 0.0, Settings::sanitize( [ 'profiler_sample_rate' => -1 ] )['profiler_sample_rate'] );
		$this->assertSame( 0.0123, Settings::sanitize( [ 'profiler_sample_rate' => '0.012345' ] )['profiler_sample_rate'] );
	}

	public function test_diag_and_bypass_cookies_are_not_interchangeable(): void {
		$bypassKey = AdminBypassCookie::key();
		$diagKey   = AdminBypassCookie::key( AdminBypassCookie::DIAG_NAME );
		$this->assertNotNull( $bypassKey );
		$this->assertNotNull( $diagKey );
		$this->assertNotSame( $bypassKey, $diagKey );

		$value = AdminBypassCookie::create( 1, time() + 600, $bypassKey );
		$this->assertTrue( AdminBypassCookie::isValidRequest( [ AdminBypassCookie::NAME => $value ] ) );
		$this->assertFalse( AdminBypassCookie::isValidRequest( [ AdminBypassCookie::DIAG_NAME => $value ], null, AdminBypassCookie::DIAG_NAME ), 'una cookie de bypass no sirve como diagnóstico' );

		$diag = AdminBypassCookie::create( 1, time() + 600, $diagKey );
		$this->assertTrue( AdminBypassCookie::isValidRequest( [ AdminBypassCookie::DIAG_NAME => $diag ], null, AdminBypassCookie::DIAG_NAME ) );
		$this->assertFalse( AdminBypassCookie::isValidRequest( [ AdminBypassCookie::NAME => $diag ] ), 'ni al revés' );
	}
}
