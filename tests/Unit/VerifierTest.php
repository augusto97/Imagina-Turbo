<?php
/**
 * Verificador externo (0.20.0): configuración, clave cifrada, firma y aviso nocturno.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Cloudflare\TokenVault;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Hub\Signer;
use ImaginaTurbo\Repository\EventRepository;
use ImaginaTurbo\Verifier\VerifierService;

/**
 * Cliente del verificador sin red.
 */
final class VerifierTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	/** @var list<array{url: string, args: array<string, mixed>}> */
	private array $requests = [];

	/** @var list<array{0: int, 1: mixed}> */
	private array $responses = [];

	/** @var list<array<string, mixed>> */
	private array $events = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options   = [];
		$this->requests  = [];
		$this->responses = [];
		$this->events    = [];
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
		Functions\when( 'untrailingslashit' )->alias( static fn( string $s ): string => rtrim( $s, '/\\' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ): array {
				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];
				return array_shift( $this->responses ) ?? [ 200, [] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $r ): int => $r[0] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $r ): string => (string) json_encode( $r[1] ) );
	}

	private function service(): VerifierService {
		$wpdb            = \Mockery::mock( \wpdb::class );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 1;
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( string $table, array $row ): int {
				$this->events[] = $row;
				return 1;
			}
		);
		return new VerifierService( new EventLog( new EventRepository( $wpdb ) ), new TokenVault( 'material-de-prueba', VerifierService::KEY_OPTION ) );
	}

	public function test_urls_and_site_ids(): void {
		$this->assertTrue( VerifierService::validUrl( 'https://verificador.imaginawp.com' ) );
		$this->assertTrue( VerifierService::validUrl( 'http://127.0.0.1:8787' ) );
		$this->assertFalse( VerifierService::validUrl( 'http://verificador.imaginawp.com' ) );
		$this->assertFalse( VerifierService::validUrl( 'ftp://x' ) );

		$service = $this->service();
		$this->assertFalse( $service->save( 'http://otro.com', 'tienda', false )['ok'] );
		$this->assertFalse( $service->save( 'https://v.com', 'con espacios', false )['ok'] );
		$this->assertFalse( $service->configured() );
	}

	public function test_first_save_creates_encrypted_key_once(): void {
		$service = $this->service();
		$first   = $service->save( 'https://v.com/', 'tienda.com', false );
		$this->assertTrue( $first['ok'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first['key'] );
		$this->assertTrue( $service->configured() );
		// Cifrada: la clave no aparece en la opción.
		$this->assertStringNotContainsString( $first['key'], (string) json_encode( $this->options ) );
		$this->assertSame( 'https://v.com', VerifierService::settings()['url'] );
		// Guardar otra vez no la cambia ni la vuelve a mostrar; pedir una nueva sí.
		$this->assertSame( '', $service->save( 'https://v.com', 'tienda.com', false )['key'] );
		$again = $service->save( 'https://v.com', 'tienda.com', true )['key'];
		$this->assertNotSame( $first['key'], $again );
		$this->assertSame( substr( $again, -4 ), $service->status()['key']['last4'] );
	}

	public function test_requests_are_signed_like_the_hub(): void {
		$service = $this->service();
		$key     = $service->save( 'https://v.com', 'tienda.com', false )['key'];
		$this->responses[] = [ 202, [ 'id' => '20261026-0123456789', 'status' => 'queued' ] ];
		$reply   = $service->request( 'POST', '/v1/runs', [ 'lighthouse' => true ] );
		$this->assertSame( 202, $reply['status'] );
		$sent    = $this->requests[0];
		$this->assertSame( 'https://v.com/v1/runs', $sent['url'] );
		$h = $sent['args']['headers'];
		$this->assertSame( 'tienda.com', $h['X-ITB-Site'] );
		$this->assertArrayNotHasKey( 'X-ITB-License', $h );
		$this->assertTrue( Signer::verify( $key, 'POST', '/v1/runs', $h['X-ITB-Timestamp'], $h['X-ITB-Nonce'], (string) $sent['args']['body'], $h['X-ITB-Signature'] ) );
		$this->assertSame( 0, $sent['args']['redirection'] );

		$this->responses[] = [ 401, [ 'error' => 'bad_signature' ] ];
		$this->assertStringContainsString( 'rechazó la firma (bad_signature)', $service->request( 'GET', '/v1/runs' )['error'] );
	}

	public function test_daily_reports_each_nightly_once(): void {
		$service = $this->service();
		$service->save( 'https://v.com', 'tienda.com', false );
		$runs = [
			'runs' => [
				[ 'id' => '20261027-aaaaaaaaaa', 'trigger' => 'manual', 'status' => 'done', 'alerts' => [] ],
				[ 'id' => '20261027-bbbbbbbbbb', 'trigger' => 'nightly', 'status' => 'done', 'alerts' => [ 'Portada: Lighthouse 85 → 70' ] ],
			],
		];
		$this->responses[] = [ 200, $runs ];
		$this->responses[] = [ 200, $runs ];
		$service->daily();
		$service->daily();
		$this->assertCount( 1, $this->events );
		$this->assertStringContainsString( 'Portada: Lighthouse 85 → 70', (string) $this->events[0]['message'] );
		$this->assertSame( '20261027-bbbbbbbbbb', VerifierService::settings()['seen'] );
	}
}
