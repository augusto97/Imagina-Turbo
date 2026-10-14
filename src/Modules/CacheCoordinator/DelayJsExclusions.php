<?php
/**
 * Exclusiones de "delay JS" para el plugin de caché, desde la matriz (docs/05, fase 7).
 *
 * Verificado en el código (2026-10-13):
 * - WP Rocket 3.23.5.1 (inc/Engine/Optimization/DelayJS/HTML.php): filtro rocket_delay_js_exclusions
 *   (string[]); cada patrón se evalúa como regex con preg_match( "#patrón#i" ) contra la etiqueta.
 * - LiteSpeed Cache 7.9.1 (src/optimize.cls.php): filtro litespeed_optm_js_defer_exc; lista de
 *   subcadenas (Utility::str_hit_array), aplicada al diferir y al retrasar JS.
 *
 * A WP Rocket se le pasan los patrones tal cual. A LSCache solo los que son literales (sin comodines
 * de regex), sin las barras de escape. Solo agrega exclusiones: hace el delay menos agresivo, nunca más.
 *
 * Dependencias (0.10.4): un script excluido se ejecuta al leerse, antes que los diferidos o retrasados.
 * Si sus dependencias sí se difieren, corre sin ellas. En LSCache con "Load JS Deferred" pasó con
 * api-fetch (excluido) y url, private-apis y wp-polyfill (diferidos): "Cannot read properties of
 * undefined (reading 'use')" en las páginas de producto con el checkout exprés de Stripe. Por eso las
 * dependencias de un script excluido (y su código en línea) se marcan para que tampoco se difieran:
 * data-no-defer para LSCache (verificado en src/optimize.cls.php 7.9.1, scripts externos y en línea) y
 * nowprocket para WP Rocket (atributo documentado para excluir del delay). Se marca en script_loader_tag,
 * un filtro de WordPress, sin tocar el HTML de otra forma.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

use ImaginaTurbo\Core\Runtime;

/**
 * Filtros de los plugins de caché.
 */
final class DelayJsExclusions {

