<?php
/**
 * Clave de ruta normalizada: plantilla para el HTML, endpoint para AJAX/REST, pantalla para el admin.
 * Nunca incluye slugs ni IDs de contenido: agrupa por tipo de página.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Profiler;

use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;

/**
 * Cálculo de la clave al final del request (cuando la consulta principal ya existe).
 */
final class RouteKey {

	/** Cabe en un índice compuesto utf8mb4 aun con el límite de 767 bytes de MySQL 5.6. */
	public const MAX = 150;

	public static function current( RequestContext $request ): string {
		$key = match ( $request->type ) {
			RequestType::FrontendHtml, RequestType::Feed => self::template(),
			RequestType::AdminAjax, RequestType::WcAjax, RequestType::Rest, RequestType::StoreApi => '' === $request->endpoint ? '(raíz)' : $request->endpoint,
			RequestType::Admin => self::adminScreen(),
			default => strtolower( $request->type->value ),
		};
		return substr( $key, 0, self::MAX );
	}

	/**
	 * Tipo de plantilla del frontend a partir de los condicionales del core.
	 */
	public static function template(): string {
		if ( ! function_exists( 'is_front_page' ) || ! did_action( 'wp' ) ) {
			return 'temprano';
		}
		if ( is_404() ) {
			return '404';
		}
		if ( is_feed() ) {
			return 'feed';
		}
		if ( is_front_page() ) {
			return 'portada';
		}
		if ( is_home() ) {
			return 'blog';
		}
		if ( is_search() ) {
			return 'busqueda';
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'archivo:product';
		}
		if ( is_singular() ) {
			$type = get_post_type();
			return 'single:' . ( is_string( $type ) ? $type : 'desconocido' );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			return 'tax:' . ( $term instanceof \WP_Term ? $term->taxonomy : 'desconocida' );
		}
		if ( is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			if ( is_array( $type ) ) {
				$type = reset( $type );
			}
			return 'archivo:' . ( is_string( $type ) && '' !== $type ? $type : 'desconocido' );
		}
		if ( is_author() ) {
			return 'autor';
		}
		if ( is_date() ) {
			return 'fecha';
		}
		return 'otra';
	}

	/**
	 * Pantalla del admin: archivo + ?page= cuando existe.
	 */
	private static function adminScreen(): string {
		$screen = isset( $GLOBALS['pagenow'] ) && is_string( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : 'admin';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se lee para agrupar, sin efectos.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return '' === $page ? $screen : $screen . '?page=' . $page;
	}
}
