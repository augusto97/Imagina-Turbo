<?php
/**
 * Fases de la Rulesets API que gestiona el plugin.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Nombres de fase.
 */
final class Phases {

	public const CACHE = 'http_request_cache_settings';

	public const TRANSFORM = 'http_request_transform';

	public const WAF = 'http_request_firewall_custom';

	public const RATELIMIT = 'http_ratelimit';

	/** Fases que se leen y se guardan en los snapshots. */
	public const MANAGED = [ self::CACHE, self::TRANSFORM, self::WAF, self::RATELIMIT ];

	public static function label( string $phase ): string {
		return match ( $phase ) {
			self::CACHE     => __( 'Reglas de caché', 'imagina-turbo' ),
			self::TRANSFORM => __( 'Reescritura de URL', 'imagina-turbo' ),
			self::WAF       => __( 'Reglas WAF', 'imagina-turbo' ),
			self::RATELIMIT => __( 'Límite de peticiones', 'imagina-turbo' ),
			default         => $phase,
		};
	}

	public static function entrypoint( string $zoneId, string $phase ): string {
		return '/zones/' . rawurlencode( $zoneId ) . '/rulesets/phases/' . rawurlencode( $phase ) . '/entrypoint';
	}
}
