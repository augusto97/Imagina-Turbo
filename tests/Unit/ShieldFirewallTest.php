<?php
/**
 * Fase 4: guardia temprana, escudo, ajustes rápidos, migración de Perfmatters, firewall y origen.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use ImaginaTurbo\Cloudflare\FirewallTemplates;
use ImaginaTurbo\Cloudflare\Phases;
use ImaginaTurbo\Cloudflare\PlanLimits;
use ImaginaTurbo\Cloudflare\SiteContext;
use ImaginaTurbo\Cloudflare\Templates;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Modules\Firewall\HtaccessWriter;
use ImaginaTurbo\Support\CloudflareAccess;
use ImaginaTurbo\Modules\Firewall\OriginLock;
use ImaginaTurbo\Modules\Profiler\CacheabilityAudit;
use ImaginaTurbo\Modules\Shield\EarlyGuard;
use ImaginaTurbo\Modules\Shield\ShieldModule;
use ImaginaTurbo\Modules\Speculation\PrefetchProbe;
use ImaginaTurbo\Modules\Tweaks\PerfmattersMigration;
use ImaginaTurbo\Modules\Tweaks\TweakCatalog;

final class ShieldFirewallTest extends TestCase {

	private const ALL = [
		'static'     => true,
		'author'     => true,
		'xmlrpc'     => true,
		'rest_users' => true,
	];

	public function test_early_guard_static_miss(): void {
		$r = EarlyGuard::decide( RequestType::StaticMiss, '/wp-content/uploads/x.jpg', 'wp-json', self::ALL, [], [], [] );
		$this->assertNotNull( $r );
		$this->assertSame( 404, $r['status'] );
		$this->assertContains( 'Cache-Control: public, max-age=600', $r['headers'] );
		$this->assertNull( EarlyGuard::decide( RequestType::StaticMiss, '/x.jpg', 'wp-json', [ 'static' => false ] + self::ALL, [], [], [] ) );
	}

	public function test_early_guard_author(): void {
		$this->assertSame( 404, EarlyGuard::decide( RequestType::FrontendHtml, '/', 'wp-json', self::ALL, [ 'author' => '1' ], [], [] )['status'] ?? 0 );
		$this->assertSame( 404, EarlyGuard::decide( RequestType::FrontendHtml, '/', 'wp-json', self::ALL, [ 'author' => ' 12 ' ], [], [] )['status'] ?? 0 );
		// Con cookie de sesión, nombre no numérico o desactivado: sigue el core.
		$this->assertNull( EarlyGuard::decide( RequestType::FrontendHtml, '/', 'wp-json', self::ALL, [ 'author' => '1' ], [ 'wordpress_logged_in_abc' => 'x' ], [] ) );
		$this->assertNull( EarlyGuard::decide( RequestType::FrontendHtml, '/', 'wp-json', self::ALL, [ 'author' => 'admin' ], [], [] ) );
		$this->assertNull( EarlyGuard::decide( RequestType::FrontendHtml, '/', 'wp-json', self::ALL, [ 'author' => [ '1' ] ], [], [] ) );
		$this->assertNull( EarlyGuard::decide( RequestType::FrontendHtml, '/', 'wp-json', [ 'author' => false ] + self::ALL, [ 'author' => '1' ], [], [] ) );
	}

	public function test_early_guard_xmlrpc_and_users(): void {
		$this->assertSame( 403, EarlyGuard::decide( RequestType::XmlRpc, '/xmlrpc.php', 'wp-json', self::ALL, [], [], [] )['status'] ?? 0 );
		$this->assertNull( EarlyGuard::decide( RequestType::XmlRpc, '/xmlrpc.php', 'wp-json', [ 'xmlrpc' => false ] + self::ALL, [], [], [] ), 'la config lo apaga si un plugin lo requiere' );

		$this->assertSame( 401, EarlyGuard::decide( RequestType::Rest, '/wp-json/wp/v2/users', 'wp-json', self::ALL, [], [], [] )['status'] ?? 0 );
		$this->assertSame( 401, EarlyGuard::decide( RequestType::Rest, '/WP-JSON/wp/v2/users/1', 'wp-json', self::ALL, [], [], [] )['status'] ?? 0 );
		$this->assertSame( 401, EarlyGuard::decide( RequestType::Rest, '/', 'wp-json', self::ALL, [ 'rest_route' => '/wp/v2/users' ], [], [] )['status'] ?? 0 );
		$this->assertNull( EarlyGuard::decide( RequestType::Rest, '/wp-json/wp/v2/users', 'wp-json', self::ALL, [], [], [ 'HTTP_AUTHORIZATION' => 'Basic x' ] ), 'contraseñas de aplicación' );
		$this->assertNull( EarlyGuard::decide( RequestType::Rest, '/wp-json/wp/v2/users', 'wp-json', self::ALL, [], [ 'wordpress_logged_in_x' => '1' ], [] ) );
		$this->assertSame( 401, EarlyGuard::decide( RequestType::Rest, '/wp-json/wp/v2/users', 'wp-json', self::ALL, [], [], [ 'HTTP_AUTHORIZATION' => '' ] )['status'] ?? 0, 'cabecera vacía del .htaccess de WordPress' );
		$this->assertNull( EarlyGuard::decide( RequestType::Rest, '/wp-json/wp/v2/posts', 'wp-json', self::ALL, [], [], [] ) );
		$this->assertTrue( EarlyGuard::isUsersRoute( '/api/wp/v2/users', 'api', [] ) );
		$this->assertFalse( EarlyGuard::isUsersRoute( '/wp-json/wp/v2/usersx', 'api', [] ) );
	}

	public function test_filter_params(): void {
		$this->assertSame( 4, ShieldModule::filterParams( [ 'filter_color' => 'rojo', 'query_type_color' => 'or', 'min_price' => '1', 'max_price' => '9', 'orderby' => 'price' ] ) );
		$this->assertSame( 0, ShieldModule::filterParams( [ 's' => 'x', 'page' => 2 ] ) );
	}

	public function test_tweak_catalog_defaults_only_safe(): void {
		foreach ( TweakCatalog::all() as $id => $tweak ) {
			if ( $tweak['default'] ) {
				$this->assertSame( 'safe', $tweak['risk'], $id );
			}
			$this->assertNotSame( '', $tweak['description'], $id );
		}
		$this->assertSame( 'invasive', TweakCatalog::all()['jquery_migrate']['risk'] );
		$state = TweakCatalog::resolve( [ 'emojis' => false, 'feeds' => true, 'nope' => true, 'embeds' => 'yes' ] );
		$this->assertFalse( $state['emojis'] );
		$this->assertTrue( $state['feeds'] );
		$this->assertFalse( $state['embeds'], 'un valor no booleano usa el defecto' );
		$this->assertArrayNotHasKey( 'nope', $state );
	}

	public function test_perfmatters_migration(): void {
		$map = PerfmattersMigration::map(
			[
				'disable_emojis'                         => '1',
				'remove_jquery_migrate'                  => true,
				'hide_wp_version'                        => '1',
				'remove_wlwmanifest_link'                => '1',
				'disable_xmlrpc'                         => '1',
				'limit_post_revisions'                   => '3',
				'autosave_interval'                      => '86400',
				'disable_heartbeat'                      => 'allow_posts',
				'heartbeat_frequency'                    => '200',
				'disable_woocommerce_cart_fragmentation' => '1',
				'disable_embeds'                         => '',
				'fonts'                                  => [ 'disable_google_fonts' => '1' ],
				'assets'                                 => [ 'delay_js' => '1' ],
				'cdn'                                    => [ 'cdn_directories' => 'wp-content,wp-includes' ],
			]
		);
		$c = $map['changes'];
		$this->assertTrue( $c['tweaks']['emojis'] );
		$this->assertTrue( $c['tweaks']['jquery_migrate'] );
		$this->assertTrue( $c['tweaks']['head_cleanup'] );
		$this->assertTrue( $c['tweaks']['google_fonts'] );
		$this->assertArrayNotHasKey( 'embeds', $c['tweaks'], 'cadena vacía = apagado' );
		$this->assertSame( 3, $c['tweak_revisions'] );
		$this->assertSame( 86400, $c['tweak_autosave'] );
		$this->assertTrue( $c['shield_xmlrpc'] );
		$this->assertSame( 120, $c['shield_heartbeat_admin'], 'acotado a 15–120' );
		$from = array_column( $map['unmapped'], 'from' );
		$this->assertContains( 'disable_woocommerce_cart_fragmentation', $from );
		$this->assertContains( 'assets.*', $from );
		$this->assertNotContains( 'cdn.*', $from, 'cdn con valores por defecto pero sin activar' );

		$this->assertSame( 0, PerfmattersMigration::map( [ 'limit_post_revisions' => 'false' ] )['changes']['tweak_revisions'] );
		$this->assertSame( [], PerfmattersMigration::map( [] )['changes'] );
	}

	private static function site( bool $xmlrpcRequired = false ): SiteContext {
		return new SiteContext( 'tienda.example.com', '', 'wp-json', [ 'login' => [ '/acceso' ] ], [], [], [], 'itb-k7-', true, 86400, $xmlrpcRequired, [ '/wp-json/wompi/', 'mal path con espacios' ] );
	}

	public function test_firewall_rules_and_expressions(): void {
		$limits = PlanLimits::fromFile( 'free', self::pluginDir() . '/data/cloudflare-limits.json' );
		$fw     = new FirewallTemplates( self::site(), $limits, [ 'bots' => false ], true, [], 1_000_000 );
		$rules  = $fw->rules();
		$refs   = array_map( static fn( array $r ): string => $r['rule']->ref, $rules );
		$this->assertSame( [ 'itb-k7-fw-exploits', 'itb-k7-fw-surface', 'itb-k7-rl-login' ], $refs );
		foreach ( $rules as $r ) {
			$this->assertSame( 'moderate', $r['risk'] );
			$this->assertLessThanOrEqual( Templates::MAX_EXPRESSION, strlen( $r['rule']->expression ) );
			$bare = (string) preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/', '""', $r['rule']->expression );
			$this->assertSame( substr_count( $bare, '(' ), substr_count( $bare, ')' ), $r['rule']->ref );
			$this->assertStringNotContainsString( 'matches', $r['rule']->expression, 'sin regex por debajo de Business' );
		}
		$this->assertSame( Phases::WAF, $rules[0]['rule']->phase );
		$this->assertSame( 'managed_challenge', $rules[0]['rule']->action, 'modo desafío al principio' );

		$exploits = $fw->exploitsExpression();
		$this->assertStringContainsString( 'http.host eq "tienda.example.com"', $exploits );
		$this->assertStringContainsString( 'url_decode(http.request.uri.query) contains "../"', $exploits );
		$this->assertStringContainsString( 'starts_with(http.request.uri.path, "/wc-api/")', $exploits, 'webhooks excluidos' );
		$this->assertStringContainsString( '"/wp-json/wompi/"', $exploits );
		$this->assertStringNotContainsString( 'espacios', $exploits, 'rutas inválidas de la matriz se descartan' );

		$this->assertStringContainsString( '/xmlrpc.php', $fw->surfaceExpression() );
		$withJetpack = new FirewallTemplates( self::site( true ), $limits, [], true );
		$this->assertStringNotContainsString( 'xmlrpc', $withJetpack->surfaceExpression() );
		$this->assertStringContainsString( '"sqlmap"', $withJetpack->botsExpression() );
	}

	public function test_firewall_challenge_then_block(): void {
		$limits = PlanLimits::fromFile( 'free', self::pluginDir() . '/data/cloudflare-limits.json' );
		$now    = 10 * DAY_IN_SECONDS;
		$fw     = new FirewallTemplates( self::site(), $limits, [], false, [ 'a' => $now - 8 * DAY_IN_SECONDS, 'b' => $now - DAY_IN_SECONDS ], $now );
		$this->assertSame( 'block', $fw->action( 'a' ) );
		$this->assertSame( 'managed_challenge', $fw->action( 'b' ) );
		$this->assertSame( 'managed_challenge', $fw->action( 'c' ) );
		$this->assertCount( 3, $fw->rules(), 'sin límite de peticiones' );
	}

	public function test_rate_limit_by_plan(): void {
		$free = ( new FirewallTemplates( self::site(), PlanLimits::fromFile( 'free', self::pluginDir() . '/data/cloudflare-limits.json' ), [], true ) )->rateLimitRule( 'r' );
		$this->assertSame( Phases::RATELIMIT, $free->phase );
		$this->assertStringNotContainsString( 'http.host', $free->expression, 'Free: solo ruta' );
		$this->assertStringNotContainsString( 'query', $free->expression );
		$this->assertStringContainsString( '"/wp-login.php"', $free->expression );
		$this->assertStringContainsString( '"/acceso"', $free->expression );
		$this->assertStringContainsString( '"/wp-json/wc/store/v1/checkout"', $free->expression );
		$body = $free->body();
		$this->assertSame( [ 'cf.colo.id', 'ip.src' ], $body['ratelimit']['characteristics'] );
		$this->assertSame( 10, $body['ratelimit']['period'] );
		$this->assertSame( 10, $body['ratelimit']['mitigation_timeout'] );

		$pro = ( new FirewallTemplates( self::site(), PlanLimits::fromFile( 'pro', self::pluginDir() . '/data/cloudflare-limits.json' ), [], true ) )->rateLimitRule( 'r' );
		$this->assertStringContainsString( 'wc-ajax=checkout', $pro->expression );
		$this->assertSame( 600, $pro->body()['ratelimit']['mitigation_timeout'] );
	}

	public function test_origin_lock_classify(): void {
		$this->assertSame( OriginLock::CLOUDFLARE, OriginLock::classify( [ 'HTTP_CF_RAY' => 'x', 'HTTP_CF_CONNECTING_IP' => '190.1.2.3', 'REMOTE_ADDR' => '190.1.2.3' ] ) );
		$this->assertSame( OriginLock::CLOUDFLARE_PROXY_IP, OriginLock::classify( [ 'HTTP_CF_RAY' => 'x', 'HTTP_CF_CONNECTING_IP' => '190.1.2.3', 'REMOTE_ADDR' => '172.68.1.1' ] ) );
		// En CLI (pruebas) todo lo que no viene de Cloudflare es local.
		$this->assertSame( OriginLock::LOCAL, OriginLock::classify( [ 'REMOTE_ADDR' => '8.8.8.8' ] ) );
		$this->assertTrue( OriginLock::viaCloudflare( OriginLock::CLOUDFLARE_PROXY_IP ) );
		$this->assertFalse( OriginLock::viaCloudflare( OriginLock::DIRECT ) );
	}

	public function test_origin_lock_report(): void {
		$rows = static fn( int $code, int $n, string $type = 'FrontendHtml' ): array => array_fill( 0, $n, [
			'request_type' => $type,
			'via_cf'       => $code,
		] );
		$this->assertSame( 'unknown', OriginLock::report( $rows( 1, 5 ) )['state'] );
		$this->assertSame( 'closed', OriginLock::report( array_merge( $rows( 1, 30 ), $rows( 2, 50 ), $rows( 0, 9, 'Cron' ) ) )['state'], 'local y cron no cuentan' );
		$open = OriginLock::report( array_merge( $rows( 1, 30 ), $rows( 0, 10 ) ) );
		$this->assertSame( 'open', $open['state'] );
		$this->assertSame( 10, $open['direct'] );
		$this->assertSame( 'no_cloudflare', OriginLock::report( $rows( 0, 25 ) )['state'] );
		$this->assertTrue( OriginLock::realIpMissing( OriginLock::report( $rows( 3, 30 ) ) ) );
		$this->assertFalse( OriginLock::realIpMissing( OriginLock::report( $rows( 1, 30 ) ) ) );
		// Las muestras anteriores a la actualización (via_cf sin distinguir local) no cuentan.
		$old = array_map( static fn( array $r ): array => $r + [ 'created_at' => '2026-01-01 00:00:00' ], $rows( 0, 30 ) );
		$this->assertSame( 0, OriginLock::report( $old, strtotime( '2026-02-01 00:00:00 UTC' ) )['direct'] );
	}

	public function test_htaccess_block_goes_first_and_is_idempotent(): void {
		$wp      = "# BEGIN WordPress\nRewriteEngine On\nRewriteRule . /index.php [L]\n# END WordPress\n";
		$with    = HtaccessWriter::withBlock( $wp );
		$this->assertStringStartsWith( HtaccessWriter::BEGIN, $with, 'antes del bloque de WordPress' );
		$this->assertStringContainsString( '# BEGIN WordPress', $with );
		$this->assertSame( $with, HtaccessWriter::withBlock( $with ), 'idempotente' );
		$this->assertSame( 1, substr_count( $with, HtaccessWriter::BEGIN ) );
		$this->assertSame( $wp, HtaccessWriter::withoutBlock( $with ) );
		$this->assertTrue( HtaccessWriter::hasBlock( $with ) );
		$this->assertFalse( HtaccessWriter::hasBlock( $wp ) );
		$block = HtaccessWriter::block();
		$this->assertStringContainsString( 'Jeff Starr', $block, 'crédito al 8G' );
		$this->assertStringContainsString( '!/wc-api/', $block );
		$this->assertSame( HtaccessWriter::block(), HtaccessWriter::withBlock( '' ) );
	}

	public function test_cloudflare_access_detection(): void {
		$login = 'https://imagina.cloudflareaccess.com/cdn-cgi/access/login/tienda.test?kid=abc&redirect_url=%2Fwp-admin%2Fadmin-ajax.php';
		$this->assertTrue( CloudflareAccess::blocked( 302, $login ), 'redirección al login del equipo' );
		$this->assertTrue( CloudflareAccess::blocked( 302, 'https://tienda.test/cdn-cgi/access/login/tienda.test' ) );
		$this->assertTrue( CloudflareAccess::blocked( 200, '', $login ), 'se siguió la redirección y se terminó en Access' );
		$this->assertFalse( CloudflareAccess::blocked( 302, 'https://tienda.test/mi-cuenta/' ), 'una redirección del propio sitio' );
		$this->assertFalse( CloudflareAccess::isAccessUrl( 'https://cloudflareaccess.com.atacante.test/' ) );
		$this->assertFalse( CloudflareAccess::isAccessUrl( '' ) );
	}

	public function test_htaccess_strip_on_deactivation(): void {
		$wp   = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		$file = $this->tmp . '/.htaccess';
		file_put_contents( $file, HtaccessWriter::withBlock( $wp ) );
		$this->assertTrue( HtaccessWriter::stripFile( $file ) );
		$this->assertSame( $wp, file_get_contents( $file ), 'el resto del archivo queda igual' );
		$this->assertFalse( HtaccessWriter::stripFile( $file ), 'sin bloque no se escribe' );
		$this->assertFalse( HtaccessWriter::stripFile( $this->tmp . '/no-existe' ) );
	}

	public function test_htaccess_writer_restores_on_failure(): void {
		$options = [];
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			static function ( string $k, $d = false ) use ( &$options ) {
				return $options[ $k ] ?? $d;
			}
		);
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			static function ( string $k, $v ) use ( &$options ): bool {
				$options[ $k ] = $v;
				return true;
			}
		);
		\Brain\Monkey\Functions\when( 'wp_is_writable' )->alias( 'is_writable' );
		$file     = $this->tmp . '/.htaccess';
		$original = "# BEGIN WordPress\n# END WordPress\n";
		file_put_contents( $file, $original );

		$bad    = new HtaccessWriter( $file, static fn( string $url ): int => str_contains( $url, 'wp-json' ) ? 500 : 200, [ 'https://a/', 'https://a/wp-json/' ] );
		$result = $bad->apply();
		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['restored'] );
		$this->assertSame( $original, file_get_contents( $file ) );

		$good = new HtaccessWriter( $file, static fn(): int => 200, [ 'https://a/' ] );
		$this->assertTrue( $good->apply()['ok'] );
		$this->assertTrue( $good->status()['active'] );
		$this->assertTrue( $good->remove()['ok'] );
		$this->assertSame( $original, file_get_contents( $file ) );
	}

	public function test_prefetch_with_side_effects_is_declined(): void {
		$shield   = [
			'prefetch'       => true,
			'prefetch_paths' => [ '/carrito', '/pagar', '/cuenta' ],
		] + self::ALL;
		$prefetch = [ 'HTTP_SEC_PURPOSE' => 'prefetch' ];
		$decide   = static fn( string $path, array $get = [], array $server = [ 'HTTP_SEC_PURPOSE' => 'prefetch' ], array $s = [] ): ?string => EarlyGuard::decide( RequestType::FrontendHtml, $path, 'wp-json', $s ?: $shield, $get, [], $server )['reason'] ?? null;

		$this->assertSame( 'prefetch', $decide( '/', [ 'add-to-cart' => '15' ] ) );
		$this->assertSame( 'prefetch', $decide( '/cuenta/customer-logout', [ '_wpnonce' => 'x' ] ) );
		$this->assertSame( 'prefetch', $decide( '/carrito' ) );
		$this->assertSame( 'prefetch', $decide( '/pagar/order-received/12' ) );
		$this->assertSame( 'prefetch', $decide( '/wp-login.php', [ 'action' => 'logout' ] ) );
		$this->assertSame( 'prefetch', $decide( '/carrito', [], [ 'HTTP_SEC_PURPOSE' => 'prefetch;prerender' ] ), 'el prerender también es precarga' );
		$this->assertNull( $decide( '/producto/x/' ), 'una página normal se precarga' );
		$this->assertNull( $decide( '/carritos-de-pintura/' ), 'prefijo de ruta completo, no de texto' );
		$this->assertNull( $decide( '/', [ 'add-to-cart' => '15' ], [] ), 'un clic real agrega al carrito' );
		$this->assertNull( $decide( '/', [ 'add-to-cart' => '15' ], $prefetch, [ 'prefetch' => false ] + self::ALL ), 'apagado' );
		$this->assertSame( 503, EarlyGuard::decide( RequestType::FrontendHtml, '/carrito', 'wp-json', $shield, [], [], $prefetch )['status'] ?? 0 );

		$this->assertSame( 'refused', PrefetchProbe::classify( 503, 'prefetch refused: not eligible', true ) );
		$this->assertSame( 'ok', PrefetchProbe::classify( 200, '', true ) );
		$this->assertSame( 'direct', PrefetchProbe::classify( 200, '', false ), 'sin Cloudflare de por medio no se puede saber' );

		$notes = CacheabilityAudit::analyze(
			200,
			[
				'cache-control'     => 'max-age=0',
				'speculation-rules' => '"/cdn-cgi/speculation"',
				'cf-cache-status'   => 'DYNAMIC',
			]
		)['notes'];
		$this->assertStringContainsString( 'Speed Brain', implode( ' ', $notes ) );
		$this->assertStringContainsString( '503', implode( ' ', $notes ) );
	}

	public function test_facet_combinations_for_crawlers(): void {
		$shield  = [ 'facets' => true, 'facets_views' => true ] + self::ALL;
		$google  = [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ];
		$person  = [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 11; CUBOT KINGKONG 5 Pro) AppleWebKit/537.36 Chrome/130 Mobile Safari/537.36' ];
		$decide  = static fn( array $get, array $server, array $s = [] ): ?array => EarlyGuard::decide( RequestType::FrontendHtml, '/tiendas/', 'wp-json', $s ?: $shield, $get, [], $server );
		$combo   = [
			'filter_color' => 'rojo',
			'filter_talla' => 'm',
		];

		$r = $decide( $combo, $google );
		$this->assertSame( 403, $r['status'] ?? 0 );
		$this->assertSame( 'facets', $r['reason'] ?? '' );
		$this->assertContains( 'Cache-Control: no-store', $r['headers'] ?? [] );
		$this->assertSame( 'facets', $decide( [ 'filter_color' => 'rojo,azul' ], $google )['reason'] ?? null, 'varios valores de un filtro' );
		$this->assertSame( 'facets', $decide( [ 'filter_color' => [ 'rojo' ] ], $google )['reason'] ?? null );
		$this->assertSame( 'facets', $decide( [ 'filter_color' => 'rojo', 'min_price' => '1' ], $google )['reason'] ?? null, 'precio con otro filtro' );
		$this->assertSame( 'facets', $decide( [ 'rating_filter' => '4', 'stock_status' => 'instock' ], [ 'HTTP_USER_AGENT' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' ] )['reason'] ?? null );
		$this->assertSame( 'facets', $decide( [ 'orderby' => 'price' ], [ 'HTTP_USER_AGENT' => 'meta-externalagent/1.1' ] )['reason'] ?? null, 'orden del listado' );

		// La base de cada filtro, las personas, la sesión y los ajustes apagados siguen.
		$this->assertNull( $decide( [ 'filter_color' => 'rojo' ], $google ) );
		$this->assertNull( $decide( [ 'filter_color' => 'rojo', 'query_type_color' => 'or' ], $google ), 'query_type_ no es otro filtro' );
		$this->assertNull( $decide( [ 'min_price' => '1', 'max_price' => '9' ], $google ), 'el precio es un solo filtro' );
		$this->assertNull( $decide( $combo, $person ), 'CUBOT no es un bot' );
		$this->assertNull( $decide( $combo, [] ) );
		$this->assertNull( $decide( [ 'orderby' => 'price' ], $google, [ 'facets_views' => false ] + $shield ) );
		$this->assertNull( $decide( $combo, $google, [ 'facets' => false ] + self::ALL ) );
		$this->assertNull( EarlyGuard::decide( RequestType::FrontendHtml, '/tiendas/', 'wp-json', $shield, $combo, [ 'wordpress_logged_in_x' => '1' ], $google ) );
		$this->assertNull( EarlyGuard::decide( RequestType::WcAjax, '/', 'wp-json', $shield, $combo, [], $google ) );
		foreach ( [ 'Storebot-Google/1.0', 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'Sogou web spider/4.0', 'Mozilla/5.0 (compatible; GoogleOther)' ] as $ua ) {
			$this->assertTrue( EarlyGuard::isCrawler( [ 'HTTP_USER_AGENT' => $ua ] ), $ua );
		}
		$this->assertFalse( EarlyGuard::isCrawler( [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36' ] ) );

		// robots.txt: dentro de cada grupo, una sola vez, y con las mismas decisiones que la guardia.
		$robots = (string) ShieldModule::robotsTxt( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nUser-agent: GPTBot\nUser-agent: CCBot\nDisallow: /privado/\n\nSitemap: https://tienda.example.com/sitemap.xml\n" );
		$this->assertSame( 2, substr_count( $robots, ShieldModule::ROBOTS_MARKER ) );
		$this->assertStringContainsString( "User-agent: CCBot\n# " . ShieldModule::ROBOTS_MARKER, $robots, 'tras la última línea User-agent del grupo' );
		$this->assertSame( $robots, ShieldModule::robotsTxt( $robots ), 'idempotente' );
		$this->assertStringContainsString( "User-agent: *\n# " . ShieldModule::ROBOTS_MARKER, (string) ShieldModule::robotsTxt( "Sitemap: https://x/s.xml\n" ), 'sin grupos, uno para todos' );

		$rules   = ShieldModule::robotsRules( true );
		$blocked = static function ( string $url ) use ( $rules ): bool {
			foreach ( $rules as $rule ) {
				$pattern = '#^' . str_replace( '\*', '.*', preg_quote( substr( $rule, strlen( 'Disallow: ' ) ), '#' ) ) . '#';
				if ( 1 === preg_match( $pattern, $url ) ) {
					return true;
				}
			}
			return false;
		};
		foreach ( [ '/tiendas/?filter_color=rojo&filter_talla=m', '/tiendas/?filter_color=rojo,azul', '/tiendas/?filter_color=rojo%2Cazul', '/c/?rating_filter=4&filter_color=rojo', '/c/?min_price=1&max_price=9&filter_color=rojo', '/c/?stock_status=instock&rating_filter=5', '/c/?orderby=price' ] as $url ) {
			$this->assertTrue( $blocked( $url ), $url );
		}
		foreach ( [ '/tiendas/?filter_color=rojo', '/tiendas/?filter_color=rojo&query_type_color=or', '/c/?min_price=1&max_price=9', '/c/?rating_filter=4', '/c/page/2/?filter_color=rojo', '/producto/x/' ] as $url ) {
			$this->assertFalse( $blocked( $url ), $url );
		}
		$this->assertNotContains( 'Disallow: /*?*orderby=', ShieldModule::robotsRules( false ) );

		// Cloudflare: apagada por defecto; activa, misma definición con wildcard (todos los planes).
		$limits = PlanLimits::fromFile( 'free', self::pluginDir() . '/data/cloudflare-limits.json' );
		$refs   = static fn( FirewallTemplates $fw ): array => array_map( static fn( array $r ): string => $r['rule']->ref, $fw->rules() );
		$this->assertNotContains( 'itb-k7-fw-facets', $refs( new FirewallTemplates( self::site(), $limits, [], false ) ) );
		$fw = new FirewallTemplates( self::site(), $limits, [ 'facets' => true ], false );
		$this->assertContains( 'itb-k7-fw-facets', $refs( $fw ) );
		$expr = $fw->facetsExpression();
		$this->assertLessThanOrEqual( Templates::MAX_EXPRESSION, strlen( $expr ) );
		$this->assertStringContainsString( 'cf.client.bot or lower(http.user_agent) contains "bot/"', $expr );
		$this->assertStringContainsString( 'http.request.uri.query wildcard "*filter_*filter_*"', $expr );
		$this->assertStringContainsString( 'http.request.uri.query wildcard "*filter_*%2c*"', $expr );
		$this->assertStringContainsString( 'http.request.uri.query contains "orderby="', $expr );
		$this->assertStringNotContainsString( 'matches', $expr );
		$this->assertStringNotContainsString( '**', $expr, 'dos asteriscos seguidos no son válidos en wildcard' );
		$bare = (string) preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/', '""', $expr );
		$this->assertSame( substr_count( $bare, '(' ), substr_count( $bare, ')' ) );
		$this->assertStringNotContainsString( 'orderby', ( new FirewallTemplates( self::site(), $limits, [ 'facets' => true ], false, [], null, false ) )->facetsExpression() );
	}
}
