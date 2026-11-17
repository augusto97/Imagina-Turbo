<?php
/**
 * 0.25.0: perfil de hosting compartido (detección, cron, loopback) y recalentado ante los límites.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Environment\HostingProfile;
use ImaginaTurbo\Modules\CacheCoordinator\Warmer;
use ImaginaTurbo\Repository\PurgeQueueRepository;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

final class HostingProfileTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	/** @var list<array{id: int, kind: string, item: string}> */
	private array $queue = [];

	private int $nextId = 1;

	/** @var list<int> */
	private array $scheduled = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options   = [];
		$this->queue     = [];
		$this->scheduled = [];
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $at ): bool {
				$this->scheduled[] = $at - time();
				return true;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $r ): int => (int) $r['response']['code'] );
	}

	/**
	 * @param array<string, mixed> $over Cambios.
	 * @return array{path: string, uname: string, open_basedir: string, disabled: list<string>, cloudlinux_file: bool, cpanel_dir: bool, memory_limit: string, max_execution_time: int}
	 */
	private static function facts( array $over = [] ): array {
		return array_merge(
			[
				'path'               => '/srv/www/sitio/',
				'uname'              => '6.1.0-13-amd64',
				'open_basedir'       => '',
				'disabled'           => [],
				'cloudlinux_file'    => false,
				'cpanel_dir'         => false,
				'memory_limit'       => '512M',
				'max_execution_time' => 0,
			],
			$over
		);
	}

	public function test_detection(): void {
		$vps = HostingProfile::evaluate( self::facts() );
		$this->assertFalse( $vps['shared'] );
		$this->assertSame( [ '', [], [] ], [ $vps['panel'], $vps['signals'], $vps['restrictions'] ] );

		$cpanel = HostingProfile::evaluate( self::facts( [ 'path' => '/home2/tienda/public_html/' ] ) );
		$this->assertSame( 'cpanel', $cpanel['panel'] );
		$this->assertTrue( $cpanel['shared'] );
		$this->assertTrue(
			HostingProfile::evaluate(
				self::facts(
					[
						'path'       => '/home/tienda/otra.com/',
						'cpanel_dir' => true,
					]
				)
			)['shared'],
			'dominio adicional fuera de public_html'
		);
		$this->assertSame( 'directadmin', HostingProfile::evaluate( self::facts( [ 'path' => '/home/user/domains/x.com/public_html/' ] ) )['panel'] );

		// Plesk, Cloudways y ServerAvatar no son compartidos por la ruta.
		$plesk = HostingProfile::evaluate( self::facts( [ 'path' => '/var/www/vhosts/x.com/httpdocs/' ] ) );
		$this->assertSame( [ 'plesk', false ], [ $plesk['panel'], $plesk['shared'] ] );
		$this->assertFalse( HostingProfile::evaluate( self::facts( [ 'path' => '/home/master/applications/abc/public_html/' ] ) )['shared'] );
		$this->assertFalse( HostingProfile::evaluate( self::facts( [ 'path' => '/home/sitio/apps/tienda/public_html/' ] ) )['shared'] );

		$lve = HostingProfile::evaluate( self::facts( [ 'uname' => '4.18.0-477.27.1.lve.el8.x86_64' ] ) );
		$this->assertTrue( $lve['cloudlinux'] && $lve['shared'] );
		$this->assertTrue( HostingProfile::evaluate( self::facts( [ 'cloudlinux_file' => true ] ) )['cloudlinux'] );

		$limits = HostingProfile::evaluate(
			self::facts(
				[
					'open_basedir'       => '/home/x:/tmp',
					'disabled'           => [ 'exec', 'sys_getloadavg', 'mail' ],
					'memory_limit'       => '128M',
					'max_execution_time' => 30,
				]
			)
		);
		$this->assertCount( 4, $limits['restrictions'] );
		$this->assertStringContainsString( 'sys_getloadavg, exec', $limits['restrictions'][1] );
		$this->assertStringNotContainsString( 'mail', $limits['restrictions'][1], 'solo las que usa el plugin' );
		$this->assertFalse( $limits['shared'], 'las restricciones solas no hacen compartido un servidor' );
		$this->assertCount( 0, HostingProfile::evaluate( self::facts( [ 'memory_limit' => '-1' ] ) )['restrictions'] );
		$this->assertCount( 0, HostingProfile::evaluate( self::facts( [ 'memory_limit' => '1G' ] ) )['restrictions'] );

		$this->assertSame( 'shared', HostingProfile::effective( 'auto', true ) );
		$this->assertSame( 'dedicated', HostingProfile::effective( 'auto', false ) );
		$this->assertSame( 'dedicated', HostingProfile::effective( 'dedicated', true ), 'el ajuste manual manda' );
		$this->assertSame( 'shared', HostingProfile::effective( 'shared', false ) );
	}

	public function test_cron_commands_and_alerts(): void {
		$cmd = HostingProfile::cronCommands( 'https://tienda.com/', '/home/t/public_html/' );
		$this->assertSame( '*/5 * * * * curl -fsS https://tienda.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1', $cmd['http'] );
		$this->assertSame( '*/5 * * * * cd /home/t/public_html && php -q wp-cron.php >/dev/null 2>&1', $cmd['php'] );
		$this->assertStringContainsString( 'Trabajos de cron', HostingProfile::cronSteps( 'cpanel' ) );

		$now = 100000;
		$this->assertSame( [], HostingProfile::cronAlerts( false, false, 0, $now, $now, '' ), 'sin HTML en el edge, WP-Cron con visitas basta' );
		$visits = HostingProfile::cronAlerts( true, false, 0, $now, $now, 'cpanel' );
		$this->assertSame( 'cron_visits', $visits[0]['code'] );
		$this->assertStringContainsString( 'cPanel', $visits[0]['message'] );

		$this->assertSame( [], HostingProfile::cronAlerts( true, true, $now - 600, $now - 9000, $now, '' ), 'la tarea del sistema corre' );
		$stalled = HostingProfile::cronAlerts( false, true, $now - 2400, $now - 9000, $now, '' );
		$this->assertSame( [ 'error', 'cron_stalled' ], [ $stalled[0]['level'], $stalled[0]['code'] ] );
		$this->assertStringContainsString( '40 minutos', $stalled[0]['message'] );
		$this->assertSame( 'cron_stalled', HostingProfile::cronAlerts( false, true, 0, $now - 7200, $now, '' )[0]['code'], 'nunca se vio correr desde que se vigila' );
		$this->assertSame( [], HostingProfile::cronAlerts( false, true, 0, $now - 600, $now, '' ), 'se vigila desde hace poco' );
	}

	public function test_loopback_classification(): void {
		$s = static fn( string $id, int $code, array $headers = [], string $body = '', string $error = '' ): string => HostingProfile::classify( $id, $code, $headers, $body, $error )['status'];
		$this->assertSame( 'ok', $s( 'home', 200 ) );
		$this->assertSame( 'ok', $s( 'ajax', 400, [], '0' ), 'admin-ajax sin acción: WordPress respondió' );
		$this->assertSame( 'error', $s( 'home', 400 ) );
		$this->assertSame( 'ok', $s( 'rest', 401, [], '{"code":"rest_cannot_access"}' ), 'la REST cerrada a anónimos también es WordPress' );
		$this->assertSame( 'auth', $s( 'home', 401 ) );
		$this->assertSame( 'connection', $s( 'home', 0, [], '', 'cURL error 7' ) );
		$this->assertStringContainsString( 'cURL error 7', HostingProfile::classify( 'home', 0, [], '', 'cURL error 7' )['message'] );
		$this->assertSame( 'redirect', $s( 'home', 301, [ 'location' => 'https://www.x.com/' ] ) );
		$this->assertSame( 'limit', $s( 'home', 508 ) );
		$this->assertSame( 'busy', $s( 'home', 503 ) );
		$this->assertSame( 'busy', $s( 'home', 429 ) );
		$this->assertSame( 'forbidden', $s( 'home', 403 ) );
		$this->assertSame( 'forbidden', $s( 'home', 403, [ 'cf-ray' => 'x' ] ), 'un 403 que pasó por Cloudflare no se le atribuye sin cf-mitigated' );
		$this->assertStringContainsString( 'Cloudflare', HostingProfile::classify( 'home', 403, [ 'cf-ray' => 'x' ], '', '' )['message'] );
		$this->assertSame(
			'cloudflare',
			$s(
				'home',
				403,
				[
					'cf-ray'       => 'x',
					'cf-mitigated' => 'challenge',
				]
			)
		);
		$this->assertSame( 'error', $s( 'home', 500 ) );

		foreach ( [ 'connection', 'forbidden', 'cloudflare', 'auth', 'limit', 'busy', 'redirect' ] as $status ) {
			$this->assertNotSame( '', HostingProfile::advice( $status, '203.0.113.5' ), $status );
		}
		$this->assertStringContainsString( '203.0.113.5', HostingProfile::advice( 'forbidden', '203.0.113.5' ) );
		$this->assertSame( [ 'home', 'rest', 'ajax', 'cron' ], array_keys( HostingProfile::affects() ) );
	}

	/**
	 * Cola en memoria con la forma del repositorio real.
	 */
	private function repository(): PurgeQueueRepository {
		$wpdb         = \Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $sql, ...$args ): string => (string) wp_json_encode( [ $sql, is_array( $args[0] ?? null ) ? $args[0] : $args ] ) );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( string $prepared ): array {
				[ $sql, $args ] = json_decode( $prepared, true );
				if ( str_contains( $sql, 'COUNT' ) ) {
					$counts = [];
					foreach ( $this->queue as $row ) {
						$counts[ $row['kind'] ] = ( $counts[ $row['kind'] ] ?? 0 ) + 1;
					}
					return array_map(
						static fn( string $k, int $n ): array => [
							'kind' => $k,
							'n'    => $n,
						],
						array_keys( $counts ),
						$counts
					);
				}
				$limit = (int) end( $args );
				$kinds = array_slice( $args, 1, -1 );
				$rows  = array_values( array_filter( $this->queue, static fn( array $r ): bool => in_array( $r['kind'], $kinds, true ) ) );
				return array_slice( $rows, 0, $limit );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $prepared ): int {
				[ $sql, $args ] = json_decode( $prepared, true );
				if ( str_starts_with( $sql, 'DELETE' ) ) {
					$ids         = array_slice( $args, 1 );
					$this->queue = array_values( array_filter( $this->queue, static fn( array $r ): bool => ! in_array( $r['id'], $ids, true ) ) );
					return count( $ids );
				}
				$added = 0;
				for ( $i = 1; $i < count( $args ); $i += 4 ) {
					$this->queue[] = [
						'id'   => $this->nextId++,
						'kind' => $args[ $i ],
						'item' => $args[ $i + 2 ],
					];
					++$added;
				}
				return $added;
			}
		);
		return new PurgeQueueRepository( $wpdb );
	}

	/**
	 * @param list<int> $codes Códigos de respuesta, en orden.
	 */
	private function respond( array $codes ): void {
		Functions\when( 'wp_remote_get' )->alias(
			static function () use ( &$codes ): array {
				return [ 'response' => [ 'code' => (int) array_shift( $codes ) ] ];
			}
		);
	}

	public function test_warmer_pauses_when_the_host_limits(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$repo = $this->repository();
		$repo->add( PurgeQueueRepository::KIND_WARM, [ 'https://t.com/a/', 'https://t.com/b/', 'https://t.com/c/' ] );
		$this->respond( [ 200, 508, 200 ] );
		$warmer = new Warmer( $repo, [], [], [] );
		$this->assertSame( 1, $warmer->run( 0.1, 4 ) );
		$this->assertSame( [ 'https://t.com/b/', 'https://t.com/c/' ], array_column( $this->queue, 'item' ), 'lo que no se pidió vuelve a la cola' );
		$state = $warmer->state();
		$this->assertSame( 508, $state['limited_code'] );
		$this->assertGreaterThan( 0, $state['limited_at'] );
		$this->assertEqualsWithDelta( Warmer::LIMIT_PAUSE, $this->scheduled[0], 2, 'pausa de 15 minutos' );

		// Compartido: la carga del servidor no se mira, lotes de 3, separados 30 s, y el doble de pausa.
		$this->queue     = [];
		$this->scheduled = [];
		$repo->add( PurgeQueueRepository::KIND_WARM, array_map( static fn( int $i ): string => 'https://t.com/p-' . $i . '/', range( 1, 5 ) ) );
		$this->respond( [ 200, 200, 200 ] );
		$shared = new Warmer( $repo, [], [], [], true );
		$this->assertSame( 3, $shared->run( 99.0, 1 ), 'una carga alta de todo el servidor no lo frena' );
		$this->assertCount( 2, $this->queue );
		$this->assertEqualsWithDelta( Warmer::SHARED_GAP, $this->scheduled[0], 2 );
		$this->assertTrue( $shared->state()['shared'] );

		$this->scheduled = [];
		$this->respond( [ 503 ] );
		$this->assertSame( 0, $shared->run() );
		$this->assertEqualsWithDelta( 2 * Warmer::LIMIT_PAUSE, $this->scheduled[0], 2 );
		$this->assertSame( 503, $shared->state()['limited_code'] );

		// En un VPS la carga sigue mandando.
		$this->scheduled = [];
		$this->assertSame( 0, ( new Warmer( $repo, [], [], [] ) )->run( 99.0, 2 ) );
		$this->assertTrue( Warmer::slow( 3500.0, true ), 'en compartido, 3 s ya es lento' );
		$this->assertFalse( Warmer::slow( 3500.0 ) );
	}
}
