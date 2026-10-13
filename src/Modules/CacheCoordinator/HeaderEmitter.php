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

	/** @var array{cacheable: bool, reason: string, ttl: int}|null */
	private ?array $verdict = null;

	/**
	 * @param array{mode: string, edge_ttl: int, swr: int, sie: int, nonce_refresh: bool, edge_html: bool} $settings Ajustes compilados.
	 * @param list<string>                                                                               $bypassCookies Prefijos de cookies que impiden la caché.
	 * @param callable(): void                                                                           $onCacheable   Se llama si la respuesta es cacheable (refresco de nonces).
	 */
	public function __construct(
		private readonly array $settings,
		private readonly array $bypassCookies,
		private readonly TagBuilder $tags,
		private readonly CacheAdapter $adapter,
		private readonly RequestContext $request,
		private readonly mixed $onCacheable = null,
	) {
	}

	public function register(): void {
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
			]
		);

		$tags = $this->verdict['cacheable'] ? $this->tags->tags( TagBuilder::namesForCurrentQuery() ) : [];
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
			if ( str_starts_with( $lower, 'set-cookie:' ) ) {
				return 'set_cookie';
			}
			if ( str_starts_with( $lower, 'cache-control:' ) && 1 === preg_match( '/\b(private|no-store)\b/', $lower ) ) {
				return 'private';
			}
		}
		return null;
	}

	private static function status(): int {
		$code = http_response_code();
		return is_int( $code ) ? $code : 200;
	}
}
