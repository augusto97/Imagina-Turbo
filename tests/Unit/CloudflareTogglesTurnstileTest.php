<?php
/**
 * 0.21.0: ajustes de la zona con preferencia por ajuste (bots de IA, 0-RTT), Smart Shield y Turnstile.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Cloudflare\Client;
use ImaginaTurbo\Cloudflare\Inspector;
use ImaginaTurbo\Cloudflare\Planner;
use ImaginaTurbo\Cloudflare\PlanLimits;
use ImaginaTurbo\Cloudflare\State;
use ImaginaTurbo\Cloudflare\Templates;
use ImaginaTurbo\Cloudflare\TokenVault;
use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\Turnstile\Turnstile;
use ImaginaTurbo\Modules\Turnstile\TurnstileModule;
use ImaginaTurbo\Repository\EventRepository;

require_once __DIR__ . '/Stubs/WpErrors.php';

/**
 * Sin red: las respuestas de Cloudflare se simulan.
 */
final class CloudflareTogglesTurnstileTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	/** @var list<array{method: string, url: string, body: mixed}> */
	private array $requests = [];

	/** @var list<mixed> Respuestas en orden: [código, cuerpo] o un WP_Error. */
	private array $responses = [];

	/** @var list<array<string, mixed>> */
	private array $events = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options   = [];
		$this->requests  = [];
		$this->responses = [];
		$this->events    = [];
		$_POST           = [];
		$_GET            = [];
		TurnstileModule::reset();
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $name ) => $this->options[ '_t_' . $name ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ '_t_' . $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ): bool => $v instanceof \WP_Error );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ): string => trim( $s ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		$send = function ( string $url, array $args ) {
			$body             = $args['body'] ?? null;
			$this->requests[] = [
				'method' => $args['method'] ?? 'POST',
				'url'    => $url,
				'body'   => is_string( $body ) ? json_decode( $body, true ) : $body,
			];
			$next             = array_shift( $this->responses ) ?? [
				200,
				[
					'success' => true,
					'errors'  => [],
					'result'  => [],
				],
			];
			if ( $next instanceof \WP_Error ) {
				return $next;
			}
			return [
				'code'    => $next[0],
				'body'    => json_encode( $next[1] ),
				'headers' => [],
			];
		};
		Functions\when( 'wp_remote_request' )->alias( $send );
		Functions\when( 'wp_remote_post' )->alias( $send );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $r ): int => $r['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $r ): string => $r['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
	}

	protected function tearDown(): void {
		$_POST = [];
		$_GET  = [];
		parent::tearDown();
	}

	private function client(): Client {
		return new Client( static fn(): string => 'cfut_PRUEBA0123456789abcdefghijklmnopqrstuvwxyz', null, static function (): void {} );
	}

	public function test_zone_settings_follow_the_chosen_value(): void {
		$byId = static fn( array $prefs = [] ): array => array_column( Templates::settings( false, $prefs ), null, 'id' );
		$d    = $byId();
		$this->assertSame( 'on', $d['0rtt']['recommended'] );
		$this->assertSame( 'block', $d['ai_training']['recommended'] );
		$this->assertTrue( $d['ai_training']['managed'] );
		$this->assertFalse( $d['ai_search']['managed'], 'búsqueda y agentes: sin gestionar por defecto (pueden traer compradores)' );
		$this->assertFalse( $d['ai_user']['managed'] );
		$this->assertFalse( $d['ssl']['managed'] );

		$p = $byId(
			[
				'ai_search'         => 'block',
				'ai_training'       => 'keep',
				'0rtt'              => 'off',
				'http3'             => 'maybe',
				'ssl'               => 'off',
				'browser_cache_ttl' => 14400,
			]
		);
		$this->assertSame( 'block', $p['ai_search']['recommended'] );
		$this->assertTrue( $p['ai_search']['managed'] );
		$this->assertFalse( $p['ai_training']['managed'], '"keep": el plan no lo toca' );
		$this->assertSame( 'block', $p['ai_training']['default'] );
		$this->assertSame( 'off', $p['0rtt']['recommended'] );
		$this->assertSame( 'on', $p['http3']['recommended'], 'un valor que no es una opción se ignora' );
		$this->assertSame( 'strict', $p['ssl']['recommended'], 'solo lectura (regla 8)' );
		$this->assertSame( 14400, $p['browser_cache_ttl']['recommended'] );

		$this->assertSame(
			[
				'ai_search'         => 'block',
				'browser_cache_ttl' => 14400,
			],
			Settings::zonePrefs(
				[
					'ai_search'         => 'block',
					'browser_cache_ttl' => 14400,
					'X Y'               => 'on',
					'http3'             => [ 'on' ],
					'0rtt'              => 'on;drop',
				]
			)
		);
	}

	public function test_planner_never_touches_unmanaged_settings(): void {
		$settings = Templates::settings( false, [ 'ai_training' => 'keep' ] );
		$zone     = [
			'phases'   => [],
			'settings' => [],
		];
		foreach ( $settings as $def ) {
			$zone['settings'][ $def['id'] ] = [
				'readable' => true,
				'value'    => 'ai_training' === $def['id'] ? 'disabled' : ( 'ai_search' === $def['id'] ? 'block' : ( 'keep' === $def['recommended'] ? 'disabled' : $def['recommended'] ) ),
				'editable' => true,
				'error'    => '',
			];
		}
		$zone['settings']['0rtt']['value'] = 'off';
		$planner                           = new Planner( [], [], $settings, 'itb-k7-', PlanLimits::fromFile( 'free', self::pluginDir() . '/data/cloudflare-limits.json' ), new State() );
		$changes                           = array_column( $planner->plan( $zone ), null, 'id' );
		$this->assertSame( 'none', $changes['setting:ai_training']['action'] );
		$this->assertNull( $changes['setting:ai_training']['desired'] );
		$this->assertSame( 'disabled', $changes['setting:ai_training']['current'] );
		$this->assertSame( 'none', $changes['setting:ai_search']['action'], 'bloqueado a mano: no se deshace' );
		$this->assertSame( 'update', $changes['setting:0rtt']['action'] );
		$this->assertSame( 'on', $changes['setting:0rtt']['desired'] );
	}

	public function test_inspector_reads_ai_policies_once_and_smart_shield(): void {
		$this->responses[] = [
			200,
			[
				'success' => true,
				'errors'  => [],
				'result'  => [
					'ai_training' => 'disallow',
					'ai_search'   => 'disabled',
					'fight_mode'  => false,
				],
			],
		];
		$inspector         = new Inspector( $this->client(), 'z1' );
		$this->assertSame( 'disallow', $inspector->setting( 'ai_training' )['value'] );
		$this->assertSame( 'disabled', $inspector->setting( 'ai_search' )['value'] );
		$this->assertFalse( $inspector->setting( 'ai_user' )['readable'], 'campo ausente: no se inventa' );
		$this->assertCount( 1, $this->requests, 'una sola lectura de bot_management' );
		$this->assertStringEndsWith( '/zones/z1/bot_management', $this->requests[0]['url'] );
		$this->assertStringEndsWith( '/zones/z1/settings/0rtt', Inspector::settingPath( 'z1', '0rtt' ) );

		$this->responses[] = [
			200,
			[
				'success' => true,
				'errors'  => [],
				'result'  => [
					'smart_tiered_cache' => [
						'id'       => 'smart_tiered_cache',
						'value'    => 'on',
						'editable' => true,
					],
					'smart_routing'      => [
						'id'       => 'smart_routing',
						'value'    => 'off',
						'editable' => false,
					],
					'healthchecks_count' => 0,
				],
			],
		];
		$this->assertSame(
			[
				'smart_tiered_cache' => [
					'value'    => 'on',
					'editable' => true,
				],
				'smart_routing'      => [
					'value'    => 'off',
					'editable' => false,
				],
			],
			$inspector->smartShield()
		);
		$this->responses[] = [
			403,
			[
				'success' => false,
				'errors'  => [
					[
						'code'    => 10000,
						'message' => 'Authentication error',
					],
				],
			],
		];
		$this->assertNull( $inspector->smartShield() );
	}

	private function turnstile(): Turnstile {
		$wpdb            = \Mockery::mock( \wpdb::class );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 1;
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( string $table, array $row ): int {
				$this->events[] = $row;
				return 1;
			}
		);
		$vault = new TokenVault( 'material-de-prueba', Turnstile::SECRET_OPTION );
		$vault->store( '1x0000000000000000000000000000000AA' );
		return new Turnstile( $vault, new EventLog( new EventRepository( $wpdb ) ) );
	}

	public function test_turnstile_judges_siteverify(): void {
		$this->assertSame(
			[
				'ok'   => true,
				'code' => '',
			],
			Turnstile::judge(
				[
					'success' => true,
					'action'  => 'login',
				],
				'login'
			)
		);
		$this->assertSame(
			'invalid',
			Turnstile::judge(
				[
					'success' => true,
					'action'  => 'login',
				],
				'register'
			)['code'],
			'un token del login no vale para el registro'
		);
		$this->assertSame(
			'expired',
			Turnstile::judge(
				[
					'success'     => false,
					'error-codes' => [ 'timeout-or-duplicate' ],
				],
				'login'
			)['code']
		);
		$this->assertFalse(
			Turnstile::judge(
				[
					'success'     => false,
					'error-codes' => [ 'invalid-input-response' ],
				],
				'login'
			)['ok']
		);
		$why = '';
		$r   = Turnstile::judge(
			[
				'success'     => false,
				'error-codes' => [ 'invalid-input-secret' ],
			],
			'login',
			static function ( string $w ) use ( &$why ): void {
				$why = $w;
			}
		);
		$this->assertTrue( $r['ok'], 'clave secreta revocada: falla abierto' );
		$this->assertSame( 'failopen', $r['code'] );
		$this->assertSame( 'invalid-input-secret', $why );
	}

	public function test_turnstile_verify_fails_open_without_cloudflare(): void {
		$ts = $this->turnstile();
		$this->assertSame( 'missing', $ts->verify( ' ', 'login', '1.2.3.4' )['code'] );
		$this->assertCount( 0, $this->requests, 'sin token no se llama a Cloudflare' );

		$this->responses[] = [
			200,
			[
				'success' => true,
				'action'  => 'login',
			],
		];
		$this->assertTrue( $ts->verify( 'tok', 'login', '203.0.113.9' )['ok'] );
		$sent = $this->requests[0];
		$this->assertSame( Turnstile::SITEVERIFY, $sent['url'] );
		$this->assertSame( '1x0000000000000000000000000000000AA', $sent['body']['secret'] );
		$this->assertSame( '203.0.113.9', $sent['body']['remoteip'] );

		$this->responses[] = new \WP_Error( 'http_request_failed', 'timeout' );
		$this->assertSame(
			[
				'ok'   => true,
				'code' => 'failopen',
			],
			$ts->verify( 'tok', 'login', '' )
		);
		$this->responses[] = [ 502, [] ];
		$this->assertTrue( $ts->verify( 'tok', 'login', '' )['ok'] );
		$this->assertCount( 1, $this->events, 'un solo aviso por hora' );

		$this->responses[] = [
			200,
			[
				'success'     => false,
				'error-codes' => [ 'invalid-input-response' ],
			],
		];
		$this->assertFalse( $ts->test( 'tok', '' )['ok'], 'la prueba del admin nunca falla abierto' );
		$this->responses[] = new \WP_Error( 'http_request_failed', 'timeout' );
		$this->assertSame( [ 'unreachable' ], $ts->test( 'tok', '' )['codes'] );
	}

	/**
	 * Configuración compilada con Turnstile y el servicio en un contenedor.
	 *
	 * @param array<string, bool> $forms Formularios.
	 */
	private function boot( array $forms ): void {
		Runtime::$config = CompiledConfig::fromArray(
			[
				'v'         => CompiledConfig::FORMAT_VERSION,
				'hash'      => 'x',
				'modules'   => [ 'turnstile' => true ],
				'turnstile' => [
					'key'   => '1x00000000000000000000AA',
					'forms' => $forms,
					'skip'  => [ 'cod' ],
				],
			]
		);
		$container       = new \ImaginaTurbo\Core\Container();
		$ts              = $this->turnstile();
		$container->instance( Turnstile::class, $ts );
		$request = new \ImaginaTurbo\Core\Request\RequestContext( \ImaginaTurbo\Core\Request\RequestType::Account, '', '/mi-cuenta/', 'POST' );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		( new TurnstileModule() )->register( $container, $request );
	}

	public function test_core_login_error_never_reveals_the_password(): void {
		$this->boot( [ 'login' => true ] );
		Functions\when( 'did_action' )->justReturn( 1 );
		$_POST = [ 'log' => 'admin' ];
		// Contraseña mal y sin token: el error es el de Turnstile, no el de la contraseña.
		$result = TurnstileModule::checkCoreLogin( new \WP_Error( 'incorrect_password', 'mal' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'itb_turnstile' ], $result->get_error_codes() );

		TurnstileModule::reset();
		$_POST[ Turnstile::FIELD ] = 'tok';
		$this->responses[]         = [
			200,
			[
				'success' => true,
				'action'  => 'login',
			],
		];
		$user                      = (object) [ 'ID' => 1 ];
		$this->assertSame( $user, TurnstileModule::checkCoreLogin( $user ) );
	}

	public function test_woo_forms_and_lost_password_check_once(): void {
		$this->boot(
			[
				'login'        => true,
				'register'     => true,
				'lostpassword' => true,
			]
		);
		$errors = TurnstileModule::checkWooErrors( new \WP_Error() );
		$this->assertSame( 'Completa la verificación de seguridad e inténtalo de nuevo.', $errors->get_error_message() );

		$_POST[ Turnstile::FIELD ] = 'tok';
		$this->responses[]         = [
			200,
			[
				'success' => true,
				'action'  => 'lostpassword',
			],
		];
		$lost                      = new \WP_Error();
		TurnstileModule::checkLostPassword( $lost );
		TurnstileModule::checkLostPassword( $lost );
		$this->assertFalse( $lost->has_errors() );
		$this->assertCount( 1, $this->requests, 'WooCommerce y el core disparan lostpassword_post: se verifica una vez' );

		$this->responses[] = [
			200,
			[
				'success'     => false,
				'error-codes' => [ 'timeout-or-duplicate' ],
			],
		];
		$reg               = TurnstileModule::checkWooRegistration( new \WP_Error() );
		$this->assertStringContainsString( 'caducó', $reg->get_error_message() );
	}

	public function test_checkout_only_on_the_form_submit(): void {
		$this->boot( [ 'checkout' => true ] );
		// Sin wc-ajax=checkout ni el nonce del formulario (por ejemplo, el endpoint de un botón exprés).
		$errors = new \WP_Error();
		TurnstileModule::checkClassicCheckout( [ 'payment_method' => 'stripe' ], $errors );
		$this->assertFalse( $errors->has_errors() );

		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$_GET['wc-ajax'] = 'checkout';
		TurnstileModule::checkClassicCheckout( [ 'payment_method' => 'cod' ], $errors );
		$this->assertFalse( $errors->has_errors(), 'pasarela en la lista de excepciones' );

		$other = new \WP_Error( 'billing', 'Falta la dirección' );
		TurnstileModule::checkClassicCheckout( [ 'payment_method' => 'bacs' ], $other );
		$this->assertSame( [ 'billing' ], $other->get_error_codes(), 'con otros errores no se gasta el token' );

		TurnstileModule::checkClassicCheckout( [ 'payment_method' => 'bacs' ], $errors );
		$this->assertSame( [ 'itb_turnstile' ], $errors->get_error_codes() );
		$this->assertCount( 0, $this->requests );

		// Por bloques: solo el POST, con el token en los datos de extensión.
		TurnstileModule::reset();
		TurnstileModule::checkBlockCheckout( null, new \WP_REST_Request( 'PUT', [] ) );
		$this->responses[] = [
			200,
			[
				'success' => true,
				'action'  => 'checkout',
			],
		];
		TurnstileModule::checkBlockCheckout(
			null,
			new \WP_REST_Request(
				'POST',
				[
					'payment_method' => 'bacs',
					'extensions'     => [ Turnstile::EXTENSION => [ 'turnstile' => 'tok' ] ],
				]
			)
		);
		$this->assertSame( 'tok', $this->requests[0]['body']['response'] );

		TurnstileModule::reset();
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		TurnstileModule::checkBlockCheckout( null, new \WP_REST_Request( 'POST', [ 'payment_method' => 'bacs' ] ) );
	}

	public function test_widget_markup_and_settings(): void {
		$this->boot( [ 'login' => true ] );
		Functions\when( 'wp_script_is' )->justReturn( true );
		$html = TurnstileModule::container( 'checkout', true, true );
		$this->assertStringContainsString( 'class="itb-ts"', $html );
		$this->assertStringContainsString( 'data-k="1x00000000000000000000AA"', $html );
		$this->assertStringContainsString( 'data-action="checkout"', $html );
		$this->assertStringContainsString( 'data-u="https://challenges.cloudflare.com/turnstile/v0/api.js"', $html );
		$this->assertStringContainsString( 'data-now="1" data-block="1"', $html );

		$s = Settings::sanitize(
			[
				'turnstile_site_key' => ' 0x4AAAAAAABkMYinukE8nzY ',
				'turnstile_forms'    => [
					'login'    => true,
					'other'    => true,
					'register' => 'yes',
				],
				'turnstile_skip'     => [ 'stripe', 'no válido', 'stripe' ],
				'turnstile_verified' => 'zz',
			]
		);
		$this->assertSame( '0x4AAAAAAABkMYinukE8nzY', $s['turnstile_site_key'] );
		$this->assertSame(
			[
				'login'        => true,
				'register'     => false,
				'lostpassword' => false,
				'checkout'     => false,
			],
			$s['turnstile_forms']
		);
		$this->assertSame( [ 'stripe' ], $s['turnstile_skip'] );
		$this->assertSame( '', $s['turnstile_verified'] );

		$secret = [
			'state' => 'ok',
			'last4' => '00AA',
			'saved' => 100,
		];
		$this->assertSame( Turnstile::fingerprint( 'k', $secret ), Turnstile::fingerprint( 'k', $secret ) );
		$this->assertNotSame( Turnstile::fingerprint( 'k', $secret ), Turnstile::fingerprint( 'k', [ 'saved' => 101 ] + $secret ), 'secreto nuevo: hay que volver a probar' );
	}
}
