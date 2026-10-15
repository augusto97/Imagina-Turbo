<?php
/**
 * Pistas de frontend (docs/02 módulo 9). Riesgo seguro.
 *
 * - Imagen LCP por plantilla, decidida por el RUM (RumInsights, en la tarea diaria): fetchpriority="high"
 *   y sin loading="lazy" en esa imagen (wp_get_attachment_image_attributes y wp_content_img_tag). Si es
 *   siempre la misma imagen, además <link rel="preload"> con la URL que midió el RUM en móvil, limitado
 *   a pantallas móviles (en escritorio el navegador elegiría otro tamaño y la precarga se desperdiciaría).
 *   "featured" = la imagen destacada de cada página (fichas de producto, entradas).
 * - 0.11.0, la imagen principal nunca espera a JavaScript. En un sitio real (EWWW con lazy load y
 *   "Reescritura de WebP por JS", WoodMart) la imagen LCP de producto y de categoría salía con
 *   src="data:..." y la cargaba un script: 8,7 y 11 s de LCP en móvil con 4G lento. EWWW ignora
 *   fetchpriority="high" cuando la imagen tiene srcset, y su reescritura de WebP por JS cambia el src
 *   por un marcador aunque la imagen no sea perezosa. Ahora la imagen principal lleva data-skip-lazy y
 *   data-no-lazy (los respetan EWWW, LiteSpeed Cache y WP Rocket), queda fuera de la reescritura por JS
 *   (filtro ewwwio_js_webp_exclusions) y apunta directamente a la copia .webp que ya existe en disco.
 *   Sin datos del RUM: la imagen principal de la ficha de producto (clase wp-post-image) y la primera
 *   imagen de los primeros productos de un listado (woocommerce_before_shop_loop_item, tamaño
 *   woocommerce_thumbnail). No vale "la primera imagen de la página": el megamenú de WoodMart imprime
 *   decenas de imágenes de producto antes del contenido.
 * - Fachadas de YouTube y Vimeo (embed_oembed_html): miniatura y botón; el iframe se crea al tocar.
 *   No se tocan los iframes con enablejsapi (otro script los controla).
 * - Renderizado diferido (0.11.0, riesgo moderado, solo con reglas): content-visibility:auto en
 *   secciones que empiezan fuera de pantalla (pie, tarjetas de producto a partir de la quinta). El
 *   navegador no las maqueta ni pinta hasta acercarse; en una categoría de WoodMart con 56 productos,
 *   el re-maquetado tras una interacción bajó de 267 a 213 ms (móvil, CPU 4x). Una hoja <style> en el
 *   <head>, solo en las plantillas de cada regla.
 * - El delay de JS y de fuentes de terceros es del plugin de caché.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\FrontendHints;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\Profiler\RouteKey;

/**
 * Registro del módulo.
 */
final class FrontendHintsModule implements ModuleInterface {

	private static int $lcpId = 0;

	private static string $lcpSrc = '';

	private static bool $facadeUsed = false;

	/** "featured": solo la imagen principal (wp-post-image), no sus repeticiones en menús o miniaturas. */
	private static bool $featuredOnly = false;

	private static bool $lcpDone = false;

	/** Productos del listado cuyo primer imagen no espera al lazy load (el primero, además, con prioridad alta). */
	public const LOOP_EAGER = 2;

	private static int $loopIndex = 0;

	private static int $loopMarked = 0;

	/** @var array<string, string|null> URL => URL .webp (o null), por request. */
	private static array $webp = [];

	/** Atributo que marca las imágenes priorizadas (y las saca de la reescritura de WebP por JS de EWWW). */
	public const MARK = 'data-itb-lcp';

	public function register( Container $container, RequestContext $request ): void {
		if ( RequestType::FrontendHtml !== $request->type ) {
			return;
		}
		$s = self::settings();
		if ( $s['lcp'] ) {
			add_action( 'wp', [ self::class, 'prepare' ] );
		}
		if ( [] !== $s['lazy'] ) {
			add_action( 'wp_head', [ self::class, 'printLazyRender' ], 8 );
		}
		if ( $s['facades'] ) {
			add_filter( 'embed_oembed_html', [ self::class, 'facade' ], 20, 2 );
			add_action( 'wp_footer', [ self::class, 'printFacadeAssets' ], 50 );
		}
	}

