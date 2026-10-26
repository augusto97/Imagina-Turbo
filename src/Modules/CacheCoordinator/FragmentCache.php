<?php
/**
 * Caché de fragmentos en la caché de objetos (0.17.0). Riesgo moderado, apagado por defecto.
 *
 * Para las páginas que el plugin de caché no guarda (carrito, checkout, cuenta, visitantes con sesión
 * y la primera visita tras una purga): los menús no se vuelven a renderizar en cada página.
 * Medido en La Tienda del Pintor (WoodMart + Elementor): el menú «Categorías» imprime 10 bloques HTML
 * de Elementor en todas las páginas (287 KB de 680 KB); esas páginas tardan 1–2 s en PHP.
 *
 * - wp_nav_menu: pre_wp_nav_menu devuelve la copia guardada; el filtro wp_nav_menu la guarda. La llave
 *   lleva los argumentos del menú, la URL pedida (las clases current-menu-item dependen de ella), el
 *   usuario (algunos plugins muestran elementos por usuario o rol), el idioma, si es móvil, las cookies
 *   de variación (moneda, idioma) y la generación.
 * - Lo que el render encola (CSS y JS de los widgets de Elementor, la hoja del bloque, scripts de
 *   WoodMart, CSS en línea agregado a handles ya encolados) se anota con la copia y se vuelve a encolar
 *   al servirla. Si el render dejó a Elementor "con contenido en la página", también: sin eso Elementor
 *   no carga su JS en el pie (Frontend::wp_footer, verificado en Elementor 4.3.5).
 * - No se guarda un menú que lleve un nonce ni uno de más de 2 MB.
 * - Generación: cualquier cambio de contenido, menús, términos, stock, tema, plugins o una purga total
 *   del plugin de caché cambia la generación y todas las copias quedan viejas a la vez. Además
 *   caducan a las 12 h.
 * - Solo con caché de objetos persistente (Redis, Memcached): sin ella no hace nada.
 *
 * La verificación (admin) compara una página renderizada de cero con la misma servida desde la caché
 * de fragmentos (FragmentVerifier).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

use ImaginaTurbo\Core\Safety\AdminBypassCookie;
use ImaginaTurbo\Modules\FrontendHints\LazyElements;

/**
 * Menús desde la caché de objetos.
 *
 * @phpstan-type Dep array{src: string|false, deps: list<string>, ver: string|bool|null, args: mixed, extra: array<string, mixed>}
 * @phpstan-type Side array{css: list<string>, js: list<string>, reg_css: array<string, Dep>, reg_js: array<string, Dep>, after_css: array<string, list<string>>, after_js: array<string, list<string>>, el: bool}
 */
final class FragmentCache {

	public const GROUP = 'itb_frag';

	public const TTL = 12 * HOUR_IN_SECONDS;

	public const MAX_BYTES = 2097152;

	/** Cookie firmada del verificador; con ?itb_frag=b (sin caché), f (renderizar y guardar) o h (solo la copia). */
	public const COOKIE = 'itb_frag';

	public const RESULT_TRANSIENT = 'itb_frag_v_';

	/** Hooks que cambian la generación. */
	public const FLUSH_HOOKS = [
		'save_post',
		'deleted_post',
		'trashed_post',
		'untrashed_post',
		'created_term',
		'edited_term',
		'delete_term',
		'wp_update_nav_menu',
		'wp_delete_nav_menu',
		'switch_theme',
		'customize_save_after',
		'activated_plugin',
		'deactivated_plugin',
		'upgrader_process_complete',
		'woocommerce_product_set_stock',
		'woocommerce_variation_set_stock',
		'woocommerce_product_set_stock_status',
		'woocommerce_variation_set_stock_status',
		'elementor/core/files/clear_cache',
		'litespeed_purged_all',
		'after_rocket_clean_domain',
		'update_option_sidebars_widgets',
	];

	/** @var list<array{oid: int, key: string, css: list<string>, js: list<string>, reg_css: list<string>, reg_js: list<string>, after_css: array<string, int>, after_js: array<string, int>, el: bool}> */
	private static array $frames = [];

	private static ?string $generation = null;

	/** Modo del verificador: '' (normal), 'b', 'f' o 'h'. */
	private static string $mode = '';

	private static string $nonce = '';

	/** @var array{hits: int, stores: int, misses: int, skipped: int} */
	private static array $stats = [
		'hits'    => 0,
		'stores'  => 0,
		'misses'  => 0,
		'skipped' => 0,
	];

