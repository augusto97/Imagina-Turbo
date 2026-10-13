<?php
/**
 * Migración desde Perfmatters (fase 4): lee perfmatters_options y propone los ajustes equivalentes.
 *
 * Claves verificadas en el código de Perfmatters 2.5.0 y 2.6.6 (inc/settings.php, inc/functions.php e
 * inc/classes/General.php): las casillas se guardan como '1' (otros plugins escriben true), así que se
 * leen con !empty(). remove_wlwmanifest_link solo existe hasta la 2.1.6.
 *
 * Lo que no tiene equivalente se lista con el lugar donde vive en Imagina Turbo (o en el plugin de
 * caché, que es quien hace delay/defer de JS, CSS no usado y lazy load).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Tweaks;

/**
 * Lectura y mapeo.
 */
final class PerfmattersMigration {

	public const OPTION = 'perfmatters_options';

	/**
	 * @param array<mixed> $options Contenido de perfmatters_options.
	 * @return array{changes: array<string, mixed>, mapped: list<array{from: string, to: string, value: string}>, unmapped: list<array{from: string, where: string}>}
	 */
	public static function map( array $options ): array {
		$on      = static fn( string $key ): bool => ! empty( $options[ $key ] );
		$tweaks  = [];
		$changes = [];
		$mapped  = [];
		$add     = static function ( string $from, string $to, string $value ) use ( &$mapped ): void {
			$mapped[] = [
				'from'  => $from,
				'to'    => $to,
				'value' => $value,
			];
		};

		$direct = [
			'disable_emojis'                  => 'emojis',
			'remove_rest_api_links'           => 'rest_links',
			'disable_self_pingbacks'          => 'self_pingbacks',
			'disable_dashicons'               => 'dashicons',
			'disable_password_strength_meter' => 'password_meter',
			'disable_embeds'                  => 'embeds',
			'disable_rss_feeds'               => 'feeds',
			'disable_comments'                => 'comments',
			'remove_jquery_migrate'           => 'jquery_migrate',
		];
		foreach ( $direct as $from => $to ) {
			if ( $on( $from ) ) {
				$tweaks[ $to ] = true;
				$add( $from, 'tweaks.' . $to, 'on' );
			}
		}
		foreach ( [ 'hide_wp_version', 'remove_rsd_link', 'remove_shortlink', 'remove_wlwmanifest_link' ] as $from ) {
			if ( $on( $from ) ) {
				$tweaks['head_cleanup'] = true;
				$add( $from, 'tweaks.head_cleanup', 'on' );
			}
		}
		$fonts = is_array( $options['fonts'] ?? null ) ? $options['fonts'] : [];
		if ( ! empty( $fonts['disable_google_fonts'] ) ) {
			$tweaks['google_fonts'] = true;
			$add( 'fonts.disable_google_fonts', 'tweaks.google_fonts', 'on' );
		}

		// Revisiones: '' (sin cambio), 'false' (desactivadas) o un número.
		$revisions = $options['limit_post_revisions'] ?? '';
		if ( 'false' === $revisions || ( is_scalar( $revisions ) && ctype_digit( (string) $revisions ) ) ) {
			$tweaks['revisions']        = true;
			$changes['tweak_revisions'] = 'false' === $revisions ? 0 : (int) $revisions;
			$add( 'limit_post_revisions', 'tweak_revisions', (string) $changes['tweak_revisions'] );
		}
		// Autoguardado: '' (60 s) o segundos ('86400' = prácticamente desactivado).
		$autosave = $options['autosave_interval'] ?? '';
		if ( is_scalar( $autosave ) && ctype_digit( (string) $autosave ) && (int) $autosave >= 30 ) {
			$tweaks['autosave']        = true;
			$changes['tweak_autosave'] = (int) $autosave;
			$add( 'autosave_interval', 'tweak_autosave', (string) $autosave );
		}

		if ( $on( 'disable_xmlrpc' ) ) {
			$changes['shield_xmlrpc'] = true;
			$add( 'disable_xmlrpc', 'shield_xmlrpc', 'on' );
		}
		// Heartbeat: Imagina Turbo lo quita del frontend y espacia admin y editor; no lo apaga en el admin.
		$heartbeat = $options['disable_heartbeat'] ?? '';
		$frequency = $options['heartbeat_frequency'] ?? '';
		if ( '' !== $heartbeat || ( is_scalar( $frequency ) && ctype_digit( (string) $frequency ) ) ) {
			$changes['shield_heartbeat'] = true;
			if ( is_scalar( $frequency ) && ctype_digit( (string) $frequency ) ) {
				$changes['shield_heartbeat_admin']  = max( 15, min( 120, (int) $frequency ) );
				$changes['shield_heartbeat_editor'] = max( 15, min( 120, (int) $frequency ) );
			}
			$add( 'disable_heartbeat / heartbeat_frequency', 'shield_heartbeat', is_scalar( $heartbeat ) && '' !== (string) $heartbeat ? (string) $heartbeat : 'on' );
		}
		if ( [] !== $tweaks ) {
			$changes['tweaks'] = $tweaks;
		}

		$unmapped = [];
		$where    = [
			'disable_woocommerce_cart_fragmentation' => __( 'Módulo «Carrito por Store API» (reemplaza cart-fragments sin perder el contador).', 'imagina-turbo' ),
			'disable_woocommerce_scripts'            => __( 'Gestor de assets por ruta (fase 7).', 'imagina-turbo' ),
			'disable_woocommerce_status'             => __( 'Sin equivalente (widget del escritorio).', 'imagina-turbo' ),
			'disable_woocommerce_widgets'            => __( 'Sin equivalente.', 'imagina-turbo' ),
			'disable_google_maps'                    => __( 'Gestor de assets por ruta (fase 7).', 'imagina-turbo' ),
			'remove_comment_urls'                    => __( 'Sin equivalente.', 'imagina-turbo' ),
			'remove_feed_links'                      => __( 'Incluido en «Desactivar feeds RSS».', 'imagina-turbo' ),
			'disable_rest_api'                       => __( 'Escudo: bloquea /wp/v2/users a anónimos. Cerrar toda la REST rompe WooCommerce y el editor.', 'imagina-turbo' ),
			'login_url'                              => __( 'Sin equivalente: usa un plugin dedicado (la ruta se detecta sola).', 'imagina-turbo' ),
			'blank_favicon'                          => __( 'Sin equivalente.', 'imagina-turbo' ),
			'remove_global_styles'                   => __( 'Gestor de assets por ruta (fase 7).', 'imagina-turbo' ),
		];
		foreach ( $where as $key => $message ) {
			if ( ! empty( $options[ $key ] ) ) {
				$unmapped[] = [
					'from'  => $key,
					'where' => $message,
				];
			}
		}
		foreach ( [
			'assets'   => __( 'Delay/defer de JS, CSS no usado y minificación: el plugin de caché (LSCache o WP Rocket).', 'imagina-turbo' ),
			'lazyload' => __( 'Lazy load: el plugin de caché. Las pistas de LCP llegan con la fase 6.', 'imagina-turbo' ),
			'preload'  => __( 'Precarga y especulación: módulo «Navegación instantánea» (fase 6).', 'imagina-turbo' ),
			'cdn'      => __( 'CDN: Cloudflare ya sirve los estáticos.', 'imagina-turbo' ),
		] as $section => $message ) {
			$values = is_array( $options[ $section ] ?? null ) ? array_filter( $options[ $section ] ) : [];
			// cdn trae valores por defecto (directorios, exclusiones): solo cuenta si está activado.
			if ( 'cdn' === $section ) {
				$values = array_filter( [ $values['enable_cdn'] ?? null ] );
			}
			if ( [] !== $values ) {
				$unmapped[] = [
					'from'  => $section . '.*',
					'where' => $message,
				];
			}
		}
		return [
			'changes'  => $changes,
			'mapped'   => $mapped,
			'unmapped' => $unmapped,
		];
	}
}
