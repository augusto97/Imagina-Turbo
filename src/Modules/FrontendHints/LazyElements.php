<?php
/**
 * Elementos diferidos de Elementor (0.17.0). Riesgo moderado, apagado por defecto.
 *
 * Partes de la página que no se ven al cargar (el megamenú de categorías, el pie, carruseles de
 * abajo) se imprimen dentro de un <template>: el navegador las lee pero no las convierte en DOM, no
 * calcula sus estilos, no las maqueta ni descarga sus imágenes. Un script en línea (itb-lazy.js,
 * < 2 KB) las pasa al DOM cuando hacen falta y vuelve a iniciar sus widgets de Elementor.
 *
 * Medido en La Tienda del Pintor (WoodMart 8.5.4): el menú «Categorías» imprime 10 bloques HTML de
 * Elementor en todas las páginas, 287 KB de los 680 KB de la portada, ~4.900 etiquetas y 92 imágenes.
 *
 * Qué se difiere, sin reescribir el HTML:
 * - Elementos con la clase CSS itb-lazy (Avanzado → Clases CSS en Elementor): los hooks
 *   elementor/frontend/before_render y after_render, que rodean al elemento completo (verificado en
 *   Element_Base::print_element de Elementor 4.3.5), imprimen dos comentarios marcadores. La caché de
 *   elementos de Elementor (activa por defecto) guarda el HTML con ellos y lo vuelve a servir sin
 *   pasar por esos hooks; el filtro elementor/frontend/the_content, que sí recibe siempre el HTML del
 *   documento, los convierte en el envoltorio. Si Imagina Turbo se apaga, quedan como comentarios: el
 *   contenido se ve normal, nunca atrapado en una plantilla.
 *   Se pasan al DOM al acercarse a la pantalla; con itb-lazy-interaction, en la primera interacción
 *   (para lo que está en menús desplegables). Con itb-lazy-h{N} (p. ej. itb-lazy-h600) se reserva esa
 *   altura mientras tanto.
 * - Documentos completos por id (bloques HTML de WoodMart, plantillas): el mismo filtro, que recibe el
 *   HTML del documento como texto.
 *
 * El script además: mueve al lugar del marcador los <style> y <link> de cada plantilla (Elementor
 * imprime el CSS de un widget solo con su primera aparición), pasa todo al DOM antes de imprimir y si
 * la URL apunta a un ancla, y avisa a WoodMart (wdLoadDropdownsSuccess) como hace su carga AJAX de
 * desplegables.
 *
 * Límites (por eso es moderado): lo diferido no está en el DOM para los scripts que lo buscan al
 * cargar ni para la búsqueda del navegador (Ctrl+F) hasta que se muestra, y Google puede no ver sus
 * enlaces. Úsalo en contenido sin valor para SEO o repetido en otra parte.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\FrontendHints;

use ImaginaTurbo\Core\Runtime;

/**
 * Envoltorio con <template> y script de hidratación.
 */
final class LazyElements {

	public const CLASS_VIEW = 'itb-lazy';

	public const CLASS_INTERACTION = 'itb-lazy-interaction';

	public const DEFAULT_HEIGHT = 400;

	public const MAX_DOCUMENTS = 30;

	/** @var list<bool> Pila paralela a los before_render/after_render: ¿este elemento abrió un marcador? */
	private static array $stack = [];

	/** Elementos diferidos abiertos (no se anidan). */
	private static int $open = 0;

	private static bool $used = false;

	/**
	 * @param array{on: bool, docs: array<int, array{mode: string, height: int}>} $settings Ajustes compilados.
	 */
	public static function register( array $settings ): void {
		if ( ! $settings['on'] ) {
			return;
		}
		add_action( 'elementor/frontend/before_render', [ self::class, 'beforeRender' ], 1 );
		add_action( 'elementor/frontend/after_render', [ self::class, 'afterRender' ], PHP_INT_MAX );
		add_filter( 'elementor/frontend/the_content', [ self::class, 'filterDocument' ], PHP_INT_MAX );
		add_action( 'wp_footer', [ self::class, 'printScript' ], 1000 );
	}