	public static function register(): void {
		$patterns = self::patterns();
		if ( [] === $patterns ) {
			return;
		}
		add_filter( 'rocket_delay_js_exclusions', [ self::class, 'rocket' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de WP Rocket.
		add_filter( 'litespeed_optm_js_defer_exc', [ self::class, 'litespeed' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de LiteSpeed Cache.
		add_filter( 'script_loader_tag', [ self::class, 'pinTag' ], 20, 2 );
	}

	/** @var array<string, bool> Resultado por handle: ¿su src o su código en línea coincide con una exclusión? */
	private static array $rootCache = [];

	/** @var array<string, true> */
	private static array $pinned = [];

	private static int $pinnedFor = -1;

	private static ?string $combined = null;

	private static string $signature = '';

	/**
	 * Marca la etiqueta de un script que no se debe diferir ni retrasar.
	 *
	 * @param mixed $tag    Etiqueta.
	 * @param mixed $handle Handle.
	 * @return mixed
	 */
	public static function pinTag( mixed $tag, mixed $handle ): mixed {
		global $wp_scripts;
		if ( ! is_string( $tag ) || ! is_string( $handle ) || ! ( $wp_scripts instanceof \WP_Scripts ) ) {
			return $tag;
		}
		// El conjunto se recalcula solo si cambió la cola (un shortcode puede encolar durante el render).
		$queued = count( $wp_scripts->queue );
		if ( $queued !== self::$pinnedFor ) {
			self::$pinnedFor = $queued;
			self::$pinned    = self::pinnedHandles( self::graph( $wp_scripts ), $wp_scripts->queue, array_merge( self::patterns(), self::litespeedOwn() ) );
		}
		return isset( self::$pinned[ $handle ] ) ? self::markTag( $tag ) : $tag;
	}

	/**
	 * Exclusiones escritas a mano en LiteSpeed Cache (subcadenas): sus dependencias tampoco se difieren.
	 *
	 * @return list<string>
	 */
	private static function litespeedOwn(): array {
		if ( ! defined( 'LSCWP_V' ) ) {
			return [];
		}
		$own = get_option( 'litespeed.conf.optm-js_defer_exc' );
		if ( is_string( $own ) ) {
			$own = preg_split( '/\R/', $own ) ?: [];
		}
		$out = [];
		foreach ( is_array( $own ) ? $own : [] as $item ) {
			if ( is_string( $item ) && strlen( trim( $item ) ) >= 4 ) {
				$out[] = preg_quote( trim( $item ), '#' );
			}
		}
		return $out;
	}

	/**
	 * Grafo de los scripts registrados: src, dependencias y código en línea.
	 *
	 * @return array<string, array{src: string, deps: list<string>, inline: string}>
	 */
	private static function graph( \WP_Scripts $scripts ): array {
		$out = [];
		foreach ( $scripts->registered as $handle => $dep ) {
			$inline = '';
			foreach ( [ 'before', 'after' ] as $position ) {
				$data = $dep->extra[ $position ] ?? [];
				if ( is_array( $data ) ) {
					$inline .= implode( "\n", array_filter( $data, 'is_string' ) );
				}
			}
			$out[ (string) $handle ] = [
				'src'    => is_string( $dep->src ) ? $dep->src : '',
				'deps'   => array_values( array_filter( $dep->deps, 'is_string' ) ),
				'inline' => $inline,
			];
		}
		return $out;
	}

	/**
	 * Handles que no se pueden diferir: los que coinciden con una exclusión (por src o por su código en
	 * línea) y todas sus dependencias, recursivamente. Solo entre los que se van a imprimir.
	 *
	 * @param array<string, array{src: string, deps: list<string>, inline: string}> $graph    Scripts registrados.
	 * @param array<string>                                                          $queue    Handles encolados.
	 * @param list<string>                                                           $patterns Exclusiones (regex de WP Rocket).
	 * @return array<string, true>
	 */
	public static function pinnedHandles( array $graph, array $queue, array $patterns ): array {
		if ( [] === $patterns ) {
			return [];
		}
		$signature = md5( implode( "\n", $patterns ) );
		if ( $signature !== self::$signature ) {
			self::$signature = $signature;
			self::$rootCache = [];
			self::$combined  = null;
		}
		// Todo lo que se imprimirá: la cola y sus dependencias.
		$printed = [];
		$stack   = array_values( $queue );
		while ( [] !== $stack ) {
			$handle = (string) array_pop( $stack );
			if ( isset( $printed[ $handle ] ) || ! isset( $graph[ $handle ] ) ) {
				continue;
			}
			$printed[ $handle ] = true;
			foreach ( $graph[ $handle ]['deps'] as $dep ) {
				$stack[] = $dep;
			}
		}

		$pinned = [];
		$stack  = [];
		foreach ( array_keys( $printed ) as $handle ) {
			if ( self::isRoot( $handle, $graph[ $handle ], $patterns ) ) {
				$stack[] = $handle;
			}
		}
		while ( [] !== $stack ) {
			$handle = (string) array_pop( $stack );
			if ( isset( $pinned[ $handle ] ) || ! isset( $graph[ $handle ] ) ) {
				continue;
			}
			$pinned[ $handle ] = true;
			foreach ( $graph[ $handle ]['deps'] as $dep ) {
				$stack[] = $dep;
			}
		}
		return $pinned;
	}

	/**
	 * @param array{src: string, deps: list<string>, inline: string} $node     Script.
	 * @param list<string>                                           $patterns Exclusiones.
	 */
	private static function isRoot( string $handle, array $node, array $patterns ): bool {
		if ( ! isset( self::$rootCache[ $handle ] ) ) {
			$subject                    = $node['src'] . "\n" . $node['inline'];
			self::$rootCache[ $handle ] = '' !== trim( $subject ) && self::matches( $subject, $patterns );
		}
		return self::$rootCache[ $handle ];
	}

	/**
	 * Una sola regex con todos los patrones; si alguno es inválido, uno por uno.
	 *
	 * @param list<string> $patterns Exclusiones.
	 */
	private static function matches( string $subject, array $patterns ): bool {
		if ( null === self::$combined ) {
			$combined       = '#(?:' . implode( ')|(?:', $patterns ) . ')#i';
			self::$combined = false === @preg_match( $combined, '' ) ? '' : $combined; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- un patrón inválido no debe emitir avisos.
		}
		if ( '' !== self::$combined ) {
			return 1 === preg_match( self::$combined, $subject );
		}
		foreach ( $patterns as $pattern ) {
			if ( 1 === @preg_match( '#' . $pattern . '#i', $subject ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- idem.
				return true;
			}
		}
		return false;
	}

	/**
	 * Agrega los atributos a cada <script> de la etiqueta (el externo y su código en línea).
	 */
	public static function markTag( string $tag ): string {
		return (string) preg_replace( '/<script(?![^>]*\bdata-no-defer\b)(?=[\s>])/i', '<script data-no-defer="1" nowprocket', $tag );
	}

	/**
	 * Vacía los cálculos (pruebas y cambios de configuración en el mismo proceso).
	 */
	public static function reset(): void {
		self::$rootCache = [];
		self::$pinned    = [];
		self::$pinnedFor = -1;
		self::$combined  = null;
		self::$signature = '';
	}

	/**
	 * @return list<string>
	 */
	public static function patterns(): array {
		$list = Runtime::$config?->get( 'delay_js', [] );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : [];
	}

	/**
	 * @param mixed $excluded Lista de WP Rocket.
	 * @return mixed
	 */
	public static function rocket( mixed $excluded ): mixed {
		return is_array( $excluded ) ? array_values( array_unique( array_merge( $excluded, self::patterns() ) ) ) : $excluded;
	}

	/**
	 * @param mixed $excluded Lista de LSCache (puede llegar como texto con una por línea).
	 * @return mixed
	 */
	public static function litespeed( mixed $excluded ): mixed {
		if ( is_string( $excluded ) ) {
			$excluded = preg_split( '/\R/', $excluded ) ?: [];
		}
		return is_array( $excluded ) ? array_values( array_unique( array_merge( array_filter( $excluded, static fn( $v ): bool => is_string( $v ) && '' !== trim( $v ) ), self::literals( self::patterns() ) ) ) ) : $excluded;
	}

	/**
	 * Patrones regex que son texto literal (con "." como punto), sin escapes.
	 *
	 * @param list<string> $patterns Regex de WP Rocket.
	 * @return list<string>
	 */
	public static function literals( array $patterns ): array {
		$out = [];
		foreach ( $patterns as $pattern ) {
			$plain = (string) preg_replace( '/\\\\(.)/', '$1', $pattern );
			// Sin escapes, ¿quedan metacaracteres de regex? (el punto se acepta como literal).
			$bare = (string) preg_replace( '/\\\\./', '', $pattern );
			if ( 1 === preg_match( '/[*+?\[\]{}()|^$]/', $bare ) || strlen( $plain ) < 4 ) {
				continue;
			}
			$out[] = $plain;
		}
		return array_values( array_unique( $out ) );
	}
}
