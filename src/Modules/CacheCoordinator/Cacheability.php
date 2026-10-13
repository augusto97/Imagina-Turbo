<?php
/**
 * ¿Puede el edge guardar esta respuesta? Decisión pura a partir de lo que ya se sabe al llegar a
 * template_redirect (tipo de request, método, usuario, cookies, estado y cabeceras enviadas).
 *
 * Es una decisión del lado seguro: ante la duda, no. Aunque se equivoque hacia el "sí", Cloudflare
 * no guarda una respuesta con Set-Cookie mientras la regla del edge respete al origen (docs/03).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

use ImaginaTurbo\Core\Request\RequestType;

/**
 * Veredicto y TTL.
 */
final class Cacheability {

	/** TTL del edge sin refresco de nonces: los nonces de WordPress viven 12–24 h (docs/02). */
	public const MAX_TTL_WITHOUT_NONCE_REFRESH = 36000;

	/** Un 404 se guarda poco: si después se publica algo en esa URL, no hay tag que lo purgue. */
	public const TTL_404 = 600;

	/**
	 * @param array{edge_ttl: int, nonce_refresh: bool} $settings Ajustes de caché.
	 * @param array{
	 *     type: RequestType,
	 *     method: string,
	 *     logged_in: bool,
	 *     cookies: list<string>,
	 *     bypass_cookies: list<string>,
	 *     status: int,
	 *     headers: list<string>,
	 *     preview: bool,
	 *     search: bool,
	 *     password_required: bool,
	 *     do_not_cache: bool,
	 *     bypass_path?: bool
	 * } $request Datos del request.
	 * @return array{cacheable: bool, reason: string, ttl: int}
	 */
	public static function decide( array $settings, array $request ): array {
		$no = static fn( string $reason ): array => [
			'cacheable' => false,
			'reason'    => $reason,
			'ttl'       => 0,
		];

		if ( ! in_array( $request['type'], [ RequestType::FrontendHtml, RequestType::Feed, RequestType::Sitemap ], true ) ) {
			return $no( 'type' );
		}
		if ( 'GET' !== $request['method'] && 'HEAD' !== $request['method'] ) {
			return $no( 'method' );
		}
		if ( $request['logged_in'] ) {
			return $no( 'logged_in' );
		}
		foreach ( $request['cookies'] as $cookie ) {
			foreach ( $request['bypass_cookies'] as $prefix ) {
				if ( '' !== $prefix && str_starts_with( $cookie, $prefix ) ) {
					return $no( 'cookie' );
				}
			}
		}
		if ( $request['preview'] ) {
			return $no( 'preview' );
		}
		if ( $request['search'] ) {
			return $no( 'search' );
		}
		if ( $request['password_required'] ) {
			return $no( 'password' );
		}
		if ( $request['do_not_cache'] ) {
			return $no( 'donotcachepage' );
		}
		if ( $request['bypass_path'] ?? false ) {
			return $no( 'bypass_path' );
		}
		foreach ( $request['headers'] as $header ) {
			$lower = strtolower( $header );
			if ( str_starts_with( $lower, 'set-cookie:' ) ) {
				return $no( 'set_cookie' );
			}
			if ( str_starts_with( $lower, 'cache-control:' ) && 1 === preg_match( '/\b(private|no-store)\b/', $lower ) ) {
				return $no( 'private' );
			}
		}

		$ttl = max( 0, $settings['edge_ttl'] );
		if ( ! $settings['nonce_refresh'] ) {
			$ttl = min( $ttl, self::MAX_TTL_WITHOUT_NONCE_REFRESH );
		}
		if ( 404 === $request['status'] ) {
			$ttl = min( $ttl, self::TTL_404 );
		} elseif ( 200 !== $request['status'] ) {
			return $no( 'status' );
		}
		if ( 0 === $ttl ) {
			return $no( 'ttl' );
		}

		return [
			'cacheable' => true,
			'reason'    => 'ok',
			'ttl'       => $ttl,
		];
	}

	/**
	 * La ruta está en las rutas que nunca se cachean (integraciones de la matriz, páginas dinámicas).
	 *
	 * Patrón con * = comodín; sin * = la ruta exacta y lo que cuelga de ella; con ? = se compara con
	 * "ruta?query".
	 *
	 * @param string       $path     Ruta relativa a home, en minúsculas.
	 * @param list<string> $patterns Patrones.
	 */
	public static function pathBypassed( string $path, string $query, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( trim( $pattern ) );
			if ( '' === $pattern ) {
				continue;
			}
			$subject = str_contains( $pattern, '?' ) ? $path . '?' . $query : $path;
			if ( str_contains( $pattern, '*' ) ) {
				$regex = '#^' . str_replace( '\\*', '.*', preg_quote( $pattern, '#' ) ) . '$#';
				if ( 1 === preg_match( $regex, $subject ) ) {
					return true;
				}
				continue;
			}
			$pattern = rtrim( $pattern, '/' );
			$base    = rtrim( $subject, '/' );
			if ( '' !== $pattern && ( $base === $pattern || str_starts_with( $subject, $pattern . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Valor de Cloudflare-CDN-Cache-Control: solo lo lee el edge de Cloudflare y no llega al navegador,
	 * así que el Cache-Control del sitio (y el bfcache) no cambian.
	 */
	public static function edgeHeader( bool $cacheable, int $ttl, int $swr, int $sie ): string {
		if ( ! $cacheable ) {
			return 'no-store';
		}
		$parts = [ 'max-age=' . $ttl ];
		if ( $swr > 0 ) {
			$parts[] = 'stale-while-revalidate=' . $swr;
		}
		if ( $sie > 0 ) {
			$parts[] = 'stale-if-error=' . $sie;
		}
		return implode( ', ', $parts );
	}
}
