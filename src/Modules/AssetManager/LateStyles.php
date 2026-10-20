<?php
/**
 * Hojas de estilo tardías al head (0.16.0).
 *
 * WoodMart (y otros temas y constructores que cargan CSS por módulo) registran y imprimen hojas de
 * estilo al renderizar cada sección: quedan en el <body>, a veces después del contenido al que dan
 * forma. Hasta que llegan, ese contenido se pinta con otra disposición y luego salta. Medido en La
 * Tienda del Pintor (WoodMart 8.5.4): wd-shop-off-canvas-sidebar, impresa tras la cuadrícula de
 * productos, apila en móvil la cabecera de la tienda; al llegar (~3 s en móvil lento) empujaba la
 * cuadrícula unos 90 px. CLS 0,3 en todas las categorías.
 *
 * Sin reescribir el HTML:
 * - El inventario anota las hojas externas que se imprimen después del head (AssetInventory).
 * - El admin las detecta por plantilla y elige cuáles adelantar.
 * - En esas plantillas, al final del head (wp_head 9999) se registran si hace falta con la URL y la
 *   versión del inventario y se imprimen con wp_print_styles. WordPress las marca como impresas y no
 *   las repite donde el tema las pide después. Al final del head siguen detrás de todo el CSS del
 *   head, como antes: el orden de la cascada no cambia respecto a lo que ya estaba arriba.
 * - Solo hojas del propio sitio (.css). Las hojas en línea no hacen falta: se imprimen justo antes del
 *   contenido que estilan.
 *
 * Riesgo moderado (va con el gestor de assets): cambia cuándo se aplica CSS. Se verifica mirando las
 * páginas de esas plantillas.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\AssetManager;

use ImaginaTurbo\Modules\Profiler\RouteKey;
use ImaginaTurbo\Core\Runtime;

/**
 * Normalización y aplicación.
 *
 * @phpstan-type Sheet array{h: string, src: string, ver: string, media: string}
 */
final class LateStyles {

	public const MAX_TEMPLATES = 20;

	public const MAX_PER_TEMPLATE = 25;

	/**
	 * Valida la lista guardada: plantilla → hojas (handle, URL del propio sitio, versión, media).
	 *
	 * @param array<mixed> $input Lista.
	 * @return array<string, list<Sheet>>
	 */
	public static function normalize( array $input, string $home ): array {
		$out = [];
		foreach ( $input as $template => $sheets ) {
			if ( ! is_string( $template ) || 1 !== preg_match( '/^[a-z0-9:_\-]{1,40}$/', $template ) || ! is_array( $sheets ) || count( $out ) >= self::MAX_TEMPLATES ) {
				continue;
			}
			$list = [];
			foreach ( $sheets as $sheet ) {
				$clean = is_array( $sheet ) ? self::sheet( $sheet, $home ) : null;
				if ( null !== $clean && ! isset( $list[ $clean['h'] ] ) && count( $list ) < self::MAX_PER_TEMPLATE ) {
					$list[ $clean['h'] ] = $clean;
				}
			}
			if ( [] !== $list ) {
				$out[ $template ] = array_values( $list );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Una hoja válida o null. La URL tiene que ser del propio sitio (relativa o con el host de la
	 * portada) y terminar en .css; el handle, el de WordPress.
	 *
	 * @param array<mixed> $sheet Hoja.
	 * @return Sheet|null
	 */
	public static function sheet( array $sheet, string $home ): ?array {
		$handle = is_string( $sheet['h'] ?? null ) ? $sheet['h'] : '';
		$src    = is_string( $sheet['src'] ?? null ) ? trim( $sheet['src'] ) : '';
		$ver    = is_string( $sheet['ver'] ?? null ) ? $sheet['ver'] : '';
		$media  = is_string( $sheet['media'] ?? null ) && '' !== $sheet['media'] ? $sheet['media'] : 'all';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-.]{1,80}$/', $handle ) || str_starts_with( $handle, 'itb-' ) || 'admin-bar' === $handle ) {
			return null;
		}
		$path = (string) strtok( $src, '?#' );
		if ( 1 !== preg_match( '/\.css$/i', $path ) || 1 === preg_match( '/["\'<>\s\\\\]/', $src ) ) {
			return null;
		}
		if ( str_starts_with( $src, '//' ) || 1 === preg_match( '#^https?://#i', $src ) ) {
			$host = parse_url( str_starts_with( $src, '//' ) ? 'https:' . $src : $src, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- sin WordPress en las pruebas.
			$own  = parse_url( $home, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- idem.
			if ( ! is_string( $host ) || ! is_string( $own ) || strtolower( $host ) !== strtolower( $own ) ) {
				return null;
			}
		} elseif ( ! str_starts_with( $src, '/' ) ) {
			return null;
		}
		return [
			'h'     => $handle,
			'src'   => $src,
			'ver'   => 1 === preg_match( '/^[A-Za-z0-9_\-.]{0,40}$/', $ver ) ? $ver : '',
			'media' => 1 === preg_match( '/^[A-Za-z0-9_\-.:()\s,]{1,120}$/', $media ) ? $media : 'all',
		];
	}

	/**
	 * Hojas tardías de un inventario: impresas después del head, externas y del propio sitio.
	 *
	 * @param array<mixed> $late Lista "late_css" del inventario.
	 * @return list<Sheet>
	 */
	public static function fromInventory( array $late, string $home ): array {
		$out = [];
		foreach ( $late as $sheet ) {
			$clean = is_array( $sheet ) ? self::sheet( $sheet, $home ) : null;
			if ( null !== $clean ) {
				$out[ $clean['h'] ] = $clean;
			}
		}
		return array_values( $out );
	}

	/**
	 * @return array<string, list<Sheet>>
	 */
	public static function compiled(): array {
		$list = Runtime::$config?->get( 'late_styles', [] );
		return is_array( $list ) ? $list : [];
	}

	public static function register(): void {
		if ( [] === self::compiled() ) {
			return;
		}
		add_action( 'wp_head', [ self::class, 'printHoisted' ], 9999 );
	}

	/**
	 * Imprime al final del head las hojas de esta plantilla que el tema imprimiría después.
	 */
	public static function printHoisted(): void {
		if ( AssetInventory::active() ) {
			return;
		}
		$sheets = self::compiled()[ RouteKey::template() ] ?? [];
		if ( ! is_array( $sheets ) || [] === $sheets ) {
			return;
		}
		$styles  = wp_styles();
		$handles = [];
		foreach ( $sheets as $sheet ) {
			if ( ! is_array( $sheet ) || ! is_string( $sheet['h'] ?? null ) || in_array( $sheet['h'], $styles->done, true ) || in_array( $sheet['h'], AssetManagerModule::$removed['css'], true ) ) {
				continue;
			}
			if ( ! $styles->query( $sheet['h'], 'registered' ) ) {
				$ver = is_string( $sheet['ver'] ?? null ) && '' !== $sheet['ver'] ? $sheet['ver'] : false;
				wp_register_style( $sheet['h'], (string) ( $sheet['src'] ?? '' ), [], $ver, is_string( $sheet['media'] ?? null ) ? $sheet['media'] : 'all' );
			}
			$handles[] = $sheet['h'];
		}
		if ( [] !== $handles ) {
			wp_print_styles( $handles );
		}
	}
}
