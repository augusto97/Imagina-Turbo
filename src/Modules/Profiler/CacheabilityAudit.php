<?php
/**
 * Auditoría de cacheabilidad (Set-Cookie y bfcache, lado servidor).
 *
 * Pide como visitante anónimo (loopback, sin cookies) unas pocas páginas representativas y revisa
 * las cabeceras: cookies enviadas a anónimos y Cache-Control privado o no-store. Corre desde el admin
 * o el cron diario; no tiene costo en el frontend.
 *
 * 0.10.5: si la primera respuesta trae cookies, la página se pide otra vez. Cuando la segunda llega
 * sin cookies (la sirvió la caché de página, que guarda la copia sin Set-Cookie), la cookie solo la
 * recibe quien regenera la página y no impide la caché: queda como nota, no como problema. Visto en
 * un sitio real con _fbp (Meta for WooCommerce) y se-recently-viewed-products (Searchanise). Una
 * página que el plugin de caché marca no-cache a propósito (resultados de búsqueda, formularios) es
 * también una nota.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Profiler;

use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Support\CloudflareAccess;

/**
 * Ejecución y análisis de la auditoría.
 */
final class CacheabilityAudit {

	public const OPTION = 'itb_audit_last';

	/** Cookies conocidas → origen probable. Se compara por prefijo. */
	private const KNOWN_COOKIES = [
		'woocommerce_recently_viewed' => 'WooCommerce: widget de productos vistos (wc_track_product_view). Se puede reemplazar por localStorage (fase 3).',
		'wp_woocommerce_session_'     => 'WooCommerce inicia sesión para visitantes sin carrito.',
		'woocommerce_items_in_cart'   => 'Carrito de WooCommerce.',
		'woocommerce_cart_hash'       => 'Carrito de WooCommerce.',
		'PHPSESSID'                   => 'Algún plugin llama a session_start() en el frontend.',
		'pll_language'                => 'Polylang: detección del idioma del navegador.',
		'_icl_current_language'       => 'WPML: idioma actual.',
		'wpml_browser_redirect_test'  => 'WPML: redirección por idioma del navegador.',
		'wordpress_test_cookie'       => 'Página de login o formulario que comprueba cookies.',
		'_fbp'                        => 'Meta (Facebook): píxel o API de conversiones, p. ej. Meta for WooCommerce o PixelYourSite.',
		'_fbc'                        => 'Meta (Facebook): identificador de clic de anuncios.',
		'se-recently-viewed'          => 'Smart Search & Product Filter (Searchanise): productos vistos.',
		'woodmart_recently_viewed'    => 'WoodMart: productos vistos recientemente.',
		'yith_ywraq_'                 => 'YITH Request a Quote: lista de cotización.',
		'sbjs_'                       => 'WooCommerce: atribución de pedidos (sourcebuster).',
		'pys_'                        => 'PixelYourSite.',
		'cmplz_'                      => 'Complianz: consentimiento de cookies.',
		'cookielawinfo'               => 'CookieYes / GDPR Cookie Consent.',
	];

	public function __construct( private readonly EventLog $events ) {
	}

