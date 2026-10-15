<?php
/**
 * Carga de scripts (0.14.0). Riesgo moderado, apagado por defecto.
 *
 * Dos funciones, cada una con su interruptor, sin output buffering (regla 2):
 *
 * - Diferido nativo: pide a WordPress la estrategia `defer` (6.3+) para los scripts registrados. El
 *   núcleo solo la aplica si todos los scripts que dependen de uno también pueden diferirse y si no
 *   lleva código en línea "after" (WP_Scripts::filter_eligible_strategies, verificado en trunk). jQuery,
 *   los scripts propios, las exclusiones de la matriz (pasarelas de pago) y las escritas a mano en
 *   LiteSpeed Cache, con sus dependencias, se quedan
 *   como están. Es menos agresivo que retrasar: el script se descarga en paralelo y corre antes de
 *   DOMContentLoaded, así que los `jQuery( function () { … } )` del tema siguen funcionando.
 * - Retraso selectivo: solo los scripts que coinciden con los patrones indicados (analítica, píxeles,
 *   chat) se cargan en la primera interacción o tras unos segundos. Se cambia su etiqueta en los
 *   filtros del núcleo (script_loader_tag, wp_script_attributes, wp_inline_script_attributes): lo que
 *   un plugin imprime con echo no se puede retrasar sin reescribir el HTML.
 *
 * Coexistencia: si LiteSpeed Cache ya difiere o retrasa el JS ("Load JS Deferred") o WP Rocket tiene
 * "Delay JavaScript execution" o "Load JavaScript deferred", la función equivalente no hace nada.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\ScriptLoading;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\CacheCoordinator\DelayJsExclusions;

/**
 * Registro del módulo.
 */
final class ScriptLoadingModule implements ModuleInterface {

	/** Nunca se difieren: el código en línea del tema y de los plugins los usa al leerse. */
	public const KEEP = [ 'jquery', 'jquery-core', 'jquery-migrate' ];

	/** Atributo que marca una etiqueta retrasada (el cargador la busca). */
	public const ATTR = 'data-itb-delay';

	/** @var array<string, bool> Handle => ¿su src coincide con un patrón de retraso? */
	private static array $delayed = [];

	public function register( Container $container, RequestContext $request ): void {
		if ( RequestType::FrontendHtml !== $request->type ) {
			return;
		}
		$s = self::settings();
		if ( $s['defer'] && ! self::cacheDefers() ) {
			add_action( 'wp_enqueue_scripts', [ self::class, 'markDefer' ], PHP_INT_MAX );
			// Lo que se encola durante el render (shortcodes, bloques) se imprime en el pie.
			add_action( 'wp_print_footer_scripts', [ self::class, 'markDefer' ], 1 );
		}
		if ( [] !== $s['delay'] && ! self::cacheDelays() ) {
			add_filter( 'script_loader_tag', [ self::class, 'delayTag' ], 30, 3 );
			add_filter( 'wp_script_attributes', [ self::class, 'delayAttributes' ], 30 );
			add_filter( 'wp_inline_script_attributes', [ self::class, 'delayInline' ], 30, 2 );
			add_action( 'wp_footer', [ self::class, 'printLoader' ], PHP_INT_MAX - 1 );
		}
	}

	/**
	 * @return array{defer: bool, defer_exclude: list<string>, delay: list<string>, delay_timeout: int}
	 */
	public static function settings(): array {
		$s    = Runtime::$config?->get( 'scripts', [] );
		$s    = is_array( $s ) ? $s : [];
		$list = static fn( mixed $v ): array => is_array( $v ) ? array_values( array_filter( $v, 'is_string' ) ) : [];
		return [
			'defer'         => true === ( $s['defer'] ?? false ),
			'defer_exclude' => $list( $s['defer_exclude'] ?? [] ),
			'delay'         => $list( $s['delay'] ?? [] ),
			'delay_timeout' => is_int( $s['delay_timeout'] ?? null ) ? $s['delay_timeout'] : 0,
		];
	}

	/**
	 * El plugin de caché ya difiere o retrasa todo el JS.
	 */
	public static function cacheDefers(): bool {
		if ( defined( 'LSCWP_V' ) && ( (int) get_option( 'litespeed.conf.optm-js_defer' ) > 0 || defined( 'LITESPEED_GUEST_OPTM' ) ) ) {
			return true;
		}
		$rocket = get_option( 'wp_rocket_settings' );
		return defined( 'WP_ROCKET_VERSION' ) && is_array( $rocket ) && ( ! empty( $rocket['defer_all_js'] ) || ! empty( $rocket['delay_js'] ) );
	}

	/**
	 * El plugin de caché ya retrasa todo el JS hasta la interacción.
	 */
	public static function cacheDelays(): bool {
		if ( DelayJsExclusions::litespeedDelays() ) {
			return true;
		}
		$rocket = get_option( 'wp_rocket_settings' );
		return defined( 'WP_ROCKET_VERSION' ) && is_array( $rocket ) && ! empty( $rocket['delay_js'] );
	}

