<?php
/**
 * 0.23.0: reglas ajustables a mano (firewall, caché, gestión de reglas en Cloudflare) y purga.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Cloudflare\EdgeOptions;
use ImaginaTurbo\Cloudflare\FirewallTemplates;
use ImaginaTurbo\Cloudflare\Phases;
use ImaginaTurbo\Cloudflare\Planner;
use ImaginaTurbo\Cloudflare\PlanLimits;
use ImaginaTurbo\Cloudflare\Rule;
use ImaginaTurbo\Cloudflare\SiteContext;
use ImaginaTurbo\Cloudflare\State;
use ImaginaTurbo\Cloudflare\Templates;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Modules\CacheCoordinator\Cacheability;

final class ManualRulesTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options = [];
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://tienda.example.com/' );
		Functions\when( '_n' )->alias( static fn( string $one, string $many, int $n ): string => 1 === $n ? $one : $many );
	}

	private static function site( array $cache = [] ): SiteContext {
		return new SiteContext( 'tienda.example.com', '', 'wp-json', [ 'login' => [ '/acceso' ] ], [], [], [], 'itb-k7-', true, 86400, false, [ '/wp-json/wompi/' ], false, [], false, [], $cache );
	}

	private static function limits( string $plan ): PlanLimits {
		return PlanLimits::fromFile( $plan, self::pluginDir() . '/data/cloudflare-limits.json' );
	}

	/**
	 * Paréntesis equilibrados fuera de las cadenas.
	 */
	private function assertBalanced( string $expression ): void {
		$bare = (string) preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/', '""', $expression );
		$this->assertSame( substr_count( $bare, '(' ), substr_count( $bare, ')' ), $expression );
		$this->assertSame( substr_count( $bare, '{' ), substr_count( $bare, '}' ), $expression );
	}

	public function test_ip_normalization(): void {
		$this->assertSame( '203.0.113.10', EdgeOptions::ip( ' 203.0.113.10 ' ) );
		$this->assertSame( '203.0.113.0/24', EdgeOptions::ip( '203.0.113.77/24' ), 'dirección de red' );
		$this->assertSame( '198.51.0.0/16', EdgeOptions::ip( '198.51.100.1/16' ) );
		$this->assertSame( '203.0.113.10', EdgeOptions::ip( '203.0.113.10/32' ) );
		$this->assertSame( '2001:db8::1', EdgeOptions::ip( '2001:0db8:0000::1' ) );
		$this->assertSame( '2001:db8:abcd::/48', EdgeOptions::ip( '2001:db8:abcd:12::/48' ) );
		foreach ( [ '10.0.0.0/8', '0.0.0.0/0', '2001:db8::/31', '300.1.1.1', '1.2.3.4/33', '1.2.3.4/x', '1.2.3.4/24/1', 'localhost', '', '1.2.3.4 or 1' ] as $bad ) {
			$this->assertNull( EdgeOptions::ip( $bad ), $bad );
		}
	}

	public function test_path_user_agent_country_cookie(): void {
		$this->assertSame( '/wp-json/erp', EdgeOptions::path( '/wp-json/erp/' ) );
		$this->assertSame( '/tienda/b', EdgeOptions::path( 'https://tienda.example.com/Tienda/b' ) );
		foreach ( [ '/', '', 'sin-barra', '/a/../b', '/a b', '/a"b', '/a?x=1', '//doble' ] as $bad ) {
			$this->assertNull( EdgeOptions::path( $bad ), $bad );
		}
		$this->assertSame( 'mi-scraper', EdgeOptions::userAgent( 'Mi-Scraper' ) );
		foreach ( [ 'mozilla', 'chrome', 'bot', 'safari', 'googlebot', 'gecko', 'ab', 'x"y', 'imaginaturbo', 'whatsapp' ] as $bad ) {
			$this->assertNull( EdgeOptions::userAgent( $bad ), $bad );
		}
		$this->assertSame( 'CO', EdgeOptions::country( ' co ' ) );
		$this->assertSame( 'T1', EdgeOptions::country( 't1' ) );
		$this->assertNull( EdgeOptions::country( 'COL' ) );
		$this->assertNull( EdgeOptions::country( 'C"' ) );
		$this->assertSame( 'mi_cookie', EdgeOptions::cookie( 'mi_cookie' ) );
		$this->assertNull( EdgeOptions::cookie( 'a;b' ) );
	}

	public function test_firewall_options_normalize(): void {
		$this->assertSame( EdgeOptions::firewallDefaults(), EdgeOptions::firewall( [] ) );
		$o = EdgeOptions::firewall(
			[
				'allow_ips'    => [ '203.0.113.5', 'basura', '203.0.113.5' ],
				'actions'      => [
					'exploits' => 'block',
					'bots'     => 'otra',
					'malo'     => 'block',
				],
				'bots_off'     => [ 'sqlmap', 'inventado' ],
				'countries'    => [ 'co', 'mx', 'XYZ' ],
				'country_mode' => 'others',
				'rl_requests'  => 99999,
				'rl_period'    => 7,
				'rl_timeout'   => 3600,
			]
		);
		$this->assertSame( [ '203.0.113.5' ], $o['allow_ips'] );
		$this->assertSame( [ 'exploits' => 'block' ], $o['actions'] );
		$this->assertSame( [ 'sqlmap' ], $o['bots_off'] );
		$this->assertSame( [ 'CO', 'MX' ], $o['countries'] );
		$this->assertSame( 'others', $o['country_mode'] );
		$this->assertSame( EdgeOptions::RL_REQUESTS_MAX, $o['rl_requests'] );
		$this->assertSame( 10, $o['rl_period'], 'un periodo que no existe vuelve al de siempre' );
		$this->assertSame( 3600, $o['rl_timeout'] );

		$problems = EdgeOptions::problems(
			[
				'allow_ips' => [ '203.0.113.5', '10.0.0.0/8', '' ],
				'countries' => [ 'COL' ],
			],
			'firewall'
		);
		$this->assertCount( 2, $problems );
		$this->assertStringContainsString( '10.0.0.0/8', $problems[0] );
		$this->assertSame( [], EdgeOptions::problems( [ 'allow_ips' => [ '203.0.113.5' ] ], 'firewall' ) );
		$this->assertCount( 1, EdgeOptions::problems( [ 'allow_ips' => array_map( static fn( int $i ): string => '203.0.113.' . $i, range( 1, EdgeOptions::MAX_IPS + 1 ) ) ], 'firewall' ) );
		$this->assertCount( 1, EdgeOptions::problems( [ 'bypass_paths' => [ '/' ] ], 'cache' ) );
	}

	public function test_defaults_keep_rules_identical(): void {
		foreach ( [ 'free', 'pro', 'business' ] as $plan ) {
			$before = new FirewallTemplates( self::site(), self::limits( $plan ), [ 'facets' => true ], true, [], 1_000_000 );
			$after  = new FirewallTemplates( self::site(), self::limits( $plan ), [ 'facets' => true ], true, [], 1_000_000, true, false, EdgeOptions::firewallDefaults() );
			$this->assertSame( $before->webhooks(), $before->excluded(), 'sin excepciones, lo mismo que los webhooks' );
			$this->assertSame(
				array_map( static fn( array $r ): string => $r['rule']->hash(), $before->rules() ),
				array_map( static fn( array $r ): string => $r['rule']->hash(), $after->rules() ),
				$plan
			);
		}
		$free = ( new FirewallTemplates( self::site(), self::limits( 'free' ), [], true ) )->rateLimitRule( 'r' )->body()['ratelimit'];
		$this->assertSame( [ 10, 5, 10 ], [ $free['period'], $free['requests_per_period'], $free['mitigation_timeout'] ] );
		$pro = ( new FirewallTemplates( self::site(), self::limits( 'pro' ), [], true ) )->rateLimitRule( 'r' )->body()['ratelimit'];
		$this->assertSame( [ 10, 5, 600 ], [ $pro['period'], $pro['requests_per_period'], $pro['mitigation_timeout'] ] );
		$this->assertSame( 2592000, ( new Templates( self::site(), self::limits( 'free' ) ) )->staticParameters()['edge_ttl']['default'] );
	}

	public function test_exceptions_actions_and_bots(): void {
		$custom = [
			'allow_ips'   => [ '203.0.113.0/24', '2001:db8::1' ],
			'allow_paths' => [ '/wp-json/erp' ],
			'actions'     => [
				'exploits' => 'block',
				'surface'  => 'challenge',
			],
			'bots_off'    => FirewallTemplates::BAD_BOTS,
			'bots_extra'  => [ 'mi-scraper' ],
		];
		$fw     = new FirewallTemplates( self::site(), self::limits( 'free' ), [], false, [ 'itb-k7-fw-bots' => 1 ], 30 * DAY_IN_SECONDS, true, false, $custom );
		$rules  = [];
		foreach ( $fw->rules() as $r ) {
			$rules[ $r['rule']->ref ] = $r;
			$this->assertBalanced( $r['rule']->expression );
			$this->assertLessThanOrEqual( Templates::MAX_EXPRESSION, strlen( $r['rule']->expression ) );
		}
		$exploits = $rules['itb-k7-fw-exploits']['rule'];
		$this->assertStringContainsString( 'ip.src in {203.0.113.0/24 2001:db8::1}', $exploits->expression );
		$this->assertStringContainsString( '(http.request.uri.path eq "/wp-json/erp" or starts_with(http.request.uri.path, "/wp-json/erp/"))', $exploits->expression );
		$this->assertStringContainsString( 'starts_with(http.request.uri.path, "/wc-api/")', $exploits->expression, 'los webhooks siguen excluidos' );
		$this->assertSame( 'block', $exploits->action );
		$this->assertSame( 'managed_challenge', $rules['itb-k7-fw-surface']['rule']->action, 'siempre desafío aunque hayan pasado 7 días' );
		$this->assertSame( 'block', $rules['itb-k7-fw-bots']['rule']->action, 'automático: 7 días después, bloqueo' );
		$this->assertSame( [ 'mi-scraper' ], $fw->botList() );
		$this->assertStringContainsString( '"mi-scraper"', $rules['itb-k7-fw-bots']['rule']->expression );
		$this->assertStringNotContainsString( 'sqlmap', $rules['itb-k7-fw-bots']['rule']->expression );
		$this->assertStringContainsString( 'Excepciones: 2', $rules['itb-k7-fw-exploits']['description'] );

		$empty = new FirewallTemplates( self::site(), self::limits( 'free' ), [], false, [], null, true, false, [ 'bots_off' => FirewallTemplates::BAD_BOTS ] );
		$bots  = array_values( array_filter( $empty->rules(), static fn( array $r ): bool => 'itb-k7-fw-bots' === $r['rule']->ref ) )[0];
		$this->assertNotSame( '', $bots['blocked'], 'sin escáneres, la regla no se propone' );
	}

	public function test_countries_rule(): void {
		$off = new FirewallTemplates( self::site(), self::limits( 'free' ), [], false, [], null, true, false, [ 'countries' => [ 'CN' ] ] );
		$this->assertNotContains( 'itb-k7-fw-countries', array_map( static fn( array $r ): string => $r['rule']->ref, $off->rules() ), 'apagada por defecto' );

		$listed = new FirewallTemplates( self::site(), self::limits( 'free' ), [ 'countries' => true ], false, [], null, true, false, [ 'countries' => [ 'CN', 'RU' ] ] );
		$rule   = array_values( array_filter( $listed->rules(), static fn( array $r ): bool => 'itb-k7-fw-countries' === $r['rule']->ref ) )[0];
		$this->assertSame( Phases::WAF, $rule['rule']->phase );
		$this->assertSame( 'managed_challenge', $rule['rule']->action, 'países: desafío por defecto' );
		$this->assertStringContainsString( '(ip.src.country in {"CN" "RU"} and not cf.client.bot)', $rule['rule']->expression );
		$this->assertBalanced( $rule['rule']->expression );
		$this->assertSame( '', $rule['blocked'] );

		$others = new FirewallTemplates(
			self::site(),
			self::limits( 'free' ),
			[ 'countries' => true ],
			false,
			[],
			null,
			true,
			false,
			[
				'countries'    => [ 'CO' ],
				'country_mode' => 'others',
				'actions'      => [ 'countries' => 'block' ],
			]
		);
		$expr   = $others->countriesExpression();
		$this->assertStringContainsString( '(not ip.src.country in {"CO"} and not cf.client.bot)', $expr );
		$this->assertSame( 'block', $others->action( 'itb-k7-fw-countries', 'countries' ) );

		$none  = new FirewallTemplates( self::site(), self::limits( 'free' ), [ 'countries' => true ], false, [], null, true, false, [] );
		$empty = array_values( array_filter( $none->rules(), static fn( array $r ): bool => 'itb-k7-fw-countries' === $r['rule']->ref ) )[0];
		$this->assertNotSame( '', $empty['blocked'], 'sin países no se propone' );
	}

	public function test_lockout_and_ip_match(): void {
		$base = EdgeOptions::firewall(
			[
				'countries' => [ 'CO', 'MX' ],
				'actions'   => [ 'countries' => 'block' ],
			]
		);
		$this->assertTrue( EdgeOptions::locksOut( $base, 'CO', '190.1.2.3' ) );
		$this->assertFalse( EdgeOptions::locksOut( $base, 'US', '190.1.2.3' ) );
		$this->assertFalse( EdgeOptions::locksOut( $base, '', '190.1.2.3' ), 'sin país, no se sabe' );
		$this->assertFalse( EdgeOptions::locksOut( [ 'allow_ips' => [ '190.1.0.0/16' ] ] + $base, 'CO', '190.1.2.3' ), 'su IP está en las excepciones' );
		$this->assertFalse( EdgeOptions::locksOut( [ 'actions' => [ 'countries' => 'challenge' ] ] + $base, 'CO', '190.1.2.3' ), 'un desafío se pasa' );
		$others = [ 'country_mode' => 'others' ] + $base;
		$this->assertTrue( EdgeOptions::locksOut( $others, 'US', '190.1.2.3' ) );
		$this->assertFalse( EdgeOptions::locksOut( $others, 'CO', '190.1.2.3' ) );

		$this->assertTrue( EdgeOptions::ipMatches( '203.0.113.200', [ '203.0.113.0/24' ] ) );
		$this->assertFalse( EdgeOptions::ipMatches( '203.0.114.1', [ '203.0.113.0/24' ] ) );
		$this->assertTrue( EdgeOptions::ipMatches( '2001:db8::5', [ '2001:db8::/32' ] ) );
		$this->assertTrue( EdgeOptions::ipMatches( '198.51.100.7', [ '198.51.100.7' ] ) );
		$this->assertFalse( EdgeOptions::ipMatches( 'basura', [ '198.51.100.7' ] ) );
	}

	public function test_rate_limit_by_plan_with_custom_values(): void {
		$custom = [
			'rl_requests' => 20,
			'rl_period'   => 300,
			'rl_timeout'  => 3600,
			'rl_paths'    => [ '/mi-cuenta/lost-password' ],
			'allow_ips'   => [ '203.0.113.0/24' ],
		];
		$free   = ( new FirewallTemplates( self::site(), self::limits( 'free' ), [], true, [], null, true, false, $custom ) )->rateLimitRule( 'r' );
		$body   = $free->body()['ratelimit'];
		$this->assertSame( [ 10, 20, 10 ], [ $body['period'], $body['requests_per_period'], $body['mitigation_timeout'] ], 'Free: 10 s, lo único posible' );
		$this->assertStringContainsString( '"/mi-cuenta/lost-password"', $free->expression );
		$this->assertStringNotContainsString( 'ip.src', $free->expression, 'Free no deja mirar la IP en esta fase' );

		$pro = ( new FirewallTemplates( self::site(), self::limits( 'pro' ), [], true, [], null, true, false, $custom ) )->rateLimitRule( 'r' )->body()['ratelimit'];
		$this->assertSame( [ 60, 3600 ], [ $pro['period'], $pro['mitigation_timeout'] ], 'Pro: el mayor permitido que no pasa del elegido' );

		$business = ( new FirewallTemplates( self::site(), self::limits( 'business' ), [], true, [], null, true, false, $custom ) )->rateLimitRule( 'r' );
		$this->assertSame( 300, $business->body()['ratelimit']['period'] );
		$this->assertStringContainsString( 'not ip.src in {203.0.113.0/24}', $business->expression );
		$this->assertBalanced( $business->expression );
	}

	public function test_static_ttl_and_cache_options(): void {
		$site = self::site( [ 'static_ttl' => 31536000 ] );
		$t    = new Templates( $site, self::limits( 'free' ) );
		$this->assertSame( 31536000, $t->staticParameters()['edge_ttl']['default'] );
		$static = array_values( array_filter( $t->rules(), static fn( array $r ): bool => 'itb-k7-cache-static' === $r['rule']->ref ) )[0];
		$this->assertStringContainsString( '1 año', $static['description'] );
		$this->assertSame( 2592000, self::site( [ 'static_ttl' => 123 ] )->staticTtl(), 'un valor que no es opción vuelve a 30 días' );

		$c = EdgeOptions::cache(
			[
				'bypass_paths'   => [ '/cotizador/', '/', 'mal' ],
				'bypass_cookies' => [ 'mi_sesion', 'a b' ],
			]
		);
		$this->assertSame( [ '/cotizador' ], $c['bypass_paths'] );
		$this->assertSame( [ 'mi_sesion' ], $c['bypass_cookies'] );
		// La exclusión cubre la ruta y lo que hay debajo, pero no otra ruta que empiece igual.
		$this->assertTrue( Cacheability::pathBypassed( '/cotizador', '', $c['bypass_paths'] ) );
		$this->assertTrue( Cacheability::pathBypassed( '/cotizador/paso-2', '', $c['bypass_paths'] ) );
		$this->assertFalse( Cacheability::pathBypassed( '/cotizadores', '', $c['bypass_paths'] ) );
	}

	public function test_settings_sanitize_and_import_keep_exceptions(): void {
		$clean = Settings::sanitize(
			[
				'firewall_custom' => [
					'allow_ips' => [ '203.0.113.5', 'x' ],
					'countries' => [ 'co' ],
				],
				'cache_custom'    => [ 'static_ttl' => 604800 ],
			]
		);
		$this->assertSame( [ '203.0.113.5' ], $clean['firewall_custom']['allow_ips'] );
		$this->assertSame( [ 'CO' ], $clean['firewall_custom']['countries'] );
		$this->assertSame( 604800, $clean['cache_custom']['static_ttl'] );
		$this->assertSame( [], Settings::sanitize( [] )['firewall_custom'] );

		$this->options[ Settings::OPTION ] = [ 'firewall_custom' => [ 'allow_ips' => [ '198.51.100.1' ] ] ];
		$settings                          = new Settings();
		$result                            = $settings->import(
			[
				'plugin'   => 'imagina-turbo',
				'version'  => Settings::SCHEMA_VERSION,
				'settings' => [
					'firewall_custom' => [
						'allow_ips'   => [ '6.6.6.6' ],
						'allow_paths' => [ '/todo' ],
						'rl_requests' => 9,
					],
				],
			]
		);
		$this->assertSame( [ '198.51.100.1' ], $result['settings']['firewall_custom']['allow_ips'], 'una importación no abre el firewall' );
		$this->assertSame( [], $result['settings']['firewall_custom']['allow_paths'] );
		$this->assertSame( 9, $result['settings']['firewall_custom']['rl_requests'], 'el resto sí se importa' );
	}

	public function test_unmanaged_rules_are_left_alone(): void {
		$limits    = self::limits( 'free' );
		$templates = new Templates( self::site(), $limits );
		$rules     = $templates->rules();
		$static    = $rules[0]['rule'];
		$this->assertSame( 'itb-k7-cache-static', $static->ref );
		$state = new State();
		$hand  = $static->body() + [ 'id' => 'abc' ];
		$hand['action_parameters']['edge_ttl']['default'] = 60;
		$orphan = [
			'id'          => 'old',
			'ref'         => 'itb-k7-viejo',
			'description' => '[imagina-turbo] Vieja',
			'expression'  => 'true',
			'action'      => 'block',
		];
		$zone   = [
			'phases'   => [
				Phases::CACHE     => [
					'readable' => true,
					'exists'   => true,
					'id'       => 'p1',
					'rules'    => [ $hand, $orphan ],
					'error'    => '',
				],
				Phases::TRANSFORM => [
					'readable' => true,
					'exists'   => false,
					'id'       => '',
					'rules'    => [],
					'error'    => '',
				],
			],
			'settings' => [],
		];
		$state->markApplied( $static->ref, 'otro-hash' );
		$plan   = static fn( array $zone ): array => array_column( ( new Planner( $rules, [ Phases::CACHE, Phases::TRANSFORM ], [], 'itb-k7-', $limits, $state ) )->plan( $zone ), 'action', 'ref' );
		$before = $plan( $zone );
		$this->assertSame( 'drifted', $before['itb-k7-cache-static'] );
		$this->assertSame( 'delete', $before['itb-k7-viejo'] );

		$state->setManaged( 'itb-k7-cache-static', false );
		$state->setManaged( 'itb-k7-viejo', false );
		$after = $plan( $zone );
		$this->assertSame( 'unmanaged', $after['itb-k7-cache-static'], 'ni se sobrescribe' );
		$this->assertSame( 'unmanaged', $after['itb-k7-viejo'], 'ni se borra' );

		// Si el usuario la borró en Cloudflare, no se vuelve a crear.
		$zone['phases'][ Phases::CACHE ]['rules'] = [];
		$this->assertSame( 'unmanaged', $plan( $zone )['itb-k7-cache-static'] );

		$state->setManaged( 'itb-k7-cache-static', true );
		$this->assertSame( 'create', $plan( $zone )['itb-k7-cache-static'], 'al volver a gestionarla, el plan la propone otra vez' );
		$this->assertArrayHasKey( 'itb-k7-viejo', $state->unmanaged() );
		unset( $hand['id'] );
		$this->assertInstanceOf( Rule::class, Rule::fromApi( Phases::CACHE, $hand ) );
	}
}
