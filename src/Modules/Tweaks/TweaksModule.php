<?php
/**
 * Ajustes rápidos (docs/02 módulo 7). Riesgo seguro (cada ajuste tiene el suyo), activo por defecto.
 *
 * Solo hooks de WordPress, verificados en WordPress 7.1 y WooCommerce 11.2 (wlwmanifest_link ya no
 * existe desde WordPress 6.3; los estilos de emoji pasaron a wp_enqueue_emoji_styles en 6.4).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Tweaks;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Runtime;

/**
 * Registro del módulo.
 */
final class TweaksModule implements ModuleInterface {

	/** Feeds que redirigen a la portada con el ajuste "feeds". */
	private const FEED_HOOKS = [ 'do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom', 'do_feed_rss2_comments', 'do_feed_atom_comments' ];

	/** @var array{on: array<string, bool>, revisions: int, autosave: int} */
	private array $settings;

	public function register( Container $container, RequestContext $request ): void {
		$this->settings = self::settings();
		$on             = $this->settings['on'];
		$type           = $request->type;
		$frontend       = in_array( $type, [ RequestType::FrontendHtml, RequestType::Cart, RequestType::Checkout, RequestType::Account, RequestType::Feed ], true );

		if ( $on['emojis'] ?? false ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_action( 'embed_head', 'print_emoji_detection_script' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'emoji_svg_url', '__return_false' );
			add_filter( 'tiny_mce_plugins', [ self::class, 'withoutEmojiPlugin' ] );
		}
		if ( $on['head_cleanup'] ?? false ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wp_generator' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
			add_filter( 'the_generator', '__return_empty_string' );
		}
		if ( $on['rest_links'] ?? false ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}
		if ( $on['self_pingbacks'] ?? false ) {
			add_action( 'pre_ping', [ self::class, 'removeSelfPings' ] );
		}
		if ( $on['embeds'] ?? false ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 4 );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			add_action( 'wp_footer', [ self::class, 'removeEmbedScript' ], 1 );
		}
		if ( ( $on['revisions'] ?? false ) && in_array( $type, [ RequestType::Admin, RequestType::AdminAjax, RequestType::Rest, RequestType::Cron, RequestType::Cli ], true ) ) {
			add_filter( 'wp_revisions_to_keep', [ $this, 'revisions' ] );
		}
		if ( ( $on['autosave'] ?? false ) && in_array( $type, [ RequestType::Admin, RequestType::Rest ], true ) ) {
			// AUTOSAVE_INTERVAL se define después de plugins_loaded (wp_functionality_constants).
			add_action( 'plugins_loaded', [ $this, 'defineAutosave' ], 0 );
			add_filter( 'block_editor_settings_all', [ $this, 'blockEditorAutosave' ] );
		}
		if ( $on['feeds'] ?? false ) {
			foreach ( self::FEED_HOOKS as $hook ) {
				add_action( $hook, [ self::class, 'redirectFeed' ], 1 );
			}
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}
		if ( $on['comments'] ?? false ) {
			add_filter( 'comments_open', [ self::class, 'commentsOpen' ], 20, 2 );
			add_filter( 'pings_open', '__return_false', 20 );
			add_filter( 'xmlrpc_methods', [ self::class, 'withoutPingback' ] );
			if ( RequestType::Admin === $type ) {
				add_action( 'admin_menu', [ self::class, 'removeCommentsMenu' ], 999 );
			}
		}
		if ( ( $on['wc_admin'] ?? false ) && in_array( $type, [ RequestType::Admin, RequestType::Rest, RequestType::AdminAjax ], true ) ) {
			add_filter( 'woocommerce_admin_features', [ self::class, 'withoutWcAdminFeatures' ], 20 );
		}

