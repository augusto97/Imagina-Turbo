<?php
/**
 * Cabeceras para el edge en el frontend.
 *
 * Se decide en template_redirect: la consulta principal ya existe (los tags dependen de ella) y la
 * plantilla aún no empezó a escribir. Sin output buffering (regla 2).
 *
 * - Cacheable: `Cloudflare-CDN-Cache-Control: max-age=…, stale-while-revalidate=…, stale-if-error=…`
 *   y `Cache-Tag`. Cloudflare quita las dos antes de responder al visitante.
 * - No cacheable: `Cloudflare-CDN-Cache-Control: no-store`. Solo afecta al edge: el Cache-Control
 *   del sitio no cambia y el navegador conserva el bfcache.
 *
 * Lo que cambie durante el render (DONOTCACHEPAGE, un Set-Cookie) se revisa en shutdown: ver lateCheck().
 *
 * 0.14.1: mientras se genera una página cacheable se anotan los productos que muestra en listados,
 * carruseles y relacionados (acción the_post, que también dispara setup_postdata) y en shutdown se
 * agregan como tags l-ID. Así, cuando un producto se agota o cambia de precio, se purgan también las
 * páginas que lo muestran (PurgeMap); una venta normal ya no las toca.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\CacheAdapter;

/**
 * Emisión por request.
 */
final class HeaderEmitter {

	public const HEADER = 'Cloudflare-CDN-Cache-Control';

	/** Productos anotados como máximo por página (la cabecera Cache-Tag tiene límite). */
	public const MAX_LISTED = 100;

	/** @var array{cacheable: bool, reason: string, ttl: int}|null */
	private ?array $verdict = null;

	/** @var list<string> Tags emitidos en template_redirect. */
	private array $tagsSent = [];

	/** @var array<int, true> Productos que la página muestra fuera de su contenido principal. */
	private array $listed = [];

	/**
	 * @param array{mode: string, edge_ttl: int, swr: int, sie: int, nonce_refresh: bool, edge_html: bool, bypass_paths: list<string>} $settings Ajustes compilados.
	 * @param list<string>                                                                               $bypassCookies Prefijos de cookies que impiden la caché.
	 * @param callable(): void                                                                           $onCacheable   Se llama si la respuesta es cacheable (refresco de nonces).
	 * @param list<string>                                                                               $cartCookies   Prefijos de cookies de carrito: esa respuesta tampoco la guarda el plugin de caché.
	 */
	public function __construct(
		private readonly array $settings,
		private readonly array $bypassCookies,
		private readonly TagBuilder $tags,
		private readonly CacheAdapter $adapter,
		private readonly RequestContext $request,
		private readonly mixed $onCacheable = null,
		private readonly array $cartCookies = [],
	) {
	}

	public function register(): void {
		// Provisional: si algo termina el request antes de decidir (una redirección en template_redirect,
		// como la de geolocalización de WooCommerce o redirect_canonical), sale con no-store para el edge.
		add_action( 'template_redirect', [ $this, 'provisional' ], -10000 );
		add_action( 'template_redirect', [ $this, 'emit' ], 99999 );
		// La pantalla de login (también la de WPS Hide Login) termina antes de template_redirect.
		add_action( 'login_init', [ $this, 'emit' ], 99999 );
		add_action( 'shutdown', [ $this, 'lateCheck' ], 0 );
	}

	/**
	 * @return array{cacheable: bool, reason: string, ttl: int}|null
	 */
	public function verdict(): ?array {
		return $this->verdict;
	}

	public function provisional(): void {
		if ( 'headers' === $this->settings['mode'] && $this->settings['edge_html'] && ! headers_sent() ) {
			header( self::HEADER . ': no-store', true );
		}
	}