	/**
	 * @param list<string> $vary   Cookies de variación (moneda, idioma).
	 * @param list<string> $ignore Parámetros de campañas, que no cambian el menú.
	 */
	public function __construct( private readonly array $vary = [], private readonly bool $enabled = true, private readonly array $ignore = [] ) {
	}

	public static function available(): bool {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
	}

	/**
	 * Generación: invalida todo cambiándola. Se registra en todos los tipos de request.
	 */
	public static function registerFlush(): void {
		foreach ( self::FLUSH_HOOKS as $hook ) {
			add_action( $hook, 'save_post' === $hook ? [ self::class, 'onSavePost' ] : [ self::class, 'flush' ], 999, 'save_post' === $hook ? 2 : 1 );
		}
		add_action( 'update_option_' . (string) get_option( 'stylesheet' ) . '_options', [ self::class, 'flush' ] );
		add_action( 'update_option_xts-woodmart-options', [ self::class, 'flush' ] );
	}

	/** Tipos de entrada que no se muestran en menús ni bloques: no invalidan. */
	public const IGNORED_TYPES = [ 'revision', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'customize_changeset', 'oembed_cache', 'scheduled-action', 'wp_global_styles', 'user_request', 'custom_css' ];

	/**
	 * @param mixed $postId Id.
	 * @param mixed $post   Entrada.
	 */
	public static function onSavePost( mixed $postId, mixed $post = null ): void {
		if ( $post instanceof \WP_Post && ( in_array( $post->post_type, self::IGNORED_TYPES, true ) || 'auto-draft' === $post->post_status || wp_is_post_autosave( $post ) ) ) {
			return;
		}
		self::flush();
	}

	public static function flush(): void {
		if ( ! self::available() ) {
			return;
		}
		self::$generation = str_replace( '.', '', uniqid( '', true ) );
		wp_cache_set( 'gen', self::$generation, self::GROUP );
	}

	public static function generation(): string {
		if ( null === self::$generation ) {
			$gen = wp_cache_get( 'gen', self::GROUP );
			if ( ! is_string( $gen ) || '' === $gen ) {
				self::flush();
				$gen = (string) self::$generation;
			}
			self::$generation = $gen;
		}
		return self::$generation;
	}

	public function register(): void {
		if ( ! self::available() ) {
			return;
		}
		self::$mode = self::verifierMode();
		if ( '' === self::$mode && ! $this->enabled ) {
			return;
		}
		if ( 'b' === self::$mode ) {
			add_action( 'wp_footer', [ self::class, 'saveResult' ], PHP_INT_MAX );
			return;
		}
		add_filter( 'pre_wp_nav_menu', [ $this, 'pre' ], PHP_INT_MAX - 10, 2 );
		add_filter( 'wp_nav_menu', [ $this, 'post' ], PHP_INT_MAX, 2 );
		if ( '' !== self::$mode ) {
			add_action( 'wp_footer', [ self::class, 'saveResult' ], PHP_INT_MAX );
		}
	}

