<?php
/**
 * Etiquetas de analítica del sitio (0.18.0): qué hay, de dónde sale y qué se puede pasar a Zaraz.
 *
 * Por las opciones de los plugins (sin pedir la página):
 * - Site Kit: googlesitekit_analytics-4_settings (measurementID, googleTagID, useSnippet) y
 *   googlesitekit_tagmanager_settings (containerID, useSnippet). Verificado en Site Kit 1.190.0.
 * - Meta for WooCommerce: wc_facebook_pixel_id (WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID,
 *   3.7.8).
 * - WooCommerce Google Analytics: woocommerce_google_analytics_settings → ga_id.
 * Y en el HTML de la portada (cualquier otro origen: el tema, un fragmento de código, otro plugin):
 * gtag/js?id=, config de gtag, contenedores GTM- y fbq('init').
 *
 * Qué se puede tomar sin tocar ajustes de otros plugins (filtros verificados en su código):
 * - GA4 de Site Kit: googlesitekit_analytics-4_tag_blocked (Module_Web_Tag::is_tag_blocked). Sin la
 *   etiqueta, Site Kit tampoco encola sus eventos de conversión (Conversion_Tracking exige que se haya
 *   impreso la de Analytics o la de Ads).
 * - Meta for WooCommerce: facebook_for_woocommerce_integration_pixel_enabled. En falso apaga el píxel
 *   y también sus eventos por la API de conversiones (WC_Facebookcommerce_EventsTracker no registra
 *   nada): sin duplicados con Zaraz, que los envía desde Cloudflare.
 * Un contenedor de Google Tag Manager no se puede pasar a Zaraz: se informa para revisarlo.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Integrations;

/**
 * Detección.
 */
final class AnalyticsTags {

	/**
	 * Lo que dicen las opciones de los plugins conocidos.
	 *
	 * @return array{ga4: list<array{id: string, source: string, takeover: bool}>, gtm: list<array{id: string, source: string}>, meta: list<array{id: string, source: string, takeover: bool}>}
	 */
	public static function fromPlugins(): array {
		$out = [
			'ga4'  => [],
			'gtm'  => [],
			'meta' => [],
		];
		if ( defined( 'GOOGLESITEKIT_VERSION' ) ) {
			$ga = get_option( 'googlesitekit_analytics-4_settings', [] );
			if ( is_array( $ga ) && is_string( $ga['measurementID'] ?? null ) && self::isGa4( $ga['measurementID'] ) && false !== ( $ga['useSnippet'] ?? true ) ) {
				$out['ga4'][] = [
					'id'       => strtoupper( $ga['measurementID'] ),
					'source'   => 'Site Kit',
					'takeover' => true,
				];
			}
			$tm = get_option( 'googlesitekit_tagmanager_settings', [] );
			if ( is_array( $tm ) && is_string( $tm['containerID'] ?? null ) && self::isGtm( $tm['containerID'] ) && false !== ( $tm['useSnippet'] ?? true ) ) {
				$out['gtm'][] = [
					'id'     => strtoupper( $tm['containerID'] ),
					'source' => 'Site Kit',
				];
			}
		}
		$wcga = get_option( 'woocommerce_google_analytics_settings', [] );
		if ( is_array( $wcga ) && is_string( $wcga['ga_id'] ?? null ) && self::isGa4( $wcga['ga_id'] ) ) {
			$out['ga4'][] = [
				'id'       => strtoupper( $wcga['ga_id'] ),
				'source'   => 'WooCommerce Google Analytics',
				'takeover' => false,
			];
		}
		if ( class_exists( 'WC_Facebookcommerce' ) || defined( 'WC_FACEBOOKCOMMERCE_PLUGIN_VERSION' ) ) {
			$pixel = get_option( 'wc_facebook_pixel_id', '' );
			if ( is_string( $pixel ) && self::isPixel( $pixel ) ) {
				$out['meta'][] = [
					'id'       => $pixel,
					'source'   => 'Meta for WooCommerce',
					'takeover' => true,
				];
			}
		}
		return $out;
	}

	/**
	 * Ids que aparecen en el HTML de una página.
	 *
	 * @return array{ga4: list<string>, google_tag: list<string>, gtm: list<string>, meta: list<string>}
	 */
	public static function scanHtml( string $html ): array {
		$find = static function ( string $pattern ) use ( $html ): array {
			preg_match_all( $pattern, $html, $m );
			return array_values( array_unique( array_map( 'strtoupper', $m[1] ) ) );
		};
		return [
			'ga4'        => $find( '/["\'=(,\s](G-[A-Z0-9]{6,12})\b/i' ),
			'google_tag' => $find( '/["\'=(,\s](GT-[A-Z0-9]{6,12})\b/i' ),
			'gtm'        => $find( '/\b(GTM-[A-Z0-9]{4,10})\b/i' ),
			'meta'       => array_values( array_unique( array_merge( $find( "/fbq\\(\\s*['\"]init['\"]\\s*,\\s*['\"](\\d{8,20})['\"]/" ), $find( '/"pixelId"\s*:\s*"(\d{8,20})"/' ) ) ) ),
		];
	}

	public static function isGa4( string $id ): bool {
		return 1 === preg_match( '/^G-[A-Z0-9]{6,12}$/i', $id );
	}

	public static function isGtm( string $id ): bool {
		return 1 === preg_match( '/^GTM-[A-Z0-9]{4,10}$/i', $id );
	}

	public static function isPixel( string $id ): bool {
		return 1 === preg_match( '/^\d{8,20}$/', $id );
	}
}
