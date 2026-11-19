<?php
/**
 * 0.25.6: el aviso de «Purgar toda la caché» de la barra se veía con «u00bf» en vez de «¿».
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use Brain\Monkey\Functions;
use ImaginaTurbo\Admin\AdminBarPurge;

final class AdminBarPurgeTest extends TestCase {

	/**
	 * Lo que hace WordPress con el onclick de un nodo de la barra: esc_js() (formatting.php) dentro de
	 * un atributo con comillas simples, y lo que entiende el navegador al leer el atributo.
	 */
	private static function browserSees( string $onclick ): string {
		$js = htmlspecialchars( $onclick, ENT_COMPAT, 'UTF-8' );
		$js = (string) preg_replace( '/&#(x)?0*(?(1)27|39);?/i', "'", stripslashes( $js ) );
		$js = str_replace( "\n", '\\n', addslashes( str_replace( "\r", '', $js ) ) );
		return html_entity_decode( $js, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public function test_confirm_survives_esc_js(): void {
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, int $flags = 0 ) => json_encode( $data, $flags ) );
		$message = '¿Purgar toda la caché? Las páginas se vuelven a generar hasta que el recalentado las llene de nuevo.';

		// Antes: wp_json_encode sin flags escribe ¿ y esc_js le quita la barra.
		$before = 'return confirm(' . json_encode( $message ) . ');';
		$this->assertStringContainsString( 'u00bfPurgar', self::browserSees( $before ), 'reproduce el aviso roto' );

		$this->assertSame( 'return confirm("' . $message . '");', self::browserSees( AdminBarPurge::confirmScript( $message ) ) );
		// Una traducción con comillas o barras no rompe el atributo ni el texto.
		$this->assertSame( 'return confirm("Purge the site’s ”whole” cache?");', self::browserSees( AdminBarPurge::confirmScript( 'Purge the site\'s "whole" cache?' ) ) );
		$this->assertSame( 'return confirm("a/b c");', self::browserSees( AdminBarPurge::confirmScript( 'a/b\\ c' ) ) );
	}
}
