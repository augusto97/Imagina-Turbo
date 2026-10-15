<?php
/**
 * Auditoría de la plataforma (0.11.1): ajustes de Elementor y WooCommerce que cambian la velocidad,
 * caché de objetos e índices de la base. Solo lectura, solo en el admin, a petición. Recomienda; no
 * cambia nada.
 *
 * Opciones verificadas en el código (2026-10-15):
 * - Elementor 4.3.5: experimentos con Plugin::$instance->experiments->is_feature_active()
 *   (e_font_icon_svg, e_optimized_markup); elementor_element_cache_ttl ("disable" = apagado),
 *   elementor_css_print_method, elementor_google_font ("1" por defecto), elementor_local_google_fonts,
 *   elementor_font_display, elementor_lazy_load_background_images ("1" por defecto) y
 *   elementor_load_fa4_shim ("yes" = cargando).
 * - WooCommerce 11.2: woocommerce_custom_orders_table_enabled, woocommerce_hpos_datastore_caching_enabled
 *   (función "HPOS Data Caching", solo con HPOS, recomendada con caché de objetos),
 *   woocommerce_attribute_lookup_enabled y su regeneración (_regeneration_in_progress, _aborted).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

/**
 * Lectura del entorno y evaluación pura.
 *
 * @phpstan-type Check array{id: string, label: string, status: string, advice: string}
 */
final class PlatformAudit {

	/** Filas de wp_postmeta desde las que conviene revisar los índices. */
	public const INDEX_ROWS = 100000;

