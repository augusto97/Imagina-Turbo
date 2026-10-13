<?php
/**
 * Recalentado: vuelve a pedir las páginas purgadas (y, tras purgar todo, las del sitemap) para que
 * la próxima visita encuentre la caché llena.
 *
 * Una URL a la vez, en lotes cortos de Action Scheduler; se pausa si la carga del servidor supera
 * núcleos × 0,7. Respeta las exclusiones de precarga de la matriz (trampas de crawler, enlaces que
 * no son páginas) y nunca pide carrito, checkout, cuenta, login ni URLs con acciones.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

use ImaginaTurbo\Repository\PurgeQueueRepository;
use ImaginaTurbo\Support\Jobs;

/**
 * Recalentado por lotes.
 */
final class Warmer {

	public const STATE_OPTION = 'itb_warm_state';

	public const USER_AGENT = 'ImaginaTurbo-Warmer/1.0';

	public const BATCH = 10;

	public const MAX_SEED = 500;

	/** Fracción de los núcleos por encima de la cual se pausa. */
	public const LOAD_FACTOR = 0.7;

	/** Parámetros que nunca se recalientan (acciones, no páginas). */
	private const BAD_QUERY = [ 'add-to-cart', 'remove_item', '_wpnonce', 'action', 'wc-ajax', 'preview', 's', 'replytocom' ];

	/**
	 * @param list<string> $patterns  Exclusiones de precarga de la matriz (expresiones regulares).
	 * @param list<string> $paths     Rutas que nunca se piden (carrito, checkout, cuenta, login).
	 * @param list<string> $sitemaps  Sitemaps de la matriz para el plugin SEO activo.
	 */
	public function __construct(
		private readonly PurgeQueueRepository $queue,
		private readonly array $patterns,
		private readonly array $paths,
		private readonly array $sitemaps,
	) {
	}

	/**
	 * Agrega URLs al recalentado.
	 *
	 * @param list<string> $urls URLs absolutas.
	 */
	public function enqueue( array $urls ): int {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$ok   = array_values( array_filter( $urls, fn( string $url ): bool => ! self::excluded( $url, $host, $this->patterns, $this->paths ) ) );
		if ( [] === $ok ) {
			return 0;
		}
		$added = $this->queue->add( PurgeQueueRepository::KIND_WARM, $ok );
		self::schedule( Jobs::WARM, 3 );
		return $added;
	}