		if ( ! $frontend ) {
			return;
		}
		if ( $on['dashicons'] ?? false ) {
			add_action( 'wp_enqueue_scripts', [ self::class, 'dequeueDashicons' ], PHP_INT_MAX );
		}
		if ( $on['password_meter'] ?? false ) {
			add_action( 'wp_enqueue_scripts', [ self::class, 'dequeuePasswordMeter' ], PHP_INT_MAX );
		}
		if ( $on['google_fonts'] ?? false ) {
			add_action( 'wp_enqueue_scripts', [ self::class, 'dequeueGoogleFonts' ], PHP_INT_MAX );
			add_action( 'wp_print_styles', [ self::class, 'dequeueGoogleFonts' ], PHP_INT_MAX );
		}
		if ( $on['jquery_migrate'] ?? false ) {
			add_action( 'wp_default_scripts', [ self::class, 'removeJqueryMigrate' ] );
		}
	}

	/**
	 * @return array{on: array<string, bool>, revisions: int, autosave: int}
	 */
	public static function settings(): array {
		$t  = Runtime::$config?->get( 'tweaks', [] );
		$t  = is_array( $t ) ? $t : [];
		$on = [];
		foreach ( is_array( $t['on'] ?? null ) ? $t['on'] : [] as $id => $enabled ) {
			if ( is_string( $id ) ) {
				$on[ $id ] = true === $enabled;
			}
		}
		return [
			'on'        => $on,
			'revisions' => is_int( $t['revisions'] ?? null ) ? $t['revisions'] : 5,
			'autosave'  => is_int( $t['autosave'] ?? null ) ? $t['autosave'] : 120,
		];
	}

	/**
	 * @param mixed $plugins Plugins de TinyMCE.
	 * @return mixed
	 */
	public static function withoutEmojiPlugin( mixed $plugins ): mixed {
		return is_array( $plugins ) ? array_values( array_diff( $plugins, [ 'wpemoji' ] ) ) : $plugins;
	}

	/**
	 * @param array<mixed> $links Enlaces a los que se haría ping (por referencia).
	 */
	public static function removeSelfPings( array &$links ): void {
		$home = (string) home_url();
		foreach ( $links as $i => $link ) {
			if ( is_string( $link ) && str_starts_with( $link, $home ) ) {
				unset( $links[ $i ] );
			}
		}
	}

	public static function removeEmbedScript(): void {
		wp_dequeue_script( 'wp-embed' );
	}

	public function revisions(): int {
		return $this->settings['revisions'];
	}

	public function defineAutosave(): void {
		if ( ! defined( 'AUTOSAVE_INTERVAL' ) ) {
			define( 'AUTOSAVE_INTERVAL', $this->settings['autosave'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante del core.
		}
	}

	/**
	 * @param mixed $settings Ajustes del editor de bloques.
	 * @return mixed
	 */
	public function blockEditorAutosave( mixed $settings ): mixed {
		if ( is_array( $settings ) ) {
			$settings['autosaveInterval'] = $this->settings['autosave'];
		}
		return $settings;
	}

	public static function redirectFeed(): void {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	/**
	 * Las reseñas de WooCommerce usan comentarios: en productos no se cierran.
	 *
	 * @param mixed $open   Abierto.
	 * @param mixed $postId Post.
	 */
	public static function commentsOpen( mixed $open, mixed $postId ): bool {
		return 'product' === get_post_type( is_numeric( $postId ) ? (int) $postId : 0 ) ? (bool) $open : false;
	}

	/**
	 * @param mixed $methods Métodos de XML-RPC.
	 * @return mixed
	 */
	public static function withoutPingback( mixed $methods ): mixed {
		if ( is_array( $methods ) ) {
			unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		}
		return $methods;
	}

	public static function removeCommentsMenu(): void {
		remove_menu_page( 'edit-comments.php' );
	}

	/**
	 * @param mixed $features Funciones de WooCommerce Admin.
	 * @return mixed
	 */
	public static function withoutWcAdminFeatures( mixed $features ): mixed {
		return is_array( $features ) ? array_values( array_diff( $features, [ 'marketing', 'analytics', 'remote-inbox-notifications' ] ) ) : $features;
	}

	public static function dequeueDashicons(): void {
		if ( ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}

	public static function dequeuePasswordMeter(): void {
		$keep = ( function_exists( 'is_account_page' ) && is_account_page() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_lost_password_page' ) && is_lost_password_page() );
		if ( ! $keep ) {
			wp_dequeue_script( 'wc-password-strength-meter' );
			wp_dequeue_script( 'password-strength-meter' );
			wp_dequeue_script( 'zxcvbn-async' );
		}
	}

	public static function dequeueGoogleFonts(): void {
		$styles = wp_styles();
		foreach ( $styles->queue as $handle ) {
			$src = isset( $styles->registered[ $handle ] ) ? $styles->registered[ $handle ]->src : false;
			if ( is_string( $src ) && str_contains( $src, 'fonts.googleapis.com' ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	/**
	 * jQuery sin jquery-migrate en el frontend (el admin lo sigue necesitando).
	 *
	 * @param \WP_Scripts $scripts Scripts registrados.
	 */
	public static function removeJqueryMigrate( \WP_Scripts $scripts ): void {
		if ( isset( $scripts->registered['jquery'] ) ) {
			$scripts->registered['jquery']->deps = array_values( array_diff( $scripts->registered['jquery']->deps, [ 'jquery-migrate' ] ) );
		}
	}
}
