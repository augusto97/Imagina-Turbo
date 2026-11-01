<?php
/**
 * Endpoint RUM local (docs/02 módulo 11, "alternativa sin hub").
 *
 * POST /?itb-rum=1 con el beacon de itb-rum.js. Corre desde el loader MU, antes de cargar plugins y
 * tema; usa $wpdb, que WordPress ya cargó. Solo agrega contadores:
 * - itb_rum_hist: histogramas por hora, plantilla, dispositivo y métrica (LCP, INP, CLS, TTFB, tipo de
 *   navegación, peso de la carga). El p75 sale de los histogramas.
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

use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Core\Safety\DataFile;

/**
 * Validación, conversión a contadores y escritura.
 */
final class RumCollector {

	public const QUERY = 'itb-rum';

	public const MAX_BODY = 2048;

	/** Beacons por minuto e IP (con APCu o caché de objetos). */
	public const RATE_LIMIT = 30;

	/**
	 * Beacons por minuto en todo el sitio, y filas de eventos (rutas de LCP, fuentes, siguiente página)
	 * nuevas por día (0.21.2). Sin estos topes, un bucle anónimo con valores al azar creaba filas sin
	 * fin. Con la muestra por defecto (5 %), 120 por minuto son 2.400 páginas vistas por minuto.
	 */
	public const GLOBAL_LIMIT = 120;

	public const EVENTS_PER_DAY = 50000;

