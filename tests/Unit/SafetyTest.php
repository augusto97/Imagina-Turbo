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
use ImaginaTurbo\Core\Safety\DataFile;
use ImaginaTurbo\Core\Safety\SafeMode;
use ImaginaTurbo\Support\Filesystem;

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
		$this->assertTrue(
			$safe->isAttributable(
				[
					'file'    => '/var/www/wp-content/plugins/otro/a.php',
					'message' => 'Uncaught Error: Call to undefined function wc_get_product()',
				],
				true
			),
			'con plugins filtrados, el error de un plugin que falta'
		);
		$this->assertTrue( $safe->isAttributable( [ 'message' => 'Uncaught Error: Class "WooCommerce" not found' ], true ) );
		$this->assertTrue( $safe->isAttributable( [ 'message' => 'Uncaught TypeError: call_user_func_array(): Argument #1 ($callback) must be a valid callback, class "X" not found' ], true ) );
		$this->assertFalse(
			$safe->isAttributable(
				[
					'file'    => '/var/www/wp-content/plugins/otro/a.php',
					'message' => 'Allowed memory size of 134217728 bytes exhausted',
				],
				true
			),
			'otro fatal provocado a propósito no apaga el plugin'
		);
		$this->assertFalse( $safe->isAttributable( [ 'message' => 'Maximum execution time of 30 seconds exceeded' ], true ) );
	}

	public function test_data_files_cannot_be_read_by_url(): void {
		$safe = new SafeMode( $this->tmp, '/plugin' );
		$safe->enter( 'fatal_errors', [ 'message' => '<?php echo "x"; ?> en <b>a.php</b>' ], 5 );
		$safe->recordFatal( [ 'type' => E_ERROR ], 10 );
		foreach ( [ SafeMode::FLAG, SafeMode::COUNTER ] as $name ) {
			$this->assertStringEndsWith( '.php', $name );
			$raw = (string) file_get_contents( $this->tmp . '/' . $name );
			$this->assertStringStartsWith( DataFile::GUARD, $raw );
			$this->assertStringNotContainsString( '<', substr( $raw, strlen( DataFile::GUARD ) ), 'sin etiquetas después de la guarda' );
			// Pedido por URL: PHP termina sin mostrar nada.
			$this->assertSame( '', (string) shell_exec( 'php ' . escapeshellarg( $this->tmp . '/' . $name ) . ' 2>&1' ) );
		}
		$this->assertSame( '<?php echo "x"; ?> en <b>a.php</b>', ( $safe->details() ?? [] )['context']['message'] ?? null, 'y se lee igual' );

		$file = $this->tmp . '/lines.php';
		$this->assertTrue( DataFile::append( $file, [ 'k' => 'a' ] ) );
		$this->assertTrue( DataFile::append( $file, [ 'k' => '?><?php phpinfo();' ] ) );
		$this->assertSame( [ [ 'k' => 'a' ], [ 'k' => '?><?php phpinfo();' ] ], DataFile::lines( $file ) );
		$this->assertSame( 1, substr_count( (string) file_get_contents( $file ), '<?php' ), 'solo la guarda' );
		$this->assertSame( '', (string) shell_exec( 'php ' . escapeshellarg( $file ) . ' 2>&1' ) );
	}

	public function test_legacy_flag_migrates(): void {
		file_put_contents( $this->tmp . '/safe-mode.flag', '{"reason":"fatal_errors","at":7,"context":[],"notified":true}' );
		file_put_contents( $this->tmp . '/fatals.json', '[1,2]' );
		$safe = new SafeMode( $this->tmp, '/plugin' );
		$this->assertFalse( $safe->isActive(), 'la 0.21.2 solo mira el nombre nuevo' );
		$safe->migrateLegacy();
		$this->assertTrue( $safe->isActive() );
		$this->assertSame( 7, ( $safe->details() ?? [] )['at'] ?? null );
		$this->assertFileDoesNotExist( $this->tmp . '/safe-mode.flag' );
		$this->assertFileDoesNotExist( $this->tmp . '/fatals.json' );
		$this->assertTrue( $safe->leave() );
	}

	public function test_data_dir_htaccess_blocks_openlitespeed(): void {
		$dir = $this->tmp . '/datos';
		mkdir( $dir );
		file_put_contents( $dir . '/.htaccess', "# Imagina Turbo: datos internos, sin acceso web.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n" );
		$this->assertTrue( Filesystem::ensureDataDir( $dir ) );
		$this->assertSame( Filesystem::DATA_HTACCESS, file_get_contents( $dir . '/.htaccess' ), 'el .htaccess propio de antes se actualiza' );
		$this->assertStringContainsString( 'RewriteRule .* - [F,L]', Filesystem::DATA_HTACCESS );

		file_put_contents( $dir . '/.htaccess', "# Reglas del administrador\nDeny from all\n" );
		Filesystem::ensureDataDir( $dir );
		$this->assertSame( "# Reglas del administrador\nDeny from all\n", file_get_contents( $dir . '/.htaccess' ), 'uno ajeno no se toca' );
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
