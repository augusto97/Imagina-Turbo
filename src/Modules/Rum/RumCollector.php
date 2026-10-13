<?php
/**
 * Endpoint RUM local (docs/02 módulo 11, "alternativa sin hub").
 *
 * POST /?itb-rum=1 con el beacon de itb-rum.js. Corre desde el loader MU, antes de cargar plugins y
 * tema; usa $wpdb, que WordPress ya cargó. Solo agrega contadores:
 * - itb_rum_hist: histogramas por hora, plantilla, dispositivo y métrica (LCP, INP, CLS, TTFB, tipo de
 *   navegación). El p75 sale de los histogramas.
 * - itb_rum_events: por día, el elemento LCP, la página siguiente y las causas de bfcache, con un mapa de
 *   64 bits del hash de la IP para estimar visitantes distintos. La IP no se guarda.
 *
 * Los beacons no se autentican: cualquiera puede mandar datos falsos. Por eso se valida el origen, se
 * limita por IP (si hay APCu) y lo que se usa para cambiar el sitio exige muchas muestras de muchos
 * visitantes distintos (RumInsights).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Rum;

/**
 * Validación, conversión a contadores y escritura.
 */
final class RumCollector {

	public const QUERY = 'itb-rum';

	public const MAX_BODY = 2048;

	/** Beacons por minuto e IP (con APCu). */
	public const RATE_LIMIT = 30;

	/** Tipos de navegación (métrica "nav"). */
	public const NAV = [
		'n' => 0, // navegación normal.
		'r' => 1, // recarga.
		'b' => 2, // atrás/adelante sin bfcache.
		'B' => 3, // restaurada desde bfcache.
		'p' => 4, // prerenderizada (activationStart > 0).
		'f' => 5, // servida desde prefetch.
	];

	/**
	 * Atiende el request y termina.
	 */
	public static function handle(): never {
		$status = 204;
		try {
			$status = self::process();
		} catch ( \Throwable ) {
			$status = 204;
		}
		if ( ! headers_sent() ) {
			http_response_code( $status );
			header( 'Cache-Control: no-store' );
			header( 'X-Robots-Tag: noindex' );
		}
		exit;
	}

