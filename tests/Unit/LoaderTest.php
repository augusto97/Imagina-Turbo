<?php
/**
 * Loader MU y etapa temprana, en procesos aislados como en un request real.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\MuLoaderInstaller;
use ImaginaTurbo\Core\Safety\AdminBypassCookie;
use ImaginaTurbo\Core\Safety\SafeMode;

final class LoaderTest extends TestCase {

	/**
	 * @param list<string> $args Argumentos.
	 * @return array{code: int, out: string}
	 */
	private static function php( string $script, array $args ): array {
		$cmd = escapeshellarg( PHP_BINARY ) . ' -d display_errors=stderr -d log_errors=0 ' . escapeshellarg( $script );
		foreach ( $args as $arg ) {
			$cmd .= ' ' . escapeshellarg( $arg );
		}
		exec( $cmd . ' 2>/dev/null', $out, $code );
		return [
			'code' => $code,
			'out'  => implode( "\n", $out ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function early( string $mode = 'ok', string $uri = '/', array $cookies = [] ): array {
		$result = self::php( self::pluginDir() . '/tests/fixtures/early-harness.php', [ $this->tmp, $mode, $uri, (string) json_encode( $cookies ) ] );
		$line   = strtok( $result['out'], "\n" );
		$data   = json_decode( false === $line ? '' : $line, true );
		$this->assertIsArray( $data, $result['out'] );
		return $data;
	}

	private function writeConfig( array $extra = [] ): void {
		$data         = array_merge(
			[
				'v'     => CompiledConfig::FORMAT_VERSION,
				'hash'  => 'h',
				'paths' => [ 'cart' => [ '/carrito' ] ],
			],
			$extra
		);
		file_put_contents( $this->tmp . '/config.php', ConfigCompiler::render( $data, '0.1.0' ) );
	}

	public function test_early_stage_without_config_does_nothing(): void {
		$this->assertSame(
			[
				'kill'   => null,
				'safe'   => false,
				'config' => false,
				'type'   => null,
			],
			$this->early()
		);
	}

	public function test_early_stage_classifies_with_config(): void {
		$this->writeConfig();
		$state = $this->early( 'ok', '/carrito/' );
		$this->assertTrue( $state['config'] );
		$this->assertSame( 'Cart', $state['type'] );
	}

	public function test_kill_switch_file_and_cookie(): void {
		$this->writeConfig();
		touch( $this->tmp . '/disable' );
		$this->assertSame( 'file', $this->early()['kill'] );
		unlink( $this->tmp . '/disable' );

		$cookie = AdminBypassCookie::create( 1, time() + 600, (string) AdminBypassCookie::key() );
		$state  = $this->early( 'ok', '/', [ 'itb_bypass' => $cookie ] );
		$this->assertSame( 'cookie', $state['kill'] );
		$this->assertFalse( $state['config'], 'con kill switch no se carga nada' );

		$this->assertNull( $this->early( 'ok', '/', [ 'itb_bypass' => '1|2|x' ] )['kill'], 'cookie falsa' );
	}

	public function test_three_fatals_enable_safe_mode(): void {
		$this->writeConfig();
		$this->early( 'fatal' );
		$this->early( 'fatal' );
		$this->assertFileDoesNotExist( $this->tmp . '/' . SafeMode::FLAG, 'dos fatales no bastan' );
		$this->early( 'fatal' );
		$this->assertFileExists( $this->tmp . '/' . SafeMode::FLAG );

		$details = ( new SafeMode( $this->tmp, self::pluginDir() ) )->details();
		$this->assertSame( 'fatal_errors', $details['reason'] ?? null );
		$this->assertStringContainsString( 'itb_funcion_que_no_existe', (string) ( $details['context']['message'] ?? '' ) );

		$state = $this->early();
		$this->assertTrue( $state['safe'] );
		$this->assertFalse( $state['config'], 'en modo seguro no se aplica la config' );
	}

	public function test_orphan_loader_is_silent(): void {
		$template = (string) file_get_contents( self::pluginDir() . '/mu-loader/' . MuLoaderInstaller::FILENAME );
		$loader   = $this->tmp . '/loader.php';
		$runner   = $this->tmp . '/runner.php';

		foreach ( [ 'plugin-borrado' => 'false', basename( self::pluginDir() ) => 'true' ] as $dirname => $expected ) {
			file_put_contents( $loader, str_replace( [ '__ITB_PLUGIN_DIRNAME__', '__ITB_LOADER_VERSION__' ], [ $dirname, '1' ], $template ) );
			file_put_contents(
				$runner,
				'<?php define( "ABSPATH", "/tmp/" ); define( "WP_PLUGIN_DIR", ' . var_export( dirname( self::pluginDir() ), true ) . ' ); define( "ITB_DATA_DIR", ' . var_export( $this->tmp . '/datos', true ) . ' );'
				. ' require ' . var_export( $loader, true ) . ';'
				. ' echo class_exists( "ImaginaTurbo\\\\Core\\\\Runtime", false ) && \\ImaginaTurbo\\Core\\Runtime::$fromMuLoader ? "true" : "false";'
			);
			$result = self::php( $runner, [] );
			$this->assertSame( 0, $result['code'], $dirname );
			$this->assertSame( $expected, $result['out'], $dirname );
		}
	}

	public function test_mu_installer_lifecycle(): void {
		Functions\when( 'wp_mkdir_p' )->alias( static fn( string $d ): bool => is_dir( $d ) || mkdir( $d, 0755, true ) );
		Functions\when( 'wp_delete_file' )->alias( 'unlink' );
		Functions\when( 'wp_is_writable' )->alias( 'is_writable' );

		$mu        = $this->tmp . '/mu-plugins';
		$installer = new MuLoaderInstaller( self::pluginDir(), $mu );

		$this->assertFalse( $installer->isCurrent() );
		$this->assertTrue( $installer->install() );
		$this->assertTrue( $installer->isCurrent() );
		$contents = (string) file_get_contents( $installer->target() );
		$this->assertStringContainsString( "/" . basename( self::pluginDir() ) . "';", $contents );
		$this->assertStringNotContainsString( '__ITB_', $contents );
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $installer->target() ), $out, $code );
		$this->assertSame( 0, $code );

		$status = $installer->status();
		$this->assertTrue( $status['installed'] );
		$this->assertTrue( $status['current'] );

		$this->assertTrue( $installer->uninstall() );
		$this->assertFileDoesNotExist( $installer->target() );
		$this->assertTrue( $installer->uninstall(), 'idempotente' );
	}

	public function test_mu_installer_never_touches_foreign_file(): void {
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'wp_delete_file' )->alias( 'unlink' );

		$mu = $this->tmp . '/mu';
		mkdir( $mu );
		file_put_contents( $mu . '/' . MuLoaderInstaller::FILENAME, "<?php // de otro\n" );
		$installer = new MuLoaderInstaller( self::pluginDir(), $mu );

		$this->assertFalse( $installer->install() );
		$this->assertFalse( $installer->uninstall() );
		$this->assertSame( "<?php // de otro\n", file_get_contents( $mu . '/' . MuLoaderInstaller::FILENAME ) );
	}

	public function test_mu_installer_rejects_weird_dirnames(): void {
		$installer = new MuLoaderInstaller( $this->tmp . "/con'comilla", $this->tmp . '/mu' );
		$this->assertSame( '', $installer->render() );
	}
}
