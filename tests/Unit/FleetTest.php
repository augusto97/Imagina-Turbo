<?php
/**
 * Fase 8: plantilla de flota (validación y desviaciones) y peticiones firmadas del hub.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Environment\Detector;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\ModuleCatalog;
use ImaginaTurbo\Fleet\FleetPolicy;
use ImaginaTurbo\Hub\HubClient;
use ImaginaTurbo\Hub\Signer;
use ImaginaTurbo\Hub\TelemetryBuilder;
use ImaginaTurbo\Licensing\ImaginaUpdater;
use ImaginaTurbo\Repository\EventRepository;
use ImaginaTurbo\Repository\MetricsRepository;

final class FleetTest extends TestCase {

	private const KEY = 'clave-del-sitio-0123456789abcdef0123456789abcdef';

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options = [];
		Functions\when( 'get_option' )->alias( fn( string $name, mixed $default = false ): mixed => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function raw(): array {
		return [
			'schema'     => 1,
			'id'         => 'estandar-woo',
			'version'    => 3,
			'name'       => '<b>Estándar</b> Woo',
			'settings'   => [
				'spec_eagerness' => 'conservative',
				'rum_rate'       => 0.1,
				'hub_url'        => 'https://malo.test',
				'tag_prefix'     => 'zz',
				'cache_edge_ttl' => 1,
			],
			'modules'    => [
				'speculation'   => true,
				'cloudflare'    => true,
				'plugin_filter' => true,
				'no_existe'     => true,
			],
			'tweaks'     => [
				'emojis'         => false,
				'feeds'          => true,
				'jquery_migrate' => true,
			],
			'cloudflare' => [
				'rules'    => [ 'cache-html', 'cache-html', 'MAL"' ],
				'settings' => [ 'http3', 'early_hints' ],
			],
		];
	}

	public function test_policy_normalize(): void {
		$policy = FleetPolicy::normalize( self::raw(), new ModuleCatalog() );
		$this->assertNotNull( $policy );
		$this->assertSame( 'Estándar Woo', $policy['name'] );
		$this->assertSame( [ 'spec_eagerness', 'rum_rate', 'cache_edge_ttl' ], array_keys( $policy['settings'] ), 'nunca el hub ni el prefijo de tags' );
		$this->assertSame( 60, $policy['settings']['cache_edge_ttl'], 'pasa por la misma validación que los ajustes' );
		$this->assertSame( [ 'speculation' => true, 'cloudflare' => true ], $policy['modules'], 'nada invasivo ni desconocido' );
		$this->assertSame( [ 'emojis' => false, 'feeds' => true ], $policy['tweaks'] );
		$this->assertSame( [ 'cache-html' ], $policy['cloudflare']['rules'] );
		$this->assertContains( 'setting:hub_url', $policy['ignored'] );
		$this->assertContains( 'module:plugin_filter', $policy['ignored'] );
		$this->assertContains( 'tweak:jquery_migrate', $policy['ignored'] );

		$this->assertNull( FleetPolicy::normalize( [ 'schema' => 2 ] + self::raw(), new ModuleCatalog() ) );
		$this->assertNull( FleetPolicy::normalize( [ 'id' => 'Mal Id' ] + self::raw(), new ModuleCatalog() ) );
		$this->assertNull( FleetPolicy::normalize( [ 'version' => '3' ] + self::raw(), new ModuleCatalog() ) );
	}

	public function test_policy_drift(): void {
		$catalog  = new ModuleCatalog();
		$policy   = FleetPolicy::normalize( self::raw(), $catalog );
		$current  = Settings::sanitize(
			[
				'spec_eagerness' => 'conservative',
				'rum_rate'       => 0.05,
				'modules'        => [ 'cloudflare' => false ],
				'tweaks'         => [ 'emojis' => true ],
			]
		);
		$defaults = [
			'speculation' => true,
			'cloudflare'  => false,
		];
		$this->assertNotNull( $policy );
		$drift = FleetPolicy::drift( $policy, $current, $defaults, $catalog );
		$keys  = array_map( static fn( array $d ): string => $d['kind'] . ':' . $d['key'], $drift );
		$this->assertSame( [ 'setting:rum_rate', 'setting:cache_edge_ttl', 'module:cloudflare', 'tweak:emojis', 'tweak:feeds' ], $keys );
		$byKey = array_combine( $keys, $drift );
		$this->assertSame( 'moderate', $byKey['module:cloudflare']['risk'], 'encender un módulo moderado' );
		$this->assertSame( 'safe', $byKey['tweak:emojis']['risk'], 'apagar nunca es arriesgado' );
		$this->assertSame( 'moderate', $byKey['tweak:feeds']['risk'] );
	}

	public function test_cloudflare_drift(): void {
		$policy  = FleetPolicy::normalize( self::raw(), new ModuleCatalog() );
		$changes = [
			[
				'id'     => 'rule:itb-k7-cache-html',
				'kind'   => 'rule',
				'ref'    => 'itb-k7-cache-html',
				'action' => 'create',
				'title'  => 'HTML',
				'risk'   => 'moderate',
			],
			[
				'id'     => 'rule:itb-k7-cache-static',
				'kind'   => 'rule',
				'ref'    => 'itb-k7-cache-static',
				'action' => 'create',
			],
			[
				'id'      => 'setting:http3',
				'kind'    => 'setting',
				'setting' => 'http3',
				'action'  => 'none',
			],
			[
				'id'      => 'setting:early_hints',
				'kind'    => 'setting',
				'setting' => 'early_hints',
				'action'  => 'drifted',
			],
		];
		$this->assertNotNull( $policy );
		$drift = FleetPolicy::cloudflareDrift( $policy, $changes, 'itb-k7-' );
		$this->assertSame( [ 'rule:itb-k7-cache-html', 'setting:early_hints' ], array_column( $drift, 'key' ), 'solo lo que pide la plantilla y falta' );
		$this->assertSame( [ 'apply', 'manual' ], array_column( $drift, 'desired' ), 'lo cambiado a mano en el panel no se aplica por plantilla' );
	}

	public function test_same_compares_json_values(): void {
		$this->assertTrue( FleetPolicy::same( 1, 1.0 ) );
		$this->assertTrue( FleetPolicy::same( [ 'b', 'a' ], [ 'a', 'b' ] ) );
		$this->assertTrue( FleetPolicy::same( [ 'x' => true, 'y' => false ], [ 'y' => false, 'x' => true ] ) );
		$this->assertFalse( FleetPolicy::same( true, 1 ) );
		$this->assertFalse( FleetPolicy::same( 'a', 'b' ) );
	}

	private function hub(): HubClient {
		$wpdb = \Mockery::mock( \wpdb::class );
		Functions\when( 'is_wp_error' )->justReturn( false );
		$settings = new Settings();
		return new HubClient(
			$settings,
			new TelemetryBuilder( new Detector(), new MetricsRepository( $wpdb ), new EventRepository( $wpdb ), $settings, new ModuleCatalog() ),
			new ImaginaUpdater(),
			new EventLog( new EventRepository( $wpdb ) )
		);
	}

	public function test_incoming_hub_requests_are_signed_fresh_and_unique(): void {
		$this->options = [
			Settings::OPTION         => [ 'hub_url' => 'https://hub.test' ],
			HubClient::STATE_OPTION  => [
				'site_id' => 'site_1',
				'hub'     => 'https://hub.test',
			],
			HubClient::KEY_OPTION    => self::KEY,
		];
		$hub     = $this->hub();
		$now     = 1_760_000_000;
		$body    = '{"action":"apply","policy_version":3}';
		$path    = '/wp-json/imagina-turbo/v1/fleet/remote';
		$signed  = array_change_key_case( Signer::headers( 'site_1', self::KEY, '', 'POST', $path, $body, $now, str_repeat( 'ef', 16 ) ), CASE_LOWER );

		$this->assertNull( $hub->verifyIncoming( 'POST', $path, $body, $signed, $now + 5 ) );
		$this->assertSame( 'replay', $hub->verifyIncoming( 'POST', $path, $body, $signed, $now + 6 ), 'el mismo nonce dos veces' );

		$other = array_change_key_case( Signer::headers( 'site_1', self::KEY, '', 'POST', $path, $body, $now, str_repeat( '12', 16 ) ), CASE_LOWER );
		$this->assertSame( 'signature', $hub->verifyIncoming( 'POST', $path, '{"action":"status"}', $other, $now ), 'otro cuerpo' );
		$this->assertSame( 'signature', $hub->verifyIncoming( 'POST', $path, $body, $other, $now + Signer::MAX_SKEW + 1 ), 'caducada' );
		$this->assertSame( 'site', $hub->verifyIncoming( 'POST', $path, $body, [ 'x-itb-site' => 'site_2' ] + $other, $now ) );

		$this->options[ HubClient::STATE_OPTION ]['hub'] = 'https://otro-hub.test';
		$this->assertSame( 'not_registered', $hub->verifyIncoming( 'POST', $path, $body, $other, $now ), 'registro de otro hub' );
	}
}
