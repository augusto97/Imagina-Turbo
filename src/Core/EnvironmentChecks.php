<?php
/**
 * Revisiones del entorno que frenaron un sitio real (0.10.4). Solo admin/REST, nunca en el frontend.
 *
 * - OPcache lleno: con 128 MB y unos 800 MB de plugins y temas, la tasa de aciertos era del 44 % y
 *   WordPress tardaba 1,5 s en arrancar; con 512 MB, 0,35 s.
 * - EWWW Image Optimizer generaba WebP sin ningún método de entrega: el navegador seguía bajando los
 *   PNG originales (10 MB por página).
 * - LiteSpeed Cache con "Caché de la página de acceso": la copia en caché no envía la cookie de prueba
 *   de WordPress y el primer intento de acceso falla con "las cookies están bloqueadas".
 *
 * - 0.11.0, dos lazy load a la vez (EWWW y LiteSpeed Cache): EWWW no difería las 3 primeras imágenes
 *   (logo, cabecera) y LiteSpeed las difería igual; el logo era el LCP de una categoría (4,7 s en móvil;
 *   3,2 s con uno solo). Opciones: ewww_image_optimizer_lazy_load y litespeed.conf.media-lazy.
 *
 * Opciones verificadas en el código de los plugins (2026-10-14): EWWW Image Optimizer 8.9.0
 * (ewww_image_optimizer_webp, _webp_for_cdn, _picture_webp, _exactdn; bloque "EWWWIO" del .htaccess)
 * y LiteSpeed Cache 7.9.1 (opción litespeed.conf.cache-page_login, Root::name()).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

/**
 * Comprobaciones puras más el lector del entorno real.
 *
 * @phpstan-type OpcacheIni array{memory: int, strings: int, files: int}
 * @phpstan-type OpcacheReading array{problem: bool, causes: list<string>, warming: bool, used_mb: int, total_mb: int, strings_pct: int, hit_rate: float, keys: int, max_keys: int, oom_restarts: int, hash_restarts: int, ini: OpcacheIni}
 */
final class EnvironmentChecks {

	/** Valores de OPcache recomendados para WordPress con WooCommerce y constructores: MB, MB y archivos. */
	public const OPCACHE_RECOMMENDED = [
		'memory'  => 512,
		'strings' => 64,
		'files'   => 100000,
	];

	/**
	 * La tasa de aciertos de OPcache se cuenta desde el último arranque de PHP o reinicio de OPcache
	 * (opcache_reset(), que usa por ejemplo «Purgar todo» de LiteSpeed Cache): durante este tiempo
	 * es baja aunque todo esté bien, y no se juzga.
	 */
	public const OPCACHE_WARMUP = 21600;

	/**
	 * Alertas a partir del entorno real.
	 *
	 * @return list<array{level: string, code: string, message: string}>
	 */
	public static function current( bool $cloudflare ): array {
		$opcache = null;
		if ( function_exists( 'opcache_get_status' ) && (bool) ini_get( 'opcache.enable' ) ) {
			$status  = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- con opcache.restrict_api avisa y devuelve false.
			$opcache = is_array( $status ) ? $status : null;
		}

		$ewww = null;
		if ( defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) ) {
			$ewww = [];
			foreach ( [ 'webp', 'webp_for_cdn', 'picture_webp', 'exactdn', 'lazy_load' ] as $key ) {
				$ewww[ $key ] = (bool) get_option( 'ewww_image_optimizer_' . $key );
			}
			$htaccess         = ABSPATH . '.htaccess';
			$ewww['htaccess'] = is_readable( $htaccess ) && str_contains( (string) file_get_contents( $htaccess ), '# BEGIN EWWWIO' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo local.
		}

		$lscache = null;
		if ( defined( 'LSCWP_V' ) ) {
			$lscache = [
				'page_login' => (bool) get_option( 'litespeed.conf.cache-page_login' ),
				'lazy'       => (bool) get_option( 'litespeed.conf.media-lazy' ),
			];
		}

		$opcacheIni = [
			'memory'  => (int) ini_get( 'opcache.memory_consumption' ),
			'strings' => (int) ini_get( 'opcache.interned_strings_buffer' ),
			'files'   => (int) ini_get( 'opcache.max_accelerated_files' ),
		];

		$alerts = self::evaluate( $opcache, $ewww, $lscache, $cloudflare, $opcacheIni );
		if ( 'refused' === \ImaginaTurbo\Modules\Speculation\PrefetchProbe::cached() ) {
			$alerts[] = self::prefetchRefused();
		}
		if ( defined( 'LSCWP_V' ) && function_exists( 'WC' ) && null !== Runtime::$config && Runtime::$config->moduleEnabled( 'cache_coordinator' ) ) {
			$interval = self::litespeedWooInterval( get_option( 'litespeed.conf.wc_update_interval', 0 ) );
			if ( null !== $interval ) {
				$alerts[] = $interval;
			}
		}
		if ( function_exists( 'WC' ) && \ImaginaTurbo\Modules\WooCart\WooCartModule::iapiRefreshes() && self::themeHasMiniCartBlock() ) {
			$alerts[] = self::miniCartBlock( 'prerender' === \ImaginaTurbo\Modules\Speculation\SpeculationModule::settings()['mode'] );
		}
		return $alerts;
	}

