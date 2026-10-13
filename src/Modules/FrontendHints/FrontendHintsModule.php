<?php
/**
 * Pistas de frontend (docs/02 módulo 9). Riesgo seguro.
 *
 * - Imagen LCP por plantilla, decidida por el RUM (RumInsights, en la tarea diaria): fetchpriority="high"
 *   y sin loading="lazy" en esa imagen (wp_get_attachment_image_attributes y wp_content_img_tag). Si es
 *   siempre la misma imagen, además <link rel="preload"> con la URL que midió el RUM en móvil, limitado
 *   a pantallas móviles (en escritorio el navegador elegiría otro tamaño y la precarga se desperdiciaría).
 *   "featured" = la imagen destacada de cada página (fichas de producto, entradas).
 * - Fachadas de YouTube y Vimeo (embed_oembed_html): miniatura y botón; el iframe se crea al tocar.
 *   No se tocan los iframes con enablejsapi (otro script los controla).
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

	public function register( Container $container, RequestContext $request ): void {
		if ( RequestType::FrontendHtml !== $request->type ) {
			return;
		}
		$s = self::settings();
		if ( $s['lcp'] && [] !== $s['images'] ) {
			add_action( 'wp', [ self::class, 'prepare' ] );
		}
		if ( $s['facades'] ) {
			add_filter( 'embed_oembed_html', [ self::class, 'facade' ], 20, 2 );
			add_action( 'wp_footer', [ self::class, 'printFacadeAssets' ], 50 );
		}
	}

	/**
	 * @return array{lcp: bool, facades: bool, images: array<string, array{kind: string, id: int, src: string}>}
	 */
	public static function settings(): array {
		$s = Runtime::$config?->get( 'hints', [] );
		$s = is_array( $s ) ? $s : [];
		return [
			'lcp'     => false !== ( $s['lcp'] ?? true ),
			'facades' => false !== ( $s['facades'] ?? true ),
			'images'  => is_array( $s['images'] ?? null ) ? $s['images'] : [],
		];
	}

	/**
	 * Decide la imagen LCP de esta página cuando ya se conoce la plantilla.
	 */
	public static function prepare(): void {
		$entry = self::settings()['images'][ RouteKey::template() ] ?? null;
		if ( ! is_array( $entry ) ) {
			return;
		}
		$id = 0;
		if ( 'featured' === ( $entry['kind'] ?? '' ) && is_singular() ) {
			$thumb = get_post_thumbnail_id( get_queried_object_id() );
			$id    = is_int( $thumb ) ? $thumb : 0;
		} elseif ( 'image' === ( $entry['kind'] ?? '' ) ) {
			$id           = (int) ( $entry['id'] ?? 0 );
			self::$lcpSrc = is_string( $entry['src'] ?? null ) ? $entry['src'] : '';
		}
		if ( $id <= 0 ) {
			return;
		}
		self::$lcpId = $id;
		add_filter( 'wp_get_attachment_image_attributes', [ self::class, 'attachmentAttributes' ], 20, 2 );
		add_filter( 'wp_content_img_tag', [ self::class, 'contentImage' ], 20, 3 );
		if ( '' !== self::$lcpSrc ) {
			add_action( 'wp_head', [ self::class, 'printPreload' ], 2 );
		}
	}

	/**
	 * @param mixed $attr       Atributos.
	 * @param mixed $attachment Adjunto.
	 * @return mixed
	 */
	public static function attachmentAttributes( mixed $attr, mixed $attachment ): mixed {
		if ( is_array( $attr ) && $attachment instanceof \WP_Post && $attachment->ID === self::$lcpId ) {
			$attr['fetchpriority'] = 'high';
			$attr['loading']       = 'eager';
			unset( $attr['decoding'] );
		}
		return $attr;
	}

	/**
	 * @param mixed $html       Etiqueta img.
	 * @param mixed $context    Contexto.
	 * @param mixed $attachment Id del adjunto.
	 * @return mixed
	 */
	public static function contentImage( mixed $html, mixed $context, mixed $attachment ): mixed {
		unset( $context );
		if ( ! is_string( $html ) || (int) $attachment !== self::$lcpId ) {
			return $html;
		}
		return self::prioritize( $html );
	}

	/**
	 * Quita loading="lazy" y agrega fetchpriority="high" a una etiqueta img.
	 */
	public static function prioritize( string $img ): string {
		$img = (string) preg_replace( '/\sloading=(["\'])lazy\1/i', '', $img );
		$img = (string) preg_replace( '/\sfetchpriority=(["\'])[a-z]+\1/i', '', $img );
		return (string) preg_replace( '/^<img\b/i', '<img fetchpriority="high"', $img, 1 );
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