	/**
	 * Páginas a revisar: portada, tienda, un producto, una categoría de producto, una entrada y una página.
	 *
	 * @return list<array{label: string, url: string}>
	 */
	public function targets(): array {
		$targets = [
			[
				'label' => __( 'Portada', 'imagina-turbo' ),
				'url'   => home_url( '/' ),
			],
		];
		$exclude = [ (int) get_option( 'page_on_front' ) ];

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
				$exclude[] = (int) wc_get_page_id( $page );
			}
			$shop = (int) wc_get_page_id( 'shop' );
			if ( $shop > 0 ) {
				$exclude[] = $shop;
				$this->push( $targets, __( 'Tienda', 'imagina-turbo' ), get_permalink( $shop ) );
			}
			$product = get_posts( self::latestQuery( 'product' ) );
			if ( isset( $product[0] ) && is_int( $product[0] ) ) {
				$this->push( $targets, __( 'Producto', 'imagina-turbo' ), get_permalink( $product[0] ) );
			}
			$terms = get_terms(
				[
					'taxonomy'   => 'product_cat',
					'number'     => 1,
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'DESC',
				]
			);
			if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
				$link = get_term_link( $terms[0] );
				$this->push( $targets, __( 'Categoría de producto', 'imagina-turbo' ), is_string( $link ) ? $link : false );
			}
		}

		$post = get_posts( self::latestQuery( 'post' ) );
		if ( isset( $post[0] ) && is_int( $post[0] ) ) {
			$this->push( $targets, __( 'Entrada', 'imagina-turbo' ), get_permalink( $post[0] ) );
		}
		$page = get_posts( array_merge( self::latestQuery( 'page' ), [ 'post__not_in' => array_values( array_filter( $exclude ) ) ] ) );
		if ( isset( $page[0] ) && is_int( $page[0] ) ) {
			$this->push( $targets, __( 'Página', 'imagina-turbo' ), get_permalink( $page[0] ) );
		}

		return $targets;
	}

	/**
	 * Ejecuta la auditoría, guarda el resultado y registra un evento.
	 *
	 * @return array{at: int, results: list<array<string, mixed>>, issues: int}
	 */
	public function run(): array {
		$results = [];
		$issues  = 0;
		foreach ( $this->targets() as $target ) {
			$response = wp_remote_get( $target['url'], self::requestArgs() );
			if ( CloudflareAccess::blockedResponse( $response ) ) {
				$result = [
					'label'   => $target['label'],
					'url'     => $target['url'],
					'status'  => 0,
					'verdict' => 'error',
					'issues'  => [ CloudflareAccess::message( $target['url'] ) ],
					'notes'   => [],
					'cookies' => [],
					'headers' => [],
				];
			} elseif ( is_wp_error( $response ) ) {
				$result = [
					'label'   => $target['label'],
					'url'     => $target['url'],
					'status'  => 0,
					'verdict' => 'error',
					'issues'  => [ $response->get_error_message() ],
					'notes'   => [],
					'cookies' => [],
					'headers' => [],
				];
			} else {
				$analysis = self::analyze( (int) wp_remote_retrieve_response_code( $response ), self::headersOf( $response ) );
				if ( [] !== $analysis['cookies'] && 200 === $analysis['status'] ) {
					// ¿La segunda visita llega de la caché, sin cookies?
					$again = wp_remote_get( $target['url'], self::requestArgs() );
					if ( ! is_wp_error( $again ) && ! CloudflareAccess::blockedResponse( $again ) ) {
						$analysis = self::reconcile( $analysis, (int) wp_remote_retrieve_response_code( $again ), self::headersOf( $again ) );
					}
				}
				$result = array_merge(
					[
						'label' => $target['label'],
						'url'   => $target['url'],
					],
					$analysis
				);
			}
			$issues   += count( $result['issues'] );
			$results[] = $result;
		}

		$report = [
			'at'      => time(),
			'results' => $results,
			'issues'  => $issues,
		];
		update_option( self::OPTION, $report, false );

		$this->events->log(
			'profiler',
			'audit',
			$issues > 0 ? EventLog::WARNING : EventLog::INFO,
			$issues > 0
				/* translators: %d: número de problemas */
				? sprintf( _n( 'Auditoría de cacheabilidad: %d problema.', 'Auditoría de cacheabilidad: %d problemas.', $issues, 'imagina-turbo' ), $issues )
				: __( 'Auditoría de cacheabilidad sin problemas.', 'imagina-turbo' ),
			[ 'pages' => count( $results ) ]
		);

		return $report;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function requestArgs(): array {
		return [
			'timeout'     => 10,
			'redirection' => 3,
			'cookies'     => [],
			'headers'     => [ 'User-Agent' => 'ImaginaTurbo-Audit/1.0' ],
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
		];
	}

	/**
	 * @param array<string, mixed>|\WP_Error $response Respuesta.
	 * @return array<string, mixed>
	 */
	private static function headersOf( array|\WP_Error $response ): array {
		$headers = [];
		if ( is_array( $response ) ) {
			foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
				$headers[ strtolower( (string) $name ) ] = $value;
			}
		}
		return $headers;
	}

	/**
	 * Primera respuesta con cookies + segunda petición: si la segunda llega sin cookies, las de la
	 * primera solo las recibe quien regenera la página y pasan a ser notas.
	 *
	 * @param array{status: int, verdict: string, issues: list<string>, notes: list<string>, cookies: list<array{name: string, hint: string}>, headers: array<string, string>} $first   Análisis de la primera.
	 * @param array<string, mixed>                                                                                                                                         $headers Cabeceras de la segunda.
	 * @return array{status: int, verdict: string, issues: list<string>, notes: list<string>, cookies: list<array{name: string, hint: string}>, headers: array<string, string>}
	 */
	public static function reconcile( array $first, int $status, array $headers ): array {
		$second = self::analyze( $status, $headers );
		if ( 200 !== $status || [] !== $second['cookies'] ) {
			return $first;
		}
		$names  = array_column( $first['cookies'], 'name' );
		$issues = [];
		foreach ( $first['issues'] as $issue ) {
			$isCookie = false;
			foreach ( $names as $name ) {
				if ( str_contains( $issue, '«' . $name . '»' ) ) {
					$isCookie = true;
				}
			}
			if ( ! $isCookie ) {
				$issues[] = $issue;
			}
		}
		$hit   = self::cacheHit( $headers );
		$notes = $first['notes'];
		/* translators: 1: cookies, 2: cabecera que indica la caché (puede ir vacía) */
		$notes[]          = sprintf( __( 'Solo la visita que regenera la página recibe %1$s: la siguiente llegó de la caché, sin cookies%2$s. No impide la caché.', 'imagina-turbo' ), '«' . implode( '», «', $names ) . '»', '' === $hit ? '' : ' (' . $hit . ')' );
		$first['issues']  = $issues;
		$first['notes']   = $notes;
		$first['verdict'] = self::verdict( $first['status'], $issues, $notes );
		$first['headers'] = array_merge( $first['headers'], array_intersect_key( $second['headers'], array_flip( [ 'x-litespeed-cache', 'cf-cache-status', 'x-cache', 'age' ] ) ) );
		return $first;
	}

	/**
	 * Cabecera que muestra que la respuesta salió de una caché ("x-litespeed-cache: hit"), o vacío.
	 *
	 * @param array<string, mixed> $headers Cabeceras.
	 */
	private static function cacheHit( array $headers ): string {
		foreach ( [ 'x-litespeed-cache', 'x-qc-cache', 'cf-cache-status', 'x-cache', 'x-proxy-cache' ] as $name ) {
			$value = strtolower( self::headerValue( $headers[ $name ] ?? '' ) );
			if ( str_contains( $value, 'hit' ) ) {
				return $name . ': ' . $value;
			}
		}
		return '';
	}

	/**
	 * @param list<string> $issues Problemas.
	 * @param list<string> $notes  Notas.
	 */
	private static function verdict( int $status, array $issues, array $notes ): string {
		if ( 200 !== $status ) {
			return 'error';
		}
		if ( [] !== $issues ) {
			return 'warning';
		}
		return [] === $notes ? 'ok' : 'info';
	}

	/**
	 * Último resultado guardado.
	 *
	 * @return array<string, mixed>|null
	 */
	public function last(): ?array {
		$report = get_option( self::OPTION, null );
		return is_array( $report ) ? $report : null;
	}

	/**
	 * Análisis puro de una respuesta.
	 *
	 * @param array<string, mixed> $headers Cabeceras en minúsculas (los valores pueden ser listas).
	 * @return array{status: int, verdict: string, issues: list<string>, notes: list<string>, cookies: list<array{name: string, hint: string}>, headers: array<string, string>}
	 */
	public static function analyze( int $status, array $headers ): array {
		$issues  = [];
		$notes   = [];
		$cookies = [];

		$setCookies = $headers['set-cookie'] ?? [];
		foreach ( is_array( $setCookies ) ? $setCookies : [ $setCookies ] as $line ) {
			$name = trim( explode( '=', (string) $line, 2 )[0] );
			if ( '' === $name ) {
				continue;
			}
			$cookies[] = [
				'name' => $name,
				'hint' => self::hint( $name ),
			];
		}

		$cacheControl = strtolower( self::headerValue( $headers['cache-control'] ?? '' ) );

		if ( 200 !== $status ) {
			/* translators: %d: código HTTP */
			$issues[] = sprintf( __( 'Respondió %d en lugar de 200.', 'imagina-turbo' ), $status );
		}
		foreach ( $cookies as $cookie ) {
			/* translators: %s: nombre de la cookie */
			$issues[] = sprintf( __( 'Envía la cookie «%s» a visitantes anónimos: ni el edge ni el plugin de caché pueden guardar la página.', 'imagina-turbo' ), $cookie['name'] );
		}
		$lsNoCache = str_contains( strtolower( self::headerValue( $headers['x-litespeed-cache-control'] ?? '' ) ), 'no-cache' );
		if ( str_contains( $cacheControl, 'no-store' ) && $lsNoCache ) {
			$notes[] = __( 'Un plugin marcó la página como dinámica a propósito (LiteSpeed: no-cache) y no se guarda en caché ni usa el bfcache. Es lo correcto en resultados de búsqueda, formularios o páginas de cuenta; si es una página normal, revisa qué plugin la marca.', 'imagina-turbo' );
		} elseif ( str_contains( $cacheControl, 'no-store' ) ) {
			$issues[] = __( 'Cache-Control: no-store impide el bfcache (atrás/adelante instantáneo) y la caché en el edge.', 'imagina-turbo' );
		} elseif ( str_contains( $cacheControl, 'private' ) ) {
			$issues[] = __( 'Cache-Control: private impide la caché en el edge.', 'imagina-turbo' );
		}

		$keep = [];
		foreach ( [ 'cache-control', 'x-litespeed-cache', 'x-litespeed-cache-control', 'cf-cache-status', 'x-cache', 'age', 'vary' ] as $name ) {
			if ( isset( $headers[ $name ] ) ) {
				$keep[ $name ] = substr( self::headerValue( $headers[ $name ] ), 0, 200 );
			}
		}

		return [
			'status'  => $status,
			'verdict' => self::verdict( $status, $issues, $notes ),
			'issues'  => $issues,
			'notes'   => $notes,
			'cookies' => $cookies,
			'headers' => $keep,
		];
	}

	private static function hint( string $cookie ): string {
		foreach ( self::KNOWN_COOKIES as $prefix => $hint ) {
			if ( str_starts_with( $cookie, $prefix ) ) {
				return $hint;
			}
		}
		return __( 'Origen desconocido: revisa los plugins de sesiones, idioma, moneda o consentimiento.', 'imagina-turbo' );
	}

	/**
	 * @param mixed $value Valor (cadena o lista).
	 */
	private static function headerValue( mixed $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param list<array{label: string, url: string}> $targets Lista.
	 * @param mixed                                   $url     URL o false.
	 */
	private function push( array &$targets, string $label, mixed $url ): void {
		if ( is_string( $url ) && '' !== $url ) {
			$targets[] = [
				'label' => $label,
				'url'   => $url,
			];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function latestQuery( string $postType ): array {
		return [
			'post_type'      => $postType,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];
	}
}
