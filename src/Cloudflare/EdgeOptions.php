<?php
/**
 * Ajustes manuales de las reglas (0.23.0): excepciones, acción por regla, límite de peticiones,
 * escáneres y países del firewall; rutas, cookies y TTL de estáticos de la caché.
 *
 * Nada de esto se escribe como expresión: son valores validados que las plantillas ponen entre
 * comillas (Templates::quote) o como IP normalizada, así que no pueden romper la sintaxis ni salirse
 * de la regla. Al guardar, las entradas no válidas se descartan; problems() dice cuáles para avisar
 * antes, en lugar de borrarlas en silencio.
 *
 * Verificado en la documentación de Cloudflare (2026-11-17):
 * - `ip.src in {203.0.113.0/24 203.0.113.1}`: IPs y rangos CIDR sin comillas (operadores).
 * - `ip.src.country`: código ISO 3166-1 alfa-2 ("GB"). Bloquear o permitir países con reglas
 *   personalizadas está disponible en todos los planes (con IP Access rules, solo en Enterprise).
 *   Ejemplo de la documentación para permitir solo algunos: `(not ip.src.country in {"US" "MX"})`.
 * - Límite de peticiones: Free solo mira la ruta y el bot verificado, con periodo y bloqueo de 10 s;
 *   Pro, hasta 1 min y 1 h; Business, hasta 10 min y 1 día, y puede mirar la IP de origen. Los valores
 *   exactos por plan viven en data/cloudflare-limits.json (PlanLimits::ratelimit()).
 * - Edge TTL mínimo: Free 2 h, Pro 1 h, Business y Enterprise 1 s. Las opciones de los estáticos
 *   empiezan en 1 día, así que valen en todos los planes.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Validación y normalización.
 *
 * @phpstan-type FirewallOptions array{allow_ips: list<string>, allow_paths: list<string>, actions: array<string, string>, bots_extra: list<string>, bots_off: list<string>, countries: list<string>, country_mode: string, rl_requests: int, rl_period: int, rl_timeout: int, rl_paths: list<string>}
 * @phpstan-type CacheOptions array{bypass_paths: list<string>, bypass_cookies: list<string>, static_ttl: int}
 */
final class EdgeOptions {

	/** Acción de una regla: desafío 7 días y después bloqueo, siempre desafío o siempre bloqueo. */
	public const ACTIONS = [ 'auto', 'challenge', 'block' ];

	/** Reglas del firewall que aceptan una acción elegida. */
	public const ACTION_RULES = [ 'exploits', 'surface', 'bots', 'facets', 'countries' ];

	/** «listed»: se desafían o bloquean esos países; «others»: todos menos esos. */
	public const COUNTRY_MODES = [ 'listed', 'others' ];

	public const MAX_IPS = 30;

	public const MAX_PATHS = 20;

	public const MAX_BOTS = 30;

	public const MAX_COUNTRIES = 60;

	public const MAX_RL_PATHS = 10;

	public const MAX_CACHE_PATHS = 30;

	public const MAX_CACHE_COOKIES = 10;

	public const RL_REQUESTS_MIN = 2;

	public const RL_REQUESTS_MAX = 1000;

	/** Periodos y tiempos de bloqueo que existen en algún plan (cada plan admite un subconjunto). */
	public const RL_PERIODS = [ 10, 60, 120, 300, 600, 3600 ];

	public const RL_TIMEOUTS = [ 10, 60, 120, 300, 600, 3600, 86400 ];

	/** 1 día, 7 días, 30 días (el de siempre) y 1 año. */
	public const STATIC_TTLS = [ 86400, 604800, 2592000, 31536000 ];

	public const STATIC_TTL_DEFAULT = 2592000;

	/**
	 * Rangos más amplios que estos dejarían media Internet fuera del firewall: no se aceptan.
	 */
	private const MIN_PREFIX_V4 = 16;

	private const MIN_PREFIX_V6 = 32;

