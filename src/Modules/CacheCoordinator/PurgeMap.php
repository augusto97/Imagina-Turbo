<?php
/**
 * Mapa de purga (docs/02, módulo 2): qué eventos del sitio purgan qué.
 *
 * Registrar es barato (solo add_action); el Purger se crea cuando un evento ocurre. Hooks de
 * WooCommerce verificados en 11.2.1: el cambio de stock va por SQL directo (no dispara save_post) y
 * un cambio solo de precio no pasa por wp_update_post, por eso se escuchan sus hooks propios. Las
 * ofertas programadas llegan por woocommerce_update_product (Action Scheduler por producto).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

/**
 * Registro de escuchas.
 */
final class PurgeMap {

	/** Eventos que cambian todo el sitio. */
	public const ALL_HOOKS = [
		'wp_update_nav_menu',
		'update_option_sidebars_widgets',
		'customize_save_after',
		'switch_theme',
		'activated_plugin',
		'deactivated_plugin',
		'update_option_woocommerce_cart_page_id',
		'update_option_woocommerce_checkout_page_id',
		'update_option_woocommerce_myaccount_page_id',
		'update_option_permalink_structure',
	];

	/**
	 * @param \Closure(): Purger $purger    Fábrica del Purger.
	 * @param list<string>       $allHooks  Hooks de "purgar todo" de la matriz (Elementor, etc.).
	 */
	public function __construct( private readonly \Closure $purger, private readonly array $allHooks = [] ) {
	}

	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'onTransition' ], 10, 3 );
		add_action( 'post_updated', [ $this, 'onPostUpdated' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'onBeforeDelete' ], 10, 2 );

		add_action( 'transition_comment_status', [ $this, 'onCommentTransition' ], 10, 3 );
		add_action( 'comment_post', [ $this, 'onCommentPost' ], 10, 2 );
		add_action( 'edit_comment', [ $this, 'onCommentEdit' ] );

		add_action( 'edited_term', [ $this, 'onTerm' ], 10, 3 );
		add_action( 'delete_term', [ $this, 'onTerm' ], 10, 3 );

		add_action( 'woocommerce_product_set_stock', [ $this, 'onProduct' ] );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'onProduct' ] );
		add_action( 'woocommerce_product_set_stock_status', [ $this, 'onProductId' ] );
		add_action( 'woocommerce_variation_set_stock_status', [ $this, 'onProductId' ] );
		add_action( 'woocommerce_update_product', [ $this, 'onProductId' ] );
		add_action( 'woocommerce_update_product_variation', [ $this, 'onProductId' ] );

		foreach ( array_unique( array_merge( self::ALL_HOOKS, $this->allHooks ) ) as $hook ) {
			add_action( $hook, fn() => $this->purgeAll( $hook ), 99 );
		}
	}

	/**
	 * Publicar, despublicar o actualizar algo publicado.
	 *
	 * @param mixed $new  Estado nuevo.
	 * @param mixed $old  Estado anterior.
	 * @param mixed $post Post.
	 */
	public function onTransition( mixed $new, mixed $old, mixed $post ): void {
		if ( ! $post instanceof \WP_Post || ( 'publish' !== $new && 'publish' !== $old ) || ! self::relevant( $post ) ) {
			return;
		}
		( $this->purger )()->purgePost( $post, 'post' );
	}

	/**
	 * Si cambió el slug, la URL anterior también.
	 *
	 * @param mixed $postId Id.
	 * @param mixed $after  Post después.
	 * @param mixed $before Post antes.
	 */
	public function onPostUpdated( mixed $postId, mixed $after, mixed $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || 'publish' !== $before->post_status || $after->post_name === $before->post_name || ! self::relevant( $before ) ) {
			return;
		}
		$old = get_permalink( $before );
		if ( is_string( $old ) ) {
			( $this->purger )()->purge( new PurgeRequest( [], [ $old ], false, 'slug' ) );
		}
	}

	/**
	 * @param mixed $postId Id.
	 * @param mixed $post   Post.
	 */
	public function onBeforeDelete( mixed $postId, mixed $post = null ): void {
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && self::relevant( $post ) ) {
			( $this->purger )()->purgePost( $post, 'delete' );
		}
	}

	/**
	 * @param mixed $new     Estado nuevo.
	 * @param mixed $old     Estado anterior.
	 * @param mixed $comment Comentario.
	 */
	public function onCommentTransition( mixed $new, mixed $old, mixed $comment ): void {
		if ( $comment instanceof \WP_Comment && ( 'approved' === $new || 'approved' === $old ) ) {
			$this->purgeCommentPost( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * @param mixed $commentId Id.
	 * @param mixed $approved  1 si quedó aprobado.
	 */
	public function onCommentPost( mixed $commentId, mixed $approved ): void {
		if ( 1 === $approved || '1' === $approved ) {
			$comment = get_comment( (int) $commentId );
			if ( $comment instanceof \WP_Comment ) {
				$this->purgeCommentPost( (int) $comment->comment_post_ID );
			}
		}
	}

	/**
	 * @param mixed $commentId Id.
	 */
	public function onCommentEdit( mixed $commentId ): void {
		$comment = get_comment( (int) $commentId );
		if ( $comment instanceof \WP_Comment && '1' === (string) $comment->comment_approved ) {
			$this->purgeCommentPost( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * @param mixed $termId   Id.
	 * @param mixed $ttId     Id de taxonomía del término.
	 * @param mixed $taxonomy Taxonomía.
	 */
	public function onTerm( mixed $termId, mixed $ttId, mixed $taxonomy ): void {
		$tax = is_string( $taxonomy ) ? get_taxonomy( $taxonomy ) : false;
		if ( ! $tax instanceof \WP_Taxonomy || ! is_taxonomy_viewable( $tax ) ) {
			return;
		}
		$term = get_term( (int) $termId, $taxonomy );
		$url  = $term instanceof \WP_Term ? get_term_link( $term ) : '';
		( $this->purger )()->purge( new PurgeRequest( [ 't-' . (int) $termId ], is_string( $url ) ? [ $url ] : [], false, 'term' ) );
	}

	/**
	 * @param mixed $product Producto de WooCommerce.
	 */
	public function onProduct( mixed $product ): void {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$this->onProductId( $product->get_id() );
		}
	}

	/**
	 * @param mixed $productId Id del producto o la variación (la variación purga a su padre).
	 */
	public function onProductId( mixed $productId ): void {
		$post = get_post( (int) $productId );
		if ( $post instanceof \WP_Post && ( 'publish' === $post->post_status || 'product_variation' === $post->post_type ) ) {
			( $this->purger )()->purgePost( $post, 'product' );
		}
	}

	public function purgeAll( string $hook ): void {
		( $this->purger )()->purge( PurgeRequest::all( $hook ) );
	}

	private function purgeCommentPost( int $postId ): void {
		$post = get_post( $postId );
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			$url = get_permalink( $post );
			( $this->purger )()->purge( new PurgeRequest( [ 'p-' . $postId ], is_string( $url ) ? [ $url ] : [], false, 'comment' ) );
		}
	}

	/**
	 * Tipos con URL pública (sin revisiones, menús, plantillas…).
	 */
	private static function relevant( \WP_Post $post ): bool {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}
		return 'product_variation' === $post->post_type || is_post_type_viewable( $post->post_type );
	}
}
