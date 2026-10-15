<?php
/**
 * Navegación instantánea (docs/02 módulo 5). Prefetch seguro, activo; prerender moderado, apagado.
 *
 * API del core verificada en WordPress 7.1 (wp-includes/speculative-loading.php):
 * - wp_speculation_rules_configuration: {mode, eagerness} o null (null = desactivado; es el valor por
 *   defecto con sesión iniciada o sin enlaces permanentes, y se respeta).
 * - wp_speculation_rules_href_exclude_paths ($paths, $mode): patrones de ruta; el core ya excluye
 *   wp-admin, wp-*.php, uploads, plugins, temas y, con enlaces permanentes, toda URL con query.
 * - wp_load_speculation_rules (WP_Speculation_Rules): add_rule( $mode, $id, $rule ); una regla de
 *   lista admite eagerness "immediate", una de documento no.
 * En WordPress < 6.8 se imprime una regla equivalente en wp_footer.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Speculation;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\Profiler\RouteKey;

/**
 * Registro del módulo.
 */
final class SpeculationModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
		// Agregar al carrito, cupones, login y logout llegan por wc-ajax, la Store API, admin-ajax o un
		// formulario: allí se descarta lo precargado con el estado anterior.
		if ( in_array( $request->type, [ RequestType::FrontendHtml, RequestType::WcAjax, RequestType::StoreApi, RequestType::AdminAjax, RequestType::Rest, RequestType::Cart, RequestType::Checkout, RequestType::Account, RequestType::Login ], true ) ) {
			( new ClearSpeculations() )->register();
		}
		if ( RequestType::FrontendHtml !== $request->type ) {
			return;
		}
		$s = self::settings();
		if ( function_exists( 'wp_get_speculation_rules' ) ) {
			add_filter( 'wp_speculation_rules_configuration', [ self::class, 'configuration' ], 20 );
			add_filter( 'wp_speculation_rules_href_exclude_paths', [ self::class, 'exclude' ], 10, 2 );
			if ( $s['predictive'] ) {
				add_action( 'wp_load_speculation_rules', [ self::class, 'addPredicted' ] );
			}
		} else {
			add_action( 'wp_footer', [ self::class, 'printLegacy' ] );
		}
		if ( $s['fallback'] ) {
			add_action( 'wp_footer', [ self::class, 'printFallback' ], 30 );
		}
		if ( $s['view_transitions'] ) {
			add_action( 'wp_head', [ self::class, 'printViewTransitions' ], 5 );
		}
	}

	/**
	 * @return array{mode: string, eagerness: string, predictive: bool, fallback: bool, view_transitions: bool, exclude: list<string>, predicted: array<string, list<string>>}
	 */
	public static function settings(): array {
		$s = Runtime::$config?->get( 'speculation', [] );
		$s = is_array( $s ) ? $s : [];
		return [
			'mode'             => in_array( $s['mode'] ?? '', [ 'prefetch', 'prerender' ], true ) ? (string) $s['mode'] : 'prefetch',
			'eagerness'        => in_array( $s['eagerness'] ?? '', [ 'conservative', 'moderate', 'eager' ], true ) ? (string) $s['eagerness'] : 'moderate',
			'predictive'       => false !== ( $s['predictive'] ?? true ),
			'fallback'         => false !== ( $s['fallback'] ?? true ),
			'view_transitions' => true === ( $s['view_transitions'] ?? false ),
			'exclude'          => array_values( array_filter( is_array( $s['exclude'] ?? null ) ? $s['exclude'] : [], 'is_string' ) ),
			'predicted'        => is_array( $s['predicted'] ?? null ) ? $s['predicted'] : [],
		];
	}

	/**
	 * @param mixed $config Configuración del core.
	 * @return mixed
	 */
	public static function configuration( mixed $config ): mixed {
		if ( null === $config ) {
			return null;
		}
		$s = self::settings();
		return [
			'mode'      => $s['mode'],
			'eagerness' => $s['eagerness'],
		];
	}

	/**
	 * Carrito, checkout, cuenta, login y las rutas que nunca se cachean (solo se especula lo cacheable).
	 *
	 * @param mixed $paths Patrones.
	 * @return mixed
	 */
	public static function exclude( mixed $paths, mixed $mode = '' ): mixed {
		unset( $mode );
		return is_array( $paths ) ? array_values( array_unique( array_merge( $paths, self::settings()['exclude'] ) ) ) : $paths;
	}

	/**
	 * Páginas siguientes más probables desde esta plantilla (RUM), con prefetch inmediato.
	 */
	public static function addPredicted( mixed $rules ): void {
		if ( ! is_object( $rules ) || ! method_exists( $rules, 'add_rule' ) ) {
			return;
		}
		$urls = self::predictedUrls();
		if ( [] !== $urls ) {
			$rules->add_rule(
				'prefetch',
				'itb-predicted',
				[
					'source'    => 'list',
					'urls'      => $urls,
					'eagerness' => 'immediate',
				]
			);
		}
	}

	/**
	 * @return list<string>
	 */
	public static function predictedUrls(): array {
		$predicted = self::settings()['predicted'][ RouteKey::template() ] ?? [];
		$current   = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- solo se compara.
		$out       = [];
		foreach ( is_array( $predicted ) ? $predicted : [] as $path ) {
			if ( is_string( $path ) && $path !== $current ) {
				$out[] = home_url( $path );
			}
		}
		return $out;
	}

	/**
	 * WordPress < 6.8: la misma regla que el core, sin sesión y con enlaces permanentes.
	 */
	public static function printLegacy(): void {
		if ( is_user_logged_in() || ! get_option( 'permalink_structure' ) ) {
			return;
		}
		$s       = self::settings();
		$exclude = array_merge( [ '/wp-*.php', '/wp-admin/*', '/wp-content/*', '/*\\?(.+)' ], $s['exclude'] );
		$rules   = [
			$s['mode'] => [
				[
					'source'    => 'document',
					'where'     => [
						'and' => [
							[ 'href_matches' => '/*' ],
							[ 'not' => [ 'href_matches' => $exclude ] ],
							[ 'not' => [ 'selector_matches' => 'a[rel~="nofollow"]' ] ],
						],
					],
					'eagerness' => $s['eagerness'],
				],
			],
		];
		$urls    = $s['predictive'] ? self::predictedUrls() : [];
		if ( [] !== $urls ) {
			$rules['prefetch'][] = [
				'source'    => 'list',
				'urls'      => $urls,
				'eagerness' => 'immediate',
			];
		}
		wp_print_inline_script_tag( (string) wp_json_encode( $rules, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ), [ 'type' => 'speculationrules' ] );
	}

	/**
	 * Safari y Firefox: script pequeño solo si el navegador no entiende Speculation Rules.
	 */
	public static function printFallback(): void {
		if ( is_user_logged_in() || ( function_exists( 'wp_get_speculation_rules_configuration' ) && null === wp_get_speculation_rules_configuration() ) ) {
			return;
		}
		$s      = self::settings();
		$file   = is_file( Runtime::$pluginDir . '/assets/js/itb-prefetch.min.js' ) ? 'itb-prefetch.min.js' : 'itb-prefetch.js';
		$src    = plugins_url( 'assets/js/' . $file, Runtime::$pluginDir . '/imagina-turbo.php' ) . '?ver=' . ITB_VERSION;
		$config = [
			'x' => self::prefixes( $s['exclude'] ),
			'p' => array_values( array_map( static fn( string $u ): string => (string) wp_parse_url( $u, PHP_URL_PATH ), $s['predictive'] ? self::predictedUrls() : [] ) ),
		];
		$js     = sprintf(
			'if(!(window.HTMLScriptElement&&HTMLScriptElement.supports&&HTMLScriptElement.supports("speculationrules"))){window.itbPf=%s;var s=document.createElement("script");s.src=%s;s.async=true;document.head.appendChild(s)}',
			wp_json_encode( $config, JSON_UNESCAPED_SLASHES ),
			wp_json_encode( $src, JSON_UNESCAPED_SLASHES )
		);
		wp_print_inline_script_tag( $js, [ 'id' => 'itb-prefetch-loader' ] );
	}

	/**
	 * Prefijos para el script de respaldo (compara con indexOf). Los patrones que empiezan con comodín
	 * (los de feeds y embeds de cualquier ruta) darían un prefijo vacío, que excluiría todo: se omiten;
	 * el script ya ignora las URLs con query y con extensión.
	 *
	 * @param list<string> $patterns Patrones de exclusión.
	 * @return list<string>
	 */
	public static function prefixes( array $patterns ): array {
		$out = [];
		foreach ( $patterns as $pattern ) {
			$prefix = rtrim( (string) strtok( $pattern, '*' ), '/' );
			if ( str_starts_with( $pattern, '/*' ) || '' === $prefix ) {
				continue;
			}
			$out[] = $prefix;
		}
		return array_values( array_unique( $out ) );
	}

	public static function printViewTransitions(): void {
		echo "<style id=\"itb-view-transitions\">@view-transition{navigation:auto}</style>\n";
	}
}