	/**
	 * User agents de navegadores y crawlers legítimos (en minúsculas). Un escáner propio cuyo texto
	 * aparezca en alguno bloquearía a personas o a Google, así que se rechaza.
	 */
	private const SAFE_USER_AGENTS = [
		'mozilla/5.0 (windows nt 10.0; win64; x64) applewebkit/537.36 (khtml, like gecko) chrome/131.0.0.0 safari/537.36',
		'mozilla/5.0 (windows nt 10.0; win64; x64) applewebkit/537.36 (khtml, like gecko) chrome/131.0.0.0 safari/537.36 edg/131.0.0.0',
		'mozilla/5.0 (macintosh; intel mac os x 10.15; rv:133.0) gecko/20100101 firefox/133.0',
		'mozilla/5.0 (macintosh; intel mac os x 10_15_7) applewebkit/605.1.15 (khtml, like gecko) version/18.1 safari/605.1.15',
		'mozilla/5.0 (iphone; cpu iphone os 18_1 like mac os x) applewebkit/605.1.15 (khtml, like gecko) version/18.1 mobile/15e148 safari/604.1',
		'mozilla/5.0 (linux; android 14; sm-s918b) applewebkit/537.36 (khtml, like gecko) chrome/131.0.0.0 mobile safari/537.36',
		'mozilla/5.0 (compatible; googlebot/2.1; +http://www.google.com/bot.html)',
		'mozilla/5.0 (linux; android 6.0.1; nexus 5x build/mmb29p) applewebkit/537.36 (khtml, like gecko) chrome/131.0.0.0 mobile safari/537.36 (compatible; googlebot/2.1; +http://www.google.com/bot.html)',
		'mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
		'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
		'whatsapp/2.23.20.0',
		'wordpress/6.8; https://example.com',
		'imaginaturbo-warmer/1.0',
		'imaginaturbo-connector/1.0',
	];

	/**
	 * @return FirewallOptions
	 */
	public static function firewallDefaults(): array {
		return [
			'allow_ips'    => [],
			'allow_paths'  => [],
			'actions'      => [],
			'bots_extra'   => [],
			'bots_off'     => [],
			'countries'    => [],
			'country_mode' => 'listed',
			'rl_requests'  => 5,
			'rl_period'    => 10,
			// 0: lo de siempre (10 s en Free, 10 minutos en los demás planes).
			'rl_timeout'   => 0,
			'rl_paths'     => [],
		];
	}

	/**
	 * @return CacheOptions
	 */
	public static function cacheDefaults(): array {
		return [
			'bypass_paths'   => [],
			'bypass_cookies' => [],
			'static_ttl'     => self::STATIC_TTL_DEFAULT,
		];
	}

	/**
	 * Opciones del firewall completas y válidas.
	 *
	 * @param array<mixed> $input Datos crudos.
	 * @return FirewallOptions
	 */
	public static function firewall( array $input ): array {
		$out                = self::firewallDefaults();
		$out['allow_ips']   = self::listOf( $input['allow_ips'] ?? [], [ self::class, 'ip' ], self::MAX_IPS );
		$out['allow_paths'] = self::listOf( $input['allow_paths'] ?? [], [ self::class, 'path' ], self::MAX_PATHS );
		foreach ( is_array( $input['actions'] ?? null ) ? $input['actions'] : [] as $id => $action ) {
			if ( in_array( $id, self::ACTION_RULES, true ) && in_array( $action, self::ACTIONS, true ) ) {
				$out['actions'][ (string) $id ] = (string) $action;
			}
		}
		$out['bots_extra'] = self::listOf( $input['bots_extra'] ?? [], [ self::class, 'userAgent' ], self::MAX_BOTS );
		$off               = is_array( $input['bots_off'] ?? null ) ? $input['bots_off'] : [];
		$out['bots_off']   = array_values( array_filter( FirewallTemplates::BAD_BOTS, static fn( string $bot ): bool => in_array( $bot, $off, true ) ) );
		$out['countries']  = self::listOf( $input['countries'] ?? [], [ self::class, 'country' ], self::MAX_COUNTRIES );
		if ( in_array( $input['country_mode'] ?? null, self::COUNTRY_MODES, true ) ) {
			$out['country_mode'] = (string) $input['country_mode'];
		}
		if ( is_numeric( $input['rl_requests'] ?? null ) ) {
			$out['rl_requests'] = (int) max( self::RL_REQUESTS_MIN, min( self::RL_REQUESTS_MAX, (int) $input['rl_requests'] ) );
		}
		if ( in_array( (int) ( $input['rl_period'] ?? 0 ), self::RL_PERIODS, true ) ) {
			$out['rl_period'] = (int) $input['rl_period'];
		}
		$timeout = (int) ( $input['rl_timeout'] ?? 0 );
		if ( 0 === $timeout || in_array( $timeout, self::RL_TIMEOUTS, true ) ) {
			$out['rl_timeout'] = $timeout;
		}
		$out['rl_paths'] = self::listOf( $input['rl_paths'] ?? [], [ self::class, 'path' ], self::MAX_RL_PATHS );
		return $out;
	}

	/**
	 * Opciones de la caché completas y válidas.
	 *
	 * @param array<mixed> $input Datos crudos.
	 * @return CacheOptions
	 */
	public static function cache( array $input ): array {
		$out                   = self::cacheDefaults();
		$out['bypass_paths']   = self::listOf( $input['bypass_paths'] ?? [], [ self::class, 'path' ], self::MAX_CACHE_PATHS );
		$out['bypass_cookies'] = self::listOf( $input['bypass_cookies'] ?? [], [ self::class, 'cookie' ], self::MAX_CACHE_COOKIES );
		if ( in_array( (int) ( $input['static_ttl'] ?? 0 ), self::STATIC_TTLS, true ) ) {
			$out['static_ttl'] = (int) $input['static_ttl'];
		}
		return $out;
	}