	/**
	 * @param array<string, mixed>|null $opcache    Resultado de opcache_get_status(false).
	 * @param array<string, bool>|null  $ewww       Opciones de EWWW (null si no está activo).
	 * @param array<string, bool>|null  $lscache    Opciones de LSCache (null si no está activo).
	 * @param bool                      $cloudflare El sitio pasa por Cloudflare.
	 * @param array<string, int>        $opcacheIni memory_consumption, interned_strings_buffer y max_accelerated_files (0 = desconocido).
	 * @param int                       $now        Hora actual (0 = ahora).
	 * @return list<array{level: string, code: string, message: string}>
	 */
	public static function evaluate( ?array $opcache, ?array $ewww, ?array $lscache, bool $cloudflare, array $opcacheIni = [], int $now = 0 ): array {
		$alerts = [];

		$op = null === $opcache ? null : self::opcache( $opcache, $opcacheIni, $now );
		if ( null !== $op && $op['problem'] ) {
			$alerts[] = self::opcacheAlert( $op );
		}

		if ( null !== $ewww && true === ( $ewww['webp'] ?? false ) ) {
			$js      = true === ( $ewww['webp_for_cdn'] ?? false );
			$picture = true === ( $ewww['picture_webp'] ?? false );
			$easyIo  = true === ( $ewww['exactdn'] ?? false );
			$rewrite = true === ( $ewww['htaccess'] ?? false );
			if ( ! $js && ! $picture && ! $easyIo && ! $rewrite ) {
				$alerts[] = [
					'level'   => 'warning',
					'code'    => 'webp_not_served',
					'message' => __( 'EWWW Image Optimizer genera WebP pero no los entrega: los navegadores siguen descargando los JPG y PNG originales. En EWWW → WebP activa «Reescritura de WebP por JS» o «Reescritura Picture WebP».', 'imagina-turbo' ),
				];
			} elseif ( $rewrite && ! $js && ! $picture && ! $easyIo && $cloudflare ) {
				$alerts[] = [
					'level'   => 'warning',
					'code'    => 'webp_htaccess_cloudflare',
					'message' => __( 'EWWW entrega los WebP con reglas del .htaccess, pero el sitio pasa por Cloudflare: el edge guarda la primera versión que pide alguien y se la da a todos. Usa «Reescritura de WebP por JS» o «Reescritura Picture WebP».', 'imagina-turbo' ),
				];
			}
		}

		if ( null !== $lscache && true === ( $lscache['page_login'] ?? false ) ) {
			$alerts[] = [
				'level'   => 'warning',
				'code'    => 'login_cached',
				'message' => __( 'LiteSpeed Cache guarda en caché la página de acceso: la copia no envía la cookie de prueba de WordPress y el primer intento de inicio de sesión falla con «las cookies están bloqueadas». Desactiva LiteSpeed Cache → Caché → «Caché de la página de acceso».', 'imagina-turbo' ),
			];
		}

		if ( true === ( $ewww['lazy_load'] ?? false ) && true === ( $lscache['lazy'] ?? false ) ) {
			$alerts[] = [
				'level'   => 'warning',
				'code'    => 'double_lazy',
				'message' => __( 'EWWW Image Optimizer y LiteSpeed Cache difieren las imágenes a la vez: LiteSpeed difiere también las que EWWW deja cargar de inmediato (logo, cabecera) y la imagen principal llega tarde. Desactiva LiteSpeed Cache → Optimización de página → Medios → «Carga diferida de imágenes» y deja el de EWWW, que además entrega los WebP.', 'imagina-turbo' ),
			];
		}

		return $alerts;
	}

