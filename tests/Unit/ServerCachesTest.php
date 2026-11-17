<?php
/**
 * 0.24.0: Varnish y la caché de nginx delante de WordPress.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;
use ImaginaTurbo\Modules\CacheCoordinator\ServerCaches;

require_once __DIR__ . '/Stubs/ServerCachePlugins.php';

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $nginx_purger es el global de Nginx Helper que se imita.

final class ServerCachesTest extends TestCase {

	/** @var array<string, callable> */
	private array $hooks = [];

	/** @var list<array{url: string, args: array<string, mixed>}> */
	private array $requests = [];

	/** @var array<string, mixed> */
	private array $siteOptions = [];

	private int $code = 200;

	protected function setUp(): void {
		parent::setUp();
		\VarnishPurger::$calls       = [];
		\Breeze_PurgeVarnish::$calls = [];
		$this->hooks                 = [];
		$this->requests              = [];
		$this->siteOptions           = [ 'rt_wp_nginx_helper_options' => [ 'enable_purge' => 1 ] ];
		$this->code                  = 200;
		$GLOBALS['nginx_purger']     = null;
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://tienda.example.com' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- doble de wp_parse_url.
		Functions\when( 'get_site_option' )->alias( fn( string $name, $default = false ) => $this->siteOptions[ $name ] ?? $default );
		Functions\when( 'add_action' )->alias(
			function ( string $hook, callable $callback ): bool {
				$this->hooks[ $hook ] = $callback;
				return true;
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ): array {
				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];
				return [ 'response' => [ 'code' => $this->code ] ];
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $r ): int => (int) $r['response']['code'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['nginx_purger'] );
		parent::tearDown();
	}

	private static function config( string $varnish = '', string $nginx = '', string $address = '' ): array {
		return [
			'varnish'         => $varnish,
			'varnish_address' => $address,
			'nginx'           => $nginx,
		];
	}

	/**
	 * Doble del purgador de Nginx Helper (global $nginx_purger).
	 */
	private function nginxPurger(): object {
		$test                    = $this;
		$GLOBALS['nginx_purger'] = new class( $test ) {
			/** @var list<string> */
			public array $urls = [];

			public int $all = 0;

			public function __construct( private readonly ServerCachesTest $test ) {
			}

			public function purge_url( $url, $feed = true ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- API de Nginx Helper.
				$this->urls[] = $url . ( $feed ? '+feed' : '' );
			}

			public function purge_all(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- API de Nginx Helper.
				++$this->all;
				// Nginx Helper avisa al terminar: si lo vació Imagina Turbo, no se replica.
				$this->test->fire( 'rt_nginx_helper_after_fastcgi_purge_all' );
			}
		};
		return $GLOBALS['nginx_purger'];
	}

	public function fire( string $hook, mixed ...$args ): void {
		if ( isset( $this->hooks[ $hook ] ) ) {
			( $this->hooks[ $hook ] )( ...$args );
		}
	}

	public function test_address_validation(): void {
		$this->assertSame( '127.0.0.1', ServerCaches::address( ' 127.0.0.1 ' ) );
		$this->assertSame( '127.0.0.1:6081', ServerCaches::address( '127.0.0.1:6081' ) );
		$this->assertSame( '[::1]:6081', ServerCaches::address( '[0:0::1]:6081' ) );
		$this->assertSame( '[2001:db8::1]', ServerCaches::address( '[2001:DB8::1]' ) );
		foreach ( [ 'varnish.local', 'http://127.0.0.1', '127.0.0.1/purge', '127.0.0.1:70000', '127.0.0.1:0', '::1:6081', '999.1.1.1', '', '127.0.0.1:80 x' ] as $bad ) {
			$this->assertNull( ServerCaches::address( $bad ), $bad );
		}
	}

	public function test_settings_and_resolve(): void {
		$this->assertSame(
			[
				'varnish'         => 'auto',
				'varnish_address' => '',
				'nginx'           => 'auto',
			],
			ServerCaches::settings( [ 'varnish' => 'otra' ] )
		);
		$this->assertSame( '', ServerCaches::settings( [ 'varnish_address' => 'host.local' ] )['varnish_address'] );

		$pcp    = [ ServerCaches::PCP_FILE ];
		$breeze = [ ServerCaches::BREEZE_FILE ];
		$this->assertSame( 'proxy-cache-purge', ServerCaches::resolve( [], array_merge( $pcp, $breeze ) )['varnish'], 'Proxy Cache Purge antes que Breeze' );
		$this->assertSame( 'breeze', ServerCaches::resolve( [], $breeze )['varnish'] );
		$this->assertSame( '', ServerCaches::resolve( [], [] )['varnish'], 'sin plugin, automático no coordina' );
		$this->assertSame( '', ServerCaches::resolve( [ 'varnish' => 'off' ], $pcp )['varnish'] );
		$this->assertSame( '', ServerCaches::resolve( [ 'varnish' => 'direct' ], [] )['varnish'], 'directo sin dirección no coordina' );
		$direct = ServerCaches::resolve(
			[
				'varnish'         => 'direct',
				'varnish_address' => '10.0.0.5:6081',
			],
			$pcp
		);
		$this->assertSame( [ 'direct', '10.0.0.5:6081' ], [ $direct['varnish'], $direct['varnish_address'] ] );
		$this->assertSame( 'nginx-helper', ServerCaches::resolve( [], [ ServerCaches::NGINX_HELPER_FILE ] )['nginx'] );
		$this->assertSame( '', ServerCaches::resolve( [ 'nginx' => 'off' ], [ ServerCaches::NGINX_HELPER_FILE ] )['nginx'] );

		Functions\when( 'get_option' )->justReturn( [] );
		$this->assertSame( 'direct', Settings::sanitize( [ 'server_cache' => [ 'varnish' => 'direct' ] ] )['server_cache']['varnish'] );
		$this->assertSame( [], Settings::sanitize( [] )['server_cache'] );
	}

	public function test_proxy_cache_purge_and_breeze(): void {
		$pcp = new ServerCaches( self::config( 'proxy-cache-purge' ) );
		$this->assertSame( 'ok', $pcp->purge( false, [ 'https://tienda.example.com/a/', 'https://tienda.example.com/a/', '' ] )['varnish'] );
		$this->assertSame( [ 'https://tienda.example.com/a/' ], \VarnishPurger::$calls, 'sin repetidas ni vacías' );
		$this->assertSame( 'all', $pcp->purge( true, [] )['varnish'] );
		$this->assertSame( 'https://tienda.example.com/?vhp-regex', end( \VarnishPurger::$calls ), 'purgar todo: su convención vhp-regex' );

		$breeze = new ServerCaches( self::config( 'breeze' ) );
		$breeze->purge( false, [ 'https://tienda.example.com/b/' ] );
		$breeze->purge( true, [] );
		$this->assertSame( [ 'https://tienda.example.com/b/', 'https://tienda.example.com/?breeze' ], \Breeze_PurgeVarnish::$calls );

		$many                  = array_map( static fn( int $i ): string => 'https://tienda.example.com/p-' . $i . '/', range( 1, ServerCaches::MAX_URLS + 1 ) );
		\VarnishPurger::$calls = [];
		$this->assertSame( 'all', $pcp->purge( false, $many )['varnish'], 'más de MAX_URLS: todo de una vez' );
		$this->assertCount( 1, \VarnishPurger::$calls );

		$this->assertSame(
			[
				'varnish' => '',
				'nginx'   => '',
			],
			( new ServerCaches( self::config() ) )->purge( true, [] ),
			'sin cachés coordinadas no hace nada'
		);
		$this->assertSame( '', $pcp->purge( false, [] )['varnish'], 'nada que purgar' );
	}

	public function test_direct_varnish_purge(): void {
		$direct = new ServerCaches( self::config( 'direct', '', '127.0.0.1:6081' ) );
		$this->assertSame( 'ok', $direct->purge( false, [ 'https://tienda.example.com/producto/x/?talla=m' ] )['varnish'] );
		$req = $this->requests[0];
		$this->assertSame( 'http://127.0.0.1:6081/producto/x/?talla=m', $req['url'], 'directo al servidor, sin pasar por Cloudflare' );
		$this->assertSame( 'PURGE', $req['args']['method'] );
		$this->assertSame( 'tienda.example.com', $req['args']['headers']['Host'] );
		$this->assertSame( 'default', $req['args']['headers']['X-Purge-Method'] );
		$this->assertSame( 0, $req['args']['redirection'] );

		$this->assertSame( 'all', $direct->purge( true, [] )['varnish'] );
		$all = end( $this->requests );
		$this->assertSame( 'http://127.0.0.1:6081/.*', $all['url'] );
		$this->assertSame( 'regex', $all['args']['headers']['X-Purge-Method'] );

		// Una URL de otro sitio nunca se manda.
		$this->requests = [];
		$this->assertStringContainsString( 'sin conexión', $direct->purge( false, [ 'https://otro.example.com/x/' ] )['varnish'] );
		$this->assertSame( [], $this->requests );

		$this->code = 501;
		$this->assertStringContainsString( '501', $direct->purge( false, [ 'https://tienda.example.com/' ] )['varnish'] );
		$test = ServerCaches::test( '127.0.0.1:6081' );
		$this->assertFalse( $test['ok'] );
		$this->assertSame( 501, $test['status'] );
		$this->assertStringContainsString( 'VCL', $test['message'] );
		$this->code = 200;
		$this->assertTrue( ServerCaches::test( '127.0.0.1:6081' )['ok'] );
		$this->assertFalse( ServerCaches::test( 'varnish.local' )['ok'] );
	}

	public function test_nginx_helper(): void {
		$purger = $this->nginxPurger();
		$nginx  = new ServerCaches( self::config( '', 'nginx-helper' ) );
		$this->assertSame( 'ok', $nginx->purge( false, [ 'https://tienda.example.com/a/' ] )['nginx'] );
		$this->assertSame( [ 'https://tienda.example.com/a/' ], $purger->urls, 'sin sus feeds: Imagina Turbo ya decide qué URLs' );
		$this->assertSame( 'all', $nginx->purge( true, [] )['nginx'] );
		$this->assertSame( 1, $purger->all );

		$this->siteOptions['rt_wp_nginx_helper_options'] = [ 'enable_purge' => 0 ];
		$this->assertStringContainsString( 'apagada', $nginx->purge( true, [] )['nginx'], 'si su dueño apagó la purga, se respeta' );
		$this->assertSame( 1, $purger->all );

		$GLOBALS['nginx_purger'] = null;
		$this->assertStringContainsString( 'no está cargado', $nginx->purge( true, [] )['nginx'] );

		$this->siteOptions['easyengine_cache_manager_cf_settings'] = [
			'api_token' => 'x',
			'zone_id'   => 'z',
		];
		$this->assertTrue( ServerCaches::nginxHelperCloudflare() );
	}

	public function test_listen_mirrors_their_full_purges_without_loops(): void {
		$purger   = $this->nginxPurger();
		$mirrored = [];
		$caches   = new ServerCaches( self::config( 'proxy-cache-purge', 'nginx-helper' ) );
		$caches->listen(
			static function ( PurgeRequest $r ) use ( &$mirrored ): void {
				$mirrored[] = $r->all ? 'all:' . $r->reason : 'urls';
			}
		);
		$this->assertArrayHasKey( 'after_purge_url', $this->hooks );
		$this->assertArrayHasKey( 'rt_nginx_helper_after_redis_purge_all', $this->hooks );

		// El botón «Purgar todo» de Nginx Helper o de Proxy Cache Purge: también el edge.
		$this->fire( 'rt_nginx_helper_after_fastcgi_purge_all' );
		$this->fire( 'after_purge_url', 'https://tienda.example.com/?vhp-regex', 'http://x/.*', [], [ 'X-Purge-Method' => 'regex' ] );
		$this->fire( 'after_purge_url', 'https://tienda.example.com/a/', 'http://x/a/', [], [ 'X-Purge-Method' => 'default' ] );
		$this->assertSame( [ 'all:nginx', 'all:varnish' ], $mirrored, 'una URL suelta no se replica: Imagina Turbo ya purga el edge por el mismo cambio' );

		// Lo que vacía el propio Imagina Turbo no vuelve a la cola.
		$mirrored = [];
		$caches->purge( true, [] );
		$this->assertSame( 1, $purger->all );
		$this->assertSame( [], $mirrored );
	}
}
