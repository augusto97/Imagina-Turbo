<?php
/**
 * Mapa de purga (docs/02, módulo 2): qué eventos del sitio purgan qué.
 *
 * Registrar es barato (solo add_action); el Purger se crea cuando un evento ocurre. Hooks de
 * WooCommerce verificados en 11.2.1: el cambio de stock va por SQL directo (no dispara save_post) y
 * un cambio solo de precio no pasa por wp_update_post, por eso se escuchan sus hooks propios. Las
 * ofertas programadas llegan por woocommerce_update_product (Action Scheduler por producto).
 *
 * 0.14.1, purga por impacto. Una venta descuenta stock por SQL (wc_update_product_stock) y después
 * guarda el producto: dispara woocommerce_product_set_stock y woocommerce_update_product, y antes
 * purgaba la portada, la tienda y todas las categorías en cada venta. Ahora:
 * - solo cambió la cantidad (el guardado no trae más cambios que stock_quantity o date_modified):
 *   solo la página del producto;
 * - cambió el estado del stock, el precio u otro dato, o se editó en el admin: el producto, sus
 *   términos, la tienda, la portada y las páginas que lo muestran en un listado o como relacionado
 *   (tag l-ID, que pone HeaderEmitter).
 * Los cambios se leen en woocommerce_before_{product,product_variation}_object_save, el único punto en
 * que get_changes() todavía los tiene (WC_Data::save, verificado en 11.2.1).
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

	/** Cambios de un guardado que no se ven fuera de la página del producto. */
	public const MINOR_CHANGES = [ 'stock_quantity', 'date_modified' ];

	/** @var array<int, array<string, true>> Campos cambiados por id de producto o variación. */
	private array $changes = [];

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

		add_action( 'woocommerce_before_product_object_save', [ $this, 'noteChanges' ] );
		add_action( 'woocommerce_before_product_variation_object_save', [ $this, 'noteChanges' ] );
		add_action( 'woocommerce_product_set_stock', [ $this, 'onStockQuantity' ] );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'onStockQuantity' ] );
		add_action( 'woocommerce_product_set_stock_status', [ $this, 'onProductId' ] );
		add_action( 'woocommerce_variation_set_stock_status', [ $this, 'onProductId' ] );
		add_action( 'woocommerce_update_product', [ $this, 'onProductSaved' ] );
		add_action( 'woocommerce_update_product_variation', [ $this, 'onProductSaved' ] );

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
	/**
	 * Antes de guardar: qué campos cambian (después de guardar ya no se sabe).
	 *
	 * @param mixed $product Producto o variación.
	 */
	public function noteChanges( mixed $product ): void {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) && method_exists( $product, 'get_changes' ) ) {
			$id      = (int) $product->get_id();
			$changes = $product->get_changes();
			foreach ( is_array( $changes ) ? array_keys( $changes ) : [] as $key ) {
				$this->changes[ $id ][ (string) $key ] = true;
			}
			$this->changes[ $id ] ??= [];
		}
	}

	/**
	 * Cambió la cantidad en stock (una venta, una devolución): solo la página del producto.
	 *
	 * @param mixed $product Producto o variación.
	 */
	public function onStockQuantity( mixed $product ): void {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$this->purgeProductPage( (int) $product->get_id() );
		}
	}

	/**
	 * Se guardó un producto: según lo que cambió, solo su página o todo lo que lo muestra.
	 *
	 * @param mixed $productId Id del producto o la variación.
	 */
	public function onProductSaved( mixed $productId ): void {
		$id      = (int) $productId;
		$changes = $this->changes[ $id ] ?? null;
		unset( $this->changes[ $id ] );
		if ( null !== $changes && self::minor( array_keys( $changes ) ) ) {
			$this->purgeProductPage( $id );
			return;
		}
		$this->onProductId( $id );
	}

	/**
	 * El guardado solo trae cambios que no se ven fuera de la página del producto.
	 *
	 * @param list<string> $changes Campos cambiados.
	 */
	public static function minor( array $changes ): bool {
		return [] === array_diff( $changes, self::MINOR_CHANGES );
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

	/**
	 * Solo la página del producto (la de su padre si es una variación).
	 */
	private function purgeProductPage( int $id ): void {
		$post = get_post( $id );
		if ( $post instanceof \WP_Post && 'product_variation' === $post->post_type && $post->post_parent > 0 ) {
			$post = get_post( $post->post_parent );
		}
		if ( ! ( $post instanceof \WP_Post ) || 'publish' !== $post->post_status ) {
			return;
		}
		$url = get_permalink( $post );
		( $this->purger )()->purge( new PurgeRequest( [ 'p-' . $post->ID ], is_string( $url ) ? [ $url ] : [], false, 'stock' ) );
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
