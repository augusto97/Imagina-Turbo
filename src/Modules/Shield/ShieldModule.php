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
 *   sigue permitido. Solo con el robots.txt virtual de WordPress; uno físico no pasa por PHP.
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
	 * @return array{heartbeat: bool, heartbeat_admin: int, heartbeat_editor: int, filter_limit: int, facets_robots: bool, facets_views: bool}
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

	public const ROBOTS_MARKER = 'Imagina Turbo: combinaciones de filtros';

	/** Señal de cada grupo de filtros en el query (min_price y max_price son el mismo: _price=). */
	private const ROBOTS_TOKENS = [ 'filter_', 'rating_filter=', '_price=', 'stock_status=' ];

	/**
	 * Reglas Disallow de las combinaciones: dos grupos a la vez y varios valores de un filtro (con la
	 * coma tal cual y codificada). Con $views, también el orden y la vista del listado.
	 *
	 * @return list<string>
	 */
	public static function robotsRules( bool $views ): array {
		$rules = [];
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
		$block    = array_merge( [ '# ' . self::ROBOTS_MARKER ], self::robotsRules( self::settings()['facets_views'] ) );
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