	/**
	 * @return array{lcp: bool, facades: bool, images: array<string, array{kind: string, id: int, src: string}>, lazy: list<array{selector: string, templates: list<string>, height: int}>}
	 */
	public static function settings(): array {
		$s = Runtime::$config?->get( 'hints', [] );
		$s = is_array( $s ) ? $s : [];
		return [
			'lcp'     => false !== ( $s['lcp'] ?? true ),
			'facades' => false !== ( $s['facades'] ?? true ),
			'images'  => is_array( $s['images'] ?? null ) ? $s['images'] : [],
			'lazy'    => is_array( $s['lazy'] ?? null ) ? array_values( $s['lazy'] ) : [],
		];
	}

	public static function printLazyRender(): void {
		$css = self::lazyRenderCss( self::settings()['lazy'], RouteKey::template() );
		if ( '' !== $css ) {
			echo '<style id="itb-lazy-render">' . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selectores validados por Settings::selectors (sin llaves, punto y coma ni "<").
		}
	}

	/**
	 * CSS de las reglas que aplican a la plantilla.
	 *
	 * @param list<array{selector: string, templates: list<string>, height: int}> $rules    Reglas (ya validadas).
	 */
	public static function lazyRenderCss( array $rules, string $template ): string {
		$css = '';
		foreach ( $rules as $rule ) {
			if ( [] !== $rule['templates'] && ! in_array( $template, $rule['templates'], true ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[^{};<]{1,200}$/', $rule['selector'] ) ) {
				continue;
			}
			$css .= $rule['selector'] . '{content-visibility:auto;contain-intrinsic-size:auto ' . (int) $rule['height'] . 'px}';
		}
		return $css;
	}

	/**
	 * Reglas sugeridas para el tema activo: el pie y las tarjetas de producto a partir de la quinta.
	 *
	 * @return list<array{selector: string, templates: list<string>, height: int}>
	 */
	public static function lazyRenderPresets( string $theme ): array {
		$listing = [ 'archivo:product', 'tax:product_cat', 'tax:product_tag', 'busqueda' ];
		return match ( $theme ) {
			'woodmart' => [
				[
					'selector'  => '.wd-footer',
					'templates' => [],
					'height'    => 900,
				],
				[
					'selector'  => '.products > .wd-product:nth-child(n+5)',
					'templates' => $listing,
					'height'    => 420,
				],
			],
			'astra', 'generatepress', 'kadence', 'oceanwp', 'storefront' => [
				[
					'selector'  => '.site-footer',
					'templates' => [],
					'height'    => 600,
				],
				[
					'selector'  => 'ul.products > li.product:nth-child(n+5)',
					'templates' => $listing,
					'height'    => 420,
				],
			],
			'hello-elementor' => [
				[
					'selector'  => '.elementor-location-footer',
					'templates' => [],
					'height'    => 600,
				],
			],
			default => [],
		};
	}

	/**
	 * Decide la imagen LCP de esta página cuando ya se conoce la plantilla: la que midió el RUM o, sin
	 * datos, la imagen principal del producto y los primeros productos de un listado.
	 */
	public static function prepare(): void {
		$template = RouteKey::template();
		$entry    = self::settings()['images'][ $template ] ?? null;
		if ( ! is_array( $entry ) && 'single:product' === $template ) {
			$entry = [ 'kind' => 'featured' ];
		}
		$id = 0;
		if ( is_array( $entry ) && 'featured' === ( $entry['kind'] ?? '' ) && is_singular() ) {
			$thumb              = get_post_thumbnail_id( get_queried_object_id() );
			$id                 = is_int( $thumb ) ? $thumb : 0;
			self::$featuredOnly = true;
		} elseif ( is_array( $entry ) && 'image' === ( $entry['kind'] ?? '' ) ) {
			$id           = (int) ( $entry['id'] ?? 0 );
			self::$lcpSrc = is_string( $entry['src'] ?? null ) ? $entry['src'] : '';
		}
		$loop = ! is_array( $entry ) && self::isListing( $template );
		if ( $id <= 0 && ! $loop ) {
			return;
		}
		self::$lcpId = $id;
		add_filter( 'wp_get_attachment_image_attributes', [ self::class, 'attachmentAttributes' ], 20, 3 );
		add_filter( 'ewwwio_js_webp_exclusions', [ self::class, 'ewwwExclusions' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de EWWW Image Optimizer.
		if ( $id > 0 ) {
			add_filter( 'wp_content_img_tag', [ self::class, 'contentImage' ], 20, 3 );
		}
		if ( $loop ) {
			add_action( 'woocommerce_before_shop_loop_item', [ self::class, 'nextProduct' ], 1 );
		}
		if ( '' !== self::$lcpSrc ) {
			add_action( 'wp_head', [ self::class, 'printPreload' ], 2 );
		}
	}

	/**
	 * Listados de productos (tienda, categorías, etiquetas, atributos, búsqueda).
	 */
	public static function isListing( string $template ): bool {
		return 'archivo:product' === $template || 'busqueda' === $template || str_starts_with( $template, 'tax:product_' ) || str_starts_with( $template, 'tax:pa_' );
	}

	public static function nextProduct(): void {
		++self::$loopIndex;
	}

	/**
	 * @param mixed $attr       Atributos.
	 * @param mixed $attachment Adjunto.
	 * @param mixed $size       Tamaño pedido.
	 * @return mixed
	 */
	public static function attachmentAttributes( mixed $attr, mixed $attachment, mixed $size = null ): mixed {
		if ( ! is_array( $attr ) || ! $attachment instanceof \WP_Post ) {
			return $attr;
		}
		if ( self::$lcpId > 0 && $attachment->ID === self::$lcpId && ! self::$lcpDone ) {
			if ( self::$featuredOnly && ! str_contains( (string) ( $attr['class'] ?? '' ), 'wp-post-image' ) ) {
				return $attr;
			}
			// La destacada se prioriza una vez; una imagen fija del RUM (cabecera, portada), donde aparezca.
			self::$lcpDone = self::$featuredOnly;
			return self::eager( $attr, true );
		}
		// La primera imagen de cada uno de los primeros productos del listado (no la de "hover").
		if ( self::$loopIndex > self::$loopMarked && self::$loopIndex <= self::LOOP_EAGER && 'woocommerce_thumbnail' === $size ) {
			self::$loopMarked = self::$loopIndex;
			return self::eager( $attr, 1 === self::$loopIndex );
		}
		return $attr;
	}

	/**
	 * Atributos de una imagen que no debe esperar a JavaScript.
	 *
	 * @param array<string, mixed> $attr Atributos.
	 * @return array<string, mixed>
	 */
	public static function eager( array $attr, bool $high ): array {
		$attr['loading'] = 'eager';
		if ( $high ) {
			$attr['fetchpriority'] = 'high';
		}
		$attr['data-skip-lazy'] = '1';
		$attr['data-no-lazy']   = '1';
		$attr[ self::MARK ]     = '1';
		if ( is_string( $attr['src'] ?? null ) ) {
			$attr['src'] = self::webpUrl( $attr['src'] ) ?? $attr['src'];
		}
		if ( is_string( $attr['srcset'] ?? null ) ) {
			$attr['srcset'] = self::webpSrcset( $attr['srcset'] );
		}
		return $attr;
	}

	/**
	 * Cada candidato del srcset con su copia .webp, si existe.
	 */
	public static function webpSrcset( string $srcset ): string {
		$out = [];
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ), 2 ) ?: [];
			$url   = (string) ( $parts[0] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$out[] = trim( ( self::webpUrl( $url ) ?? $url ) . ' ' . ( $parts[1] ?? '' ) );
		}
		return implode( ', ', $out );
	}

	/**
	 * URL de la copia WebP de una imagen de la biblioteca si existe en disco: "foto.png.webp" (EWWW,
	 * modo por defecto) o "foto.webp". Null si no hay copia o la URL no es de uploads.
	 */
	public static function webpUrl( string $url ): ?string {
		if ( array_key_exists( $url, self::$webp ) ) {
			return self::$webp[ $url ];
		}
		$found = null;
		$dir   = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : [];
		$base  = is_string( $dir['baseurl'] ?? null ) ? $dir['baseurl'] : '';
		$path  = (string) strtok( $url, '?#' );
		if ( '' !== $base && 1 === preg_match( '/\.(jpe?g|png|gif)$/i', $path ) ) {
			// Mismo archivo con http o https.
			$relative = null;
			foreach ( [ $base, (string) preg_replace( '#^https?:#', 'https:', $base ), (string) preg_replace( '#^https?:#', 'http:', $base ) ] as $prefix ) {
				if ( str_starts_with( $path, $prefix . '/' ) ) {
					$relative = substr( $path, strlen( $prefix ) );
					break;
				}
			}
			if ( null !== $relative && ! str_contains( $relative, '..' ) ) {
				$file = rawurldecode( (string) $dir['basedir'] . $relative );
				if ( is_file( $file . '.webp' ) ) {
					$found = $path . '.webp';
				} elseif ( is_file( (string) preg_replace( '/\.\w+$/', '.webp', $file ) ) ) {
					$found = (string) preg_replace( '/\.\w+$/', '.webp', $path );
				}
			}
		}
		self::$webp[ $url ] = $found;
		return $found;
	}

	/**
	 * EWWW: las imágenes priorizadas no pasan por la reescritura de WebP por JS (ya apuntan al .webp).
	 *
	 * @param mixed $exclusions Subcadenas excluidas.
	 * @return mixed
	 */
	public static function ewwwExclusions( mixed $exclusions ): mixed {
		if ( is_array( $exclusions ) ) {
			$exclusions[] = self::MARK;
		}
		return $exclusions;
	}

	/**
	 * @param mixed $html       Etiqueta img.
	 * @param mixed $context    Contexto.
	 * @param mixed $attachment Id del adjunto.
	 * @return mixed
	 */
	public static function contentImage( mixed $html, mixed $context, mixed $attachment ): mixed {
		unset( $context );
		if ( ! is_string( $html ) || (int) $attachment !== self::$lcpId || self::$lcpDone ) {
			return $html;
		}
		return self::prioritize( $html );
	}

	/**
	 * Quita loading="lazy", agrega fetchpriority="high" y la saca de los lazy load por JS.
	 */
	public static function prioritize( string $img ): string {
		$img = (string) preg_replace( '/\sloading=(["\'])lazy\1/i', '', $img );
		$img = (string) preg_replace( '/\sfetchpriority=(["\'])[a-z]+\1/i', '', $img );
		if ( ! str_contains( $img, self::MARK ) ) {
			$img = (string) preg_replace( '/^<img\b/i', '<img data-skip-lazy="1" data-no-lazy="1" ' . self::MARK . '="1"', $img, 1 );
		}
		return (string) preg_replace( '/^<img\b/i', '<img fetchpriority="high"', $img, 1 );
	}

	/**
	 * Vacía el estado (pruebas).
	 */
	public static function reset(): void {
		self::$lcpId        = 0;
		self::$lcpSrc       = '';
		self::$featuredOnly = false;
		self::$lcpDone      = false;
		self::$loopIndex    = 0;
		self::$loopMarked   = 0;
		self::$webp         = [];
	}

	public static function printPreload(): void {
		printf(
			"<link rel=\"preload\" as=\"image\" href=\"%s\" media=\"(max-width: 767px)\" fetchpriority=\"high\">\n",
			esc_url( home_url( self::$lcpSrc ) )
		);
	}

	/**
	 * Fachada para el iframe de YouTube o Vimeo de un oEmbed.
	 *
	 * @param mixed $html HTML del oEmbed.
	 * @param mixed $url  URL original.
	 * @return mixed
	 */
	public static function facade( mixed $html, mixed $url = '' ): mixed {
		unset( $url );
		if ( ! is_string( $html ) ) {
			return $html;
		}
		$facade = self::facadeHtml( $html );
		if ( null === $facade ) {
			return $html;
		}
		self::$facadeUsed = true;
		return $facade;
	}

	/**
	 * Fachada para un HTML de oEmbed, o null si no aplica.
	 */
	public static function facadeHtml( string $html ): ?string {
		if ( 1 !== preg_match( '#<iframe\b[^>]*\bsrc=(["\'])([^"\']+)\1[^>]*>#i', $html, $m ) || str_contains( $m[2], 'enablejsapi' ) ) {
			return null;
		}
		$src    = html_entity_decode( $m[2], ENT_QUOTES );
		$iframe = $m[0];
		$width  = 1 === preg_match( '/\bwidth=["\']?(\d+)/i', $iframe, $w ) ? (int) $w[1] : 16;
		$height = 1 === preg_match( '/\bheight=["\']?(\d+)/i', $iframe, $h ) ? (int) $h[1] : 9;
		$title  = 1 === preg_match( '/\btitle=(["\'])([^"\']*)\1/i', $iframe, $t ) ? html_entity_decode( $t[2], ENT_QUOTES ) : '';
		$thumb  = '';
		if ( 1 === preg_match( '#^https://www\.youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{11})#', $src, $yt ) ) {
			$thumb = 'https://i.ytimg.com/vi/' . $yt[1] . '/hqdefault.jpg';
		} elseif ( 1 !== preg_match( '#^https://player\.vimeo\.com/video/\d+#', $src ) ) {
			return null;
		}
		$play = add_query_arg( 'autoplay', '1', $src );
		return sprintf(
			'<div class="itb-facade" role="button" tabindex="0" data-src="%1$s" data-title="%2$s" aria-label="%3$s" style="aspect-ratio:%4$d/%5$d%6$s"><span class="itb-facade-play" aria-hidden="true"></span>%7$s</div>',
			esc_url( $play ),
			esc_attr( $title ),
			/* translators: %s: título del video. */
			esc_attr( sprintf( __( 'Reproducir: %s', 'imagina-turbo' ), $title ) ),
			max( 1, $width ),
			max( 1, $height ),
			'' !== $thumb ? ';background-image:url(' . esc_url( $thumb ) . ')' : '',
			'' !== $title ? '<span class="itb-facade-title">' . esc_html( $title ) . '</span>' : ''
		);
	}

	/**
	 * CSS y JS de las fachadas, solo si la página tiene alguna.
	 */
	public static function printFacadeAssets(): void {
		if ( ! self::$facadeUsed ) {
			return;
		}
		echo '<style id="itb-facade-css">.itb-facade{position:relative;width:100%;max-width:100%;background:#000 center/cover no-repeat;cursor:pointer;overflow:hidden}.itb-facade-play{position:absolute;top:50%;left:50%;width:68px;height:48px;margin:-24px 0 0 -34px;border-radius:12px;background:rgba(0,0,0,.75)}.itb-facade-play:after{content:"";position:absolute;left:27px;top:14px;border-style:solid;border-width:10px 0 10px 18px;border-color:transparent transparent transparent #fff}.itb-facade:hover .itb-facade-play,.itb-facade:focus .itb-facade-play{background:#f00}.itb-facade-title{position:absolute;left:0;right:0;top:0;padding:12px 16px;color:#fff;font:14px/1.3 sans-serif;background:linear-gradient(rgba(0,0,0,.6),transparent)}.itb-facade iframe{position:absolute;inset:0;width:100%;height:100%;border:0}</style>' . "\n";
		wp_print_inline_script_tag(
			'document.addEventListener("click",f);document.addEventListener("keydown",function(e){if(e.key==="Enter"||e.key===" ")f(e)});function f(e){var d=e.target.closest&&e.target.closest(".itb-facade");if(!d||d.querySelector("iframe"))return;e.preventDefault();var i=document.createElement("iframe");i.src=d.dataset.src;i.title=d.dataset.title||"";i.allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen";i.allowFullscreen=true;d.innerHTML="";d.appendChild(i)}',
			[ 'id' => 'itb-facade-js' ]
		);
	}
}
