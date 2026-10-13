<?php
/**
 * Base de las pruebas: Brain Monkey + directorio temporal por prueba.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ImaginaTurbo\Core\Runtime;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use MockeryPHPUnitIntegration;

	protected string $tmp = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Runtime::reset();
		$this->tmp = ITB_TESTS_TMP . '/' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp, 0755, true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Runtime::reset();
		if ( is_dir( $this->tmp ) ) {
			exec( 'rm -rf ' . escapeshellarg( $this->tmp ) );
		}
		parent::tearDown();
	}

	protected static function pluginDir(): string {
		return dirname( __DIR__, 2 );
	}
}
