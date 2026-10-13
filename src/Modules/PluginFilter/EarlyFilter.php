<?php
/**
 * Carga condicional de plugins (docs/02 módulo 4): quita plugins de active_plugins en este request.
 *
 * Corre desde el loader MU, sin autoloader: no referencia otras clases del plugin salvo Runtime (ya
 * cargada). Las protecciones son las que documenta el código de Perfmatters MU:
 * 1. option_active_plugins (y active_sitewide_plugins en multisite) con prioridad 1.
 * 2. Sin filtrar si wp_is_mobile aún no existe: otro MU plugin leyó la opción antes de vars.php.
 * 3. pre_update_option_active_plugins con PHP_INT_MAX reinserta los ocultos: si un plugin lee la lista
 *    filtrada y la guarda (Freemius lo hace), los ocultos quedarían desactivados para siempre.
 * 4. pre_update_option_rewrite_rules devuelve el valor anterior mientras haya ocultos; si rewrite_rules
 *    está vacía (WordPress la regenerará en este request), no se filtra nada.
 * 5. Comparación por carpeta exacta (woocommerce/ no es woocommerce-memberships/); los plugins de un
 *    archivo, por nombre de archivo.
 *
 * Decisión de la fase 5 (docs/02 módulo 4, punto 6): los filtros de lectura y de escritura se mantienen
 * todo el request. Así is_plugin_active() coincide con lo cargado y una escritura tardía (shutdown,
 * wp_loaded) tampoco puede perder plugins.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

use ImaginaTurbo\Core\Runtime;

/**
 * Filtros de active_plugins.
 */
final class EarlyFilter {

	/** @var list<string> Plugins a quitar (archivo principal relativo a plugins/). */
	private static array $remove = [];

	/** @var list<string> Lo que se quitó de verdad en este request. */
	private static array $hidden = [];

	/** @var list<string> Lo que se quitó de active_sitewide_plugins. */
	private static array $hiddenSitewide = [];

	/** Se decidió no filtrar en este request (rewrite_rules vacía). */
	private static bool $skip = false;

	private static bool $rewriteChecked = false;

	/** Cuenta para Runtime::$pluginsFiltered (no en experimentos: sus fallos se miden aparte). */
	private static bool $countsForSafeMode = true;

	/**
	 * @param list<string> $remove           Plugins a quitar.
	 * @param bool         $countsForSafeMode false en experimentos.
	 */
	public static function install( array $remove, bool $countsForSafeMode = true ): void {
		self::$remove            = array_values( array_filter( $remove, 'is_string' ) );
		self::$countsForSafeMode = $countsForSafeMode;
		if ( [] === self::$remove || ! function_exists( 'add_filter' ) ) {
			return;
		}
		add_filter( 'option_active_plugins', [ self::class, 'filterActive' ], 1 );
		add_filter( 'site_option_active_sitewide_plugins', [ self::class, 'filterSitewide' ], 1 );
		add_filter( 'pre_update_option_active_plugins', [ self::class, 'protectWrite' ], PHP_INT_MAX, 2 );
		add_filter( 'pre_update_site_option_active_sitewide_plugins', [ self::class, 'protectSitewideWrite' ], PHP_INT_MAX, 2 );
		add_filter( 'pre_update_option_rewrite_rules', [ self::class, 'protectRewrite' ], PHP_INT_MAX, 2 );
	}

