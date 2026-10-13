<?php
/**
 * Coordinador de caché: tags, cacheabilidad, purgas, adaptadores, nonces y recalentado.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\CacheAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\LiteSpeedAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\WpRocketAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\Cacheability;
use ImaginaTurbo\Modules\CacheCoordinator\HeaderEmitter;
use ImaginaTurbo\Modules\CacheCoordinator\NonceRefresh;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;
use ImaginaTurbo\Modules\CacheCoordinator\Purger;
use ImaginaTurbo\Modules\CacheCoordinator\TagBuilder;
use ImaginaTurbo\Modules\CacheCoordinator\Warmer;
use ImaginaTurbo\Repository\PurgeQueueRepository;

/**
 * Adaptador de prueba: registra las purgas y puede simular el aviso de vuelta del plugin de caché.
 */
final class RecordingAdapter implements CacheAdapter {

	/** @var list<array{request: PurgeRequest, tags: list<string>}> */
	public array $purges = [];

	/** @var (callable(PurgeRequest): void)|null */
	public $echo = null;

	public function id(): string {
		return 'test';
	}

	public function addResponseTags( array $tags ): void {
	}

	public function noCache( string $reason ): void {
	}

	public function purge( PurgeRequest $request, array $tags ): void {
		$this->purges[] = [
			'request' => $request,
			'tags'    => $tags,
		];
		if ( null !== $this->echo ) {
			( $this->echo )( $request );
		}
	}

	public function listen( callable $onPurge ): void {
	}

	public function hasDeferredPurge(): bool {
		return false;
	}

	public function headersOnHit(): bool {
		return true;
	}
}

final class CacheTest extends TestCase {

	/**
	 * Request anónimo cacheable; cada prueba cambia lo que necesita.
	 *
	 * @param array<string, mixed> $overrides Cambios.
	 * @return array<string, mixed>
	 */
	private static function request( array $overrides = [] ): array {
		return array_merge(
			[
				'type'              => RequestType::FrontendHtml,
				'method'            => 'GET',
				'logged_in'         => false,
				'cookies'           => [],
				'bypass_cookies'    => [ 'wordpress_logged_in_', 'woocommerce_items_in_cart' ],
				'status'            => 200,
				'headers'           => [ 'Content-Type: text/html; charset=UTF-8' ],
				'preview'           => false,
				'search'            => false,
				'password_required' => false,
				'do_not_cache'      => false,
			],
			$overrides
		);
	}

	private const SETTINGS = [
		'edge_ttl'      => 86400,
		'nonce_refresh' => true,
	];

	public function test_tag_builder_validates_names_and_prefix(): void {
		$tags = new TagBuilder( 'k7' );
		$this->assertSame( 'k7-p-12', $tags->tag( 'p-12' ) );
		$this->assertSame( 'k7-pt-product', $tags->tag( 'pt-product' ) );
		$this->assertSame( '', $tags->tag( 'p-12; drop' ) );
		$this->assertSame( '', $tags->tag( 'otra' ) );
		$this->assertSame( [ 'k7-home', 'k7-t-5' ], $tags->tags( [ 'home', 't-5', 'home', 'mal tag' ] ) );
		$this->assertSame( 'p-12', $tags->strip( 'k7-p-12' ) );
		$this->assertNull( $tags->strip( 'zz-p-12' ) );
		$this->assertNull( $tags->strip( 'k7-desconocido' ) );
		// Un prefijo no válido cae al de por defecto.
		$this->assertSame( 'itb', ( new TagBuilder( 'MAL PREFIJO' ) )->prefix() );
	}

