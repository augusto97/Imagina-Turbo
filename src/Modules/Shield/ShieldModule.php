<?php
/**
 * Escudo de servidor (docs/02 módulo 6). Riesgo seguro, activo por defecto.
 *
 * Lo que corta tráfico basura antes de cargar plugins vive en EarlyGuard (etapa temprana). Aquí, ya
 * con WordPress cargado:
 * - Heartbeat: fuera del frontend; cada N segundos en el admin y en el editor.
 * - Combinaciones de filtros de WooCommerce (?filter_color=…&filter_talla=…&min_price=…): por encima de
 *   N parámetros, X-Robots-Tag noindex, nofollow (los crawlers dejan de recorrer combinaciones infinitas).
 * - robots.txt (0.21.0, opcional): Disallow de las combinaciones de filtros en cada grupo de
 *   user-agent, como recomienda Google para la navegación por facetas. Cada filtro por separado
 *   sigue permitido, salvo con el alcance "todos los filtros" (0.21.1). Solo con el robots.txt
 *   virtual de WordPress; uno físico no pasa por PHP.
 * - 404 cuando un filtro no tiene productos (0.21.1, opcional): lo que recomienda Google para las
 *   combinaciones sin resultados. Solo cambia el código de estado: el visitante ve la misma página
 *   («No se encontraron productos» con los filtros para cambiarlos). No se aplica a las peticiones de
 *   los filtros AJAX del tema (WoodMart usa PJAX), que esperan un 200.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Shield;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\CacheCoordinator\Cacheability;

/**
 * Registro del módulo.
 */