	/**
	 * @return array{on: bool, docs: array<int, array{mode: string, height: int}>}
	 */
	public static function settings(): array {
		$s    = Runtime::$config?->get( 'hints', [] );
		$s    = is_array( $s ) && is_array( $s['lazy_el'] ?? null ) ? $s['lazy_el'] : [];
		$docs = [];
		foreach ( is_array( $s['docs'] ?? null ) ? $s['docs'] : [] as $id => $doc ) {
			if ( is_int( $id ) && is_array( $doc ) ) {
				$docs[ $id ] = [
					'mode'   => 'i' === ( $doc['mode'] ?? '' ) ? 'i' : 'v',
					'height' => (int) ( $doc['height'] ?? 0 ),
				];
			}
		}
		return [
			'on'   => true === ( $s['on'] ?? false ),
			'docs' => $docs,
		];
	}

	/**
	 * Solo en la vista pública: nunca en el editor ni en su vista previa.
	 */
	private static function active(): bool {
		if ( is_admin() || is_feed() || is_customize_preview() || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ) {
			return false;
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
			$elementor = \Elementor\Plugin::$instance;
			if ( ( isset( $elementor->editor ) && $elementor->editor->is_edit_mode() ) || ( isset( $elementor->preview ) && $elementor->preview->is_preview_mode() ) ) {
				return false;
			}
		}
		return true;
	}

	/** Marcadores que quedan en el HTML del documento (y en la caché de elementos de Elementor). */
	public const MARK_OPEN = '<!--itb-lz:';

	public const MARK_CLOSE = '<!--/itb-lz-->';

	/**
	 * @param object $element Elemento de Elementor (Element_Base).
	 */
	public static function beforeRender( object $element ): void {
		$mode = 0 === self::$open ? self::elementMode( $element ) : null;
		if ( null === $mode || ! self::active() ) {
			self::$stack[] = false;
			return;
		}
		self::$stack[] = true;
		++self::$open;
		echo self::MARK_OPEN . ( 'i' === $mode['mode'] ? 'i' : 'v' ) . ':' . (int) $mode['height'] . '-->'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- comentario propio con valores enteros.
	}

	/**
	 * @param object $element Elemento de Elementor.
	 */
	public static function afterRender( object $element ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- firma del hook.
		if ( true === array_pop( self::$stack ) ) {
			--self::$open;
			echo self::MARK_CLOSE; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- comentario propio.
		}
	}