	public function test_cacheable_anonymous_page(): void {
		$this->assertSame(
			[
				'cacheable' => true,
				'reason'    => 'ok',
				'ttl'       => 86400,
			],
			Cacheability::decide( self::SETTINGS, self::request() )
		);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function notCacheable(): array {
		return [
			'carrito'          => [ [ 'type' => RequestType::Cart ], 'type' ],
			'REST'             => [ [ 'type' => RequestType::Rest ], 'type' ],
			'POST'             => [ [ 'method' => 'POST' ], 'method' ],
			'logueado'         => [ [ 'logged_in' => true ], 'logged_in' ],
			'cookie de bypass' => [ [ 'cookies' => [ 'woocommerce_items_in_cart' ] ], 'cookie' ],
			'vista previa'     => [ [ 'preview' => true ], 'preview' ],
			'búsqueda'         => [ [ 'search' => true ], 'search' ],
			'contraseña'       => [ [ 'password_required' => true ], 'password' ],
			'DONOTCACHEPAGE'   => [ [ 'do_not_cache' => true ], 'donotcachepage' ],
			'Set-Cookie'       => [ [ 'headers' => [ 'Set-Cookie: wp_woocommerce_session_x=1' ] ], 'set_cookie' ],
			'privado'          => [ [ 'headers' => [ 'Cache-Control: no-cache, private' ] ], 'private' ],
			'no-store'         => [ [ 'headers' => [ 'cache-control: no-store' ] ], 'private' ],
			'error 500'        => [ [ 'status' => 500 ], 'status' ],
			'redirección'      => [ [ 'status' => 301 ], 'status' ],
		];
	}

	/**
	 * @dataProvider notCacheable
	 * @param array<string, mixed> $overrides Cambios.
	 */
	public function test_not_cacheable( array $overrides, string $reason ): void {
		$decision = Cacheability::decide( self::SETTINGS, self::request( $overrides ) );
		$this->assertFalse( $decision['cacheable'] );
		$this->assertSame( $reason, $decision['reason'] );
		$this->assertSame( 0, $decision['ttl'] );
	}

	public function test_cookie_without_bypass_prefix_is_cacheable(): void {
		$this->assertTrue( Cacheability::decide( self::SETTINGS, self::request( [ 'cookies' => [ '_ga', 'cookielawinfo' ] ] ) )['cacheable'] );
		// Un public max-age del sitio no impide la caché del edge.
		$this->assertTrue( Cacheability::decide( self::SETTINGS, self::request( [ 'headers' => [ 'Cache-Control: max-age=60' ] ] ) )['cacheable'] );
	}

	public function test_ttl_caps(): void {
		$capped = Cacheability::decide(
			[
				'edge_ttl'      => 86400,
				'nonce_refresh' => false,
			],
			self::request()
		);
		$this->assertSame( Cacheability::MAX_TTL_WITHOUT_NONCE_REFRESH, $capped['ttl'] );
		$this->assertSame( Cacheability::TTL_404, Cacheability::decide( self::SETTINGS, self::request( [ 'status' => 404 ] ) )['ttl'] );
		$this->assertSame( 300, Cacheability::decide( [ 'edge_ttl' => 300, 'nonce_refresh' => true ], self::request( [ 'status' => 404 ] ) )['ttl'] );
		$this->assertSame( 'ttl', Cacheability::decide( [ 'edge_ttl' => 0, 'nonce_refresh' => true ], self::request() )['reason'] );
	}

	public function test_edge_header(): void {
		$this->assertSame( 'no-store', Cacheability::edgeHeader( false, 600, 60, 60 ) );
		$this->assertSame( 'max-age=600, stale-while-revalidate=60, stale-if-error=120', Cacheability::edgeHeader( true, 600, 60, 120 ) );
		$this->assertSame( 'max-age=600', Cacheability::edgeHeader( true, 600, 0, 0 ) );
	}

	public function test_late_reason(): void {
		$this->assertNull( HeaderEmitter::lateReason( false, [ 'Content-Type: text/html', 'Cache-Control: max-age=60' ] ) );
		$this->assertSame( 'donotcachepage', HeaderEmitter::lateReason( true, [] ) );
		$this->assertSame( 'set_cookie', HeaderEmitter::lateReason( false, [ 'Set-Cookie: woocommerce_recently_viewed=12' ] ) );
		$this->assertSame( 'private', HeaderEmitter::lateReason( false, [ 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private' ] ) );
	}

	public function test_purge_request_merge(): void {
		$this->assertTrue( ( new PurgeRequest() )->isEmpty() );
		$this->assertFalse( PurgeRequest::all( 'x' )->isEmpty() );
		$merged = ( new PurgeRequest( [ 'p-1', 'home' ], [ 'https://a.test/' ], false, 'post' ) )->merge( new PurgeRequest( [ 'home', 't-2' ], [ 'https://a.test/', 'https://a.test/b/' ], true, 'otro' ) );
		$this->assertSame( [ 'p-1', 'home', 't-2' ], $merged->tags );
		$this->assertSame( [ 'https://a.test/', 'https://a.test/b/' ], $merged->urls );
		$this->assertTrue( $merged->all );
		$this->assertSame( 'post', $merged->reason );
	}

	public function test_litespeed_translate(): void {
		$tags = new TagBuilder( 'k7' );
		$this->assertTrue( LiteSpeedAdapter::translate( [ '1_Po.5', '*' ], $tags )->all );
		$request = LiteSpeedAdapter::translate( [ '1_Po.15', '1_T.8', '1_A.2', '1_PT.product', '1_H', '1_F', '1_FD', 'URL.9f2c', 'k7-shop', '1_Po.15', 7 ], $tags );
		$this->assertFalse( $request->all );
		$this->assertSame( [ 'p-15', 't-8', 'a-2', 'pt-product', 'home', 'feed', 'shop' ], $request->tags );
		// Un tipo con caracteres raros no produce un tag inválido.
		$this->assertSame( [], LiteSpeedAdapter::translate( [ '1_PT.Mal Tipo' ], $tags )->tags );
	}

	public function test_wp_rocket_htaccess_block(): void {
		$this->assertSame( '', WpRocketAdapter::htaccessBlock( '' ) );
		$this->assertSame( '', WpRocketAdapter::htaccessBlock( 'max-age=60"' . "\nHeader set X 1" ) );
		$block = WpRocketAdapter::htaccessBlock( 'max-age=86400, stale-while-revalidate=86400' );
		$this->assertStringContainsString( WpRocketAdapter::HTACCESS_MARKER, $block );
		$this->assertStringContainsString( '<FilesMatch "^index(-[a-z0-9]+)*\.html(_gzip)?$">', $block );
		$this->assertStringContainsString( 'Header set Cloudflare-CDN-Cache-Control "max-age=86400, stale-while-revalidate=86400"', $block );
		$this->assertTrue( ( new WpRocketAdapter( 'apache', 'max-age=60' ) )->edgeCapable() );
		$this->assertFalse( ( new WpRocketAdapter( 'nginx', 'max-age=60' ) )->edgeCapable() );
	}

	public function test_nonce_signature(): void {
		Functions\when( 'wp_salt' )->justReturn( 'sal-de-prueba' );
		$now     = 1_800_000_000;
		$actions = [ 'wc_store_api', 'wp_rest' ];
		$sig     = NonceRefresh::sign( $actions, $now - 3600 );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $sig );
		// Orden y repetidos no importan: se normalizan antes de comprobar.
		$this->assertSame( $actions, NonceRefresh::verify( [ 'wp_rest', 'wc_store_api', 'wp_rest' ], $now - 3600, $sig, $now ) );
		$this->assertNull( NonceRefresh::verify( $actions, $now - 3599, $sig, $now ), 'otra fecha' );
		$this->assertNull( NonceRefresh::verify( [ 'wp_rest', 'otra' ], $now - 3600, $sig, $now ), 'otras acciones' );
		$this->assertNull( NonceRefresh::verify( $actions, $now - 3600, str_repeat( '0', 32 ), $now ), 'firma falsa' );
		$this->assertNull( NonceRefresh::verify( 'wp_rest', $now, $sig, $now ) );
		$this->assertNull( NonceRefresh::verify( [ 'con espacio' ], $now, NonceRefresh::sign( [ 'con espacio' ], $now ), $now ) );
		$old = $now - NonceRefresh::MAX_PAGE_AGE - 1;
		$this->assertNull( NonceRefresh::verify( $actions, $old, NonceRefresh::sign( $actions, $old ), $now ), 'página demasiado vieja' );
		$future = $now + 3600;
		$this->assertNull( NonceRefresh::verify( $actions, $future, NonceRefresh::sign( $actions, $future ), $now ), 'fecha futura' );
		$many = array_map( static fn( int $i ): string => 'a' . $i, range( 1, NonceRefresh::MAX_ACTIONS + 1 ) );
		sort( $many );
		$this->assertNull( NonceRefresh::verify( $many, $now, NonceRefresh::sign( $many, $now ), $now ), 'demasiadas acciones' );
		// Otra sal (otro sitio) invalida la firma.
		Functions\when( 'wp_salt' )->justReturn( 'otra-sal' );
		$this->assertNull( NonceRefresh::verify( $actions, $now - 3600, $sig, $now ) );
	}

	public function test_warmer_exclusions(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$host  = 'tienda.test';
		$paths = [ '/carrito/', '/mi-cuenta' ];
		$check = static fn( string $url, array $patterns = [] ): bool => Warmer::excluded( $url, $host, $patterns, $paths );
		$this->assertFalse( $check( 'https://tienda.test/' ) );
		$this->assertFalse( $check( 'https://TIENDA.test/producto/camisa/?color=azul' ) );
		$this->assertFalse( $check( 'https://tienda.test/carritos-de-golf/' ), 'prefijo parecido, no la misma ruta' );
		$this->assertTrue( $check( 'https://otro.test/' ) );
		$this->assertTrue( $check( 'no es una url' ) );
		$this->assertTrue( $check( 'https://tienda.test/wp-admin/' ) );
		$this->assertTrue( $check( 'https://tienda.test/wp-login.php' ) );
		$this->assertTrue( $check( 'https://tienda.test/wp-content/uploads/foto.JPG' ) );
		$this->assertTrue( $check( 'https://tienda.test/carrito/' ) );
		$this->assertTrue( $check( 'https://tienda.test/mi-cuenta/pedidos/' ) );
		$this->assertTrue( $check( 'https://tienda.test/producto/x/?add-to-cart=5' ) );
		$this->assertTrue( $check( 'https://tienda.test/?s=camisa' ) );
		$this->assertTrue( $check( 'https://tienda.test/en/', [ '/en/' ] ) );
		// Un patrón inválido de la matriz no rompe nada.
		$this->assertFalse( $check( 'https://tienda.test/', [ '([' ] ) );
	}

	public function test_warmer_load_and_sitemap(): void {
		$this->assertFalse( Warmer::overloaded( null, 4 ) );
		$this->assertFalse( Warmer::overloaded( 2.8, 4 ) );
		$this->assertTrue( Warmer::overloaded( 2.9, 4 ) );
		$this->assertFalse( Warmer::slow( 900.0 ) );
		$this->assertTrue( Warmer::slow( 5200.0 ), 'una página lenta pausa el recalentado' );
		$this->assertTrue( Warmer::overloaded( 0.8, 0 ), 'sin núcleos conocidos se asume uno' );
		$xml = '<?xml version="1.0"?><urlset><url><loc>https://a.test/</loc></url><url><loc> <![CDATA[https://a.test/b/?x=1&amp;y=2]]> </loc></url><url><loc>https://a.test/</loc></url></urlset>';
		$this->assertSame( [ 'https://a.test/', 'https://a.test/b/?x=1&y=2' ], Warmer::parseSitemap( $xml ) );
		$this->assertSame( [], Warmer::parseSitemap( '<html>no</html>' ) );
	}

	/**
	 * @param list<string> $inserted Elementos que se escriben en la cola (salida).
	 */
	private function purger( RecordingAdapter $adapter, array &$inserted ): Purger {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		$wpdb         = \Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql, array $args ) use ( &$inserted ): string {
				for ( $i = 1; $i < count( $args ); $i += 4 ) {
					$inserted[] = $args[ $i ] . ':' . $args[ $i + 2 ];
				}
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		return new Purger( $adapter, new TagBuilder( 'k7' ), new PurgeQueueRepository( $wpdb ) );
	}

	public function test_purger_dedupes_origin_and_ignores_own_echo(): void {
		$inserted = [];
		$adapter  = new RecordingAdapter();
		$purger   = $this->purger( $adapter, $inserted );
		// El plugin de caché avisa de vuelta de la purga que le pedimos: no debe volver a encolarse dos veces.
		$adapter->echo = static function ( PurgeRequest $r ) use ( $purger ): void {
			$purger->mirror( $r );
		};

		$purger->purge( new PurgeRequest( [ 'p-1', 'home' ], [ 'https://a.test/x/' ], false, 'post' ) );
		$purger->purge( new PurgeRequest( [ 'home', 't-3' ], [ 'https://a.test/x/' ], false, 'term' ) );
		$this->assertCount( 2, $adapter->purges );
		$this->assertSame( [ 'k7-p-1', 'k7-home' ], $adapter->purges[0]['tags'] );
		$this->assertSame( [ 't-3' ], $adapter->purges[1]['request']->tags, 'home ya se purgó en este request' );
		$this->assertSame( [], $adapter->purges[1]['request']->urls, 'la URL ya se purgó' );

		// Purga propia del plugin de caché (otro cambio): se replica en el edge, salvo lo ya purgado.
		$purger->mirror( new PurgeRequest( [ 'p-1', 'p-9' ], [], false, 'litespeed' ) );
		$purger->flushToQueue();
		sort( $inserted );
		$this->assertSame( [ 't:home', 't:p-1', 't:p-9', 't:t-3', 'u:https://a.test/x/' ], $inserted );
	}

	public function test_purger_all_once(): void {
		$inserted = [];
		$adapter  = new RecordingAdapter();
		$purger   = $this->purger( $adapter, $inserted );
		$purger->purge( PurgeRequest::all( 'menu' ) );
		$purger->purge( new PurgeRequest( [ 'p-1' ], [], false, 'post' ) );
		$purger->mirror( PurgeRequest::all( 'litespeed' ) );
		$this->assertCount( 1, $adapter->purges, 'tras purgar todo, el origen no se vuelve a purgar' );
		$this->assertTrue( $adapter->purges[0]['request']->all );
		$purger->flushToQueue();
		$this->assertContains( 'a:all', $inserted );
		$this->assertSame( 1, count( array_filter( $inserted, static fn( string $i ): bool => 'a:all' === $i ) ) );
		// Una segunda escritura sin nada pendiente no toca la cola.
		$count = count( $inserted );
		$purger->flushToQueue();
		$this->assertCount( $count, $inserted );
		$this->assertFalse( Purger::selfPurging() );
	}
}
