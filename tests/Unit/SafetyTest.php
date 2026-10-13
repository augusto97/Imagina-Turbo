<?php
/**
 * Kill switch, cookie de bypass y modo seguro.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use ImaginaTurbo\Core\EarlyLoader;
use ImaginaTurbo\Core\Safety\AdminBypassCookie;
use ImaginaTurbo\Core\Safety\KillSwitch;
use ImaginaTurbo\Core\Safety\SafeMode;

final class SafetyTest extends TestCase {

	public function test_bypass_cookie_roundtrip(): void {
		$key    = (string) AdminBypassCookie::key();
		$now    = 1_700_000_000;
		$cookie = AdminBypassCookie::create( 7, $now + 3600, $key );
		$this->assertSame( 7, AdminBypassCookie::validate( $cookie, $key, $now ) );
	}

	public function test_bypass_cookie_rejects_tampering_and_expiry(): void {
		$key    = (string) AdminBypassCookie::key();
		$now    = 1_700_000_000;
		$cookie = AdminBypassCookie::create( 7, $now + 3600, $key );

		$this->assertNull( AdminBypassCookie::validate( str_replace( '7|', '8|', $cookie ), $key, $now ), 'otro usuario' );
		$this->assertNull( AdminBypassCookie::validate( $cookie, $key, $now + 3601 ), 'expirada' );
		$this->assertNull( AdminBypassCookie::validate( $cookie, 'otra-clave', $now ), 'otra clave' );
		$this->assertNull( AdminBypassCookie::validate( AdminBypassCookie::create( 7, $now + 86400, $key ), $key, $now ), 'más de 8 h' );
		$this->assertNull( AdminBypassCookie::validate( AdminBypassCookie::create( 0, $now + 60, $key ), $key, $now ), 'usuario 0' );
		foreach ( [ null, 5, [], '', 'a|b|c', '1|2', str_repeat( 'x', 500 ), '1|99999999999|' . str_repeat( 'a', 64 ) ] as $bad ) {
			$this->assertNull( AdminBypassCookie::validate( $bad, $key, $now ) );
		}
	}

	public function test_bypass_cookie_from_request(): void {
		$key = (string) AdminBypassCookie::key();
		$this->assertTrue( AdminBypassCookie::isValidRequest( [ 'itb_bypass' => AdminBypassCookie::create( 1, time() + 60, $key ) ] ) );
		$this->assertFalse( AdminBypassCookie::isValidRequest( [] ) );
		$this->assertFalse( AdminBypassCookie::isValidRequest( [ 'itb_bypass' => 'falsa' ] ) );
	}

	public function test_kill_switch_reasons(): void {
		$this->assertNull( KillSwitch::reason( $this->tmp, [] ) );

		$this->assertTrue( KillSwitch::setFile( $this->tmp, true ) );
		$this->assertSame( KillSwitch::REASON_FILE, KillSwitch::reason( $this->tmp, [] ) );
		$this->assertFalse( KillSwitch::setFile( $this->tmp, false ) );
		$this->assertNull( KillSwitch::reason( $this->tmp, [] ) );

		$cookie = AdminBypassCookie::create( 3, time() + 60, (string) AdminBypassCookie::key() );
		$this->assertSame( KillSwitch::REASON_COOKIE, KillSwitch::reason( $this->tmp, [ 'itb_bypass' => $cookie ] ) );
		$this->assertNull( KillSwitch::reason( $this->tmp, [ 'itb_bypass' => 'x' ] ) );
	}

	public function test_kill_switch_file_needs_existing_dir(): void {
		$this->assertFalse( KillSwitch::setFile( $this->tmp . '/no-existe', true ) );
	}

	public function test_safe_mode_threshold_and_window(): void {
		$safe  = new SafeMode( $this->tmp, '/plugin', 300, 3 );
		$error = [
			'type'    => E_ERROR,
			'message' => 'Uncaught Error',
			'file'    => '/plugin/src/x.php',
			'line'    => 10,
		];

		$this->assertSame( 1, $safe->recordFatal( $error, 1000 ) );
		$this->assertSame( 2, $safe->recordFatal( $error, 1100 ) );
		$this->assertFalse( $safe->isActive() );
		// El primero ya salió de la ventana: siguen siendo 2.
		$this->assertSame( 2, $safe->recordFatal( $error, 1350 ) );
		$this->assertFalse( $safe->isActive() );
		$this->assertSame( 3, $safe->recordFatal( $error, 1360 ) );
		$this->assertTrue( $safe->isActive() );

		$details = $safe->details();
		$this->assertIsArray( $details );
		$this->assertSame( 'fatal_errors', $details['reason'] );
		$this->assertFalse( $details['notified'] );
		$this->assertTrue( $safe->markNotified() );
		$this->assertTrue( ( $safe->details() ?? [] )['notified'] );

		$this->assertTrue( $safe->leave() );
		$this->assertFalse( $safe->isActive() );
		$this->assertFileDoesNotExist( $this->tmp . '/' . SafeMode::COUNTER );
	}

	public function test_corrupt_counter_is_reset(): void {
		file_put_contents( $this->tmp . '/' . SafeMode::COUNTER, '{basura' );
		$safe = new SafeMode( $this->tmp, '/plugin' );
		$this->assertSame( 1, $safe->recordFatal( [ 'type' => E_ERROR ], 10 ) );
	}

	public function test_attribution(): void {
		$safe = new SafeMode( $this->tmp, '/var/www/wp-content/plugins/imagina-turbo' );
		$this->assertTrue( $safe->isAttributable( [ 'file' => '/var/www/wp-content/plugins/imagina-turbo/src/a.php' ], false ) );
		$this->assertFalse( $safe->isAttributable( [ 'file' => '/var/www/wp-content/plugins/imagina-turbo-extra/a.php' ], false ), 'prefijo no basta' );
		$this->assertFalse( $safe->isAttributable( [ 'file' => '/var/www/wp-content/plugins/otro/a.php' ], false ) );
		$this->assertTrue( $safe->isAttributable( [ 'file' => '/var/www/wp-content/plugins/otro/a.php' ], true ), 'con plugins filtrados cuenta todo' );
	}

	public function test_fatal_types(): void {
		$this->assertTrue( SafeMode::isFatal( [ 'type' => E_ERROR ] ) );
		$this->assertTrue( SafeMode::isFatal( [ 'type' => E_PARSE ] ) );
		$this->assertFalse( SafeMode::isFatal( [ 'type' => E_WARNING ] ) );
		$this->assertFalse( SafeMode::isFatal( [ 'type' => E_DEPRECATED ] ) );
		$this->assertFalse( SafeMode::isFatal( null ) );
	}

	public function test_early_loader_constant_matches(): void {
		$this->assertSame( SafeMode::FLAG, EarlyLoader::SAFE_MODE_FLAG );
		$this->assertSame( AdminBypassCookie::NAME, KillSwitch::COOKIE );
	}

	public function test_enter_requires_dir(): void {
		$this->assertFalse( ( new SafeMode( $this->tmp . '/nada', '/p' ) )->enter( 'x' ) );
	}
}