	/**
	 * Pide la estrategia defer para los scripts que se van a imprimir. El núcleo decide al imprimir si
	 * cada uno es elegible; aquí solo se descartan los que no deben esperar.
	 */
	public static function markDefer(): void {
		global $wp_scripts;
		if ( ! ( $wp_scripts instanceof \WP_Scripts ) ) {
			return;
		}
		$s     = self::settings();
		$graph = DelayJsExclusions::graph( $wp_scripts );
		$keep  = DelayJsExclusions::pinnedHandles( $graph, $wp_scripts->queue, DelayJsExclusions::exclusionPatterns() );
		foreach ( self::deferCandidates( $graph, $wp_scripts->queue, array_merge( self::KEEP, $s['defer_exclude'] ), $keep ) as $handle ) {
			$wp_scripts->add_data( $handle, 'strategy', 'defer' );
		}
	}

	/**
	 * Handles a los que pedir defer: lo que se imprimirá (la cola y sus dependencias), con archivo propio,
	 * sin estrategia, fuera de las exclusiones, sin ser propio y que el núcleo vaya a diferir de verdad.
	 *
	 * Lo último importa: WordPress 6.4+ mueve al pie un script de la cabecera que pidió defer pero no es
	 * elegible (WP_Scripts::do_item, "Move this script to the footer"). Ese script sigue bloqueando,
	 * pero ya no está antes del cuerpo: un código en línea del cuerpo que lo usara fallaría (visto en
	 * pruebas con un script con código "after"). Por eso se replica la regla de elegibilidad del núcleo
	 * (filter_eligible_strategies): sin código "after" y con todos sus dependientes diferibles; los
	 * alias (sin src) son transparentes.
	 *
	 * @param array<string, array{src: string, deps: list<string>, inline: string, strategy?: string, after?: bool}> $graph   Scripts registrados.
	 * @param array<string>                                                                                     $queue   Handles encolados.
	 * @param list<string>                                                                                      $exclude Handles que no se difieren (exactos).
	 * @param array<string, true>                                                                               $pinned  Exclusiones de la matriz y sus dependencias.
	 * @return list<string>
	 */
	public static function deferCandidates( array $graph, array $queue, array $exclude, array $pinned ): array {
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
		$dependents = [];
		$wanted     = [];
		foreach ( array_keys( $printed ) as $handle ) {
			$node = $graph[ $handle ];
			foreach ( $node['deps'] as $dep ) {
				$dependents[ $dep ][] = $handle;
			}
			if ( '' === $node['src'] || '' !== ( $node['strategy'] ?? '' ) || in_array( $handle, $exclude, true ) || isset( $pinned[ $handle ] ) || str_contains( $node['src'], DelayJsExclusions::OWN ) || str_starts_with( $handle, 'itb-' ) ) {
				continue;
			}
			$wanted[ $handle ] = true;
		}
		$memo     = [];
		$eligible = static function ( string $handle, array $seen ) use ( &$eligible, &$memo, $graph, $dependents, $wanted ): bool {
			if ( isset( $memo[ $handle ] ) ) {
				return $memo[ $handle ];
			}
			if ( isset( $seen[ $handle ] ) ) {
				return true; // Ciclo: el núcleo también lo deja pasar.
			}
			$seen[ $handle ] = true;
			$node            = $graph[ $handle ];
			$alias           = '' === $node['src'];
			$strategy        = $node['strategy'] ?? '';
			$ok              = $alias || isset( $wanted[ $handle ] ) || in_array( $strategy, [ 'defer', 'async' ], true );
			if ( $ok && ! $alias && true === ( $node['after'] ?? false ) ) {
				$ok = false;
			}
			foreach ( $dependents[ $handle ] ?? [] as $dependent ) {
				if ( ! $ok ) {
					break;
				}
				$ok = $eligible( $dependent, $seen );
			}
			$memo[ $handle ] = $ok;
			return $ok;
		};
		$out      = [];
		foreach ( array_keys( $wanted ) as $handle ) {
			if ( $eligible( $handle, [] ) ) {
				$out[] = $handle;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * El texto coincide con algún patrón (subcadena, sin distinguir mayúsculas).
	 *
	 * @param list<string> $patterns Patrones.
	 */
	public static function matches( string $subject, array $patterns ): bool {
		if ( '' === $subject ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			if ( '' !== $pattern && false !== stripos( $subject, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * ¿Se retrasa este handle? Por su src.
	 */
	private static function handleDelayed( string $handle ): bool {
		global $wp_scripts;
		if ( ! isset( self::$delayed[ $handle ] ) ) {
			$src                      = $wp_scripts instanceof \WP_Scripts && isset( $wp_scripts->registered[ $handle ] ) && is_string( $wp_scripts->registered[ $handle ]->src ) ? $wp_scripts->registered[ $handle ]->src : '';
			self::$delayed[ $handle ] = ! str_contains( $src, DelayJsExclusions::OWN ) && ! str_starts_with( $handle, 'itb-' ) && self::matches( $src, self::settings()['delay'] );
		}
		return self::$delayed[ $handle ];
	}

	/**
	 * Etiqueta del script externo de un handle retrasado (su código en línea pasa por delayInline).
	 *
	 * @param mixed $tag    Etiqueta.
	 * @param mixed $handle Handle.
	 * @param mixed $src    URL.
	 * @return mixed
	 */
	public static function delayTag( mixed $tag, mixed $handle, mixed $src = '' ): mixed {
		unset( $src );
		if ( ! is_string( $tag ) || ! is_string( $handle ) || ! self::handleDelayed( $handle ) ) {
			return $tag;
		}
		return self::rewriteTag( $tag );
	}

	/**
	 * Convierte cada <script src> de la etiqueta en uno inerte que el cargador activará. Las etiquetas en
	 * línea que ya se marcaron (delayInline) no se tocan.
	 */
	public static function rewriteTag( string $tag ): string {
		return (string) preg_replace_callback(
			'/<script\b([^>]*)>/i',
			static function ( array $m ): string {
				$attrs = $m[1];
				if ( str_contains( $attrs, self::ATTR ) || 1 !== preg_match( '/\ssrc\s*=/i', $attrs ) ) {
					return $m[0];
				}
				$type = '';
				if ( 1 === preg_match( '/\stype\s*=\s*(["\'])([^"\']*)\1/i', $attrs, $t ) ) {
					$type  = strtolower( trim( $t[2] ) );
					$attrs = str_replace( $t[0], '', $attrs );
				}
				$attrs = (string) preg_replace( '/\ssrc(\s*=)/i', ' data-itb-src$1', $attrs, 1 );
				$extra = 'module' === $type ? ' data-itb-type="module"' : '';
				return '<script type="text/plain" ' . self::ATTR . $extra . $attrs . '>';
			},
			$tag
		);
	}

	/**
	 * Scripts externos impresos con wp_print_script_tag (sin handle).
	 *
	 * @param mixed $attributes Atributos.
	 * @return mixed
	 */
	public static function delayAttributes( mixed $attributes ): mixed {
		if ( ! is_array( $attributes ) || ! is_string( $attributes['src'] ?? null ) || isset( $attributes[ self::ATTR ] ) ) {
			return $attributes;
		}
		$id = is_string( $attributes['id'] ?? null ) ? $attributes['id'] : '';
		// Los de un handle llegan después a script_loader_tag.
		if ( 1 === preg_match( '/-js$/', $id ) || str_starts_with( $id, 'itb-' ) || str_contains( $attributes['src'], DelayJsExclusions::OWN ) || ! self::matches( $attributes['src'], self::settings()['delay'] ) ) {
			return $attributes;
		}
		if ( 'module' === ( $attributes['type'] ?? '' ) ) {
			$attributes['data-itb-type'] = 'module';
		}
		$attributes['data-itb-src'] = $attributes['src'];
		unset( $attributes['src'] );
		$attributes['type']       = 'text/plain';
		$attributes[ self::ATTR ] = true;
		return $attributes;
	}

	/**
	 * Código en línea: el de un handle retrasado (antes, después, traducciones) y el que coincide con un
	 * patrón (p. ej. "fbq(" o "gtag(").
	 *
	 * @param mixed $attributes Atributos.
	 * @param mixed $data       Código.
	 * @return mixed
	 */
	public static function delayInline( mixed $attributes, mixed $data = '' ): mixed {
		if ( ! is_array( $attributes ) || isset( $attributes[ self::ATTR ] ) ) {
			return $attributes;
		}
		$id = is_string( $attributes['id'] ?? null ) ? $attributes['id'] : '';
		if ( str_starts_with( $id, 'itb-' ) ) {
			return $attributes;
		}
		$delay = 1 === preg_match( '/^(.+)-js-(?:before|after|translations)$/', $id, $m ) && self::handleDelayed( $m[1] );
		if ( ! $delay && ( ! is_string( $data ) || ! self::matches( $data, self::settings()['delay'] ) ) ) {
			return $attributes;
		}
		$attributes['type']       = 'text/plain';
		$attributes[ self::ATTR ] = true;
		return $attributes;
	}

	/**
	 * Cargador: activa en orden los scripts retrasados en la primera interacción o tras unos segundos.
	 */
	public static function printLoader(): void {
		$file = is_file( Runtime::$pluginDir . '/assets/js/itb-delay.min.js' ) ? 'itb-delay.min.js' : 'itb-delay.js';
		$path = Runtime::$pluginDir . '/assets/js/' . $file;
		$code = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo propio, < 1 KB, en línea para no sumar una petición.
		if ( '' === $code ) {
			return;
		}
		wp_print_inline_script_tag(
			'window.itbDelay=' . wp_json_encode( [ 't' => self::settings()['delay_timeout'] ] ) . ';' . $code,
			[
				'id'               => 'itb-delay',
				'data-no-defer'    => '1',
				'data-no-optimize' => '1',
				'nowprocket'       => true,
			]
		);
	}

	/**
	 * Vacía el cálculo por handle (pruebas).
	 */
	public static function reset(): void {
		self::$delayed = [];
	}
}
