<?php
/**
 * RUM (docs/02 módulo 11). Riesgo seguro, activo al 5 %.
 *
 * Un script en línea mínimo (en todas las páginas del frontend sin sesión) guarda la plantilla de la
 * página para medir transiciones y decide la muestra; solo la muestra descarga itb-rum.js. En un
 * prerender espera a la activación.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Rum;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\Profiler\RouteKey;

/**
 * Registro del módulo.
 */
final class RumModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
		if ( RequestType::FrontendHtml !== $request->type ) {
			return;
		}
		add_action( 'wp_footer', [ self::class, 'printLoader' ], 40 );
	}

	/**
	 * @return array{rate: float, endpoint: string}
	 */
	public static function settings(): array {
		$s = Runtime::$config?->get( 'rum', [] );
		$s = is_array( $s ) ? $s : [];
		return [
			'rate'     => is_float( $s['rate'] ?? null ) || is_int( $s['rate'] ?? null ) ? (float) $s['rate'] : 0.05,
			'endpoint' => is_string( $s['endpoint'] ?? null ) ? $s['endpoint'] : '',
		];
	}

	public static function printLoader(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$s = self::settings();
		if ( $s['rate'] <= 0 ) {
			return;
		}
		$config = [
			'e' => '' !== $s['endpoint'] ? $s['endpoint'] : home_url( '/?' . RumCollector::QUERY . '=1' ),
			't' => substr( RouteKey::template(), 0, 40 ),
			'f' => self::featuredNames(),
		];
		$file   = is_file( Runtime::$pluginDir . '/assets/js/itb-rum.min.js' ) ? 'itb-rum.min.js' : 'itb-rum.js';
		$src    = plugins_url( 'assets/js/' . $file, Runtime::$pluginDir . '/imagina-turbo.php' ) . '?ver=' . ITB_VERSION;
		$js     = sprintf(
			'(function(c,r,u){function go(){var p;try{p=sessionStorage.getItem("itb_t");sessionStorage.setItem("itb_t",c.t)}catch(e){}c.pf=p;if(Math.random()<r){window.itbRum=c;var s=document.createElement("script");s.src=u;s.async=true;document.head.appendChild(s)}}document.prerendering?addEventListener("prerenderingchange",go,{once:true}):go()})(%s,%s,%s);',
			wp_json_encode( $config, JSON_UNESCAPED_SLASHES ),
			wp_json_encode( $s['rate'] ),
			wp_json_encode( $src, JSON_UNESCAPED_SLASHES )
		);
		wp_print_inline_script_tag( $js, [ 'id' => 'itb-rum-loader' ] );
	}

	/**
	 * Nombres de archivo de la imagen destacada (para reconocerla como LCP en cualquier tamaño).
	 *
	 * @return list<string>
	 */
	public static function featuredNames(): array {
		if ( ! is_singular() ) {
			return [];
		}
		$id = get_post_thumbnail_id( get_queried_object_id() );
		if ( ! is_int( $id ) || $id <= 0 ) {
			return [];
		}
		$names = [];
		foreach ( [ wp_get_attachment_url( $id ), function_exists( 'wp_get_original_image_url' ) ? wp_get_original_image_url( $id ) : false ] as $url ) {
			if ( is_string( $url ) && '' !== $url ) {
				$names[] = (string) preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			}
		}
		return array_values( array_unique( $names ) );
	}
}
