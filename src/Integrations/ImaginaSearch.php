<?php
/**
 * Integración con Imagina Search (docs/02 módulo 12).
 *
 * Verificado en augusto97/imagina-search (Woo Smart Search 6.0.2, 2026-10-13):
 * - Plugin woo-smart-search/woo-smart-search.php; la carpeta puede cambiar, se reconoce por el archivo.
 * - REST wss/v1: GET /search (q, limit, page, filters, sort, facets), GET /popular, POST /track-click y
 *   POST /track-search (analítica).
 * - En modo WooCommerce la búsqueda usa wc_get_product() y wc_get_attribute_taxonomies(): WooCommerce
 *   tiene que seguir cargado.
 * - Límite propio de 30 búsquedas por minuto e IP (filtro wss_rate_limit), guardado en transients.
 * - Página de resultados: results_page_id en la opción wss_settings, con el shortcode
 *   [woo_smart_search_results]. Imprime el término (?q=) y pide los resultados a la REST desde el
 *   navegador: el HTML es cacheable por URL.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Integrations;

/**
 * Detección, regla sugerida y datos para la config.
 */
final class ImaginaSearch {

	public const REST_NAMESPACE = 'wss/v1';

	public const MAIN_FILE = 'woo-smart-search.php';

	/**
	 * Archivo del plugin entre los activos, o null.
	 *
	 * @param list<string> $active Plugins activos.
	 */
	public static function plugin( array $active ): ?string {
		foreach ( $active as $plugin ) {
			if ( self::MAIN_FILE === $plugin || str_ends_with( $plugin, '/' . self::MAIN_FILE ) ) {
				return $plugin;
			}
		}
		return null;
	}

	/**
	 * Peticiones de prueba: una búsqueda y las populares.
	 *
	 * @return list<array{method: string, path: string, body: string}>
	 */
	public static function probes( string $restBase ): array {
		return [
			[
				'method' => 'GET',
				'path'   => $restBase . self::REST_NAMESPACE . '/search?q=' . rawurlencode( self::sampleTerm() ) . '&limit=8',
				'body'   => '',
			],
			[
				'method' => 'GET',
				'path'   => $restBase . self::REST_NAMESPACE . '/popular?limit=6',
				'body'   => '',
			],
		];
	}

	/**
	 * Un término que dé resultados: el primer producto (o entrada) publicado.
	 */
	public static function sampleTerm(): string {
		if ( ! function_exists( 'get_posts' ) ) {
			return 'a';
		}
		$posts = get_posts(
			[
				'post_type'      => post_type_exists( 'product' ) ? 'product' : 'post',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			]
		);
		if ( ! isset( $posts[0] ) ) {
			return 'a';
		}
		$word = strtok( wp_strip_all_tags( get_the_title( (int) $posts[0] ) ), ' ' );
		return is_string( $word ) && mb_strlen( $word ) >= 2 ? mb_strtolower( $word ) : 'a';
	}

	/**
	 * En los requests de experimento el límite de búsquedas por IP no aplica: 40 llamadas seguidas desde
	 * el propio servidor lo agotarían y la comparación fallaría por un 429 que no tiene que ver con la regla.
	 */
	public static function liftRateLimit(): void {
		add_filter( 'wss_rate_limit', static fn(): int => PHP_INT_MAX ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de Imagina Search.
	}

	/**
	 * Datos para la config compilada (la fase 6 excluye la página de resultados del prerender).
	 *
	 * @param list<string> $active Plugins activos.
	 * @return array{active: bool, namespace: string, results_path: string}
	 */
	public static function config( array $active ): array {
		$plugin = self::plugin( $active );
		$path   = '';
		if ( null !== $plugin && function_exists( 'get_option' ) ) {
			$settings = get_option( 'wss_settings', [] );
			$page     = is_array( $settings ) ? (int) ( $settings['results_page_id'] ?? 0 ) : 0;
			$link     = $page > 0 ? get_permalink( $page ) : false;
			if ( is_string( $link ) ) {
				$path = (string) wp_parse_url( $link, PHP_URL_PATH );
			}
		}
		return [
			'active'       => null !== $plugin,
			'namespace'    => self::REST_NAMESPACE,
			'results_path' => $path,
		];
	}
}