	/**
	 * LiteSpeed Cache → WooCommerce → «Intervalo de actualización del producto» (wc_update_interval,
	 * thirdparty/woocommerce.cls.php): con 0 (por defecto) o 3 purga el producto en cada cambio de
	 * cantidad, es decir en cada venta, y su purga de entrada aplica las reglas de purga automática
	 * (portada, páginas, categorías…). Imagina Turbo ya purga la página del producto cuando cambia la
	 * cantidad (0.14.1); con 1 LiteSpeed solo purga cuando cambia el estado del stock.
	 *
	 * @return array{level: string, code: string, message: string}|null
	 */
	public static function litespeedWooInterval( mixed $value ): ?array {
		if ( ! in_array( (int) $value, [ 0, 3 ], true ) ) {
			return null;
		}
		return [
			'level'   => 'info',
			'code'    => 'lscache_woo_interval',
			'message' => __( 'LiteSpeed Cache purga en cada venta la página del producto y, con sus reglas de purga automática, también la portada, las páginas y las categorías: esas páginas se regeneran sin necesidad. Imagina Turbo ya purga la página del producto cuando cambia la cantidad y todo lo que lo muestra cuando se agota o cambia de precio. En LiteSpeed Cache → Caché → WooCommerce → «Product Update Interval» (Intervalo de actualización del producto), elige «Purge product and categories only when the stock status changes» (purgar el producto y las categorías solo cuando cambie el estado del stock).', 'imagina-turbo' ),
		];
	}