	/** Rangos de Cloudflare (cloudflare.com/ips, revisados el 2026-11-01). */
	public const CLOUDFLARE_RANGES = [ '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32' ];

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
	 * Edad mínima (s) del HTML para contar la visita como servida desde una copia guardada. Por debajo,
	 * la página se generó para esa visita (con margen para el desfase del reloj del visitante).
	 */
	public const CACHED_AGE = 30;

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
		$rum       = Runtime::$config?->get( 'rum', [] );
		$templates = is_array( $rum ) && is_array( $rum['templates'] ?? null ) ? array_values( array_filter( $rum['templates'], 'is_string' ) ) : [];
		$rows      = self::rows( $beacon, self::ipBit( $ip, gmdate( 'Y-m-d' ) ), time(), $templates );
		if ( [] === $rows['hist'] && [] === $rows['events'] ) {
			return 400;
		}
		// Pasado el cupo del día, solo los histogramas (acotados por plantilla, métrica y hora).
		if ( [] !== $rows['events'] && self::counter( 'ev' . gmdate( 'Ymd' ), count( $rows['events'] ), 86400 ) > self::EVENTS_PER_DAY ) {
			$rows['events'] = [];
		}
		self::write( $rows );
		return 204;
	}

	/**
	 * Convierte un beacon en filas de contadores. Lo que no valida se descarta.
	 *
	 * @param array<mixed> $b         Beacon.
	 * @param list<string> $templates Plantillas conocidas (vacío = cualquiera).
	 * @return array{hist: list<array{0: string, 1: string, 2: string, 3: string, 4: int}>, events: list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>}
	 */
	public static function rows( array $b, string $ipBit, int $now, array $templates = [] ): array {
		$out      = [
			'hist'   => [],
			'events' => [],
		];
		$template = self::template( $b['t'] ?? null );
		// Solo plantillas que existen en el sitio (0.21.2; la config las lista).
		if ( [] !== $templates && ! in_array( $template, $templates, true ) ) {
			return $out;
		}
		$device = ( $b['d'] ?? '' ) === 'd' ? 'd' : 'm';
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
		// Peso de la carga (0.19.0): peticiones y KB de JS, CSS y total hasta el evento load.
		foreach ( [
			'req' => [ $b['q'] ?? null, 5, 200 ],
			'js'  => [ $b['j'] ?? null, 10, 300 ],
			'css' => [ $b['s'] ?? null, 5, 200 ],
			'kb'  => [ $b['kb'] ?? null, 50, 400 ],
		] as $metric => [ $value, $step, $max ] ) {
			if ( is_int( $value ) && $value >= 0 && $value <= 100000 ) {
				$out['hist'][] = [ $hour, $template, $device, $metric, min( $max, intdiv( $value, $step ) ) ];
			}
		}
		// Desglose del TTFB: conexión, espera del servidor (separada por copia guardada o generada) y la
		// parte de visitas servidas desde una copia (métrica "hit": 1 copia, 0 generada).
		$conn = $b['k'] ?? null;
		if ( is_int( $conn ) && $conn >= 0 && $conn <= 120000 ) {
			$out['hist'][] = [ $hour, $template, $device, 'conn', min( 400, intdiv( $conn, 25 ) ) ];
		}
		$age    = $b['a'] ?? null;
		$cached = is_int( $age ) && $age >= 0 && $age <= 34560000 ? $age >= self::CACHED_AGE : null;
		if ( null !== $cached ) {
			$out['hist'][] = [ $hour, $template, $device, 'hit', $cached ? 1 : 0 ];
		}
		$wait = $b['w'] ?? null;
		if ( is_int( $wait ) && $wait >= 0 && $wait <= 120000 ) {
			$out['hist'][] = [ $hour, $template, $device, null === $cached ? 'wait' : ( $cached ? 'waith' : 'waitm' ), min( 400, intdiv( $wait, 25 ) ) ];
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
		// Fuentes del propio sitio pedidas antes del LCP (0.15.0), hasta 2 por beacon.
		foreach ( array_slice( is_array( $b['fo'] ?? null ) ? $b['fo'] : [], 0, 2 ) as $font ) {
			$font = self::path( $font );
			if ( '' !== $font && 1 === preg_match( '/\.(woff2?|ttf|otf)$/i', $font ) ) {
				$out['events'][] = [ $day, 'font', $template, $device, $font, $ipBit ];
			}
		}
		$from = self::template( $b['pf'] ?? null );
		$from = [] === $templates || in_array( $from, $templates, true ) ? $from : '';
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
	 * IP del visitante. CF-Connecting-IP solo cuenta si la petición llega de una IP de Cloudflare
	 * (0.21.2): si el origen está abierto, cualquiera la falsifica. Si el servidor ya la traduce
	 * (mod_remoteip, «Use Client IP in Header» de LiteSpeed), REMOTE_ADDR ya es la del visitante.
	 *
	 * @param array<mixed> $server $_SERVER.
	 */
	public static function clientIp( array $server ): string {
		$remote = is_string( $server['REMOTE_ADDR'] ?? null ) ? trim( $server['REMOTE_ADDR'] ) : '';
		$cf     = is_string( $server['HTTP_CF_CONNECTING_IP'] ?? null ) ? trim( $server['HTTP_CF_CONNECTING_IP'] ) : '';
		if ( '' !== $cf && false !== filter_var( $cf, FILTER_VALIDATE_IP ) && self::fromCloudflare( $remote ) ) {
			return $cf;
		}
		return $remote;
	}

	/**
	 * La IP está en los rangos de Cloudflare.
	 */
	public static function fromCloudflare( string $ip ): bool {
		$bin = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
		if ( false === $bin ) {
			return false;
		}
		foreach ( self::CLOUDFLARE_RANGES as $range ) {
			[ $net, $bits ] = explode( '/', $range );
			$netBin         = inet_pton( $net );
			if ( false === $netBin || strlen( $netBin ) !== strlen( $bin ) ) {
				continue;
			}
			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			if ( substr( $bin, 0, $bytes ) !== substr( $netBin, 0, $bytes ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest || ( ( ord( $bin[ $bytes ] ) ^ ord( $netBin[ $bytes ] ) ) & ( 0xFF << ( 8 - $rest ) ) & 0xFF ) === 0 ) {
				return true;
			}
		}
		return false;
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

	/**
	 * Tope global por minuto y, si hay dónde contarlo, por IP.
	 */
	private static function allow( string $ip ): bool {
		$minute = intdiv( time(), 60 );
		if ( self::counter( 'm' . $minute, 1, 120 ) > self::GLOBAL_LIMIT ) {
			return false;
		}
		if ( '' === $ip || ! self::sharedMemory() ) {
			return true;
		}
		return self::counter( 'ip' . md5( $ip ) . '_' . $minute, 1, 120, false ) <= self::RATE_LIMIT;
	}

	private static function sharedMemory(): bool {
		return function_exists( 'apcu_inc' ) || ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_incr' ) );
	}

	/**
	 * Contador compartido entre peticiones: APCu, la caché de objetos o, sin ninguna, un archivo con
	 * bloqueo en el directorio de datos (solo los contadores globales: uno por IP no cabe en un archivo).
	 */
	private static function counter( string $key, int $by, int $ttl, bool $fileFallback = true ): int {
		if ( function_exists( 'apcu_inc' ) ) {
			$count = apcu_inc( 'itb_rum_' . $key, $by, $success, $ttl );
			if ( is_int( $count ) ) {
				return $count;
			}
		}
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_incr' ) ) {
			wp_cache_add( $key, 0, 'itb_rum', $ttl );
			$count = wp_cache_incr( $key, $by, 'itb_rum' );
			if ( is_int( $count ) ) {
				return $count;
			}
		}
		return $fileFallback ? self::fileCounter( $key, $by ) : 0;
	}

	/**
	 * Contadores en rum-limits.php (con guarda): solo los de la ventana actual (minuto y día).
	 */
	private static function fileCounter( string $key, int $by ): int {
		$dir = Runtime::$dataDir;
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}
		// Loader MU: sin autoloader.
		if ( ! class_exists( DataFile::class, false ) ) {
			require_once dirname( __DIR__, 2 ) . '/Core/Safety/DataFile.php';
		}
		$handle = fopen( $dir . '/rum-limits.php', 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- loader MU, contador con bloqueo.
		if ( false === $handle ) {
			return 0;
		}
		$count = 0;
		if ( flock( $handle, LOCK_EX ) ) {
			$data          = json_decode( DataFile::strip( (string) stream_get_contents( $handle ) ), true );
			$data          = is_array( $data ) ? $data : [];
			$kind          = (string) preg_replace( '/\d+$/', '', $key );
			$prev          = is_array( $data[ $kind ] ?? null ) && ( $data[ $kind ][0] ?? null ) === $key ? (int) ( $data[ $kind ][1] ?? 0 ) : 0;
			$count         = $prev + $by;
			$data[ $kind ] = [ $key, $count ];
			ftruncate( $handle, 0 );
			rewind( $handle );
			fwrite( $handle, DataFile::encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- loader MU.
			flock( $handle, LOCK_UN );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- idem.
		return $count;
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
