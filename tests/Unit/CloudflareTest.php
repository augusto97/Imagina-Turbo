<?php
/**
 * Cloudflare: bóveda del token, cliente, respuestas, plantillas, plan, purga y analíticas.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Cloudflare\Analytics;
use ImaginaTurbo\Cloudflare\Client;
use ImaginaTurbo\Cloudflare\CloudflarePurger;
use ImaginaTurbo\Cloudflare\Connector;
use ImaginaTurbo\Cloudflare\Phases;
use ImaginaTurbo\Cloudflare\Planner;
use ImaginaTurbo\Cloudflare\PlanLimits;
use ImaginaTurbo\Cloudflare\Response;
use ImaginaTurbo\Cloudflare\Rule;
use ImaginaTurbo\Cloudflare\SiteContext;
use ImaginaTurbo\Cloudflare\State;
use ImaginaTurbo\Cloudflare\Templates;
use ImaginaTurbo\Cloudflare\TokenVault;
use ImaginaTurbo\Modules\CacheCoordinator\Cacheability;

final class CloudflareTest extends TestCase {

	private const TOKEN = 'cfut_PRUEBA0123456789abcdefghijklmnopqrstuvwxyz';

	/** @var array<string, mixed> */
	private array $options = [];

	/** @var list<array{method: string, url: string, body: mixed}> */
	private array $requests = [];

	/** @var list<array{0: int, 1: array<string, mixed>, 2?: array<string, string>}> Respuestas en orden. */
	private array $responses = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options   = [];
		$this->requests  = [];
		$this->responses = [];
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ): array {
				$this->requests[] = [
					'method' => $args['method'],
					'url'    => $url,
					'body'   => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
				];
				$next = array_shift( $this->responses ) ?? [ 200, [ 'success' => true, 'errors' => [], 'result' => [] ] ];
				return [
					'code'    => $next[0],
					'body'    => json_encode( $next[1] ),
					'headers' => $next[2] ?? [],
				];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $r ): int => $r['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $r ): string => $r['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( array $r, string $h ): string => $r['headers'][ $h ] ?? '' );
	}

	private function client( ?array &$slept = null ): Client {
		$slept = [];
		return new Client(
			static fn(): string => self::TOKEN,
			null,
			static function ( int $s ) use ( &$slept ): void {
				$slept[] = $s;
			}
		);
	}

	private static function site( bool $edgeHeaders = true, array $ignore = [ 'utm_source', 'fbclid' ] ): SiteContext {
		return new SiteContext(
			'tienda.example.com',
			'',
			'wp-json',
			[
				'cart'     => [ '/carrito' ],
				'checkout' => [ '/finalizar-compra' ],
				'account'  => [ '/mi-cuenta' ],
				'login'    => [ '/acceso' ],
			],
			[ 'wordpress_logged_in_', 'wp-postpass_', 'itb_bypass', 'woocommerce_items_in_cart' ],
			$ignore,
			[ '.staging.example.com', '.kinsta.cloud' ],
			'itb-k7-',
			$edgeHeaders,
			86400
		);
	}

	private static function limits( string $plan = 'free' ): PlanLimits {
		return PlanLimits::fromFile( $plan, self::pluginDir() . '/data/cloudflare-limits.json' );
	}

	public function test_token_vault_encrypts_and_detects_key_rotation(): void {
		$vault = new TokenVault( 'clave-a' );
		$this->assertFalse( $vault->store( 'corto' ) );
		$this->assertTrue( $vault->store( '  ' . self::TOKEN . "\n" ) );
		$stored = $this->options[ TokenVault::OPTION ];
		$this->assertStringNotContainsString( 'PRUEBA', (string) json_encode( $stored ), 'nunca en claro' );
		$this->assertSame( 'wxyz', $stored['last4'] );
		$this->assertSame( self::TOKEN, $vault->read() );
		$this->assertSame( 'ok', $vault->status()['state'] );

		// Las claves de wp-config cambiaron: no se puede leer y se marca para reconectar.
		$rotated = new TokenVault( 'clave-b' );
		$this->assertNull( $rotated->read() );
		$this->assertSame( 'rotated', $rotated->status()['state'] );

		// Dato manipulado.
		$this->options[ TokenVault::OPTION ]['box'] = base64_encode( random_bytes( 60 ) );
		$this->assertNull( $vault->read() );
		$this->assertSame( 'broken', $vault->status()['state'] );

		$vault->forget();
		$this->assertSame( 'none', $vault->status()['state'] );
	}

	public function test_response_parsing(): void {
		$ok = Response::fromHttp( 200, '{"success":true,"errors":[],"result":{"id":"x"}}' );
		$this->assertTrue( $ok->ok() );
		$this->assertSame( [ 'id' => 'x' ], $ok->resultArray() );

		$err = Response::fromHttp( 429, '{"success":false,"errors":[{"code":1134,"message":"rate limit"}],"result":null}', 60 );
		$this->assertFalse( $err->ok() );
		$this->assertTrue( $err->retryable() );
		$this->assertTrue( $err->hasCode( 1134 ) );
		$this->assertSame( '1134: rate limit', $err->message() );
		$this->assertSame( 60, $err->retryAfter );

		// GraphQL: sin "success", con data y errors.
		$this->assertTrue( Response::fromHttp( 200, '{"data":{"viewer":{}},"errors":null}' )->ok() );
		$this->assertFalse( Response::fromHttp( 200, '{"data":null,"errors":[{"message":"bad field"}]}' )->ok() );
		$this->assertFalse( Response::fromHttp( 502, '<html>' )->ok() );
		$this->assertTrue( Response::fromHttp( 502, '<html>' )->retryable() );
		$this->assertFalse( Response::fromHttp( 400, '{"success":false,"errors":[]}' )->retryable() );
	}

	public function test_client_retries_with_backoff_and_returns_long_waits(): void {
		$this->responses = [
			[ 500, [ 'success' => false, 'errors' => [] ] ],
			[ 503, [ 'success' => false, 'errors' => [] ] ],
			[ 200, [ 'success' => true, 'errors' => [], 'result' => [ 'ok' => 1 ] ] ],
		];
		$client = $this->client( $slept );
		$this->assertTrue( $client->get( '/zones' )->ok() );
		$this->assertSame( [ 1, 2 ], $slept );
		$this->assertStringStartsWith( Client::BASE . '/zones', $this->requests[0]['url'] );

		// 429 con Retry-After largo: no se espera dentro del request.
		$this->responses = [ [ 429, [ 'success' => false, 'errors' => [ [ 'code' => 1134, 'message' => 'x' ] ] ], [ 'retry-after' => '60' ] ] ];
		$client          = $this->client( $slept );
		$r               = $client->post( '/zones/x/purge_cache', [ 'tags' => [ 'a' ] ] );
		$this->assertSame( 429, $r->status );
		$this->assertSame( 60, $r->retryAfter );
		$this->assertSame( [], $slept );

		// Rutas raras no salen.
		$count = count( $this->requests );
		$this->assertFalse( $client->get( '/zones/../user' )->ok() );
		$this->assertFalse( $client->get( 'https://otro.example.com' )->ok() );
		$this->assertCount( $count, $this->requests );

		// Sin token no hay petición.
		$none = new Client( static fn(): ?string => null );
		$this->assertTrue( $none->get( '/zones' )->hasCode( 'itb_no_token' ) );
	}

	public function test_zone_candidates(): void {
		$this->assertSame( [ 'www.tienda.com.co', 'tienda.com.co', 'com.co' ], Connector::candidates( 'WWW.tienda.com.co' ) );
		$this->assertSame( [], Connector::candidates( '127.0.0.1' ) );
		$this->assertSame( [], Connector::candidates( 'localhost' ) );
		$this->assertSame( 'free', PlanLimits::normalize( '' ) );
		$this->assertSame( 'business', PlanLimits::normalize( 'Business' ) );
		$this->assertSame( 'free', PlanLimits::normalize( 'raro' ) );
		$this->assertSame( 10, self::limits()->rulesFor( Phases::CACHE ) );
		$this->assertSame( 1, self::limits()->rulesFor( Phases::RATELIMIT ) );
		$this->assertTrue( self::limits( 'enterprise' )->bool( 'cache_key_query_lists' ) );
	}

	public function test_guard_blocks_staging_and_foreign_zones(): void {
		$this->assertSame( '', self::site()->guard( 'example.com' ) );
		$this->assertSame( '', self::site()->guard( 'tienda.example.com' ) );
		$this->assertNotSame( '', self::site()->guard( 'otro.com' ) );
		$this->assertNotSame( '', self::site()->guard( 'ample.com' ), 'sufijo sin punto no es la misma zona' );
		$staging = new SiteContext( 'mi.staging.example.com', '', 'wp-json', [], [], [], [ '.staging.example.com' ], 'itb-x-', true, 3600 );
		$this->assertNotSame( '', $staging->guard( 'example.com' ) );
	}

	public function test_templates(): void {
		$templates = new Templates( self::site(), self::limits() );
		$rules     = $templates->rules();
		$refs      = array_map( static fn( array $r ): string => $r['rule']->ref, $rules );
		$this->assertSame( [ 'itb-k7-cache-static', 'itb-k7-cache-html', 'itb-k7-strip-campaign' ], $refs );

		$html = $templates->htmlExpression();
		$this->assertStringContainsString( 'http.host eq "tienda.example.com"', $html );
		$this->assertStringContainsString( 'not http.cookie contains "wordpress_logged_in_"', $html );
		$this->assertStringContainsString( 'not (http.request.uri.path eq "/carrito" or starts_with(http.request.uri.path, "/carrito/"))', $html );
		$this->assertStringContainsString( 'not starts_with(http.request.uri.path, "/wp-json/")', $html );
		$this->assertStringNotContainsString( '"s="', $html, 's= por subcadena sacaría páginas cacheables' );
		$this->assertStringNotContainsString( 'http.request.method', $html, 'campo no confirmado en reglas de caché' );
		$this->assertSame( 'respect_origin', $templates->htmlParameters()['edge_ttl']['mode'], 'nunca override_origin en el HTML' );
		$this->assertArrayNotHasKey( 'cache_key', $templates->htmlParameters(), 'la lista de parámetros es solo Enterprise' );

		$campaign = $templates->campaignParameters()['uri']['query']['expression'];
		$this->assertSame( 'remove_query_args(http.request.uri.query, "utm_source", "fbclid")', $campaign );

		// Enterprise: llave de caché en vez de reescritura.
		$enterprise = new Templates( self::site(), self::limits( 'enterprise' ) );
		$this->assertSame( [ 'utm_source', 'fbclid' ], $enterprise->htmlParameters()['cache_key']['custom_key']['query_string']['exclude']['list'] );
		$this->assertCount( 2, $enterprise->rules() );

		// Sin cabeceras del coordinador, el HTML queda bloqueado.
		$blocked = ( new Templates( self::site( false ), self::limits() ) )->rules();
		$this->assertNotSame( '', $blocked[1]['blocked'] );
		$this->assertSame( '', $blocked[0]['blocked'], 'los estáticos no dependen del coordinador' );

		$this->assertSame( '"a\\"b\\\\c"', Templates::quote( 'a"b\\c' ) );
	}

	public function test_html_expression_stays_within_limit(): void {
		$many = array_map( static fn( int $i ): string => 'cookie_larga_de_plugin_numero_' . $i, range( 1, 140 ) );
		$site = new SiteContext( 'a.com', '', 'wp-json', [], $many, [], [], 'itb-x-', true, 3600 );
		$templates = new Templates( $site, self::limits() );
		$this->assertStringContainsString( 'cookie_larga_de_plugin_numero_140"', $templates->htmlExpression(), 'las cookies nunca se recortan' );
		$this->assertNotSame( '', $templates->rules()[1]['blocked'], 'si no cabe, la regla no se propone' );

		// Con pocas cookies pero muchas exclusiones, caben quitando las de parámetros.
		$some = array_map( static fn( int $i ): string => 'cookie_larga_de_plugin_numero_' . $i, range( 1, 58 ) );
		$site = new SiteContext( 'a.com', '', 'wp-json', [], $some, [], [], 'itb-x-', true, 3600 );
		$expr = ( new Templates( $site, self::limits() ) )->htmlExpression();
		$this->assertLessThanOrEqual( Templates::MAX_EXPRESSION, strlen( $expr ) );
		$this->assertStringNotContainsString( 'uri.query', $expr );
	}

	/**
	 * @param list<array<string, mixed>> $cache Reglas de caché actuales.
	 */
	private static function zone( array $cache, array $settings = [] ): array {
		$phase = static fn( array $rules, bool $exists = true ): array => [
			'readable' => true,
			'exists'   => $exists,
			'id'       => $exists ? 'rs1' : '',
			'rules'    => $rules,
			'error'    => '',
		];
		$out = [
			'phases'   => [
				Phases::CACHE     => $phase( $cache ),
				Phases::TRANSFORM => $phase( [], false ),
			],
			'settings' => [],
		];
		foreach ( Templates::settings() as $def ) {
			$out['settings'][ $def['id'] ] = [
				'readable' => true,
				'value'    => $settings[ $def['id'] ] ?? $def['recommended'],
				'editable' => true,
				'error'    => '',
			];
		}
		return $out;
	}

	private static function planner( Templates $templates, PlanLimits $limits ): Planner {
		return new Planner( $templates->rules(), [ Phases::CACHE, Phases::TRANSFORM ], Templates::settings(), 'itb-k7-', $limits, new State() );
	}

	/**
	 * @param list<array<string, mixed>> $changes Cambios.
	 * @return array<string, string>
	 */
	private static function actions( array $changes ): array {
		return array_column( $changes, 'action', 'id' );
	}

	public function test_planner_creates_updates_and_respects_foreign_rules(): void {
		$templates = new Templates( self::site(), self::limits() );
		$client    = [
			'id'         => 'c1',
			'ref'        => 'cliente',
			'expression' => 'true',
			'action'     => 'set_cache_settings',
		];
		$plan      = self::actions( self::planner( $templates, self::limits() )->plan( self::zone( [ $client ], [ 'http3' => 'off' ] ) ) );
		$this->assertSame( 'create', $plan['rule:itb-k7-cache-static'] );
		$this->assertSame( 'create', $plan['rule:itb-k7-strip-campaign'] );
		$this->assertSame( 'update', $plan['setting:http3'] );
		$this->assertSame( 'none', $plan['setting:early_hints'] );
		$this->assertArrayNotHasKey( 'rule:cliente', $plan, 'las reglas del cliente no se tocan' );
		$this->assertArrayNotHasKey( 'setting:ssl', $plan, 'SSL es solo lectura' );

		// Ya aplicada tal cual (Cloudflare añade id, version, last_updated) → sin cambios.
		$static  = $templates->rules()[0]['rule'];
		$current = $static->body() + [
			'id'           => 'r1',
			'version'      => '1',
			'last_updated' => 'x',
		];
		$plan    = self::actions( self::planner( $templates, self::limits() )->plan( self::zone( [ $current ] ) ) );
		$this->assertSame( 'none', $plan['rule:itb-k7-cache-static'] );

		// Distinta → modificar; propia que ya no se recomienda → eliminar.
		$changed                      = $current;
		$changed['action_parameters'] = [ 'cache' => false ];
		$old                          = [
			'id'         => 'r9',
			'ref'        => 'itb-k7-vieja',
			'expression' => 'true',
			'action'     => 'set_cache_settings',
		];
		$other                        = [
			'id'         => 'r8',
			'ref'        => 'itb-zz-cache-html',
			'expression' => 'true',
			'action'     => 'set_cache_settings',
		];
		$plan                         = self::actions( self::planner( $templates, self::limits() )->plan( self::zone( [ $changed, $old, $other ] ) ) );
		$this->assertSame( 'update', $plan['rule:itb-k7-cache-static'] );
		$this->assertSame( 'delete', $plan['rule:itb-k7-vieja'] );
		$this->assertArrayNotHasKey( 'rule:itb-zz-cache-html', $plan, 'reglas de otro sitio de la misma zona' );
	}

	public function test_planner_detects_drift_and_plan_limits(): void {
		$templates = new Templates( self::site(), self::limits() );
		$static    = $templates->rules()[0]['rule'];
		$applied   = $static->body() + [ 'id' => 'r1' ];
		( new State() )->markApplied( $static->ref, Rule::fromApi( Phases::CACHE, $applied )->hash() );

		$edited                                  = $applied;
		$edited['action_parameters']['edge_ttl'] = [
			'mode'    => 'override_origin',
			'default' => 60,
		];
		$plan                                    = self::actions( self::planner( $templates, self::limits() )->plan( self::zone( [ $edited ] ) ) );
		$this->assertSame( 'drifted', $plan['rule:itb-k7-cache-static'] );

		// Fase llena (10 reglas en Free): la creación queda bloqueada.
		$full = array_map(
			static fn( int $i ): array => [
				'id'         => 'c' . $i,
				'ref'        => 'cliente-' . $i,
				'expression' => 'true',
				'action'     => 'set_cache_settings',
			],
			range( 1, 10 )
		);
		$plan = self::actions( self::planner( $templates, self::limits() )->plan( self::zone( $full ) ) );
		$this->assertSame( 'blocked', $plan['rule:itb-k7-cache-static'] );
		$this->assertSame( 'create', self::actions( self::planner( $templates, self::limits( 'pro' ) )->plan( self::zone( $full ) ) )['rule:itb-k7-cache-static'] );

		// La regla del HTML existe pero el coordinador dejó de emitir cabeceras: se propone retirarla.
		$noHeaders = new Templates( self::site( false ), self::limits() );
		$html      = $templates->rules()[1]['rule']->body() + [ 'id' => 'h1' ];
		$plan      = self::actions( self::planner( $noHeaders, self::limits() )->plan( self::zone( [ $html ] ) ) );
		$this->assertSame( 'delete', $plan['rule:itb-k7-cache-html'] );
	}

	public function test_contains_and_same(): void {
		$this->assertTrue( Planner::contains( [ 'a' => 1, 'b' => [ 'c' => 2, 'x' => 9 ] ], [ 'b' => [ 'c' => 2 ] ] ) );
		$this->assertFalse( Planner::contains( [ 'a' => [ 1, 2, 3 ] ], [ 'a' => [ 1, 2 ] ] ), 'las listas se comparan enteras' );
		$this->assertTrue( Planner::same( 0, '0' ) );
		$this->assertFalse( Planner::same( true, '1' ) );
		$this->assertFalse( Planner::same( 'on', 'off' ) );
	}

	public function test_purger_batches_and_returns_leftovers(): void {
		$tags   = array_map( static fn( int $i ): string => 'k7-p-' . $i, range( 1, 250 ) );
		$purger = new CloudflarePurger( $this->client(), 'z1', 'tienda.example.com', false, 100, 2 );
		$result = $purger->purge( $tags, [ 'https://tienda.example.com/a/', 'https://otro.com/b/' ], false );
		$this->assertFalse( $result['ok'] );
		$this->assertCount( 2, $this->requests );
		$this->assertCount( 100, $this->requests[0]['body']['tags'] );
		$this->assertCount( 50, $result['tags_left'] );
		$this->assertSame( [ 'https://tienda.example.com/a/' ], $result['urls_left'], 'otras URLs del host no se purgan' );

		// 429: lo pendiente vuelve con la espera pedida.
		$this->requests  = [];
		$this->responses = [ [ 429, [ 'success' => false, 'errors' => [ [ 'code' => 1134, 'message' => 'x' ] ] ], [ 'retry-after' => '60' ] ] ];
		$result          = ( new CloudflarePurger( $this->client(), 'z1', 'tienda.example.com', false, 100, 5 ) )->purge( [ 'k7-home' ], [], false );
		$this->assertSame( [ 'k7-home' ], $result['tags_left'] );
		$this->assertSame( 60, $result['retry_after'] );

		// Todo: una sola llamada con purge_everything.
		$this->requests = [];
		$this->assertTrue( ( new CloudflarePurger( $this->client(), 'z1', 'tienda.example.com', false, 100, 5 ) )->purge( [], [], true )['ok'] );
		$this->assertSame( [ 'purge_everything' => true ], $this->requests[0]['body'] );

		// Staging o sin zona: nunca.
		$this->assertFalse( ( new CloudflarePurger( $this->client(), 'z1', 'x', true, 100, 5 ) )->available() );
		$this->assertFalse( ( new CloudflarePurger( $this->client(), '', 'x', false, 100, 5 ) )->available() );
	}

	public function test_analytics_parse(): void {
		$status = [ 'viewer' => [ 'zones' => [ [ 'httpRequestsAdaptiveGroups' => [ [ 'count' => 80, 'dimensions' => [ 'cacheStatus' => 'hit' ] ], [ 'count' => 5, 'dimensions' => [ 'cacheStatus' => 'revalidated' ] ], [ 'count' => 15, 'dimensions' => [ 'cacheStatus' => 'dynamic' ] ] ] ] ] ] ];
		$paths  = [ 'viewer' => [ 'zones' => [ [ 'httpRequestsAdaptiveGroups' => [ [ 'count' => 9, 'dimensions' => [ 'clientRequestPath' => '/carrito/' ] ] ] ] ] ] ];
		$parsed = Analytics::parse( $status, $paths );
		$this->assertSame( 100, $parsed['requests'] );
		$this->assertSame( 85, $parsed['cached'] );
		$this->assertSame( 0.85, $parsed['ratio'] );
		$this->assertSame( 'hit', $parsed['by_status'][0]['status'] );
		$this->assertSame( '/carrito/', $parsed['top_uncached'][0]['path'] );
		$this->assertNull( Analytics::parse( [], [] )['ratio'] );
	}

	public function test_bypass_paths_from_matrix(): void {
		$patterns = [ '/edd-sl/*', '/wc-api/v*', '/wp.serviceworker', '/tienda/checkout', '/?rest_route=*' ];
		$this->assertTrue( Cacheability::pathBypassed( '/edd-sl/package_download/1', '', $patterns ) );
		$this->assertTrue( Cacheability::pathBypassed( '/wc-api/v3/orders', '', $patterns ) );
		$this->assertTrue( Cacheability::pathBypassed( '/wp.serviceworker', '', $patterns ) );
		$this->assertTrue( Cacheability::pathBypassed( '/tienda/checkout/', '', $patterns ) );
		$this->assertTrue( Cacheability::pathBypassed( '/tienda/checkout/confirmacion', '', $patterns ) );
		$this->assertTrue( Cacheability::pathBypassed( '/', 'rest_route=/wp/v2', $patterns ) );
		$this->assertFalse( Cacheability::pathBypassed( '/tienda/checkouts', '', $patterns ) );
		$this->assertFalse( Cacheability::pathBypassed( '/producto/camisa/', 'color=azul', $patterns ) );
		$decision = Cacheability::decide(
			[
				'edge_ttl'      => 3600,
				'nonce_refresh' => true,
			],
			[
				'type'              => \ImaginaTurbo\Core\Request\RequestType::FrontendHtml,
				'method'            => 'GET',
				'logged_in'         => false,
				'cookies'           => [],
				'bypass_cookies'    => [],
				'status'            => 200,
				'headers'           => [],
				'preview'           => false,
				'search'            => false,
				'password_required' => false,
				'do_not_cache'      => false,
				'bypass_path'       => true,
			]
		);
		$this->assertSame( 'bypass_path', $decision['reason'] );
	}

	public function test_state_keeps_originals_and_counts_footprint(): void {
		$state = new \ImaginaTurbo\Cloudflare\State();
		$state->update( [ 'zone_id' => 'z1' ] );
		$state->rememberOriginal( 'rocket_loader', 'on' );
		$state->rememberOriginal( 'rocket_loader', 'off' );
		$state->rememberOriginal( 'http3', null );
		$state->markApplied( 'itb-k7-cache-html', 'hash1' );
		$state->update( [ 'checked_at' => 5 ] );
		$this->assertSame(
			[
				'rocket_loader' => [
					'value'  => 'on',
					'active' => true,
				],
			],
			$state->originals(),
			'el primer valor manda y otras escrituras no lo borran'
		);
		$this->assertSame(
			[
				'rules'    => 1,
				'settings' => 1,
			],
			$state->footprint()
		);
		$state->setOriginalActive( 'rocket_loader', false );
		$state->markApplied( 'itb-k7-cache-html', null );
		$this->assertSame(
			[
				'rules'    => 0,
				'settings' => 0,
			],
			$state->footprint()
		);
		$this->assertSame( 'z1', $state->zoneId() );
		$state->rememberOriginal( 'rocket_loader', 'off' );
		$this->assertSame( 'on', $state->originals()['rocket_loader']['value'], 'volver a cambiarlo no pisa el original' );
		$this->assertSame( 1, $state->footprint()['settings'] );
	}
}
