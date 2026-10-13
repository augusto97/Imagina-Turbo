<?php
/**
 * Matriz, resolutor y guardia de solapamientos.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use ImaginaTurbo\Compat\Matrix;
use ImaginaTurbo\Compat\MatrixException;
use ImaginaTurbo\Compat\Resolver;
use ImaginaTurbo\Core\Environment\OverlapGuard;

final class CompatTest extends TestCase {

	private static ?Matrix $matrix = null;

	private static function matrix(): Matrix {
		return self::$matrix ??= Matrix::fromFile( self::pluginDir() . '/data/compat-matrix-v1.json' );
	}

	public function test_bundled_matrix_is_valid(): void {
		$matrix = self::matrix();
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $matrix->version() );
		$this->assertCount( 411, $matrix->integrations() );
		$this->assertCount( 94, $matrix->coreList( 'ignore_query_params' ) );
		$this->assertNotEmpty( $matrix->overlapping()['plugin_filtering'] ?? [] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function invalidMatrices(): array {
		$base = [
			'schema'         => 'imagina-perf-compat',
			'schema_version' => 1,
			'version'        => '1.0.0',
			'core'           => [],
			'integrations'   => [],
		];
		$dup                 = $base;
		$dup['integrations'] = [
			[
				'id'     => 'a',
				'type'   => 'theme',
				'detect' => [ 'theme' => 'x' ],
			],
			[
				'id'     => 'a',
				'type'   => 'theme',
				'detect' => [ 'theme' => 'x' ],
			],
		];
		$badType                 = $base;
		$badType['integrations'] = [
			[
				'id'     => 'a',
				'type'   => 'otro',
				'detect' => [],
			],
		];
		$badPlugin                 = $base;
		$badPlugin['integrations'] = [
			[
				'id'     => 'a',
				'type'   => 'plugin',
				'detect' => [ 'plugin_file' => 'sin-extension' ],
			],
		];
		$badCore         = $base;
		$badCore['core'] = [ 'bypass_cookies' => [ 1, 2 ] ];

		return [
			'json roto'        => [ '{', 'JSON' ],
			'no objeto'        => [ '"hola"', 'objeto' ],
			'otro esquema'     => [ (string) json_encode( array_merge( $base, [ 'schema' => 'x' ] ) ), 'Esquema' ],
			'otra versión'     => [ (string) json_encode( array_merge( $base, [ 'schema_version' => 2 ] ) ), 'Versión' ],
			'versión inválida' => [ (string) json_encode( array_merge( $base, [ 'version' => 'v1' ] ) ), 'versión' ],
			'duplicada'        => [ (string) json_encode( $dup ), 'duplicada' ],
			'tipo'             => [ (string) json_encode( $badType ), 'tipo' ],
			'plugin_file'      => [ (string) json_encode( $badPlugin ), 'plugin_file' ],
			'core'             => [ (string) json_encode( $badCore ), 'bypass_cookies' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalidMatrices' )]
	public function test_invalid_matrix_is_rejected( string $json, string $fragment ): void {
		$this->expectException( MatrixException::class );
		$this->expectExceptionMessageMatches( '/' . preg_quote( $fragment, '/' ) . '/i' );
		Matrix::fromJson( $json );
	}

	public function test_missing_file(): void {
		$this->expectException( MatrixException::class );
		Matrix::fromFile( $this->tmp . '/no.json' );
	}

	public function test_signature_verification(): void {
		$pair   = sodium_crypto_sign_keypair();
		$json   = '{"x":1}';
		$sig    = base64_encode( sodium_crypto_sign_detached( $json, sodium_crypto_sign_secretkey( $pair ) ) );
		$public = base64_encode( sodium_crypto_sign_publickey( $pair ) );

		$this->assertTrue( Matrix::verifySignature( $json, $sig, $public ) );
		$this->assertFalse( Matrix::verifySignature( $json . ' ', $sig, $public ), 'contenido alterado' );
		$this->assertFalse( Matrix::verifySignature( $json, 'no-base64!', $public ) );
		$this->assertFalse( Matrix::verifySignature( $json, base64_encode( 'corta' ), $public ) );
	}

	public function test_resolver_only_compiles_active_plugins(): void {
		$resolved = ( new Resolver() )->resolve( self::matrix(), [ 'woocommerce/woocommerce.php', 'jetpack/jetpack.php' ], 'astra-child', 'astra' );

		$this->assertContains( 'plugin:woocommerce/woocommerce.php', $resolved['integrations'] );
		$this->assertContains( 'plugin:jetpack/jetpack.php', $resolved['integrations'] );
		$this->assertContains( 'theme:astra', $resolved['integrations'] );
		$this->assertNotContains( 'plugin:polylang/polylang.php', $resolved['integrations'] );
		$this->assertCount( 3, $resolved['integrations'] );

		$this->assertTrue( $resolved['requires']['xmlrpc'], 'Jetpack necesita XML-RPC' );
		$this->assertContains( 'wordpress_logged_in_', $resolved['bypass_cookies'] );
		$this->assertContains( 'woocommerce_items_in_cart', $resolved['cart_cookies'] );
		$this->assertContains( 'wp_woocommerce_session_', $resolved['cart_cookies'] );
		$this->assertContains( 'wc_page:cart', $resolved['dynamic_paths'] );
		$this->assertContains( '/wc-api/v*', $resolved['bypass_paths'] );
		$this->assertSame( [], $resolved['vary_cookies'] );
	}

	public function test_resolver_matches_by_plugin_dir(): void {
		$resolved = ( new Resolver() )->resolve( self::matrix(), [ 'the-post-grid/the-post-grid.php' ], '', '' );
		$this->assertContains( 'plugin:the-post-grid/', $resolved['integrations'] );
		$resolved = ( new Resolver() )->resolve( self::matrix(), [ 'the-post-grid-pro/x.php' ], '', '' );
		$this->assertNotContains( 'plugin:the-post-grid/', $resolved['integrations'], 'carpeta exacta, no prefijo' );
	}

	public function test_resolver_without_active_plugins(): void {
		$resolved = ( new Resolver() )->resolve( self::matrix(), [], 'twentytwentyfour', 'twentytwentyfour' );
		$this->assertSame( [], $resolved['integrations'] );
		$this->assertFalse( $resolved['requires']['xmlrpc'] );
		$this->assertNotEmpty( $resolved['bypass_cookies'], 'las cookies del core siempre aplican' );
	}

	public function test_overlap_perfmatters_turns_off_modules(): void {
		$result = ( new OverlapGuard() )->evaluate( self::matrix()->overlapping(), [ 'perfmatters/perfmatters.php' ] );
		$this->assertArrayHasKey( 'plugin_filter', $result['forced_off'] );
		$this->assertArrayHasKey( 'asset_manager', $result['forced_off'] );
		$this->assertArrayHasKey( 'tweaks', $result['forced_off'] );
		$this->assertArrayNotHasKey( 'cache_coordinator', $result['forced_off'] );
		$this->assertFalse( $result['multiple_page_caches'] );
	}

	public function test_overlap_coordinates_supported_caches(): void {
		$result = ( new OverlapGuard() )->evaluate( self::matrix()->overlapping(), [ 'litespeed-cache/litespeed-cache.php', 'cloudflare/cloudflare.php' ] );
		$this->assertSame( [], $result['forced_off'] );
		$this->assertSame( [], $result['conflicts'] );
		$this->assertContains( 'litespeed-cache', $result['coordinated'] );
		$this->assertContains( 'cloudflare', $result['coordinated'] );
	}

	public function test_overlap_detects_two_page_caches(): void {
		$result = ( new OverlapGuard() )->evaluate( self::matrix()->overlapping(), [ 'wp-rocket/wp-rocket.php', 'w3-total-cache/w3-total-cache.php' ] );
		$this->assertTrue( $result['multiple_page_caches'] );
		$this->assertContains( 'wp-rocket', $result['coordinated'] );
	}

	public function test_overlap_plugin_filtering_category(): void {
		$result = ( new OverlapGuard() )->evaluate( self::matrix()->overlapping(), [ 'plugin-organizer/plugin-organizer.php' ] );
		$this->assertArrayHasKey( 'plugin_filter', $result['forced_off'] );
	}
}
