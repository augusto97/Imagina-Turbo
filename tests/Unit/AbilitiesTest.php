<?php
/**
 * Habilidades para asistentes de IA (0.15.0).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Core\ModuleCatalog;
use ImaginaTurbo\Core\RiskLevel;
use ImaginaTurbo\Integrations\Abilities;

/**
 * Definiciones y rutas.
 */
final class AbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubs( [ '__' ] );
	}

	public function test_write_abilities_only_with_opt_in(): void {
		$read  = array_keys( Abilities::definitions( false ) );
		$write = array_keys( Abilities::definitions( true ) );
		$this->assertNotContains( 'imagina-turbo/purge-cache', $read );
		$this->assertNotContains( 'imagina-turbo/set-module', $read );
		$this->assertSame( [ 'imagina-turbo/purge-cache', 'imagina-turbo/set-module' ], array_values( array_diff( $write, $read ) ) );
		foreach ( Abilities::definitions( true ) as $name => $def ) {
			$this->assertMatchesRegularExpression( '#^imagina-turbo/[a-z-]+$#', $name, 'nombre válido para la Abilities API' );
			$this->assertSame( str_starts_with( $name, 'imagina-turbo/get-' ), $def['readonly'] );
		}
	}

	public function test_routes_reuse_admin_rest(): void {
		$this->assertSame( [ 'GET', '/navigation/report', [ 'days' => 30 ] ], Abilities::route( 'imagina-turbo/get-vitals', [ 'days' => 30 ] ) );
		$this->assertSame( [ 'GET', '/navigation/report', [ 'days' => 7 ] ], Abilities::route( 'imagina-turbo/get-vitals', [ 'days' => 99 ] ) );
		$this->assertSame(
			'PUT',
			Abilities::route(
				'imagina-turbo/set-module',
				[
					'module'  => 'rum',
					'enabled' => false,
				]
			)[0] ?? null
		);
		$this->assertSame(
			'/modules/rum',
			Abilities::route(
				'imagina-turbo/set-module',
				[
					'module'  => 'r/../um',
					'enabled' => true,
				]
			)[1] ?? null,
			'sin caracteres fuera de [a-z_]'
		);
		$this->assertArrayNotHasKey(
			'confirm',
			Abilities::route(
				'imagina-turbo/set-module',
				[
					'module'  => 'rum',
					'enabled' => true,
				]
			)[2] ?? [],
			'un asistente no confirma módulos de riesgo'
		);
		$this->assertNull( Abilities::route( 'imagina-turbo/borrar-todo', [] ) );
		foreach ( [ 'shield', 'firewall', 'turnstile', 'cache_coordinator', 'plugin_filter', 'cloudflare' ] as $module ) {
			$this->assertNull(
				Abilities::route(
					'imagina-turbo/set-module',
					[
						'module'  => $module,
						'enabled' => false,
					]
				),
				"un asistente no apaga $module"
			);
		}
	}

	public function test_ai_modules_are_safe_and_purge_is_destructive(): void {
		$catalog = new ModuleCatalog();
		foreach ( Abilities::AI_MODULES as $id ) {
			$this->assertSame( RiskLevel::Safe, $catalog->get( $id )?->risk, $id );
		}
		$this->assertNotContains( 'shield', Abilities::AI_MODULES, 'el escudo protege el sitio' );
		$this->assertTrue( Abilities::definitions( true )['imagina-turbo/purge-cache']['destructive'] ?? false );
		$this->assertSame( Abilities::AI_MODULES, Abilities::definitions( true )['imagina-turbo/set-module']['input']['properties']['module']['enum'] ?? null );
	}
}
