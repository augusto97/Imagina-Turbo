<?php
/**
 * Tags → URLs, para la purga por URL (WP Rocket, edge sin tags) y el recalentado.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

/**
 * Resolución de URLs.
 */
final class UrlResolver {

	/** Máximo de URLs por resolución (un tag de tipo puede tener muchas páginas; se purgan las principales). */
	public const MAX = 200;

	/**
	 * @param list<string> $names Tags sin prefijo.
	 * @return list<string>
	 */
	public static function forTags( array $names ): array {
		$urls = [];
		foreach ( $names as $name ) {
			if ( 'home' === $name ) {
				$urls[] = home_url( '/' );
			} elseif ( 'feed' === $name ) {
				$urls[] = get_feed_link();
			} elseif ( 'shop' === $name && function_exists( 'wc_get_page_permalink' ) ) {
				$urls[] = wc_get_page_permalink( 'shop' );
			} elseif ( 1 === preg_match( '/^p-(\d+)$/', $name, $m ) ) {
				$post = get_post( (int) $m[1] );
				if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
					$urls[] = get_permalink( $post );
				}
			} elseif ( 1 === preg_match( '/^t-(\d+)$/', $name, $m ) ) {
				$term = get_term( (int) $m[1] );
				// Taxonomías internas (product_type, product_visibility) no tienen página pública.
				if ( $term instanceof \WP_Term && is_taxonomy_viewable( $term->taxonomy ) ) {
					$urls[] = get_term_link( $term );
				}
			} elseif ( 1 === preg_match( '/^a-(\d+)$/', $name, $m ) ) {
				$urls[] = get_author_posts_url( (int) $m[1] );
			} elseif ( 1 === preg_match( '/^pt-([a-z0-9_\-]+)$/', $name, $m ) ) {
				$urls[] = get_post_type_archive_link( $m[1] );
			}
			if ( count( $urls ) >= self::MAX ) {
				break;
			}
		}
		return TagBuilder::cleanUrls( $urls );
	}
}