	/**
	 * Entradas que no se pueden guardar, para avisar en el admin (vacío si todo vale).
	 *
	 * @param array<mixed> $input Datos crudos.
	 * @param string       $kind  'firewall' o 'cache'.
	 * @return list<string>
	 */
	public static function problems( array $input, string $kind ): array {
		$checks = 'firewall' === $kind
			? [
				'allow_ips'   => [ [ self::class, 'ip' ], self::MAX_IPS, __( 'IP o rango no válido (los rangos, como mucho /16 en IPv4 y /32 en IPv6)', 'imagina-turbo' ) ],
				'allow_paths' => [ [ self::class, 'path' ], self::MAX_PATHS, __( 'Ruta no válida (debe empezar por / y no puede ser la portada)', 'imagina-turbo' ) ],
				'bots_extra'  => [ [ self::class, 'userAgent' ], self::MAX_BOTS, __( 'User agent no válido o que también aparece en navegadores o en Google', 'imagina-turbo' ) ],
				'countries'   => [ [ self::class, 'country' ], self::MAX_COUNTRIES, __( 'Código de país no válido (dos letras, como CO o MX)', 'imagina-turbo' ) ],
				'rl_paths'    => [ [ self::class, 'path' ], self::MAX_RL_PATHS, __( 'Ruta no válida (debe empezar por / y no puede ser la portada)', 'imagina-turbo' ) ],
			]
			: [
				'bypass_paths'   => [ [ self::class, 'path' ], self::MAX_CACHE_PATHS, __( 'Ruta no válida (debe empezar por / y no puede ser la portada)', 'imagina-turbo' ) ],
				'bypass_cookies' => [ [ self::class, 'cookie' ], self::MAX_CACHE_COOKIES, __( 'Nombre de cookie no válido', 'imagina-turbo' ) ],
			];
		$out    = [];
		foreach ( $checks as $key => [ $check, $max, $message ] ) {
			$items = is_array( $input[ $key ] ?? null ) ? $input[ $key ] : [];
			$valid = 0;
			foreach ( $items as $item ) {
				if ( is_string( $item ) && '' === trim( $item ) ) {
					continue;
				}
				if ( ! is_string( $item ) || null === $check( $item ) ) {
					$out[] = $message . ': ' . ( is_string( $item ) ? substr( trim( $item ), 0, 80 ) : '?' );
					continue;
				}
				++$valid;
			}
			if ( $valid > $max ) {
				/* translators: %d: máximo de entradas. */
				$out[] = sprintf( __( 'Demasiadas entradas: el máximo es %d por lista.', 'imagina-turbo' ), $max );
			}
		}
		return $out;
	}

