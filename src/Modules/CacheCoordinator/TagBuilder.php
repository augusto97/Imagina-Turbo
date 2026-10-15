<?php
/**
 * Tags de caché: los mismos nombres para la cabecera Cache-Tag de Cloudflare, los tags de LSCache
 * y la cola de purga. Prefijo corto por sitio para zonas compartidas.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

/**
 * Construcción de tags.
 */
final class TagBuilder {

	/** Tags propios con su forma: all, home, feed, search, shop, p-{id}, t-{id}, a-{id}, pt-{tipo}. */
	private const PATTERN = '/^(all|home|feed|search|shop|[ptal]-\d{1,20}|pt-[a-z0-9_\-]{1,40})$/';

	private readonly string $prefix;

	public function __construct( string $prefix ) {
		$this->prefix = 1 === preg_match( '/^[a-z0-9]{2,6}$/', $prefix ) ? $prefix : 'itb';
	}

	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Tag con prefijo, o '' si el nombre no es válido.
	 */
	public function tag( string $name ): string {
		return 1 === preg_match( self::PATTERN, $name ) ? $this->prefix . '-' . $name : '';
	}

	/**
	 * Varios tags, sin vacíos ni repetidos.
	 *
	 * @param list<string> $names Nombres sin prefijo.
	 * @return list<string>
	 */
	public function tags( array $names ): array {
		return array_values( array_unique( array_filter( array_map( [ $this, 'tag' ], $names ) ) ) );
	}

	/**
	 * Nombre sin prefijo de un tag propio, o null si no es nuestro.
	 */
	public function strip( string $tag ): ?string {
		$start = $this->prefix . '-';
		if ( ! str_starts_with( $tag, $start ) ) {
			return null;
		}
		$name = substr( $tag, strlen( $start ) );
		return 1 === preg_match( self::PATTERN, $name ) ? $name : null;
	}

	/**
	 * Tags del request actual según la consulta principal. Las páginas que listan contenido se
	 * purgan por los tags de sus términos, tipos y portada, no por cada post listado.
	 *
	 * @return list<string> Nombres sin prefijo.
	 */
	public static function namesForCurrentQuery(): array {
		$names = [ 'all' ];
		if ( is_front_page() || is_home() ) {
			$names[] = 'home';
		}
		if ( is_feed() ) {
			$names[] = 'feed';
		}
		if ( is_search() ) {
			$names[] = 'search';
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			$names[] = 'shop';
			$names[] = 'pt-product';
		}
		if ( is_singular() ) {
			$id = get_queried_object_id();
			if ( $id > 0 ) {
				$names[] = 'p-' . $id;
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$names[] = 't-' . $term->term_id;
			}
		} elseif ( is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			$type = is_array( $type ) ? reset( $type ) : $type;
			if ( is_string( $type ) && '' !== $type ) {
				$names[] = 'pt-' . $type;
			}
		} elseif ( is_author() ) {
			$names[] = 'a-' . (int) get_query_var( 'author' );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Qué purgar cuando cambia un post: el post, sus términos públicos, su tipo, su autor, la portada
	 * y el feed; la tienda si es un producto. Una variación purga a su producto padre.
	 *
	 * @return list<string> Nombres sin prefijo.
	 */
	public static function namesForPost( \WP_Post $post ): array {
		if ( 'product_variation' === $post->post_type && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			return $parent instanceof \WP_Post ? self::namesForPost( $parent ) : [];
		}
		$names = [ 'p-' . $post->ID, 'pt-' . $post->post_type, 'home', 'feed' ];
		if ( (int) $post->post_author > 0 ) {
			$names[] = 'a-' . (int) $post->post_author;
		}
		if ( 'product' === $post->post_type ) {
			$names[] = 'shop';
			// Las páginas que lo muestran en un listado o como relacionado (0.14.1).
			$names[] = 'l-' . $post->ID;
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! is_taxonomy_viewable( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$names[] = 't-' . $term->term_id;
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * URLs públicas afectadas por un cambio en un post (para la purga por URL y el recalentado).
	 *
	 * @return list<string>
	 */
	public static function urlsForPost( \WP_Post $post ): array {
		if ( 'product_variation' === $post->post_type && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			return $parent instanceof \WP_Post ? self::urlsForPost( $parent ) : [];
		}
		$urls = [ home_url( '/' ) ];
		if ( 'publish' === $post->post_status ) {
			$urls[] = get_permalink( $post );
		}
		$archive = get_post_type_archive_link( $post->post_type );
		$urls[]  = $archive;
		if ( 'product' === $post->post_type && function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = wc_get_page_permalink( 'shop' );
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! is_taxonomy_viewable( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				$urls[] = get_term_link( $term );
			}
		}
		return self::cleanUrls( $urls );
	}

	/**
	 * @param array<mixed> $urls URLs posibles (descarta errores y vacíos).
	 * @return list<string>
	 */
	public static function cleanUrls( array $urls ): array {
		$out = [];
		foreach ( $urls as $url ) {
			if ( is_string( $url ) && '' !== $url && str_starts_with( $url, 'http' ) ) {
				$out[ $url ] = true;
			}
		}
		return array_keys( $out );
	}
}