	/**
	 * @return array{elementor: list<Check>|null, woocommerce: list<Check>|null, server: list<Check>}
	 */
	public static function current(): array {
		global $wpdb;
		$elementor = null;
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$experiments = class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->experiments ) ? \Elementor\Plugin::$instance->experiments : null;
			$active      = static fn( string $name ): ?bool => is_object( $experiments ) && is_callable( [ $experiments, 'is_feature_active' ] ) ? (bool) $experiments->is_feature_active( $name ) : null;
			$elementor   = self::elementor(
				[
					'icons_svg'        => $active( 'e_font_icon_svg' ),
					'optimized_markup' => $active( 'e_optimized_markup' ),
					'element_cache'    => 'disable' !== (string) get_option( 'elementor_element_cache_ttl', '' ),
					'css_print'        => (string) get_option( 'elementor_css_print_method', 'external' ),
					'google_fonts'     => '1' === (string) get_option( 'elementor_google_font', '1' ),
					'local_fonts'      => (bool) get_option( 'elementor_local_google_fonts', '0' ),
					'font_display'     => (string) get_option( 'elementor_font_display', 'auto' ),
					'lazy_bg'          => '1' === (string) get_option( 'elementor_lazy_load_background_images', '1' ),
					'fa4_shim'         => 'yes' === get_option( 'elementor_load_fa4_shim' ),
				]
			);
		}
		$persistent = (bool) wp_using_ext_object_cache();
		$woo        = null;
		if ( class_exists( 'WooCommerce' ) ) {
			$woo = self::woocommerce(
				[
					'hpos'           => 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ),
					'hpos_cache'     => 'yes' === get_option( 'woocommerce_hpos_datastore_caching_enabled' ),
					'lookup'         => 'yes' === get_option( 'woocommerce_attribute_lookup_enabled' ),
					'lookup_running' => 'yes' === get_option( 'woocommerce_attribute_lookup_regeneration_in_progress' ),
					'lookup_aborted' => 'yes' === get_option( 'woocommerce_attribute_lookup_regeneration_aborted' ),
				],
				$persistent
			);
		}
		$sql  = $wpdb->prepare( 'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->postmeta );
		$rows = null === $sql ? 0 : (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- consulta de diagnóstico en el admin, preparada arriba.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return [
			'elementor'   => $elementor,
			'woocommerce' => $woo,
			'server'      => self::server( $persistent, extension_loaded( 'redis' ) || extension_loaded( 'relay' ) || class_exists( 'Redis' ), $rows, is_plugin_active( 'index-wp-mysql-for-speed/index-wp-mysql-for-speed.php' ) ),
		];
	}

	/**
	 * @param array{icons_svg: bool|null, optimized_markup: bool|null, element_cache: bool, css_print: string, google_fonts: bool, local_fonts: bool, font_display: string, lazy_bg: bool, fa4_shim: bool} $v Ajustes.
	 * @return list<Check>
	 */
	public static function elementor( array $v ): array {
		$out   = [];
		$out[] = self::check( 'el_icons', __( 'Íconos en SVG en línea', 'imagina-turbo' ), false !== $v['icons_svg'], __( 'Activa «Inline Font Icons» (Elementor → Ajustes → Funciones): los íconos van como SVG y no se descargan las fuentes de íconos (Font Awesome, eicons).', 'imagina-turbo' ) );
		$out[] = self::check( 'el_cache', __( 'Caché de elementos', 'imagina-turbo' ), $v['element_cache'], __( 'Activa la caché de elementos (Elementor → Ajustes → Rendimiento): Elementor guarda el HTML de cada widget y no lo vuelve a construir en cada página sin caché.', 'imagina-turbo' ) );
		$out[] = self::check( 'el_css', __( 'CSS en archivo externo', 'imagina-turbo' ), 'internal' !== $v['css_print'], __( 'Usa «Archivo externo» como método de impresión del CSS (Elementor → Ajustes → Rendimiento): el navegador lo guarda y no viaja dentro de cada página.', 'imagina-turbo' ) );
		$out[] = self::check( 'el_fa4', __( 'Sin compatibilidad con Font Awesome 4', 'imagina-turbo' ), ! $v['fa4_shim'], __( 'Desactiva «Cargar compatibilidad con Font Awesome 4» (Elementor → Ajustes → Avanzado) si no usas íconos de la versión 4: es una hoja de estilos más en cada página.', 'imagina-turbo' ) );
		$out[] = self::check( 'el_display', __( 'font-display: swap', 'imagina-turbo' ), 'swap' === $v['font_display'], __( 'Usa «Swap» en la carga de fuentes de Google (Elementor → Ajustes → Avanzado): el texto se ve de inmediato con una fuente del sistema.', 'imagina-turbo' ) );
		$out[] = self::check( 'el_lazy_bg', __( 'Fondos diferidos', 'imagina-turbo' ), $v['lazy_bg'], __( 'Activa la carga diferida de imágenes de fondo (Elementor → Ajustes → Rendimiento).', 'imagina-turbo' ) );
		if ( $v['google_fonts'] ) {
			$out[] = self::check( 'el_fonts', __( 'Google Fonts en el propio servidor', 'imagina-turbo' ), $v['local_fonts'], __( 'Elementor pide las fuentes a Google: activa «Cargar Google Fonts localmente» (Elementor → Ajustes → Rendimiento) o desactívalas si el tema ya define las fuentes. Una conexión menos y sin enviar datos a Google.', 'imagina-turbo' ) );
		}
		if ( false === $v['optimized_markup'] ) {
			$out[] = [
				'id'     => 'el_markup',
				'label'  => __( 'Markup optimizado', 'imagina-turbo' ),
				'status' => 'info',
				'advice' => __( '«Optimized Markup» quita envoltorios del HTML. Pruébalo con capturas antes y después: cambia el HTML y en algunos temas descuadra secciones (en un sitio con WoodMart quitaba muy poco y movía una categoría).', 'imagina-turbo' ),
			];
		}
		return $out;
	}

	/**
	 * @param array{hpos: bool, hpos_cache: bool, lookup: bool, lookup_running: bool, lookup_aborted: bool} $v Ajustes.
	 * @return list<Check>
	 */
	public static function woocommerce( array $v, bool $persistentCache ): array {
		$out = [];
		if ( $v['hpos'] ) {
			$out[] = $persistentCache
				? self::check( 'wc_hpos_cache', __( 'Caché de pedidos (HPOS)', 'imagina-turbo' ), $v['hpos_cache'], __( 'Activa «HPOS Data Caching» (WooCommerce → Ajustes → Avanzado → Funciones): con caché de objetos, las pantallas de pedidos y la REST hacen muchas menos consultas.', 'imagina-turbo' ) )
				: [
					'id'     => 'wc_hpos_cache',
					'label'  => __( 'Caché de pedidos (HPOS)', 'imagina-turbo' ),
					'status' => 'info',
					'advice' => __( 'La caché de pedidos de WooCommerce rinde con una caché de objetos persistente (Redis), que este sitio no tiene.', 'imagina-turbo' ),
				];
		}
		if ( $v['lookup_running'] ) {
			$out[] = [
				'id'     => 'wc_lookup',
				'label'  => __( 'Tabla de atributos', 'imagina-turbo' ),
				'status' => 'info',
				'advice' => __( 'WooCommerce está regenerando la tabla de búsqueda de atributos.', 'imagina-turbo' ),
			];
		} elseif ( $v['lookup_aborted'] ) {
			$out[] = [
				'id'     => 'wc_lookup',
				'label'  => __( 'Tabla de atributos', 'imagina-turbo' ),
				'status' => 'warn',
				'advice' => __( 'La regeneración de la tabla de búsqueda de atributos se interrumpió: reanúdala en WooCommerce → Estado → Herramientas antes de usarla.', 'imagina-turbo' ),
			];
		} else {
			$out[] = self::check( 'wc_lookup', __( 'Tabla de atributos', 'imagina-turbo' ), $v['lookup'], __( 'Activa «Usar la tabla de búsqueda» (WooCommerce → Ajustes → Productos → Avanzado): los filtros por color, talla o presentación consultan una tabla en lugar de recorrer todas las variaciones. Si nunca se generó, regenérala primero en WooCommerce → Estado → Herramientas.', 'imagina-turbo' ) );
		}
		return $out;
	}

	/**
	 * @return list<Check>
	 */
	public static function server( bool $persistent, bool $redisAvailable, int $postmetaRows, bool $indexPlugin ): array {
		$out = [];
		if ( $persistent ) {
			$out[] = self::check( 'object_cache', __( 'Caché de objetos persistente', 'imagina-turbo' ), true, '' );
		} else {
			$out[] = [
				'id'     => 'object_cache',
				'label'  => __( 'Caché de objetos persistente', 'imagina-turbo' ),
				'status' => $redisAvailable ? 'warn' : 'info',
				'advice' => $redisAvailable
					? __( 'PHP tiene Redis pero WordPress no lo usa: actívalo (LiteSpeed Cache → Caché → Objeto, o el plugin Redis Object Cache). El admin, el carrito y los AJAX dejan de repetir consultas.', 'imagina-turbo' )
					: __( 'Sin caché de objetos persistente cada petición sin caché repite las mismas consultas. Pide Redis en el servidor.', 'imagina-turbo' ),
			];
		}
		if ( $indexPlugin ) {
			$out[] = self::check( 'indexes', __( 'Índices de la base de datos', 'imagina-turbo' ), true, '' );
		} elseif ( $postmetaRows >= self::INDEX_ROWS ) {
			$out[] = [
				'id'     => 'indexes',
				'label'  => __( 'Índices de la base de datos', 'imagina-turbo' ),
				'status' => 'warn',
				/* translators: %s: filas de wp_postmeta */
				'advice' => sprintf( __( 'wp_postmeta tiene %s filas. «Index WP MySQL For Speed» agrega índices compuestos a postmeta, usermeta y options y se puede revertir. Aplícalo con un respaldo y fuera de horas de tráfico: cambiar los índices bloquea la tabla mientras dura.', 'imagina-turbo' ), number_format_i18n( $postmetaRows ) ),
			];
		}
		return $out;
	}

	/**
	 * @return Check
	 */
	private static function check( string $id, string $label, bool $ok, string $advice ): array {
		return [
			'id'     => $id,
			'label'  => $label,
			'status' => $ok ? 'ok' : 'warn',
			'advice' => $ok ? '' : $advice,
		];
	}
}
