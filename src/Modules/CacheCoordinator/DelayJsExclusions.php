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