	/**
	 * Modo pedido por el verificador (cookie firmada + ?itb_frag= + ?itb_nc=), o ''.
	 */
	private static function verifierMode(): string {
		$mode = isset( $_GET['itb_frag'] ) ? sanitize_key( wp_unslash( $_GET['itb_frag'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- la autorización es la cookie firmada.
		if ( ! in_array( $mode, [ 'b', 'f', 'h' ], true ) || ! AdminBypassCookie::isValidRequest( $_COOKIE, null, self::COOKIE ) ) {
			return '';
		}
		$nonce = isset( $_GET['itb_nc'] ) ? sanitize_key( wp_unslash( $_GET['itb_nc'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
		if ( 1 !== preg_match( '/^[a-z0-9]{8,24}$/', $nonce ) ) {
			return '';
		}
		self::$nonce = $nonce;
		add_action( 'init', [ \ImaginaTurbo\Core\Plugin::class, 'noPageCache' ], 0 );
		return $mode;
	}

	public static function saveResult(): void {
		if ( '' !== self::$nonce ) {
			set_transient( self::RESULT_TRANSIENT . self::$nonce, self::$stats, 300 );
		}
	}

	/**
	 * @param mixed $output Salida de otro filtro (null = renderizar).
	 * @param mixed $args   Argumentos del menú.
	 * @return mixed
	 */
	public function pre( mixed $output, mixed $args ): mixed {
		if ( null !== $output || ! is_object( $args ) || ! self::eligible() ) {
			return $output;
		}
		$key = $this->key( $args );
		if ( 'f' !== self::$mode ) {
			$hit = wp_cache_get( $key, self::GROUP );
			if ( is_array( $hit ) && is_string( $hit['h'] ?? null ) ) {
				$html = self::unpack( $hit['h'] );
				if ( null !== $html ) {
					self::replay( is_array( $hit['s'] ?? null ) ? $hit['s'] : [] );
					// Un bloque diferido dentro del menú necesita el script que lo pasa al DOM.
					if ( str_contains( $html, 'data-itb-lz' ) ) {
						LazyElements::markUsed();
					}
					++self::$stats['hits'];
					return $html;
				}
			}
			++self::$stats['misses'];
			if ( 'h' === self::$mode ) {
				return $output;
			}
		}
		self::$frames[] = [ 'oid' => spl_object_id( $args ) ] + self::snapshot( $key );
		return $output;
	}

	/**
	 * @param mixed $navMenu HTML del menú.
	 * @param mixed $args    Argumentos.
	 * @return mixed
	 */
	public function post( mixed $navMenu, mixed $args ): mixed {
		if ( [] === self::$frames || ! is_string( $navMenu ) || ! is_object( $args ) ) {
			return $navMenu;
		}
		// El marco de este menú: el mismo objeto de argumentos (wp_nav_menu completa algunos entre los dos
		// filtros, así que la llave se calcula solo al principio). Los de un fallback_cb, que no pasa por
		// este filtro, se descartan.
		$oid   = spl_object_id( $args );
		$frame = null;
		while ( null === $frame && [] !== self::$frames ) {
			$top   = array_pop( self::$frames );
			$frame = $top['oid'] === $oid ? $top : null;
		}
		if ( null === $frame ) {
			return $navMenu;
		}
		if ( strlen( $navMenu ) > self::MAX_BYTES || 1 === preg_match( '/nonce/i', $navMenu ) ) {
			++self::$stats['skipped'];
			return $navMenu;
		}
		wp_cache_set(
			$frame['key'],
			[
				'h' => self::pack( $navMenu ),
				's' => self::sideEffects( $frame ),
			],
			self::GROUP,
			self::TTL
		);
		++self::$stats['stores'];
		return $navMenu;
	}

	/**
	 * GET de una página, fuera del admin, la vista previa y el personalizador.
	 */
	public static function eligible(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		return in_array( $method, [ 'GET', 'HEAD' ], true ) && ! is_admin() && ! is_customize_preview() && ! is_preview() && ! wp_doing_ajax();
	}

	/**
	 * Llave del menú en este contexto.
	 */
	public function key( object $args ): string {
		$vary = [];
		foreach ( $this->vary as $cookie ) {
			if ( isset( $_COOKIE[ $cookie ] ) && is_string( $_COOKIE[ $cookie ] ) ) {
				$vary[ $cookie ] = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) );
			}
		}
		$uri = self::keyUri( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '', $this->ignore ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- solo forma parte de un hash.
		return 'm' . md5(
			(string) wp_json_encode(
				[
					self::fingerprint( $args ),
					$uri,
					get_current_user_id(),
					determine_locale(),
					wp_is_mobile(),
					$vary,
					self::generation(),
				]
			)
		);
	}

	/**
	 * La URL pedida sin los parámetros que no cambian el menú: los del verificador y los de campañas
	 * (utm_*, fbclid, gclid…). Los demás se quedan: un enlace personalizado del menú puede llevarlos y
	 * WordPress compara la URL completa para marcarlo como actual.
	 *
	 * @param list<string> $ignore Parámetros de campañas.
	 */
	public static function keyUri( string $uri, array $ignore ): string {
		$path  = (string) strtok( $uri, '?' );
		$query = (string) substr( $uri, strlen( $path ) + 1 );
		if ( '' === $query ) {
			return $path;
		}
		$keep = [];
		foreach ( explode( '&', $query ) as $pair ) {
			$name = strtolower( rawurldecode( (string) strtok( $pair, '=' ) ) );
			if ( '' === $name || 'itb_frag' === $name || 'itb_nc' === $name || str_starts_with( $name, 'utm_' ) || in_array( $name, $ignore, true ) ) {
				continue;
			}
			$keep[] = $pair;
		}
		return [] === $keep ? $path : $path . '?' . implode( '&', $keep );
	}

	/**
	 * Argumentos del menú en forma comparable (objetos por clase o id).
	 *
	 * @return array<string, mixed>
	 */
	public static function fingerprint( object $args ): array {
		$out = [];
		foreach ( get_object_vars( $args ) as $name => $value ) {
			$out[ $name ] = match ( true ) {
				$value instanceof \WP_Term => 'term:' . $value->term_id,
				$value instanceof \Closure => 'closure',
				is_object( $value ) => 'object:' . get_class( $value ),
				is_array( $value ) => md5( (string) wp_json_encode( $value ) ),
				default => $value,
			};
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Estado de los encolados antes de renderizar.
	 *
	 * @return array{key: string, css: list<string>, js: list<string>, reg_css: list<string>, reg_js: list<string>, after_css: array<string, int>, after_js: array<string, int>, el: bool}
	 */
	private static function snapshot( string $key ): array {
		$styles  = wp_styles();
		$scripts = wp_scripts();
		return [
			'key'       => $key,
			'css'       => array_values( array_map( 'strval', $styles->queue ) ),
			'js'        => array_values( array_map( 'strval', $scripts->queue ) ),
			'reg_css'   => array_map( 'strval', array_keys( $styles->registered ) ),
			'reg_js'    => array_map( 'strval', array_keys( $scripts->registered ) ),
			'after_css' => self::afterCounts( $styles ),
			'after_js'  => self::afterCounts( $scripts ),
			'el'        => self::elementorInPage(),
		];
	}

	/**
	 * Cuántos fragmentos en línea ("after") tiene cada handle registrado.
	 *
	 * @return array<string, int>
	 */
	private static function afterCounts( \WP_Dependencies $deps ): array {
		$out = [];
		foreach ( $deps->registered as $handle => $item ) {
			$after = $item instanceof \_WP_Dependency ? ( $item->extra['after'] ?? null ) : null;
			if ( is_array( $after ) ) {
				$out[ (string) $handle ] = count( $after );
			}
		}
		return $out;
	}

	/**
	 * Lo que el render encoló, registró o agregó en línea.
	 *
	 * @param array{key: string, css: list<string>, js: list<string>, reg_css: list<string>, reg_js: list<string>, after_css: array<string, int>, after_js: array<string, int>, el: bool} $frame Estado previo.
	 * @return Side
	 */
	private static function sideEffects( array $frame ): array {
		$styles  = wp_styles();
		$scripts = wp_scripts();
		return [
			'css'       => array_values( array_diff( array_map( 'strval', $styles->queue ), $frame['css'] ) ),
			'js'        => array_values( array_diff( array_map( 'strval', $scripts->queue ), $frame['js'] ) ),
			'reg_css'   => self::registrations( $styles, $frame['reg_css'] ),
			'reg_js'    => self::registrations( $scripts, $frame['reg_js'] ),
			'after_css' => self::afterAdded( $styles, $frame['after_css'], $frame['reg_css'] ),
			'after_js'  => self::afterAdded( $scripts, $frame['after_js'], $frame['reg_js'] ),
			'el'        => ! $frame['el'] && self::elementorInPage(),
		];
	}

	/**
	 * Handles registrados durante el render, con lo necesario para volver a registrarlos.
	 *
	 * @param list<string> $before Handles ya registrados.
	 * @return array<string, Dep>
	 */
	private static function registrations( \WP_Dependencies $deps, array $before ): array {
		$out = [];
		foreach ( array_diff( array_map( 'strval', array_keys( $deps->registered ) ), $before ) as $handle ) {
			$item = $deps->registered[ $handle ];
			if ( ! $item instanceof \_WP_Dependency ) {
				continue;
			}
			$out[ $handle ] = [
				'src'   => is_string( $item->src ) ? $item->src : false,
				'deps'  => array_values( array_map( 'strval', $item->deps ) ),
				'ver'   => is_string( $item->ver ) || is_bool( $item->ver ) ? $item->ver : null,
				'args'  => $item->args,
				'extra' => $item->extra,
			];
		}
		return $out;
	}

	/**
	 * CSS o JS en línea agregado durante el render a handles que ya estaban registrados (los registrados
	 * durante el render lo llevan en sus datos).
	 *
	 * @param array<string, int> $before     Cantidad previa por handle.
	 * @param list<string>       $registered Handles registrados antes del render.
	 * @return array<string, list<string>>
	 */
	private static function afterAdded( \WP_Dependencies $deps, array $before, array $registered ): array {
		$out   = [];
		$known = array_flip( $registered );
		foreach ( self::afterCounts( $deps ) as $handle => $count ) {
			$prev = $before[ $handle ] ?? 0;
			if ( $count > $prev && isset( $known[ $handle ] ) ) {
				$after          = $deps->registered[ $handle ]->extra['after'];
				$out[ $handle ] = array_values( array_filter( array_slice( (array) $after, $prev ), 'is_string' ) );
			}
		}
		return $out;
	}

	/**
	 * Repite al servir la copia lo que el render había encolado.
	 *
	 * @param array<mixed> $side Efectos anotados.
	 */
	public static function replay( array $side ): void {
		$styles  = wp_styles();
		$scripts = wp_scripts();
		foreach ( [
			'reg_css' => $styles,
			'reg_js'  => $scripts,
		] as $field => $deps ) {
			foreach ( is_array( $side[ $field ] ?? null ) ? $side[ $field ] : [] as $handle => $dep ) {
				if ( ! is_string( $handle ) || ! is_array( $dep ) || isset( $deps->registered[ $handle ] ) ) {
					continue;
				}
				// La versión tal cual: null (sin ?ver=) no es lo mismo que false (la versión de WordPress).
				$deps->add( $handle, $dep['src'] ?? false, is_array( $dep['deps'] ?? null ) ? $dep['deps'] : [], array_key_exists( 'ver', $dep ) ? $dep['ver'] : false, $dep['args'] ?? null );
				foreach ( is_array( $dep['extra'] ?? null ) ? $dep['extra'] : [] as $name => $value ) {
					$deps->add_data( $handle, (string) $name, $value );
				}
			}
		}
		foreach ( is_array( $side['after_css'] ?? null ) ? $side['after_css'] : [] as $handle => $list ) {
			foreach ( is_array( $list ) ? $list : [] as $css ) {
				wp_add_inline_style( (string) $handle, (string) $css );
			}
		}
		foreach ( is_array( $side['after_js'] ?? null ) ? $side['after_js'] : [] as $handle => $list ) {
			foreach ( is_array( $list ) ? $list : [] as $js ) {
				wp_add_inline_script( (string) $handle, (string) $js );
			}
		}
		foreach ( is_array( $side['css'] ?? null ) ? $side['css'] : [] as $handle ) {
			wp_enqueue_style( (string) $handle );
		}
		foreach ( is_array( $side['js'] ?? null ) ? $side['js'] : [] as $handle ) {
			wp_enqueue_script( (string) $handle );
		}
		if ( true === ( $side['el'] ?? false ) ) {
			self::markElementorInPage();
		}
	}

	public static function elementorInPage(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->frontend ) ) {
			return false;
		}
		$frontend = \Elementor\Plugin::$instance->frontend;
		return is_object( $frontend ) && method_exists( $frontend, 'has_elementor_in_page' ) && true === $frontend->has_elementor_in_page();
	}

	/**
	 * Elementor solo carga su JS en el pie si imprimió contenido (propiedad privada, sin setter).
	 */
	private static function markElementorInPage(): void {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->frontend ) ) {
			return;
		}
		$frontend = \Elementor\Plugin::$instance->frontend;
		if ( ! is_object( $frontend ) || ! property_exists( $frontend, '_has_elementor_in_page' ) ) {
			return;
		}
		try {
			( new \ReflectionProperty( $frontend, '_has_elementor_in_page' ) )->setValue( $frontend, true );
		} catch ( \ReflectionException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Elementor cambió la propiedad: su JS no se forzará.
		}
	}

	public static function pack( string $html ): string {
		if ( function_exists( 'gzcompress' ) ) {
			$z = gzcompress( $html, 6 );
			if ( is_string( $z ) ) {
				return 'z' . $z;
			}
		}
		return 'r' . $html;
	}

	public static function unpack( string $data ): ?string {
		if ( str_starts_with( $data, 'r' ) ) {
			return substr( $data, 1 );
		}
		if ( str_starts_with( $data, 'z' ) && function_exists( 'gzuncompress' ) ) {
			$html = gzuncompress( substr( $data, 1 ) );
			return is_string( $html ) ? $html : null;
		}
		return null;
	}

	/**
	 * @return array{hits: int, stores: int, misses: int, skipped: int}
	 */
	public static function stats(): array {
		return self::$stats;
	}

	/**
	 * Solo para pruebas.
	 */
	public static function reset(): void {
		self::$frames     = [];
		self::$generation = null;
		self::$mode       = '';
		self::$nonce      = '';
		self::$stats      = [
			'hits'    => 0,
			'stores'  => 0,
			'misses'  => 0,
			'skipped' => 0,
		];
	}
}