	private static function process(): int {
		$host   = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
		$origin = (string) ( $_SERVER['HTTP_ORIGIN'] ?? ( $_SERVER['HTTP_REFERER'] ?? '' ) );
		if ( '' === $host || ! self::sameHost( $origin, $host ) ) {
			return 403;
		}
		$body = file_get_contents( 'php://input', false, null, 0, self::MAX_BODY + 1 );
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_BODY ) {
			return 400;
		}
		$beacon = json_decode( $body, true );
		if ( ! is_array( $beacon ) ) {
			return 400;
		}
		$ip = self::clientIp( $_SERVER );
		if ( ! self::allow( $ip ) ) {
			return 429;
		}
		$rows = self::rows( $beacon, self::ipBit( $ip, gmdate( 'Y-m-d' ) ), time() );
		if ( [] === $rows['hist'] && [] === $rows['events'] ) {
			return 400;
		}
		self::write( $rows );
		return 204;
	}

	/**
	 * Convierte un beacon en filas de contadores. Lo que no valida se descarta.
	 *
	 * @param array<mixed> $b Beacon.
	 * @return array{hist: list<array{0: string, 1: string, 2: string, 3: string, 4: int}>, events: list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>}
	 */
	public static function rows( array $b, string $ipBit, int $now ): array {
		$out      = [
			'hist'   => [],
			'events' => [],
		];
		$template = self::template( $b['t'] ?? null );
		$device   = ( $b['d'] ?? '' ) === 'd' ? 'd' : 'm';
		if ( '' === $template ) {
			return $out;
		}
		$hour = gmdate( 'Y-m-d H:00:00', $now );
		$day  = gmdate( 'Y-m-d', $now );
		foreach ( [
			'lcp'  => [ $b['l'] ?? null, 25, 400 ],
			'inp'  => [ $b['i'] ?? null, 10, 300 ],
			'ttfb' => [ $b['b'] ?? null, 25, 400 ],
			'cls'  => [ $b['c'] ?? null, 10, 100 ],
		] as $metric => [ $value, $step, $max ] ) {
			if ( is_int( $value ) && $value >= 0 && $value <= 120000 ) {
				$out['hist'][] = [ $hour, $template, $device, $metric, min( $max, intdiv( $value, $step ) ) ];
			}
		}
		$nav = is_string( $b['n'] ?? null ) && isset( self::NAV[ $b['n'] ] ) ? self::NAV[ $b['n'] ] : null;
		if ( null !== $nav ) {
			$out['hist'][] = [ $hour, $template, $device, 'nav', $nav ];
		}
		if ( 'f' === ( $b['lr'] ?? null ) ) {
			$out['events'][] = [ $day, 'lcp', $template, $device, '#featured', $ipBit ];
		} else {
			$lcp = self::path( $b['lu'] ?? null );
			if ( '' !== $lcp ) {
				$out['events'][] = [ $day, 'lcp', $template, $device, $lcp, $ipBit ];
			}
		}
		$from = self::template( $b['pf'] ?? null );
		$to   = self::path( $b['pu'] ?? null );
		if ( '' !== $from && '' !== $to ) {
			$out['events'][] = [ $day, 'next', $from, $device, $to, $ipBit ];
		}
		foreach ( array_slice( is_array( $b['x'] ?? null ) ? $b['x'] : [], 0, 5 ) as $reason ) {
			if ( is_string( $reason ) && 1 === preg_match( '/^[a-z0-9_\- ]{1,40}$/i', $reason ) ) {
				$out['events'][] = [ $day, 'nrr', $template, $device, strtolower( $reason ), $ipBit ];
			}
		}
		return $out;
	}

	/**
	 * Plantilla de RouteKey::template() ("portada", "single:product", "tax:product_cat"…).
	 */
	public static function template( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9:_\-]{1,40}$/', $value ) ? $value : '';
	}

	/**
	 * Ruta relativa del mismo sitio (sin query ni fragmento), hasta 120 caracteres.
	 */
	public static function path( mixed $value ): string {
		if ( ! is_string( $value ) || ! str_starts_with( $value, '/' ) || str_starts_with( $value, '//' ) ) {
			return '';
		}
		$path = (string) strtok( $value, '?#' );
		return strlen( $path ) <= 120 && 1 === preg_match( '#^/[A-Za-z0-9\-._~/%:@+]*$#', $path ) ? $path : '';
	}

	public static function sameHost( string $url, string $host ): bool {
		$parsed = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- loader MU.
		$port   = parse_url( $url, PHP_URL_PORT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- loader MU.
		if ( ! is_string( $parsed ) ) {
			return false;
		}
		$candidate = strtolower( $parsed ) . ( is_int( $port ) ? ':' . $port : '' );
		return $candidate === $host || strtolower( $parsed ) === $host;
	}

	/**
	 * IP del visitante: detrás de Cloudflare, la de CF-Connecting-IP. Solo para el límite y el hash.
	 *
	 * @param array<mixed> $server $_SERVER.
	 */
	public static function clientIp( array $server ): string {
		$ip = isset( $server['HTTP_CF_RAY'] ) && is_string( $server['HTTP_CF_CONNECTING_IP'] ?? null ) ? $server['HTTP_CF_CONNECTING_IP'] : ( $server['REMOTE_ADDR'] ?? '' );
		return is_string( $ip ) ? $ip : '';
	}

	/**
	 * Un bit (de 64) a partir del hash de la IP con una sal diaria: permite estimar visitantes distintos
	 * sin guardar nada que identifique a nadie.
	 */
	public static function ipBit( string $ip, string $day ): string {
		$salt = defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : 'itb';
		$bit  = (int) hexdec( substr( hash( 'sha256', $day . '|' . $ip . '|' . $salt ), 0, 4 ) ) % 64;
		// Como cadena decimal: 2^63 no cabe en un entero con signo de PHP (la columna es BIGINT UNSIGNED).
		return 63 === $bit ? '9223372036854775808' : (string) ( 1 << $bit );
	}

	/**
	 * Visitantes distintos estimados a partir del mapa de bits (conteo lineal, m = 64).
	 */
	public static function distinct( string $bits ): int {
		$set   = self::popcount( $bits );
		$zeros = 64 - $set;
		if ( 0 === $zeros ) {
			return 64 * 4; // Saturado: muchos.
		}
		return (int) round( -64 * log( $zeros / 64 ) );
	}

	/**
	 * Bits a 1 de un BIGINT UNSIGNED que llega como cadena decimal (puede pasar de PHP_INT_MAX).
	 */
	public static function popcount( string $bits ): int {
		$value = ltrim( $bits, '0' );
		if ( '' === $value || ! ctype_digit( $value ) || strlen( $value ) > 20 ) {
			return 0;
		}
		$count = 0;
		// División larga entre 2: cada resto es un bit.
		while ( '' !== $value ) {
			$quotient = '';
			$rem      = 0;
			foreach ( str_split( $value ) as $digit ) {
				$current   = $rem * 10 + (int) $digit;
				$quotient .= (string) intdiv( $current, 2 );
				$rem       = $current % 2;
			}
			$count += $rem;
			$value  = ltrim( $quotient, '0' );
		}
		return $count;
	}

	private static function allow( string $ip ): bool {
		if ( ! function_exists( 'apcu_inc' ) || '' === $ip ) {
			return true;
		}
		$key   = 'itb_rum_' . md5( $ip ) . '_' . intdiv( time(), 60 );
		$count = apcu_inc( $key, 1, $success, 120 );
		return ! is_int( $count ) || $count <= self::RATE_LIMIT;
	}

	/**
	 * Dos consultas como máximo.
	 *
	 * @param array{hist: list<array{0: string, 1: string, 2: string, 3: string, 4: int}>, events: list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>} $rows Filas.
	 */
	private static function write( array $rows ): void {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}
		if ( [] !== $rows['hist'] ) {
			$values = [];
			foreach ( $rows['hist'] as $row ) {
				$values[] = (string) $wpdb->prepare( '(%s, %s, %s, %s, %d, 1)', $row[0], $row[1], $row[2], $row[3], $row[4] );
			}
			$wpdb->query( 'INSERT INTO ' . $wpdb->prefix . 'itb_rum_hist (hour, template, device, metric, bucket, n) VALUES ' . implode( ', ', $values ) . ' ON DUPLICATE KEY UPDATE n = n + 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- tabla propia, cada tupla preparada.
		}
		if ( [] !== $rows['events'] ) {
			$values = [];
			foreach ( $rows['events'] as $row ) {
				$values[] = (string) $wpdb->prepare( '(%s, %s, %s, %s, %s, 1, %s)', $row[0], $row[1], $row[2], $row[3], $row[4], $row[5] );
			}
			$wpdb->query( 'INSERT INTO ' . $wpdb->prefix . 'itb_rum_events (day, kind, template, device, value, n, ip_bits) VALUES ' . implode( ', ', $values ) . ' ON DUPLICATE KEY UPDATE n = n + 1, ip_bits = ip_bits | VALUES(ip_bits)' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- tabla propia, cada tupla preparada.
		}
	}
}
