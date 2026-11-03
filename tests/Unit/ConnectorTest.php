<?php
/**
 * Conector de Claude (0.22.0): rutas, clientes CIMD, PKCE, MCP y lotes de cambios.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\ModuleCatalog;
use ImaginaTurbo\Core\RiskLevel;
use ImaginaTurbo\Integrations\Abilities;
use ImaginaTurbo\Modules\Connector\ChangeSet;
use ImaginaTurbo\Modules\Connector\ClientMetadata;
use ImaginaTurbo\Modules\Connector\Connector;
use ImaginaTurbo\Modules\Connector\ConnectorModule;
use ImaginaTurbo\Modules\Connector\McpServer;
use ImaginaTurbo\Modules\Connector\OAuthServer;
use ImaginaTurbo\Modules\Connector\TokenStore;
use ImaginaTurbo\Modules\Connector\Tools;
use ImaginaTurbo\Repository\EventRepository;

/**
 * Sin red ni base de datos.
 */
final class ConnectorTest extends TestCase {

	private const CLAUDE_CODE = 'https://claude.ai/oauth/claude-code-client-metadata';

	/** @var array<string, mixed> */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		$this->transients = [];
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://tienda.example' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'untrailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/\\' ) );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Tienda' );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_transient' )->alias( fn( string $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $k, $v ): bool {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
		ClientMetadata::useFetcher( null );
	}

	protected function tearDown(): void {
		ClientMetadata::useFetcher( null );
		ChangeSet::useMeasurer( null );
		parent::tearDown();
	}

	public function test_routes(): void {
		$this->assertSame( 'mcp', ConnectorModule::route( '/itb-mcp' ) );
		$this->assertSame( 'mcp', ConnectorModule::route( '/itb-mcp/' ) );
		$this->assertSame( 'authorize', ConnectorModule::route( '/itb-oauth/authorize' ) );
		$this->assertSame( 'token', ConnectorModule::route( '/itb-oauth/token' ) );
		$this->assertSame( 'resource', ConnectorModule::route( '/.well-known/oauth-protected-resource/itb-mcp' ) );
		// Las tres rutas que prueban los clientes MCP para un issuer con ruta.
		$this->assertSame( 'server', ConnectorModule::route( '/.well-known/oauth-authorization-server/itb-oauth' ) );
		$this->assertSame( 'server', ConnectorModule::route( '/.well-known/openid-configuration/itb-oauth' ) );
		$this->assertSame( 'server', ConnectorModule::route( '/itb-oauth/.well-known/openid-configuration' ) );
		$this->assertNull( ConnectorModule::route( '/' ) );
		$this->assertNull( ConnectorModule::route( '/tienda/' ) );
		$this->assertNull( ConnectorModule::route( '/itb-oauth' ) );
	}

	public function test_module_is_moderate_and_off_by_default(): void {
		$def = ( new ModuleCatalog() )->get( 'connector' );
		$this->assertNotNull( $def );
		$this->assertSame( RiskLevel::Moderate, $def->risk );
		$this->assertFalse( $def->defaultEnabled );
		$this->assertTrue( $def->isAvailable() );
	}

	public function test_metadata(): void {
		$server = OAuthServer::serverMetadata();
		$this->assertSame( 'https://tienda.example/itb-oauth', $server['issuer'] );
		$this->assertSame( 'https://tienda.example/itb-oauth/token', $server['token_endpoint'] );
		$this->assertSame( [ 'S256' ], $server['code_challenge_methods_supported'] );
		// Las dos señales con las que Claude elige CIMD en lugar de registro dinámico.
		$this->assertTrue( $server['client_id_metadata_document_supported'] );
		$this->assertSame( [ 'none' ], $server['token_endpoint_auth_methods_supported'] );
		$this->assertArrayNotHasKey( 'registration_endpoint', $server, 'sin registro dinámico' );

		$resource = OAuthServer::resourceMetadata();
		$this->assertSame( 'https://tienda.example/itb-mcp', $resource['resource'] );
		$this->assertSame( [ 'https://tienda.example/itb-oauth' ], $resource['authorization_servers'] );
		$this->assertSame( 'https://tienda.example/.well-known/oauth-protected-resource/itb-mcp', Connector::resourceMetadataUrl() );
	}

	public function test_only_anthropic_clients(): void {
		$this->assertTrue( ClientMetadata::validClientId( self::CLAUDE_CODE ) );
		$this->assertTrue( ClientMetadata::validClientId( 'https://claude.com/oauth/mcp-client' ) );
		$this->assertFalse( ClientMetadata::validClientId( 'http://claude.ai/oauth/x' ), 'solo https' );
		$this->assertFalse( ClientMetadata::validClientId( 'https://claude.ai/' ), 'con ruta' );
		$this->assertFalse( ClientMetadata::validClientId( 'https://claude.ai.atacante.com/x' ) );
		$this->assertFalse( ClientMetadata::validClientId( 'https://atacante.com/claude.ai' ) );
		$this->assertFalse( ClientMetadata::validClientId( 'https://user@claude.ai/x' ) );
		$this->assertIsString( ClientMetadata::load( 'https://atacante.com/client' ), 'ni se descarga' );

		$fetched = 0;
		ClientMetadata::useFetcher(
			static function ( string $url ) use ( &$fetched ): array {
				++$fetched;
				return [
					'client_id'     => $url,
					'client_name'   => 'Claude Code',
					'redirect_uris' => [ 'http://localhost/callback', 'http://127.0.0.1/callback', 'http://evil.example/cb', 'javascript:alert(1)' ],
				];
			}
		);
		$client = ClientMetadata::load( self::CLAUDE_CODE );
		$this->assertIsArray( $client );
		$this->assertSame( [ 'http://localhost/callback', 'http://127.0.0.1/callback' ], $client['redirect_uris'], 'http solo para loopback' );
		$this->assertSame( 'claude.ai', $client['host'] );
		ClientMetadata::load( self::CLAUDE_CODE );
		$this->assertSame( 1, $fetched, 'el documento se guarda un día' );

		$this->assertIsString( ClientMetadata::parse( self::CLAUDE_CODE, [ 'client_id' => 'https://claude.ai/otro', 'redirect_uris' => [ 'https://claude.ai/cb' ] ] ), 'client_id distinto de la URL' );
	}

	public function test_redirect_matching(): void {
		$claude = [ 'https://claude.ai/api/mcp/auth_callback' ];
		$code   = [ 'http://localhost/callback', 'http://127.0.0.1/callback' ];
		$this->assertTrue( ClientMetadata::redirectMatches( 'https://claude.ai/api/mcp/auth_callback', $claude ) );
		$this->assertFalse( ClientMetadata::redirectMatches( 'https://claude.ai/api/mcp/auth_callback/x', $claude ) );
		$this->assertFalse( ClientMetadata::redirectMatches( 'https://claude.ai:8443/api/mcp/auth_callback', $claude ), 'el puerto solo se ignora en loopback' );
		$this->assertTrue( ClientMetadata::redirectMatches( 'http://localhost:3118/callback', $code ), 'Claude Code: cualquier puerto' );
		$this->assertTrue( ClientMetadata::redirectMatches( 'http://127.0.0.1:61234/callback', $code ) );
		$this->assertFalse( ClientMetadata::redirectMatches( 'http://localhost:3118/otra', $code ) );
		$this->assertFalse( ClientMetadata::redirectMatches( 'http://evil.example:3118/callback', $code ) );
		$this->assertFalse( ClientMetadata::redirectMatches( 'https://localhost:3118/callback', $code ) );
		$this->assertTrue( ClientMetadata::isLoopback( 'http://127.0.0.1:5/callback' ) );
	}

	public function test_pkce_and_params(): void {
		// RFC 7636, apéndice B.
		$this->assertTrue( OAuthServer::pkce( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' ) );
		$this->assertFalse( OAuthServer::pkce( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXl', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' ) );
		$this->assertFalse( OAuthServer::pkce( 'corto', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' ) );
		$this->assertFalse( OAuthServer::pkce( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', '' ) );

		$p = OAuthServer::params(
			[
				'client_id' => self::CLAUDE_CODE,
				'state'     => "abc\r\nSet-Cookie: x=1",
				'scope'     => [ 'itb.read' ],
			]
		);
		$this->assertSame( self::CLAUDE_CODE, $p['client_id'] );
		$this->assertSame( '', $p['state'], 'sin caracteres de control' );
		$this->assertSame( '', $p['scope'], 'solo cadenas' );

		$this->assertSame( [ 'itb.read', 'itb.adjust' ], Connector::scopes( 'itb.read offline_access itb.adjust itb.read' ) );
		$this->assertSame( Connector::canonical( 'HTTPS://Tienda.Example/itb-mcp/' ), Connector::canonical( 'https://tienda.example/itb-mcp' ) );
		$this->assertNotSame( Connector::canonical( 'https://otra.example/itb-mcp' ), Connector::canonical( 'https://tienda.example/itb-mcp' ) );
	}

	public function test_bearer_and_origin(): void {
		$this->assertSame( 'itbat_abc-_', McpServer::bearer( [ 'HTTP_AUTHORIZATION' => 'Bearer itbat_abc-_' ] ) );
		$this->assertSame( 'itbat_x', McpServer::bearer( [ 'REDIRECT_HTTP_AUTHORIZATION' => 'bearer itbat_x' ] ) );
		$this->assertSame( '', McpServer::bearer( [ 'HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz' ] ) );
		$this->assertSame( '', McpServer::bearer( [ 'QUERY_STRING' => 'access_token=itbat_x' ] ), 'nunca de la URL' );
		$this->assertTrue( McpServer::originAllowed( '' ) );
		$this->assertTrue( McpServer::originAllowed( 'https://claude.ai' ) );
		$this->assertTrue( McpServer::originAllowed( 'https://tienda.example' ) );
		$this->assertFalse( McpServer::originAllowed( 'https://atacante.example' ) );
	}

	public function test_mcp_dispatch(): void {
		$server = $this->server();
		$read   = $this->grant( Connector::SCOPE_READ );
		$adjust = $this->grant( Connector::SCOPE_READ . ' ' . Connector::SCOPE_ADJUST );

		[ $status, $body ] = $server->dispatch(
			[
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => [ 'protocolVersion' => '2025-06-18' ],
			],
			$read
		);
		$this->assertSame( 200, $status );
		$this->assertSame( '2025-06-18', $body['result']['protocolVersion'] ?? null );
		$this->assertStringContainsString( 'diagnose_site', (string) ( $body['result']['instructions'] ?? '' ) );
		$this->assertSame( '2025-11-25', $server->dispatch( [ 'id' => 2, 'method' => 'initialize', 'params' => [ 'protocolVersion' => '1999-01-01' ] ], $read )[1]['result']['protocolVersion'] ?? null );

		$this->assertSame( [ 202, null ], $server->dispatch( [ 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ], $read ) );
		$this->assertSame( 400, $server->dispatch( [ [ 'id' => 1, 'method' => 'ping' ] ], $read )[0], 'sin lotes' );
		$this->assertSame( -32601, $server->dispatch( [ 'id' => 3, 'method' => 'resources/list' ], $read )[1]['error']['code'] ?? null );

		$names = static fn( array $body ): array => array_column( $body['result']['tools'] ?? [], 'name' );
		$r     = $names( $server->dispatch( [ 'id' => 4, 'method' => 'tools/list' ], $read )[1] ?? [] );
		$a     = $names( $server->dispatch( [ 'id' => 5, 'method' => 'tools/list' ], $adjust )[1] ?? [] );
		$this->assertContains( 'diagnose_site', $r );
		$this->assertNotContains( 'apply_change_set', $r, 'solo lectura no ve las de ajuste' );
		$this->assertContains( 'apply_change_set', $a );
		$this->assertSame( [ 'purge_cache', 'set_module', 'update_settings', 'apply_change_set', 'revert_change' ], array_values( array_diff( $a, $r ) ) );

		$call = $server->dispatch(
			[
				'id'     => 6,
				'method' => 'tools/call',
				'params' => [
					'name'      => 'apply_change_set',
					'arguments' => [],
				],
			],
			$read
		)[1];
		$this->assertTrue( $call['result']['isError'] ?? false, 'solo lectura no puede ajustar aunque llame a la herramienta' );
		$this->assertStringContainsString( 'solo lectura', (string) ( $call['result']['content'][0]['text'] ?? '' ) );

		$prompt = $server->dispatch( [ 'id' => 7, 'method' => 'prompts/get', 'params' => [ 'name' => 'acelerar_sitio' ] ], $read )[1];
		$this->assertSame( 'user', $prompt['result']['messages'][0]['role'] ?? null );
	}

	public function test_tools_never_touch_protections(): void {
		$areas = array_keys( Tools::WRITE_AREAS );
		foreach ( [ 'shield', 'firewall', 'cloudflare', 'turnstile', 'plugin_filter', 'fleet', 'hub', 'settings', 'safety' ] as $forbidden ) {
			$this->assertNotContains( $forbidden, $areas, $forbidden );
			$this->assertNotContains( $forbidden, Tools::CHANGE_SET_MODULES, $forbidden );
		}
		foreach ( Tools::CHANGE_SET_MODULES as $id ) {
			$risk = ( new ModuleCatalog() )->get( $id )?->risk;
			$this->assertNotSame( RiskLevel::Invasive, $risk, $id );
		}
		$this->assertSame( [], array_diff( Abilities::AI_MODULES, Tools::CHANGE_SET_MODULES ) );
		$this->assertSame( [ 'spec_mode' => 'prefetch' ], Tools::changes( [ 'spec_mode' => 'prefetch', 'confirm' => true, 'Bad Key' => 1, 5 => 'x' ] ), 'el confirm solo lo pone el lote' );
	}

	public function test_change_set_validation_and_regressions(): void {
		$this->assertIsString( ChangeSet::validSteps( [] ) );
		$this->assertIsString( ChangeSet::validSteps( array_fill( 0, 6, [ 'module' => 'rum', 'enabled' => true ] ) ) );
		$this->assertIsString( ChangeSet::validSteps( [ [ 'module' => 'shield', 'enabled' => false ] ] ), 'no apaga el escudo' );
		$this->assertIsString( ChangeSet::validSteps( [ [ 'module' => 'plugin_filter', 'enabled' => true ] ] ), 'lo invasivo, en el admin' );
		$this->assertIsString( ChangeSet::validSteps( [ [ 'area' => 'shield', 'changes' => [ 'xmlrpc' => false ] ] ] ) );
		$this->assertIsString( ChangeSet::validSteps( [ [ 'area' => 'navigation', 'changes' => [] ] ] ) );
		$this->assertIsString( ChangeSet::validSteps( [ [ 'area' => 'navigation', 'changes' => [ 'confirm' => true, 'spec_mode' => 'prerender' ] ] ] ), 'el confirm solo lo pone el lote' );
		$this->assertSame(
			[
				[
					'area'    => 'navigation',
					'changes' => [ 'spec_mode' => 'prerender' ],
					'keys'    => [ 'spec_mode' ],
				],
				[
					'module'  => 'cache_coordinator',
					'enabled' => true,
				],
			],
			ChangeSet::validSteps(
				[
					[
						'area'    => 'navigation',
						'changes' => [ 'spec_mode' => 'prerender' ],
					],
					[
						'module'  => 'cache_coordinator',
						'enabled' => true,
					],
				]
			)
		);

		$ok = static fn( int $status = 200, int $ms = 300, int $bytes = 50000, bool $fatal = false ): array => [
			'path'    => '/',
			'status'  => $status,
			'ms'      => $ms,
			'bytes'   => $bytes,
			'fatal'   => $fatal,
			'headers' => [],
		];
		$this->assertNull( ChangeSet::regression( $ok(), $ok( 200, 450 ) ) );
		$this->assertNull( ChangeSet::regression( $ok(), $ok( 200, 1700 ) ), 'menos de 1,5 s más no cuenta' );
		$this->assertNotNull( ChangeSet::regression( $ok(), $ok( 200, 2000 ) ) );
		$this->assertNotNull( ChangeSet::regression( $ok(), $ok( 500 ) ) );
		$this->assertNotNull( ChangeSet::regression( $ok(), $ok( 404 ) ) );
		$this->assertNull( ChangeSet::regression( $ok( 301 ), $ok( 301 ) ) );
		$this->assertNotNull( ChangeSet::regression( $ok(), $ok( 200, 300, 10000 ) ), 'casi vacía' );
		$this->assertNotNull( ChangeSet::regression( $ok(), $ok( 200, 300, 50000, true ) ), 'error de PHP' );
		$this->assertNotNull( ChangeSet::regression( $ok(), 'timeout' ) );
		$this->assertNull( ChangeSet::regression( 'timeout', $ok() ), 'sin medida de antes no se compara' );
		$this->assertTrue( ChangeSet::fatal( '<b>Fatal error</b>: Uncaught Error' ) );
		$this->assertTrue( ChangeSet::fatal( 'There has been a critical error on this website.' ) );
		$this->assertFalse( ChangeSet::fatal( '<p>Error al cargar el carrito</p>' ) );
		$this->assertIsString( ChangeSet::measure( 'https://otro.example/', false ), 'solo rutas del sitio' );
		$this->assertIsString( ChangeSet::measure( '//otro.example/', false ) );
	}

	public function test_undo_touches_only_what_the_change_set_changed(): void {
		$before = [
			'spec_eagerness' => 'conservative',
			'shield_facets'  => false,
			'modules'        => [
				'rum'       => true,
				'firewall'  => false,
				'turnstile' => false,
			],
			'tweaks'         => [ 'emojis' => true ],
		];
		$after  = $before;

		$after['spec_eagerness']   = 'moderate';
		$after['modules']['rum']   = false;
		$after['tweaks']['embeds'] = true;
		$undo                      = ChangeSet::undo( $before, $after );
		$this->assertSame( [ 'spec_eagerness', 'modules', 'tweaks' ], array_keys( $undo ) );
		$this->assertSame( [ 'rum' ], array_keys( $undo['modules']['map'] ), 'solo la entrada que cambió' );

		// Después, el dueño activa el firewall y Turnstile y cambia la anticipación: deshacer no lo pisa.
		$current                         = $after;
		$current['modules']['firewall']  = true;
		$current['modules']['turnstile'] = true;
		$current['shield_facets']        = true;
		$current['spec_eagerness']       = 'eager';
		$changes                         = ChangeSet::restoration( $undo, $current );
		$this->assertArrayNotHasKey( 'spec_eagerness', $changes, 'el dueño lo cambió después: se respeta' );
		$this->assertArrayNotHasKey( 'shield_facets', $changes );
		$this->assertSame(
			[
				'rum'       => true,
				'firewall'  => true,
				'turnstile' => true,
			],
			$changes['modules']
		);
		$this->assertSame( [ 'emojis' => true ], $changes['tweaks'], 'la entrada que añadió el lote se quita' );

		// Deshacer en el mismo momento devuelve exactamente lo de antes.
		$this->assertSame( 'conservative', ChangeSet::restoration( $undo, $after )['spec_eagerness'] ?? null );
	}

	public function test_closed_list_of_settings(): void {
		// Lo seguro pasa por update_settings; lo moderado, solo en un lote.
		$this->assertSame( [ 'params' => [ 'hints_lcp' => true ], 'keys' => [ 'hints_lcp' ] ], Tools::validate( 'navigation', [ 'hints_lcp' => true ], false ) );
		$this->assertStringContainsString( 'apply_change_set', (string) Tools::validate( 'navigation', [ 'spec_mode' => 'prerender' ], false ) );
		$this->assertIsArray( Tools::validate( 'navigation', [ 'spec_mode' => 'prerender' ], true ) );
		// Fuera de la lista: lo que borra contenido, rompe el pago, purga todo o escribe archivos.
		foreach ( [
			[ 'scripts', [ 'script_delay' => [ 'stripe' ] ] ],
			[ 'database', [ 'db_keep_revisions' => 1 ] ],
			[ 'tweaks', [ 'revisions' => 1 ] ],
			[ 'tweaks', [ 'tweaks' => [ 'revisions' => true ] ] ],
			[ 'tweaks', [ 'tweaks' => [ 'comments' => true ] ] ],
			[ 'tweaks', [ 'tweaks' => [ 'feeds' => true ] ] ],
			[ 'tweaks', [ 'tweaks' => [ 'jquery_migrate' => true ] ] ],
			[ 'cache', [ 'mode' => 'headers' ] ],
			[ 'cache', [ 'edge_variants' => true ] ],
			[ 'navigation', [ 'lazy_documents' => [] ] ],
			[ 'navigation', [ 'spec_eagerness' => 'eager' ] ],
			[ 'navigation', [ 'rum_rate' => 1 ] ],
			[ 'profiler', [ 'sample_rate' => 1 ] ],
			[ 'navigation', [] ],
			[ 'navigation', [ 'hints_lcp' => 'true' ] ],
		] as [ $area, $changes ] ) {
			$this->assertIsString( Tools::validate( $area, $changes, true ), $area . ' ' . wp_json_encode( $changes ) );
		}
		$this->assertArrayNotHasKey( 'scripts', Tools::WRITE_AREAS );
		$this->assertArrayNotHasKey( 'database', Tools::WRITE_AREAS );
		$this->assertSame( [ 'profiler', 'tweaks', 'speculation', 'rum', 'frontend_hints', 'cache_coordinator' ], Tools::CHANGE_SET_MODULES );

		// Para deshacer un lote que muere a mitad: los valores de antes de lo que toca.
		$this->assertSame(
			[
				'spec_mode' => 'prefetch',
				'modules'   => [ 'cache_coordinator' => false ],
			],
			ChangeSet::touched(
				[
					'spec_mode' => 'prefetch',
					'hints_lcp' => true,
					'modules'   => [ 'cache_coordinator' => false ],
				],
				[
					[ 'keys' => [ 'spec_mode' ] ],
					[
						'module'  => 'cache_coordinator',
						'enabled' => true,
					],
				]
			)
		);
	}

	public function test_oauth_hardening(): void {
		// Solo las direcciones de vuelta de Claude, aunque el documento declare otras.
		$client = ClientMetadata::parse(
			'https://claude.ai/oauth/x',
			[
				'client_id'     => 'https://claude.ai/oauth/x',
				'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback', 'https://evil.tld/cb', 'https://claude.ai/otra', 'http://localhost:8080/callback', 'http://user@localhost/callback' ],
			]
		);
		$this->assertIsArray( $client );
		$this->assertSame( [ 'https://claude.ai/api/mcp/auth_callback' ], $client['redirect_uris'] );
		$this->assertFalse( ClientMetadata::validClientId( 'https://claude.ai:8443/oauth/x' ), 'sin puerto' );
		$this->assertTrue( OAuthServer::adjustRequested( 'itb.read itb.adjust' ) );
		$this->assertTrue( OAuthServer::adjustRequested( '' ) );
		$this->assertFalse( OAuthServer::adjustRequested( 'itb.read' ), 'el alcance pedido es el techo' );
		$this->assertSame( '2001:0db8:0001:0002::/64', Connector::ipKey( '2001:db8:1:2:aaaa:bbbb:cccc:dddd' ) === Connector::ipKey( '2001:db8:1:2::1' ) ? '2001:0db8:0001:0002::/64' : 'distinto' );
		$this->assertSame( '203.0.113.9', Connector::ipKey( '203.0.113.9' ) );
	}

	public function test_rest_special_params_and_budget(): void {
		$this->assertSame(
			[ 'spec_mode' => 'prefetch' ],
			Tools::changes(
				[
					'spec_mode' => 'prefetch',
					'_method'   => 'DELETE',
					'_fields'   => 'x',
					'_envelope' => 1,
					'context'   => 'edit',
					'id'        => 'shield',
				]
			)
		);
		$many = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$many[ 'key_' . chr( 97 + intdiv( $i, 26 ) ) . chr( 97 + $i % 26 ) ] = $i;
		}
		$this->assertCount( 30, Tools::changes( $many ) );

		$this->assertTrue( Connector::budget( 'prueba', 2, 60 ) );
		$this->assertTrue( Connector::budget( 'prueba', 2, 60 ) );
		$this->assertFalse( Connector::budget( 'prueba', 2, 60 ) );
		$this->assertTrue( Connector::budget( 'otra', 2, 60 ) );
	}

	private function server(): McpServer {
		$wpdb         = \Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$events       = new EventLog( new EventRepository( $wpdb ) );
		return new McpServer( new TokenStore(), new Tools( $events, new ChangeSet( new Container() ) ) );
	}

	/**
	 * @return array{id: int, user_id: int, client_id: string, client_name: string, scope: string, resource: string, access_expires: int, refresh_expires: int, created_at: int, last_used_at: int, last_ip: string, revoked: int}
	 */
	private function grant( string $scope ): array {
		return [
			'id'              => 1,
			'user_id'         => 1,
			'client_id'       => self::CLAUDE_CODE,
			'client_name'     => 'Claude',
			'scope'           => $scope,
			'resource'        => 'https://tienda.example/itb-mcp',
			'access_expires'  => time() + 60,
			'refresh_expires' => time() + 60,
			'created_at'      => time(),
			'last_used_at'    => time(),
			'last_ip'         => '',
			'revoked'         => 0,
		];
	}
}
