<?php
/**
 * Apaga los módulos propios que se solapan con otros plugins activos.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Environment;

/**
 * Función pura: listas de solapamiento de la matriz + plugins activos → módulos apagados y avisos.
 */
final class OverlapGuard {

	/** Plugins de caché con adaptador propio: se coordinan, nunca son conflicto. */
	private const SUPPORTED_CACHE = [
		'wp-rocket/wp-rocket.php'             => 'wp-rocket',
		'litespeed-cache/litespeed-cache.php' => 'litespeed-cache',
	];

	/** Categorías de la matriz que apagan un módulo entero. */
	private const CATEGORY_MODULES = [
		'plugin_filtering' => [ 'plugin_filter' ],
	];

	/** Plugins concretos de "cache_or_full_optimization" que duplican módulos propios. */
	private const PLUGIN_MODULES = [
		'perfmatters'  => [ 'plugin_filter', 'asset_manager', 'tweaks', 'script_loading' ],
		'flying-press' => [ 'speculation', 'frontend_hints', 'script_loading' ],
		'nitropack'    => [ 'speculation', 'frontend_hints', 'script_loading' ],
	];

	/** Categorías que solo generan aviso (funciones que no hacemos o que se ajustan por función más adelante). */
	private const WARNING_CATEGORIES = [
		'generic_legacy_optimizers' => true,
		'js_minify'                 => true,
		'css_js_minify'             => true,
		'heartbeat'                 => true,
		'lazyload'                  => true,
		'lazyload_iframes'          => true,
		'font_preload'              => true,
	];

	/**
	 * @param array<string, list<array{slug: string, plugin_file: string, coordinate: bool}>> $overlapping Listas de Matrix::overlapping().
	 * @param list<string>                                                                    $activePlugins Archivos de plugins activos.
	 * @return array{
	 *     forced_off: array<string, list<string>>,
	 *     conflicts: list<array{slug: string, plugin_file: string, category: string, modules: list<string>}>,
	 *     coordinated: list<string>,
	 *     multiple_page_caches: bool
	 * }
	 */
	public function evaluate( array $overlapping, array $activePlugins ): array {
		$active = [];
		foreach ( $activePlugins as $file ) {
			$active[ strtolower( $file ) ] = true;
		}

		$forcedOff   = [];
		$conflicts   = [];
		$coordinated = [];
		$pageCaches  = [];
		$seen        = [];

		foreach ( self::SUPPORTED_CACHE as $file => $slug ) {
			if ( isset( $active[ $file ] ) ) {
				$coordinated[ $slug ] = true;
				$pageCaches[ $slug ]  = true;
			}
		}

		foreach ( $overlapping as $category => $entries ) {
			foreach ( $entries as $entry ) {
				$file = strtolower( $entry['plugin_file'] );
				if ( ! isset( $active[ $file ] ) || isset( self::SUPPORTED_CACHE[ $file ] ) ) {
					continue;
				}
				$slug = $entry['slug'];

				if ( $entry['coordinate'] ) {
					$coordinated[ $slug ] = true;
					continue;
				}

				$modules = self::CATEGORY_MODULES[ $category ] ?? [];
				if ( 'cache_or_full_optimization' === $category ) {
					$modules = self::PLUGIN_MODULES[ $slug ] ?? [];
					if ( ! isset( self::PLUGIN_MODULES[ $slug ] ) && 'autoptimize' !== $slug ) {
						$pageCaches[ $slug ] = true;
					}
				} elseif ( ! isset( self::CATEGORY_MODULES[ $category ] ) && ! isset( self::WARNING_CATEGORIES[ $category ] ) ) {
					continue;
				}

				foreach ( $modules as $module ) {
					/* translators: %s: slug del plugin */
					$forcedOff[ $module ][] = sprintf( __( 'Se solapa con %s', 'imagina-turbo' ), $slug );
				}

				$key = $category . '|' . $file;
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$conflicts[]  = [
						'slug'        => $slug,
						'plugin_file' => $entry['plugin_file'],
						'category'    => $category,
						'modules'     => $modules,
					];
				}
			}
		}

		foreach ( $forcedOff as $module => $reasons ) {
			$forcedOff[ $module ] = array_values( array_unique( $reasons ) );
		}

		return [
			'forced_off'           => $forcedOff,
			'conflicts'            => $conflicts,
			'coordinated'          => array_keys( $coordinated ),
			'multiple_page_caches' => count( $pageCaches ) > 1,
		];
	}
}