	/**
	 * Marcadores → envoltorio, y documentos de la lista completos.
	 *
	 * @param mixed $content HTML del documento.
	 * @return mixed
	 */
	public static function filterDocument( mixed $content ): mixed {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}
		if ( str_contains( $content, self::MARK_OPEN ) && self::active() ) {
			$content = self::convertMarkers( $content );
		}
		return self::wrapDocument( $content );
	}

	/**
	 * Los marcadores de apertura y cierre van siempre en pares (before_render/after_render del mismo
	 * elemento); si faltara un cierre, ese marcador no se convierte.
	 */
	public static function convertMarkers( string $html ): string {
		$converted = preg_replace_callback(
			'/<!--itb-lz:([vi]):(\d{1,4})-->(.*?)<!--\/itb-lz-->/s',
			static fn( array $m ): string => self::wrap( $m[3], $m[1], (int) $m[2] ),
			$html
		);
		return is_string( $converted ) ? $converted : $html;
	}

	/**
	 * Modo de un elemento por sus clases CSS (widgets: _css_classes; secciones, columnas y contenedores:
	 * css_classes).
	 *
	 * @param object $element Elemento.
	 * @return array{mode: string, height: int}|null
	 */
	public static function elementMode( object $element ): ?array {
		if ( ! method_exists( $element, 'get_settings' ) ) {
			return null;
		}
		$classes = '';
		foreach ( [ '_css_classes', 'css_classes' ] as $key ) {
			$value = $element->get_settings( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$classes .= ' ' . $value;
			}
		}
		return self::modeFromClasses( $classes );
	}

	/**
	 * @return array{mode: string, height: int}|null
	 */
	public static function modeFromClasses( string $classes ): ?array {
		if ( ! str_contains( $classes, self::CLASS_VIEW ) ) {
			return null;
		}
		$list = preg_split( '/\s+/', trim( $classes ) );
		$list = is_array( $list ) ? $list : [];
		$mode = null;
		if ( in_array( self::CLASS_INTERACTION, $list, true ) ) {
			$mode = 'i';
		} elseif ( in_array( self::CLASS_VIEW, $list, true ) ) {
			$mode = 'v';
		}
		if ( null === $mode ) {
			return null;
		}
		$height = 'v' === $mode ? self::DEFAULT_HEIGHT : 0;
		foreach ( $list as $class ) {
			if ( 1 === preg_match( '/^itb-lazy-h(\d{2,4})$/', $class, $m ) ) {
				$height = max( 0, min( 3000, (int) $m[1] ) );
			}
		}
		return [
			'mode'   => $mode,
			'height' => $height,
		];
	}

	/**
	 * Documento completo de la lista (bloque HTML, plantilla).
	 *
	 * @param mixed $content HTML del documento.
	 * @return mixed
	 */
	public static function wrapDocument( mixed $content ): mixed {
		if ( ! is_string( $content ) || '' === $content || self::$open > 0 || [] === self::settings()['docs'] || ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->documents ) ) {
			return $content;
		}
		$document = \Elementor\Plugin::$instance->documents->get_current();
		$id       = is_object( $document ) && method_exists( $document, 'get_main_id' ) ? (int) $document->get_main_id() : 0;
		$doc      = self::settings()['docs'][ $id ] ?? null;
		if ( null === $doc || ! self::active() ) {
			return $content;
		}
		return self::wrap( $content, $doc['mode'], $doc['height'] );
	}

	public static function wrap( string $html, string $mode, int $height ): string {
		return self::open( $mode, $height ) . $html . self::close();
	}

	public static function open( string $mode, int $height ): string {
		self::$used = true;
		$style      = $height > 0 ? ' style="min-height:' . (int) $height . 'px"' : '';
		return '<div class="itb-lz" data-itb-lz="' . ( 'i' === $mode ? 'i' : 'v' ) . '"' . $style . '><template>';
	}

	public static function close(): string {
		return '</template></div>';
	}

	/**
	 * Script en línea, solo si la página tiene algo diferido. No se retrasa ni se optimiza: tiene que
	 * correr antes de que alguien interactúe.
	 */
	public static function printScript(): void {
		if ( ! self::$used ) {
			return;
		}
		$file = is_file( Runtime::$pluginDir . '/assets/js/itb-lazy.min.js' ) ? 'itb-lazy.min.js' : 'itb-lazy.js';
		$code = (string) file_get_contents( Runtime::$pluginDir . '/assets/js/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo propio, < 2 KB.
		if ( '' === $code ) {
			return;
		}
		wp_print_inline_script_tag(
			$code,
			[
				'id'               => 'itb-lazy',
				'data-no-defer'    => '1',
				'data-no-optimize' => '1',
				'nowprocket'       => true,
			]
		);
	}

	/**
	 * Hay algo diferido que no pasó por estos filtros (un menú servido por la caché de fragmentos): el
	 * script tiene que salir igual, también si la función se apagó después de guardar esa copia.
	 */
	public static function markUsed(): void {
		self::$used = true;
		if ( function_exists( 'has_action' ) && false === has_action( 'wp_footer', [ self::class, 'printScript' ] ) ) {
			add_action( 'wp_footer', [ self::class, 'printScript' ], 1000 );
		}
	}

	/**
	 * Solo para pruebas.
	 */
	public static function reset(): void {
		self::$stack = [];
		self::$open  = 0;
		self::$used  = false;
	}

	public static function used(): bool {
		return self::$used;
	}
}