	/**
	 * El tema de bloques pinta el Mini-Cart de WooCommerce en alguna parte de plantilla.
	 */
	public static function themeHasMiniCartBlock(): bool {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! function_exists( 'get_block_templates' ) ) {
			return false;
		}
		foreach ( [ 'wp_template_part', 'wp_template' ] as $type ) {
			foreach ( get_block_templates( [], $type ) as $template ) {
				if ( is_string( $template->content ) && str_contains( $template->content, 'wp:woocommerce/mini-cart' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * WooCommerce 10.7+: el Mini-Cart de bloques pide el carrito sin caché en cada vista.
	 *
	 * @return array{level: string, code: string, message: string}
	 */
	public static function miniCartBlock( bool $prerender ): array {
		$message = __( 'El Mini-Cart de bloques de WooCommerce (10.7 o posterior) pide el carrito a la Store API sin caché en cada vista, también con el carrito vacío: una petición PHP por página aunque el HTML salga de la caché. No es de Imagina Turbo y no se puede quitar sin romper el bloque; Imagina Turbo deja de sumar la suya.', 'imagina-turbo' );
		if ( $prerender ) {
			$message .= ' ' . __( 'Con la navegación en modo prerender, cada página precargada hace esa petición aunque nadie la abra: usa el modo prefetch en este sitio.', 'imagina-turbo' );
		}
		return [
			'level'   => $prerender ? 'warning' : 'info',
			'code'    => 'mini_cart_block',
			'message' => $message,
		];
	}

	/**
	 * Cloudflare (Speed Brain) rechaza las precargas de páginas que no están en su caché.
	 *
	 * @return array{level: string, code: string, message: string}
	 */
	public static function prefetchRefused(): array {
		return [
			'level'   => 'warning',
			'code'    => 'prefetch_refused',
			'message' => __( 'Cloudflare rechaza las precargas de páginas (Speed Brain activo y el HTML no se guarda en el edge): la navegación instantánea de Imagina Turbo no tiene efecto. Desactiva Speed Brain en Cloudflare → Velocidad → Optimización → Contenido, o activa la caché de HTML en Cloudflare, o usa el modo prerender en Navegación.', 'imagina-turbo' ),
		];
	}

	/**
	 * Lectura de opcache_get_status(): ¿hay un problema, por qué y con qué cifras?
	 *
	 * Causas (verificado en php-src 8.3, ext/opcache): memoria (queda menos del 5 %, OPcache dice estar
	 * lleno o se vació solo por falta de memoria: oom_restarts), cadenas internas (queda menos del 2 %),
	 * archivos (la tabla está al 95 % o se vació por llenarse: hash_restarts) y aciertos (menos del 90 %
	 * con suficientes peticiones). Un reinicio de OPcache o de PHP pone a cero hits y misses y anota
	 * last_restart_time o start_time: en las OPCACHE_WARMUP siguientes la tasa no se juzga.
	 *
	 * @param array<string, mixed> $status Estado.
	 * @param array<string, int>   $ini    memory (MB), strings (MB) y files de la configuración (0 = desconocido).
	 * @param int                  $now    Hora actual (0 = ahora).
	 * @return OpcacheReading
	 */
	public static function opcache( array $status, array $ini = [], int $now = 0 ): array {
		$memory  = is_array( $status['memory_usage'] ?? null ) ? $status['memory_usage'] : [];
		$used    = (float) ( $memory['used_memory'] ?? 0 );
		$free    = (float) ( $memory['free_memory'] ?? 0 );
		$wasted  = (float) ( $memory['wasted_memory'] ?? 0 );
		$total   = $used + $free + $wasted;
		$strings = is_array( $status['interned_strings_usage'] ?? null ) ? $status['interned_strings_usage'] : [];
		$sBuffer = (float) ( $strings['buffer_size'] ?? 0 );
		$sFree   = (float) ( $strings['free_memory'] ?? 0 );
		$stats   = is_array( $status['opcache_statistics'] ?? null ) ? $status['opcache_statistics'] : [];
		$hitRate = (float) ( $stats['opcache_hit_rate'] ?? 100 );
		$lookups = (float) ( $stats['hits'] ?? 0 ) + (float) ( $stats['misses'] ?? 0 );
		$keys    = (int) ( $stats['num_cached_keys'] ?? 0 );
		$maxKeys = (int) ( $stats['max_cached_keys'] ?? 0 );
		$oom     = (int) ( $stats['oom_restarts'] ?? 0 );
		$hash    = (int) ( $stats['hash_restarts'] ?? 0 );
		$since   = max( (int) ( $stats['start_time'] ?? 0 ), (int) ( $stats['last_restart_time'] ?? 0 ) );
		$now     = $now > 0 ? $now : time();
		$warming = $since > 0 && $now - $since < self::OPCACHE_WARMUP;

		$keysFull = ( $maxKeys > 0 && $keys / $maxKeys >= 0.95 ) || $hash > 0;
		// Lleno sin más detalle: la memoria es la causa habitual.
		$memory  = ( $total > 0 && $free / $total < 0.05 ) || $oom > 0 || ( true === ( $status['cache_full'] ?? false ) && ! $keysFull );
		$strings = $sBuffer > 0 && $sFree / $sBuffer < 0.02;
		$hits    = ! $warming && $lookups >= 50000 && $hitRate < 90;
		$causes  = array_keys(
			array_filter(
				[
					'memory'  => $memory,
					'strings' => $strings,
					'keys'    => $keysFull,
					'hits'    => $hits,
				]
			)
		);

		return [
			'problem'       => [] !== $causes,
			'causes'        => $causes,
			'warming'       => $warming,
			'used_mb'       => (int) round( $used / 1048576 ),
			'total_mb'      => (int) round( $total / 1048576 ),
			'strings_pct'   => $sBuffer > 0 ? (int) round( 100 * ( $sBuffer - $sFree ) / $sBuffer ) : 0,
			'hit_rate'      => round( $hitRate, 1 ),
			'keys'          => $keys,
			'max_keys'      => $maxKeys,
			'oom_restarts'  => $oom,
			'hash_restarts' => $hash,
			'ini'           => [
				'memory'  => max( 0, (int) ( $ini['memory'] ?? 0 ) ),
				'strings' => max( 0, (int) ( $ini['strings'] ?? 0 ) ),
				'files'   => max( 0, (int) ( $ini['files'] ?? 0 ) ),
			],
		];
	}

	/**
	 * Alerta con la causa real. Si el ajuste ya está en lo recomendado no se repite «sube a 512/64/100000»:
	 * se propone el siguiente paso. Si solo fallan los aciertos, no se habla de lleno ni de memoria.
	 *
	 * @param OpcacheReading $op Lectura.
	 * @return array{level: string, code: string, message: string}
	 */
	private static function opcacheAlert( array $op ): array {
		$rec   = self::OPCACHE_RECOMMENDED;
		$ini   = $op['ini'];
		$found = [];
		$fixes = [];
		if ( in_array( 'memory', $op['causes'], true ) ) {
			$found[] = $op['oom_restarts'] > 0
				/* translators: 1: veces, 2: MB usados, 3: MB totales */
				? sprintf( __( 'la memoria se llenó y OPcache se vació solo %1$s veces desde que arrancó PHP (ahora %2$s de %3$s MB)', 'imagina-turbo' ), number_format_i18n( $op['oom_restarts'] ), number_format_i18n( $op['used_mb'] ), number_format_i18n( $op['total_mb'] ) )
				/* translators: 1: MB usados, 2: MB totales */
				: sprintf( __( 'la memoria está llena (%1$s de %2$s MB)', 'imagina-turbo' ), number_format_i18n( $op['used_mb'] ), number_format_i18n( $op['total_mb'] ) );
			$fixes[] = $ini['memory'] >= $rec['memory']
				/* translators: 1: MB configurados, 2: MB sugeridos */
				? sprintf( __( 'opcache.memory_consumption ya está en %1$s MB: súbelo a %2$s o quita plugins y temas que no se usen', 'imagina-turbo' ), number_format_i18n( $ini['memory'] ), number_format_i18n( (int) ( $ini['memory'] * 1.5 ) ) )
				/* translators: %s: MB recomendados */
				: sprintf( __( 'sube opcache.memory_consumption a %s', 'imagina-turbo' ), number_format_i18n( $rec['memory'] ) );
		}
		if ( in_array( 'strings', $op['causes'], true ) ) {
			/* translators: %s: porcentaje usado */
			$found[] = sprintf( __( 'las cadenas internas están al %s %%', 'imagina-turbo' ), number_format_i18n( $op['strings_pct'] ) );
			$fixes[] = $ini['strings'] >= $rec['strings']
				/* translators: 1: MB configurados, 2: MB sugeridos */
				? sprintf( __( 'opcache.interned_strings_buffer ya está en %1$s MB: súbelo a %2$s', 'imagina-turbo' ), number_format_i18n( $ini['strings'] ), number_format_i18n( $ini['strings'] * 2 ) )
				/* translators: %s: MB recomendados */
				: sprintf( __( 'sube opcache.interned_strings_buffer a %s', 'imagina-turbo' ), number_format_i18n( $rec['strings'] ) );
		}
		if ( in_array( 'keys', $op['causes'], true ) ) {
			$found[] = $op['hash_restarts'] > 0
				/* translators: %s: veces */
				? sprintf( __( 'no cabían más archivos y OPcache se vació solo %s veces desde que arrancó PHP', 'imagina-turbo' ), number_format_i18n( $op['hash_restarts'] ) )
				/* translators: 1: archivos en caché, 2: máximo */
				: sprintf( __( 'no caben más archivos (%1$s de %2$s)', 'imagina-turbo' ), number_format_i18n( $op['keys'] ), number_format_i18n( $op['max_keys'] ) );
			$fixes[] = $ini['files'] >= $rec['files']
				/* translators: 1: archivos configurados, 2: archivos sugeridos */
				? sprintf( __( 'opcache.max_accelerated_files ya está en %1$s: súbelo a %2$s', 'imagina-turbo' ), number_format_i18n( $ini['files'] ), number_format_i18n( $ini['files'] * 2 ) )
				/* translators: %s: archivos recomendados */
				: sprintf( __( 'sube opcache.max_accelerated_files a %s', 'imagina-turbo' ), number_format_i18n( $rec['files'] ) );
		}

		if ( [] === $found ) {
			return [
				'level'   => 'info',
				'code'    => 'opcache_hits',
				'message' => sprintf(
					/* translators: 1: % de aciertos, 2: MB usados, 3: MB totales, 4: % de cadenas internas usado */
					__( 'OPcache acierta solo el %1$s %% de las veces aunque tiene espacio (%2$s de %3$s MB, cadenas internas al %4$s %%) y lleva más de 6 horas sin reiniciarse. Subir la memoria no lo arregla: los fallos suelen venir de archivos PHP que se crean o cambian sin parar, como los plugins que guardan su caché en archivos .php.', 'imagina-turbo' ),
					number_format_i18n( $op['hit_rate'], 1 ),
					number_format_i18n( $op['used_mb'] ),
					number_format_i18n( $op['total_mb'] ),
					number_format_i18n( $op['strings_pct'] )
				),
			];
		}

		return [
			'level'   => 'warning',
			'code'    => 'opcache_full',
			'message' => sprintf(
				/* translators: 1: causas, 2: % de aciertos, 3: qué cambiar */
				__( 'OPcache se queda corto: %1$s. PHP recompila parte del código en cada visita sin caché y WordPress arranca más lento (aciertos %2$s %%). En la configuración de PHP del servidor, no en la de la aplicación: %3$s; después reinicia PHP.', 'imagina-turbo' ),
				implode( '; ', $found ),
				number_format_i18n( $op['hit_rate'], 1 ),
				implode( '; ', $fixes )
			),
		];
	}
}
