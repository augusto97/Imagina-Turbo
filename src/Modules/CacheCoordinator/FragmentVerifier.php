<?php
/**
 * Verificación de la caché de fragmentos (0.17.0): la página servida con los menús de la caché tiene
 * que ser igual a la renderizada de cero.
 *
 * Por cada página de muestra (portada, una de cada tipo, la tienda, el carrito y la cuenta), cuatro
 * peticiones del sitio a sí mismo como visitante anónimo, con la cookie firmada itb_frag:
 * 1. y 2. Sin caché de fragmentos (b): calibran qué cambia solo entre dos renders (nonces, ids).
 * 3. Renderizar y guardar (f).
 * 4. Solo copias (h): cada menú tiene que salir de la caché.
 * Pasa si 4 es equivalente a 1 (ResponseComparer, el mismo criterio de los experimentos de carga
 * condicional) y todos los menús salieron de la caché. Solo en el admin.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

use ImaginaTurbo\Core\Safety\AdminBypassCookie;
use ImaginaTurbo\Modules\PluginFilter\LoopbackClient;
use ImaginaTurbo\Modules\PluginFilter\ResponseComparer;

/**
 * Comparación de páginas.
 *
 * @phpstan-type Reply array{status: int, headers: array<string, string>, body: string}
 * @phpstan-type PageResult array{path: string, status: int, hits: int, stores: int, result: string, diff: string}
 */
final class FragmentVerifier {

	public const OPTION = 'itb_frag_verify';

	/** @var \Closure(string, string, string, string): (Reply|string) */
	private \Closure $http;

	/**
	 * @param (\Closure(string, string, string, string): (Reply|string))|null $http Cliente (pruebas).
	 */
	public function __construct( ?\Closure $http = null ) {
		$this->http = $http ?? \Closure::fromCallable( [ LoopbackClient::class, 'request' ] );
	}

	/**
	 * @param list<string> $paths Rutas del sitio.
	 * @return array{at: int, pass: bool, pages: list<PageResult>, error: string}
	 */
	public function run( array $paths ): array {
		$out = [
			'at'    => time(),
			'pass'  => false,
			'pages' => [],
			'error' => '',
		];
		if ( ! FragmentCache::available() ) {
			$out['error'] = __( 'El sitio no tiene caché de objetos persistente (Redis o Memcached).', 'imagina-turbo' );
			return $out;
		}
		$key = AdminBypassCookie::key( FragmentCache::COOKIE );
		if ( null === $key ) {
			$out['error'] = __( 'Faltan AUTH_KEY y AUTH_SALT en wp-config.php.', 'imagina-turbo' );
			return $out;
		}
		$cookie = FragmentCache::COOKIE . '=' . rawurlencode( AdminBypassCookie::create( max( 1, get_current_user_id() ), time() + 600, $key ) );
		$all    = true;
		$cached = 0;
		foreach ( array_slice( array_values( array_unique( $paths ) ), 0, 8 ) as $path ) {
			$page         = $this->page( $path, $cookie );
			$out['pages'] = [ ...$out['pages'], $page ];
			$all          = $all && in_array( $page['result'], [ 'ok', 'no_menus' ], true );
			$cached      += 'ok' === $page['result'] ? 1 : 0;
		}
		$out['pass'] = $all && $cached > 0;
		return $out;
	}

	/**
	 * @return PageResult
	 */
	private function page( string $path, string $cookie ): array {
		$base    = [
			'path'   => $path,
			'status' => 0,
			'hits'   => 0,
			'stores' => 0,
			'result' => 'error',
			'diff'   => '',
		];
		$replies = [];
		$stats   = [];
		foreach ( [ 'b', 'b', 'f', 'h' ] as $i => $mode ) {
			$nonce = strtolower( wp_generate_password( 12, false ) );
			$reply = ( $this->http )( 'GET', $path . ( str_contains( $path, '?' ) ? '&' : '?' ) . 'itb_frag=' . $mode . '&itb_nc=' . $nonce, '', $cookie );
			if ( is_string( $reply ) ) {
				return [ 'diff' => $reply ] + $base;
			}
			$replies[ $i ] = $reply;
			$stats[ $i ]   = get_transient( FragmentCache::RESULT_TRANSIENT . $nonce );
			delete_transient( FragmentCache::RESULT_TRANSIENT . $nonce );
		}
		$base['status'] = $replies[0]['status'];
		$fill           = is_array( $stats[2] ) ? $stats[2] : null;
		$hit            = is_array( $stats[3] ) ? $stats[3] : null;
		if ( null === $fill || null === $hit ) {
			return [
				'result' => 'no_stats',
				'diff'   => __( 'La página no informó el resultado (¿no pasa por el tema, o la sirvió una caché?).', 'imagina-turbo' ),
			] + $base;
		}
		$base['stores'] = (int) ( $fill['stores'] ?? 0 );
		$base['hits']   = (int) ( $hit['hits'] ?? 0 );
		if ( 0 === $base['stores'] && 0 === $base['hits'] ) {
			return [ 'result' => 'no_menus' ] + $base;
		}
		if ( (int) ( $hit['misses'] ?? 0 ) > 0 || $base['hits'] < $base['stores'] ) {
			return [
				'result' => 'partial',
				'diff'   => __( 'Algún menú no salió de la caché en la última petición.', 'imagina-turbo' ),
			] + $base;
		}
		$mode = ResponseComparer::calibrate( self::strip( $replies[0] ), self::strip( $replies[1] ) );
		$diff = ResponseComparer::diff( self::strip( $replies[0] ), self::strip( $replies[3] ), $mode );
		if ( null !== $diff ) {
			return [
				'result' => 'different',
				'diff'   => $diff['field'] . ': ' . mb_substr( $diff['baseline'], 0, 160 ) . ' ≠ ' . mb_substr( $diff['filtered'], 0, 160 ),
			] + $base;
		}
		return [ 'result' => 'ok' ] + $base;
	}

	/**
	 * Sin los parámetros del verificador en el cuerpo (los repite cualquier enlace a la URL actual).
	 *
	 * @param Reply $reply Respuesta.
	 * @return Reply
	 */
	public static function strip( array $reply ): array {
		$reply['body'] = (string) preg_replace( '/(?:&amp;|&|\?|%3F|%26)itb_(?:frag|nc)(?:=|%3D)[a-z0-9]*/i', '', $reply['body'] );
		return $reply;
	}
}