	/**
	 * @param mixed $plugins Valor de active_plugins.
	 * @return mixed
	 */
	public static function filterActive( mixed $plugins ): mixed {
		if ( ! is_array( $plugins ) || ! self::ready() ) {
			return $plugins;
		}
		$kept = [];
		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) && self::matches( $plugin, self::$remove ) ) {
				if ( ! in_array( $plugin, self::$hidden, true ) ) {
					self::$hidden[] = $plugin;
				}
				continue;
			}
			$kept[] = $plugin;
		}
		self::markFiltered();
		return $kept;
	}

	/**
	 * Multisite: active_sitewide_plugins es archivo => momento de activación.
	 *
	 * @param mixed $plugins Valor.
	 * @return mixed
	 */
	public static function filterSitewide( mixed $plugins ): mixed {
		if ( ! is_array( $plugins ) || ! self::ready() ) {
			return $plugins;
		}
		foreach ( array_keys( $plugins ) as $plugin ) {
			if ( is_string( $plugin ) && self::matches( $plugin, self::$remove ) ) {
				if ( ! in_array( $plugin, self::$hiddenSitewide, true ) ) {
					self::$hiddenSitewide[] = $plugin;
				}
				unset( $plugins[ $plugin ] );
			}
		}
		self::markFiltered();
		return $plugins;
	}

	/**
	 * Reinserta los ocultos en cualquier escritura de active_plugins de este request.
	 *
	 * @param mixed $value Valor nuevo.
	 * @param mixed $old   Valor anterior (filtrado).
	 * @return mixed
	 */
	public static function protectWrite( mixed $value, mixed $old ): mixed {
		unset( $old );
		return is_array( $value ) ? self::reinsert( $value, self::$hidden ) : $value;
	}

	/**
	 * @param mixed $value Valor nuevo.
	 * @param mixed $old   Valor anterior.
	 * @return mixed
	 */
	public static function protectSitewideWrite( mixed $value, mixed $old ): mixed {
		if ( ! is_array( $value ) || [] === self::$hiddenSitewide ) {
			return $value;
		}
		$previous = is_array( $old ) ? $old : [];
		foreach ( self::$hiddenSitewide as $plugin ) {
			if ( ! isset( $value[ $plugin ] ) ) {
				$value[ $plugin ] = $previous[ $plugin ] ?? time();
			}
		}
		return $value;
	}

	/**
	 * Mientras falten plugins, WordPress no puede regenerar rewrite_rules (perdería sus rutas).
	 *
	 * @param mixed $value Valor nuevo.
	 * @param mixed $old   Valor anterior.
	 * @return mixed
	 */
	public static function protectRewrite( mixed $value, mixed $old ): mixed {
		return [] === self::$hidden && [] === self::$hiddenSitewide ? $value : $old;
	}

	/**
	 * @param array<mixed> $value  Lista nueva.
	 * @param list<string> $hidden Ocultos.
	 * @return array<mixed>
	 */
	public static function reinsert( array $value, array $hidden ): array {
		$added = false;
		foreach ( $hidden as $plugin ) {
			if ( ! in_array( $plugin, $value, true ) ) {
				$value[] = $plugin;
				$added   = true;
			}
		}
		if ( $added ) {
			// WordPress guarda la lista ordenada (activate_plugin hace sort()).
			$value = array_values( $value );
			sort( $value );
		}
		return $value;
	}

	/**
	 * Carpeta exacta ("woocommerce/" no coincide con "woocommerce-memberships/"); un plugin de un solo
	 * archivo, por su nombre.
	 *
	 * @param list<string> $remove Plugins a quitar.
	 */
	public static function matches( string $plugin, array $remove ): bool {
		$folder = self::folder( $plugin );
		foreach ( $remove as $target ) {
			$targetFolder = self::folder( $target );
			if ( '' === $folder || '' === $targetFolder ) {
				if ( $plugin === $target ) {
					return true;
				}
				continue;
			}
			if ( $folder === $targetFolder ) {
				return true;
			}
		}
		return false;
	}

	private static function folder( string $plugin ): string {
		$slash = strpos( $plugin, '/' );
		return false === $slash ? '' : substr( $plugin, 0, $slash );
	}

	/**
	 * @return list<string>
	 */
	public static function hidden(): array {
		return array_values( array_merge( self::$hidden, self::$hiddenSitewide ) );
	}

	/**
	 * Se puede filtrar: WordPress ya cargó vars.php y rewrite_rules existe.
	 */
	private static function ready(): bool {
		if ( self::$skip || ! function_exists( 'wp_is_mobile' ) ) {
			return false;
		}
		if ( ! self::$rewriteChecked ) {
			self::$rewriteChecked = true;
			if ( function_exists( 'get_option' ) ) {
				$rules = get_option( 'rewrite_rules' );
				if ( empty( $rules ) ) {
					self::$skip = true;
					return false;
				}
			}
		}
		return true;
	}

	private static function markFiltered(): void {
		if ( self::$countsForSafeMode && ( [] !== self::$hidden || [] !== self::$hiddenSitewide ) ) {
			Runtime::$pluginsFiltered = true;
		}
	}

	/**
	 * Solo pruebas.
	 */
	public static function reset(): void {
		self::$remove            = [];
		self::$hidden            = [];
		self::$hiddenSitewide    = [];
		self::$skip              = false;
		self::$rewriteChecked    = false;
		self::$countsForSafeMode = true;
	}
}
