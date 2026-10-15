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
 */
final class EnvironmentChecks {

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

		return self::evaluate( $opcache, $ewww, $lscache, $cloudflare );
	}

	/**
	 * @param array<string, mixed>|null $opcache    Resultado de opcache_get_status(false).
	 * @param array<string, bool>|null  $ewww       Opciones de EWWW (null si no está activo).
	 * @param array<string, bool>|null  $lscache    Opciones de LSCache (null si no está activo).
	 * @param bool                      $cloudflare El sitio pasa por Cloudflare.
	 * @return list<array{level: string, code: string, message: string}>
	 */
	public static function evaluate( ?array $opcache, ?array $ewww, ?array $lscache, bool $cloudflare ): array {
		$alerts = [];

		$op = null === $opcache ? null : self::opcache( $opcache );
		if ( null !== $op && $op['problem'] ) {
			$alerts[] = [
				'level'   => 'warning',
				'code'    => 'opcache_full',
				'message' => sprintf(
					/* translators: 1: MB usados, 2: MB totales, 3: % de cadenas internas usado, 4: % de aciertos */
					__( 'OPcache está lleno o casi lleno (%1$s de %2$s MB, cadenas internas al %3$s %%, aciertos %4$s %%): PHP recompila parte del código en cada visita sin caché y WordPress arranca varias veces más lento. Sube opcache.memory_consumption (por ejemplo a 512), opcache.interned_strings_buffer (64) y opcache.max_accelerated_files (100000) en la configuración de PHP del servidor, no en la de la aplicación, y reinicia PHP.', 'imagina-turbo' ),
					number_format_i18n( $op['used_mb'] ),
					number_format_i18n( $op['total_mb'] ),
					number_format_i18n( $op['strings_pct'] ),
					number_format_i18n( $op['hit_rate'], 1 )
				),
			];
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
	 * Lectura de opcache_get_status(): ¿hay un problema y con qué cifras?
	 *
	 * Problema: OPcache dice estar lleno, queda menos del 5 % de memoria, quedan menos del 2 % de las
	 * cadenas internas, o la tasa de aciertos es menor al 90 % con suficientes peticiones para medirla.
	 *
	 * @param array<string, mixed> $status Estado.
	 * @return array{problem: bool, used_mb: int, total_mb: int, strings_pct: int, hit_rate: float}
	 */
	public static function opcache( array $status ): array {
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

		$full       = true === ( $status['cache_full'] ?? false ) || ( $total > 0 && $free / $total < 0.05 );
		$stringsLow = $sBuffer > 0 && $sFree / $sBuffer < 0.02;
		$lowHits    = $lookups >= 50000 && $hitRate < 90;

		return [
			'problem'     => $full || $stringsLow || $lowHits,
			'used_mb'     => (int) round( $used / 1048576 ),
			'total_mb'    => (int) round( $total / 1048576 ),
			'strings_pct' => $sBuffer > 0 ? (int) round( 100 * ( $sBuffer - $sFree ) / $sBuffer ) : 0,
			'hit_rate'    => round( $hitRate, 1 ),
		];
	}
}