	/**
	 * Recalentado completo: URLs del sitemap (el del plugin SEO o el del núcleo).
	 */
	public function seed(): int {
		$urls = [ home_url( '/' ) ];
		foreach ( array_merge( $this->sitemaps, [ '/wp-sitemap.xml' ] ) as $path ) {
			$found = $this->fromSitemap( home_url( $path ), 2 );
			if ( [] !== $found ) {
				$urls = array_merge( $urls, $found );
				break;
			}
		}
		$added = $this->enqueue( array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_SEED ) );
		$this->saveState( [ 'seeded_at' => time() ] );
		return $added;
	}

	/**
	 * Un lote. Devuelve cuántas URLs pidió.
	 */
	public function run( ?float $load = null, ?int $cores = null ): int {
		$load  = $load ?? self::load();
		$cores = $cores ?? self::cores();
		if ( null !== $load && self::overloaded( $load, $cores ) ) {
			$this->saveState(
				[
					'paused_at' => time(),
					'load'      => $load,
				]
			);
			self::schedule( Jobs::WARM, 60 );
			return 0;
		}

		$rows   = $this->queue->drain( [ PurgeQueueRepository::KIND_WARM ], self::BATCH );
		$done   = 0;
		$errors = 0;
		$ms     = 0.0;
		foreach ( $rows as $row ) {
			$start    = microtime( true );
			$response = wp_remote_get(
				$row['item'],
				[
					'timeout'     => 15,
					'redirection' => 0,
					'user-agent'  => self::USER_AGENT,
					'cookies'     => [],
					'headers'     => [ 'Accept' => 'text/html' ],
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
				]
			);
			$ms      += ( microtime( true ) - $start ) * 1000;
			++$done;
			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 400 ) {
				++$errors;
			}
		}

		$state = $this->state();
		$this->saveState(
			[
				'last_run'  => time(),
				'warmed'    => $state['warmed'] + $done,
				'errors'    => $state['errors'] + $errors,
				'avg_ms'    => $done > 0 ? (int) round( $ms / $done ) : $state['avg_ms'],
				'paused_at' => 0,
			]
		);
		if ( $this->queue->counts()[ PurgeQueueRepository::KIND_WARM ] > 0 ) {
			self::schedule( Jobs::WARM, 2 );
		}
		return $done;
	}

	/**
	 * Detiene el recalentado (vacía lo pendiente).
	 */
	public function stop(): int {
		if ( function_exists( 'as_unschedule_all_actions' ) && Jobs::actionSchedulerReady() ) {
			as_unschedule_all_actions( Jobs::WARM, [], Jobs::GROUP );
		}
		return $this->queue->clear( PurgeQueueRepository::KIND_WARM );
	}

	/**
	 * @return array{last_run: int, warmed: int, errors: int, avg_ms: int, paused_at: int, load: float, seeded_at: int}
	 */
	public function state(): array {
		$stored = get_option( self::STATE_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		return [
			'last_run'  => (int) ( $stored['last_run'] ?? 0 ),
			'warmed'    => (int) ( $stored['warmed'] ?? 0 ),
			'errors'    => (int) ( $stored['errors'] ?? 0 ),
			'avg_ms'    => (int) ( $stored['avg_ms'] ?? 0 ),
			'paused_at' => (int) ( $stored['paused_at'] ?? 0 ),
			'load'      => (float) ( $stored['load'] ?? 0 ),
			'seeded_at' => (int) ( $stored['seeded_at'] ?? 0 ),
		];
	}

	/**
	 * La URL no se recalienta.
	 *
	 * @param list<string> $patterns Exclusiones de la matriz.
	 * @param list<string> $paths    Rutas prohibidas.
	 */
	public static function excluded( string $url, string $host, array $patterns, array $paths ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( $host ) ) {
			return true;
		}
		$path = isset( $parts['path'] ) ? strtolower( $parts['path'] ) : '/';
		if ( str_contains( $path, '/wp-admin' ) || str_contains( $path, 'wp-login.php' ) || 1 === preg_match( '/\.(pdf|zip|jpe?g|png|gif|webp|avif|svg|mp4|mp3|xml|txt|css|js)$/', $path ) ) {
			return true;
		}
		foreach ( $paths as $blocked ) {
			$blocked = rtrim( strtolower( $blocked ), '/' );
			if ( '' !== $blocked && ( rtrim( $path, '/' ) === $blocked || str_starts_with( $path, $blocked . '/' ) ) ) {
				return true;
			}
		}
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			foreach ( self::BAD_QUERY as $name ) {
				if ( array_key_exists( $name, $query ) ) {
					return true;
				}
			}
		}
		foreach ( $patterns as $pattern ) {
			$regex = '#' . str_replace( '#', '\#', $pattern ) . '#i';
			if ( 1 === @preg_match( $regex, $url ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- patrones de terceros: uno inválido no debe romper el recalentado.
				return true;
			}
		}
		return false;
	}

	public static function overloaded( ?float $load, int $cores ): bool {
		return null !== $load && $load > max( 1, $cores ) * self::LOAD_FACTOR;
	}

	/**
	 * URLs de un sitemap (sigue índices hasta $depth niveles).
	 *
	 * @return list<string>
	 */
	public static function parseSitemap( string $xml ): array {
		if ( ! preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)\s*(?:\]\]>)?\s*</loc>#i', $xml, $m ) ) {
			return [];
		}
		return array_values( array_unique( array_map( static fn( string $u ): string => html_entity_decode( $u, ENT_QUOTES | ENT_XML1 ), $m[1] ) ) );
	}

	/**
	 * @return list<string>
	 */
	private function fromSitemap( string $url, int $depth ): array {
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 15,
				'user-agent' => self::USER_AGENT,
				'sslverify'  => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
			]
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}
		$body = (string) wp_remote_retrieve_body( $response );
		$locs = self::parseSitemap( $body );
		if ( ! str_contains( $body, '<sitemapindex' ) ) {
			return $locs;
		}
		if ( $depth <= 1 ) {
			return [];
		}
		$urls = [];
		foreach ( array_slice( $locs, 0, 20 ) as $child ) {
			$urls = array_merge( $urls, $this->fromSitemap( $child, $depth - 1 ) );
			if ( count( $urls ) >= self::MAX_SEED ) {
				break;
			}
		}
		return $urls;
	}

	/**
	 * @param array<string, int|float> $changes Cambios.
	 */
	private function saveState( array $changes ): void {
		update_option( self::STATE_OPTION, array_merge( $this->state(), $changes ), false );
	}

	public static function schedule( string $hook, int $delay ): void {
		if ( Jobs::actionSchedulerReady() ) {
			if ( false === as_has_scheduled_action( $hook, [], Jobs::GROUP ) ) {
				as_schedule_single_action( time() + $delay, $hook, [], Jobs::GROUP );
			}
		} elseif ( false === wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + $delay, $hook );
		}
	}

	private static function load(): ?float {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return null;
		}
		$load = sys_getloadavg();
		return is_array( $load ) ? (float) $load[0] : null;
	}

	private static function cores(): int {
		$info = @file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo del sistema, puede no existir.
		if ( is_string( $info ) ) {
			$n = substr_count( $info, "\nprocessor" ) + ( str_starts_with( $info, 'processor' ) ? 1 : 0 );
			if ( $n > 0 ) {
				return $n;
			}
		}
		return 1;
	}
}
