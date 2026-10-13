<?php
/**
 * Auditoría de cacheabilidad (Set-Cookie y bfcache, lado servidor).
 *
 * Pide como visitante anónimo (loopback, sin cookies) unas pocas páginas representativas y revisa
 * las cabeceras: cookies enviadas a anónimos y Cache-Control privado o no-store. Corre desde el admin
 * o el cron diario; no tiene costo en el frontend.
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
			$response = wp_remote_get(
				$target['url'],
				[
					'timeout'     => 10,
					'redirection' => 3,
					'cookies'     => [],
					'headers'     => [ 'User-Agent' => 'ImaginaTurbo-Audit/1.0' ],
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
				]
			);
			if ( CloudflareAccess::blockedResponse( $response ) ) {
				$result = [
					'label'   => $target['label'],
					'url'     => $target['url'],
					'status'  => 0,
					'verdict' => 'error',
					'issues'  => [ CloudflareAccess::message( $target['url'] ) ],
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
					'cookies' => [],
					'headers' => [],
				];
			} else {
				$headers = [];
				foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
					$headers[ strtolower( (string) $name ) ] = $value;
				}
				$result = array_merge(
					[
						'label' => $target['label'],
						'url'   => $target['url'],
					],
					self::analyze( (int) wp_remote_retrieve_response_code( $response ), $headers )
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
	 * @return array{status: int, verdict: string, issues: list<string>, cookies: list<array{name: string, hint: string}>, headers: array<string, string>}
	 */
	public static function analyze( int $status, array $headers ): array {
		$issues  = [];
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
		if ( str_contains( $cacheControl, 'no-store' ) ) {
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

		$verdict = [] === $issues ? 'ok' : ( 200 !== $status ? 'error' : 'warning' );

		return [
			'status'  => $status,
			'verdict' => $verdict,
			'issues'  => $issues,
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
