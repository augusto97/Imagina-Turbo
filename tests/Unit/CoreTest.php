<?php
/**
 * Contenedor, catálogo y registro de módulos, registro de eventos, utilidades de archivos.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\ModuleCatalog;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\ModuleRegistry;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\RiskLevel;
use ImaginaTurbo\Repository\EventRepository;
use ImaginaTurbo\Support\Filesystem;

final class FakeModule implements ModuleInterface {

	public static int $registered = 0;

	public function register( Container $container, RequestContext $request ): void {
		++self::$registered;
	}
}

final class BrokenModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
		throw new \RuntimeException( 'roto' );
	}
}

final class CoreTest extends TestCase {

	public function test_container_shares_instances_and_fails_loudly(): void {
		$c     = new Container();
		$calls = 0;
		$c->set(
			\ArrayObject::class,
			static function () use ( &$calls ): \ArrayObject {
				++$calls;
				return new \ArrayObject();
			}
		);
		$this->assertSame( $c->get( \ArrayObject::class ), $c->get( \ArrayObject::class ) );
		$this->assertSame( 1, $calls );
		$this->assertTrue( $c->has( \ArrayObject::class ) );

		$this->expectException( \LogicException::class );
		$c->get( \SplStack::class );
	}

	public function test_container_type_check(): void {
		$c = new Container();
		$c->set( \SplStack::class, static fn(): \ArrayObject => new \ArrayObject() );
		$this->expectException( \LogicException::class );
		$c->get( \SplStack::class );
	}

	public function test_catalog_respects_rule_3(): void {
		foreach ( ( new ModuleCatalog() )->all() as $definition ) {
			if ( $definition->defaultEnabled ) {
				$this->assertSame( RiskLevel::Safe, $definition->risk, $definition->id . ' no es seguro y viene activo' );
			}
		}
		$this->assertSame( RiskLevel::Invasive, ( new ModuleCatalog() )->get( 'plugin_filter' )?->risk );
		$this->assertFalse( ( new ModuleCatalog() )->get( 'cache_coordinator' )?->defaultEnabled, 'docs/02: inactivo por defecto' );
	}

	public function test_states_combine_settings_availability_and_overlap(): void {
		$catalog = new ModuleCatalog();
		$states  = ModuleRegistry::states( $catalog, [ 'shield' => false ], [ 'tweaks' => [ 'Se solapa con perfmatters' ] ] );

		$this->assertTrue( $states['profiler']['requested'], 'seguro y activo por defecto' );
		$this->assertFalse( $states['profiler']['enabled'], 'aún no implementado' );
		$this->assertFalse( $states['shield']['requested'] );
		$this->assertSame( [ 'Se solapa con perfmatters' ], $states['tweaks']['forcedOff'] );
		$this->assertFalse( $states['woo_cart']['requested'] );
	}

	public function test_registry_boots_only_enabled_and_survives_broken_modules(): void {
		$previous               = ini_set( 'error_log', '/dev/null' );
		FakeModule::$registered = 0;
		$config                 = CompiledConfig::fromArray(
			[
				'v'       => 1,
				'hash'    => 'x',
				'modules' => [
					'fake'   => true,
					'broken' => true,
					'off'    => false,
					'nada'   => true,
				],
			]
		);
		$this->assertNotNull( $config );
		$registry = new ModuleRegistry(
			[
				'fake'   => FakeModule::class,
				'broken' => BrokenModule::class,
				'off'    => FakeModule::class,
			]
		);
		$request = new RequestContext( RequestType::FrontendHtml, '', '/', 'GET' );

		$this->assertSame( [ 'fake' ], $registry->boot( new Container(), $config, $request ) );
		$registry->boot( new Container(), $config, $request );
		$this->assertSame( 1, FakeModule::$registered, 'no se registra dos veces' );
		ini_set( 'error_log', (string) $previous );
	}

	public function test_event_log_redacts_and_never_throws(): void {
		$this->assertSame(
			[
				'token' => '[oculto]',
				'deep'  => [ 'Password' => '[oculto]' ],
				'ok'    => 1,
				'obj'   => \stdClass::class,
			],
			EventLog::redact(
				[
					'token' => 'secreto',
					'deep'  => [ 'Password' => 'x' ],
					'ok'    => 1,
					'obj'   => new \stdClass(),
				]
			)
		);

		$wpdb = \Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'insert' )->andThrow( new \RuntimeException( 'db caída' ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$log = new EventLog( new EventRepository( $wpdb ) );
		$this->assertSame( 0, $log->info( 'core', 'x', 'mensaje', [ 'a' => 1 ] ) );
	}

	public function test_event_log_inserts_clamped_row(): void {
		$wpdb            = \Mockery::mock( \wpdb::class );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 42;
		$wpdb->shouldReceive( 'insert' )->once()->withArgs(
			static function ( string $table, array $row ): bool {
				return 'wp_itb_events' === $table
					&& 2 === $row['severity']
					&& 255 === mb_strlen( $row['message'] )
					&& null === $row['context'];
			}
		)->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$log = new EventLog( new EventRepository( $wpdb ) );
		$this->assertSame( 42, $log->log( 'core', 'x', 99, str_repeat( 'á', 400 ) ) );
	}

	public function test_atomic_write_with_validator(): void {
		$file = $this->tmp . '/f.txt';
		$this->assertTrue( Filesystem::atomicWrite( $file, 'uno' ) );
		$this->assertFalse( Filesystem::atomicWrite( $file, 'dos', static fn(): bool => false ) );
		$this->assertSame( 'uno', file_get_contents( $file ), 'un validador que falla no reemplaza' );
		$this->assertSame( [ $file ], glob( $this->tmp . '/{,.}*', GLOB_BRACE ) ? array_values( array_filter( glob( $this->tmp . '/{,.}*', GLOB_BRACE ), 'is_file' ) ) : [] );
		$this->assertFalse( Filesystem::atomicWrite( $this->tmp . '/no/existe.txt', 'x' ) );
	}

	public function test_remove_dir_only_inside_base(): void {
		mkdir( $this->tmp . '/base/data/sub', 0755, true );
		file_put_contents( $this->tmp . '/base/data/sub/a', 'x' );
		mkdir( $this->tmp . '/fuera' );

		$this->assertFalse( Filesystem::removeDir( $this->tmp . '/fuera', $this->tmp . '/base' ) );
		$this->assertDirectoryExists( $this->tmp . '/fuera' );
		$this->assertFalse( Filesystem::removeDir( $this->tmp . '/base', $this->tmp . '/base' ), 'nunca la base misma' );
		$this->assertTrue( Filesystem::removeDir( $this->tmp . '/base/data', $this->tmp . '/base' ) );
		$this->assertDirectoryDoesNotExist( $this->tmp . '/base/data' );
	}
}