	/**
	 * IP o rango CIDR, normalizado (dirección de red, IPv6 comprimida). Null si no vale.
	 */
	public static function ip( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 50 ) {
			return null;
		}
		$parts  = explode( '/', $value );
		$prefix = null;
		if ( count( $parts ) > 2 ) {
			return null;
		}
		if ( 2 === count( $parts ) ) {
			if ( 1 !== preg_match( '/^\d{1,3}$/', $parts[1] ) ) {
				return null;
			}
			$prefix = (int) $parts[1];
		}
		$packed = filter_var( $parts[0], FILTER_VALIDATE_IP ) ? inet_pton( $parts[0] ) : false;
		if ( false === $packed ) {
			return null;
		}
		$bits = 8 * strlen( $packed );
		if ( null === $prefix || $prefix === $bits ) {
			return (string) inet_ntop( $packed );
		}
		if ( $prefix > $bits || $prefix < ( 32 === $bits ? self::MIN_PREFIX_V4 : self::MIN_PREFIX_V6 ) ) {
			return null;
		}
		// Dirección de red: los bits de host a cero (192.0.2.7/24 → 192.0.2.0/24).
		$network = '';
		for ( $i = 0, $n = strlen( $packed ); $i < $n; $i++ ) {
			$keep     = max( 0, min( 8, $prefix - 8 * $i ) );
			$mask     = 0 === $keep ? 0 : ( 0xFF << ( 8 - $keep ) ) & 0xFF;
			$network .= chr( ord( $packed[ $i ] ) & $mask );
		}
		return inet_ntop( $network ) . '/' . $prefix;
	}

	/**
	 * Ruta tal como se ve en la URL (sin dominio ni query), en minúsculas y sin barra final. Nunca la
	 * portada: una excepción o una exclusión de "/" valdría para todo el sitio.
	 */
	public static function path( string $value ): ?string {
		$value = strtolower( trim( $value ) );
		if ( 1 === preg_match( '#^https?://#', $value ) ) {
			$value = (string) preg_replace( '#^https?://[^/]+#', '', $value );
		}
		$value = rtrim( $value, '/' );
		if ( '' === $value || 1 !== preg_match( '#^/[a-z0-9_\-./~%]{1,119}$#', $value ) || str_contains( $value, '..' ) || str_contains( $value, '//' ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Texto de un user agent a bloquear (en minúsculas). Nunca uno que aparezca en navegadores o en
	 * los crawlers de buscadores y redes sociales.
	 */
	public static function userAgent( string $value ): ?string {
		$value = strtolower( trim( $value ) );
		if ( 1 !== preg_match( '#^[a-z0-9][a-z0-9 ._\-/+()]{2,39}$#', $value ) ) {
			return null;
		}
		foreach ( self::SAFE_USER_AGENTS as $agent ) {
			if ( str_contains( $agent, $value ) ) {
				return null;
			}
		}
		return $value;
	}

	/**
	 * Código de país ISO 3166-1 alfa-2 en mayúsculas, o T1 (Tor) y XX (desconocido) de Cloudflare.
	 */
	public static function country( string $value ): ?string {
		$value = strtoupper( trim( $value ) );
		return 1 === preg_match( '/^([A-Z]{2}|T1)$/', $value ) ? $value : null;
	}

	/**
	 * Prefijo de una cookie (como los de las cookies de sesión de WordPress).
	 */
	public static function cookie( string $value ): ?string {
		$value = trim( $value );
		return 1 === preg_match( '/^[A-Za-z0-9_\-.]{2,60}$/', $value ) ? $value : null;
	}

	/**
	 * La IP está en la lista (IPs exactas o rangos ya normalizados por ip()).
	 *
	 * @param list<string> $list IPs y rangos.
	 */
	public static function ipMatches( string $ip, array $list ): bool {
		$bin = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
		if ( false === $bin ) {
			return false;
		}
		foreach ( $list as $entry ) {
			$parts  = explode( '/', $entry );
			$net    = inet_pton( $parts[0] );
			$prefix = isset( $parts[1] ) ? (int) $parts[1] : 8 * strlen( $bin );
			if ( false === $net || strlen( $net ) !== strlen( $bin ) ) {
				continue;
			}
			$bytes = intdiv( $prefix, 8 );
			$rest  = $prefix % 8;
			if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $rest || 0 === ( ( ord( $bin[ $bytes ] ) ^ ord( $net[ $bytes ] ) ) & ( 0xFF << ( 8 - $rest ) ) & 0xFF ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * La regla de países, con estas opciones, bloquearía (no solo desafiaría) a quien está guardando:
	 * su país queda dentro y su IP no está en las excepciones. País vacío o desconocido: no se sabe.
	 *
	 * @param array{allow_ips: list<string>, actions: array<string, string>, countries: list<string>, country_mode: string} $opts Opciones normalizadas.
	 */
	public static function locksOut( array $opts, string $country, string $ip ): bool {
		$action = $opts['actions']['countries'] ?? 'challenge';
		if ( 'challenge' === $action || '' === $country || 'XX' === $country || [] === $opts['countries'] ) {
			return false;
		}
		if ( '' !== $ip && self::ipMatches( $ip, $opts['allow_ips'] ) ) {
			return false;
		}
		$listed = in_array( $country, $opts['countries'], true );
		return 'others' === $opts['country_mode'] ? ! $listed : $listed;
	}

	/**
	 * Mayor valor permitido que no pase del elegido; si todos lo pasan, el menor.
	 *
	 * @param list<int> $allowed Valores del plan.
	 */
	public static function pick( array $allowed, int $wanted ): int {
		sort( $allowed );
		$best = $allowed[0] ?? $wanted;
		foreach ( $allowed as $value ) {
			if ( $value <= $wanted ) {
				$best = $value;
			}
		}
		return $best;
	}

	/**
	 * Lista sin repetidos de las entradas válidas, hasta el máximo.
	 *
	 * @param mixed                     $items Entradas.
	 * @param callable(string): ?string $check Normalizador.
	 * @return list<string>
	 */
	private static function listOf( mixed $items, callable $check, int $max ): array {
		$out = [];
		foreach ( is_array( $items ) ? $items : [] as $item ) {
			$clean = is_string( $item ) ? $check( $item ) : null;
			if ( null !== $clean ) {
				$out[ $clean ] = true;
			}
		}
		return array_slice( array_map( 'strval', array_keys( $out ) ), 0, $max );
	}
}