	public function emit(): void {
		$this->verdict = Cacheability::decide(
			$this->settings,
			[
				'type'              => $this->request->type,
				'method'            => $this->request->method,
				'logged_in'         => is_user_logged_in(),
				'cookies'           => array_map( 'strval', array_keys( $_COOKIE ) ),
				'bypass_cookies'    => $this->bypassCookies,
				'status'            => self::status(),
				'headers'           => headers_list(),
				'preview'           => is_preview(),
				'search'            => is_search(),
				'password_required' => is_singular() && post_password_required(),
				'do_not_cache'      => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
				'bypass_path'       => [] !== $this->settings['bypass_paths'] && Cacheability::pathBypassed( $this->request->path, isset( $_SERVER['QUERY_STRING'] ) ? strtolower( (string) $_SERVER['QUERY_STRING'] ) : '', $this->settings['bypass_paths'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- solo se compara con patrones.
			]
		);

		// Quien tiene carrito puede leer de la caché, pero su página (con su mini-carrito) no se guarda
		// en ningún nivel: LSCache, con sus valores por defecto, la guardaría como pública.
		if ( ! $this->verdict['cacheable'] && 'cookie' === $this->verdict['reason'] && self::hasCookie( array_map( 'strval', array_keys( $_COOKIE ) ), $this->cartCookies ) ) {
			$this->adapter->noCache( 'cart' );
		}

		$tags           = $this->verdict['cacheable'] ? $this->tags->tags( TagBuilder::namesForCurrentQuery() ) : [];
		$this->tagsSent = $tags;
		if ( $this->verdict['cacheable'] ) {
			add_action( 'the_post', [ $this, 'noteListed' ] );
		}
		// LSCache recibe nuestros tags aunque no emitamos cabeceras al edge: así purgar por tag también
		// purga la caché del servidor.
		$this->adapter->addResponseTags( $tags );
		// Los nonces caducan también en la caché del servidor (OpenLiteSpeed no tiene ESI para evitarlo).
		if ( $this->verdict['cacheable'] && is_callable( $this->onCacheable ) ) {
			( $this->onCacheable )();
		}

		if ( 'headers' !== $this->settings['mode'] || ! $this->settings['edge_html'] || headers_sent() ) {
			return;
		}
		header( self::HEADER . ': ' . Cacheability::edgeHeader( $this->verdict['cacheable'], $this->verdict['ttl'], $this->settings['swr'], $this->settings['sie'] ), true );
		if ( [] !== $tags ) {
			header( 'Cache-Tag: ' . implode( ',', $tags ), true );
		}
	}

	/**
	 * Revisión final: DONOTCACHEPAGE, un Set-Cookie o un Cache-Control privado añadidos durante el
	 * render. Solo se puede corregir mientras las cabeceras no hayan salido (LSCache retiene la salida
	 * en su buffer; sin él ya salieron, y entonces Cloudflare tampoco guarda una respuesta con
	 * Set-Cookie porque la regla respeta al origen).
	 */
	public function lateCheck(): void {
		if ( null === $this->verdict || ! $this->verdict['cacheable'] || headers_sent() ) {
			return;
		}
		$reason = self::lateReason( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, headers_list() );
		if ( null === $reason ) {
			$this->addListedTags();
			return;
		}
		$this->verdict = [
			'cacheable' => false,
			'reason'    => $reason,
			'ttl'       => 0,
		];
		$this->adapter->noCache( $reason );
		if ( 'headers' === $this->settings['mode'] && $this->settings['edge_html'] ) {
			header( self::HEADER . ': no-store', true );
			header_remove( 'Cache-Tag' );
		}
	}

	/**
	 * @param mixed $post Entrada que se prepara en un bucle.
	 */
	public function noteListed( mixed $post ): void {
		if ( $post instanceof \WP_Post && 'product' === $post->post_type && count( $this->listed ) < self::MAX_LISTED && get_queried_object_id() !== $post->ID ) {
			$this->listed[ $post->ID ] = true;
		}
	}

	/**
	 * Agrega los tags l-ID de los productos anotados (LSCache los recibe aunque no haya edge).
	 */
	private function addListedTags(): void {
		$listed = $this->tags->tags( self::listedNames( array_keys( $this->listed ) ) );
		if ( [] === $listed ) {
			return;
		}
		$this->adapter->addResponseTags( $listed );
		if ( 'headers' === $this->settings['mode'] && $this->settings['edge_html'] ) {
			header( 'Cache-Tag: ' . implode( ',', array_values( array_unique( array_merge( $this->tagsSent, $listed ) ) ) ), true );
		}
	}

	/**
	 * @param array<int> $ids Productos.
	 * @return list<string>
	 */
	public static function listedNames( array $ids ): array {
		$names = [];
		foreach ( array_slice( $ids, 0, self::MAX_LISTED ) as $id ) {
			if ( (int) $id > 0 ) {
				$names[] = 'l-' . (int) $id;
			}
		}
		return $names;
	}

	/**
	 * Motivo por el que una respuesta que parecía cacheable deja de serlo al final, o null.
	 *
	 * @param list<string> $headers Cabeceras ya preparadas.
	 */
	public static function lateReason( bool $doNotCache, array $headers ): ?string {
		if ( $doNotCache ) {
			return 'donotcachepage';
		}
		foreach ( $headers as $header ) {
			$lower = strtolower( $header );
			if ( str_starts_with( $lower, 'set-cookie:' ) && ! Cacheability::regenCookie( $header ) ) {
				return 'set_cookie';
			}
			if ( str_starts_with( $lower, 'cache-control:' ) && 1 === preg_match( '/\b(private|no-store)\b/', $lower ) ) {
				return 'private';
			}
		}
		return null;
	}

	/**
	 * @param list<string> $cookies  Nombres de las cookies del request.
	 * @param list<string> $prefixes Prefijos.
	 */
	public static function hasCookie( array $cookies, array $prefixes ): bool {
		foreach ( $cookies as $cookie ) {
			foreach ( $prefixes as $prefix ) {
				if ( '' !== $prefix && str_starts_with( $cookie, $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function status(): int {
		$code = http_response_code();
		return is_int( $code ) ? $code : 200;
	}
}