final class ShieldModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
		$shield = self::settings();
		if ( RequestType::FrontendHtml === $request->type ) {
			if ( $shield['heartbeat'] ) {
				add_action( 'wp_enqueue_scripts', [ self::class, 'removeFrontendHeartbeat' ], 100 );
			}
			if ( $shield['facets_empty404'] && [] !== array_diff_key( EarlyGuard::facetGroups( $_GET ), [ 'view' => true ] ) && ! self::scriptRequest( $_SERVER ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se leen nombres.
				add_action( 'template_redirect', [ self::class, 'emptyFilters404' ], 20 );
			}
			if ( $shield['filter_limit'] > 0 && self::filterParams( $_GET ) > $shield['filter_limit'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se cuentan nombres.
				add_action( 'send_headers', [ self::class, 'noindex' ] );
			}
		}
		if ( RequestType::Robots === $request->type && $shield['facets_robots'] ) {
			add_filter( 'robots_txt', [ self::class, 'robotsTxt' ], 100 );
		}
		if ( RequestType::Admin === $request->type && $shield['heartbeat'] ) {
			add_filter( 'heartbeat_settings', [ self::class, 'heartbeatInterval' ] );
		}
	}

	/**
	 * @return array{heartbeat: bool, heartbeat_admin: int, heartbeat_editor: int, filter_limit: int, facets_robots: bool, facets_views: bool, facets_all: bool, facets_empty404: bool}
	 */
	public static function settings(): array {
		$s = Runtime::$config?->get( 'shield', [] );
		$s = is_array( $s ) ? $s : [];
		return [
			'heartbeat'        => false !== ( $s['heartbeat'] ?? true ),
			'heartbeat_admin'  => is_int( $s['heartbeat_admin'] ?? null ) ? $s['heartbeat_admin'] : 60,
			'heartbeat_editor' => is_int( $s['heartbeat_editor'] ?? null ) ? $s['heartbeat_editor'] : 15,
			'filter_limit'     => is_int( $s['filter_limit'] ?? null ) ? $s['filter_limit'] : 3,
			'facets_robots'    => true === ( $s['facets_robots'] ?? false ),
			'facets_views'     => false !== ( $s['facets_views'] ?? true ),
			'facets_all'       => 'all' === ( $s['facets_scope'] ?? 'combos' ),
			'facets_empty404'  => true === ( $s['facets_empty404'] ?? false ),
		];
	}

	/**
	 * Solo para visitantes: con sesión, un plugin del frontend (LMS, foros, chat) puede usarlo, y quitar el
	 * script también quitaría los que dependen de él.
	 */
	public static function removeFrontendHeartbeat(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * @param mixed $settings Ajustes de heartbeat.
	 * @return mixed
	 */
	public static function heartbeatInterval( mixed $settings ): mixed {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		global $pagenow;
		$editor               = in_array( $pagenow, [ 'post.php', 'post-new.php' ], true );
		$s                    = self::settings();
		$settings['interval'] = max( 15, min( 120, $editor ? $s['heartbeat_editor'] : $s['heartbeat_admin'] ) );
		return $settings;
	}

	/**
	 * La tienda o una categoría filtrada sin productos responde 404 (la plantilla es la misma). El
	 * coordinador de caché lo ve después (prioridad 99999) y limita su TTL en el edge a 10 minutos; a
	 * LSCache se le pide lo mismo, así un producto que vuelve a tener stock aparece pronto.
	 */
	public static function emptyFilters404(): void {
		global $wp_query;
		if ( ! function_exists( 'is_shop' ) || ! ( is_shop() || is_product_taxonomy() ) || ! $wp_query instanceof \WP_Query || $wp_query->is_404() ) {
			return;
		}
		if ( (int) $wp_query->found_posts > 0 || $wp_query->have_posts() ) {
			return;
		}
		status_header( 404 );
		if ( ! headers_sent() ) {
			header( 'X-ITB-Shield: empty_filters', true );
		}
		do_action( 'litespeed_control_set_ttl', Cacheability::TTL_404 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción pública de LiteSpeed Cache.
	}

	/**
	 * Petición de un script (filtros AJAX/PJAX del tema, fetch), no una navegación: espera un 200.
	 *
	 * @param array<mixed> $server $_SERVER.
	 */
	public static function scriptRequest( array $server ): bool {
		$with = $server['HTTP_X_REQUESTED_WITH'] ?? '';
		$mode = $server['HTTP_SEC_FETCH_MODE'] ?? '';
		return ( is_string( $with ) && '' !== $with ) || isset( $server['HTTP_X_PJAX'] ) || ( is_string( $mode ) && '' !== $mode && 'navigate' !== $mode );
	}

	public static function noindex(): void {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	/**
	 * Parámetros de filtrado de WooCommerce en la URL.
	 *
	 * @param array<mixed> $get $_GET.
	 */
	public static function filterParams( array $get ): int {
		$n = 0;
		foreach ( array_keys( $get ) as $name ) {
			$name = (string) $name;
			if ( str_starts_with( $name, 'filter_' ) || str_starts_with( $name, 'query_type_' ) || in_array( $name, [ 'min_price', 'max_price', 'rating_filter' ], true ) ) {
				++$n;
			}
		}
		return $n;
	}

	public const ROBOTS_MARKER = 'Imagina Turbo: filtros de la tienda';

	/** Señal de cada grupo de filtros en el query (min_price y max_price son el mismo: _price=). */
	private const ROBOTS_TOKENS = [ 'filter_', 'rating_filter=', '_price=', 'stock_status=' ];

	/**
	 * Reglas Disallow de las combinaciones: dos grupos a la vez y varios valores de un filtro (con la
	 * coma tal cual y codificada). Con $all (0.21.1), cualquier filtro. Con $views, también el orden y
	 * la vista del listado.
	 *
	 * @return list<string>
	 */
	public static function robotsRules( bool $views, bool $all = false ): array {
		$rules = [];
		if ( $all ) {
			foreach ( array_merge( self::ROBOTS_TOKENS, [ 'query_type_' ] ) as $a ) {
				$rules[] = 'Disallow: /*?*' . $a;
			}
		} else {
			foreach ( self::ROBOTS_TOKENS as $a ) {
				foreach ( self::ROBOTS_TOKENS as $b ) {
					if ( $a !== $b || 'filter_' === $a ) {
						$rules[] = 'Disallow: /*?*' . $a . '*' . $b;
					}
				}
			}
			foreach ( self::ROBOTS_TOKENS as $a ) {
				if ( '_price=' !== $a ) {
					$rules[] = 'Disallow: /*?*' . $a . '*,';
					$rules[] = 'Disallow: /*?*' . $a . '*%2C';
				}
			}
		}
		if ( $views ) {
			foreach ( EarlyGuard::VIEW_PARAMS as $param ) {
				$rules[] = 'Disallow: /*?*' . $param . '=';
			}
		}
		return $rules;
	}

	/**
	 * Inserta las reglas al final de la cabecera de cada grupo (tras sus líneas User-agent): un crawler
	 * con grupo propio ignora el de "*". Sin grupos, añade uno para todos.
	 *
	 * @param mixed $output robots.txt generado por WordPress y los plugins de SEO.
	 */
	public static function robotsTxt( mixed $output ): mixed {
		if ( ! is_string( $output ) || str_contains( $output, self::ROBOTS_MARKER ) ) {
			return $output;
		}
		$s        = self::settings();
		$block    = array_merge( [ '# ' . self::ROBOTS_MARKER ], self::robotsRules( $s['facets_views'], $s['facets_all'] ) );
		$lines    = preg_split( '/\r\n|\n|\r/', $output );
		$lines    = false === $lines ? [] : $lines;
		$out      = [];
		$inserted = false;
		$agent    = static fn( ?string $line ): bool => null !== $line && 1 === preg_match( '/^\s*user-agent\s*:/i', $line );
		foreach ( $lines as $i => $line ) {
			$out[] = $line;
			if ( $agent( $line ) && ! $agent( $lines[ $i + 1 ] ?? null ) ) {
				array_push( $out, ...$block );
				$inserted = true;
			}
		}
		if ( ! $inserted ) {
			$out = array_merge( $out, [ '', 'User-agent: *' ], $block, [ '' ] );
		}
		return implode( "\n", $out );
	}
}
