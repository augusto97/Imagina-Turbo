<?php
/**
 * Verificador externo (0.20.0, docs/05): el servicio Node + Playwright del directorio verifier/,
 * instalado en un VPS. Batería funcional de páginas completas (errores de JavaScript y de PHP,
 * peticiones rotas, añadir al carrito, cambio visual) y Lighthouse cada noche.
 *
 * - Configuración: URL del servicio (https; http solo en localhost), id del sitio y una clave
 *   compartida que genera el plugin y se guarda cifrada (TokenVault en su propia opción). En el
 *   verificador se copia a sites.json junto con la URL del sitio.
 * - Las peticiones van firmadas con la misma cadena canónica que el hub (Hub\Signer). Nunca se envían
 *   credenciales del sitio: el verificador navega como un visitante sin sesión.
 * - Todo corre en el admin o en Action Scheduler; el sitio no depende del verificador (regla 7).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Verifier;

use ImaginaTurbo\Cloudflare\TokenVault;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Hub\Signer;

/**
 * Configuración, páginas y llamadas firmadas.
 */
final class VerifierService {

	public const OPTION = 'itb_verifier';

	public const KEY_OPTION = 'itb_verifier_key';

	private const TIMEOUT = 15;

	public function __construct( private readonly EventLog $events, private readonly ?TokenVault $vault = null ) {
	}

	private function vault(): TokenVault {
		return $this->vault ?? new TokenVault( null, self::KEY_OPTION );
	}

	/**
	 * @return array{url: string, site: string, seen: string}
	 */
	public static function settings(): array {
		$s = get_option( self::OPTION, [] );
		$s = is_array( $s ) ? $s : [];
		return [
			'url'  => is_string( $s['url'] ?? null ) ? $s['url'] : '',
			'site' => is_string( $s['site'] ?? null ) ? $s['site'] : '',
			'seen' => is_string( $s['seen'] ?? null ) ? $s['seen'] : '',
		];
	}

	/** URL válida: https, o http en localhost/127.0.0.1 (pruebas). */
	public static function validUrl( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return false;
		}
		return 'https' === $parts['scheme'] || ( 'http' === $parts['scheme'] && in_array( $parts['host'], [ 'localhost', '127.0.0.1' ], true ) );
	}

	public function configured(): bool {
		$s = self::settings();
		return '' !== $s['url'] && '' !== $s['site'] && null !== $this->vault()->read();
	}

	/**
	 * Guarda la URL y el id; con $newKey genera una clave nueva y la devuelve (única vez en claro).
	 *
	 * @return array{ok: bool, error: string, key: string}
	 */
	public function save( string $url, string $site, bool $newKey ): array {
		$url  = untrailingslashit( trim( $url ) );
		$site = trim( $site );
		if ( '' !== $url && ! self::validUrl( $url ) ) {
			return [
				'ok'    => false,
				'error' => __( 'La URL del verificador tiene que ser https (http solo en localhost).', 'imagina-turbo' ),
				'key'   => '',
			];
		}
		if ( '' !== $site && 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,100}$/', $site ) ) {
			return [
				'ok'    => false,
				'error' => __( 'El id del sitio solo admite letras, números, punto, guion y guion bajo.', 'imagina-turbo' ),
				'key'   => '',
			];
		}
		$current = self::settings();
		update_option(
			self::OPTION,
			[
				'url'  => $url,
				'site' => $site,
				'seen' => $current['seen'],
			],
			false
		);
		$key = '';
		if ( $newKey || null === $this->vault()->read() ) {
			$key = bin2hex( random_bytes( 32 ) );
			if ( ! $this->vault()->store( $key ) ) {
				return [
					'ok'    => false,
					'error' => __( 'No se pudo guardar la clave cifrada (falta sodium en PHP).', 'imagina-turbo' ),
					'key'   => '',
				];
			}
		}
		return [
			'ok'    => true,
			'error' => '',
			'key'   => $key,
		];
	}

	/**
	 * Estado sin la clave (solo sus últimos 4 caracteres).
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$s = self::settings();
		return [
			'url'        => $s['url'],
			'site'       => $s['site'],
			'key'        => $this->vault()->status(),
			'configured' => $this->configured(),
		];
	}

	/**
	 * Petición firmada. null en el cuerpo si falló la red o la respuesta no es JSON.
	 *
	 * @param array<string, mixed>|null $body Cuerpo JSON.
	 * @return array{status: int, body: array<mixed>|null, error: string}
	 */
	public function request( string $method, string $path, ?array $body = null ): array {
		$s   = self::settings();
		$key = $this->vault()->read();
		if ( '' === $s['url'] || '' === $s['site'] || null === $key ) {
			return [
				'status' => 0,
				'body'   => null,
				'error'  => __( 'El verificador no está configurado.', 'imagina-turbo' ),
			];
		}
		$raw     = null === $body ? '' : (string) wp_json_encode( $body );
		$headers = Signer::headers( $s['site'], $key, '', $method, $path, $raw );
		unset( $headers['X-ITB-License'] );
		$args = [
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => $headers + [ 'Content-Type' => 'application/json' ],
		];
		if ( '' !== $raw ) {
			$args['body'] = $raw;
		}
		$response = wp_remote_request( $s['url'] . $path, $args );
		if ( is_wp_error( $response ) ) {
			return [
				'status' => 0,
				'body'   => null,
				'error'  => $response->get_error_message(),
			];
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data    = is_array( $decoded ) ? $decoded : null;
		$error   = '';
		if ( $status >= 400 ) {
			$code  = is_string( $data['error'] ?? null ) ? $data['error'] : (string) $status;
			$error = 401 === $status
				/* translators: %s: código de error del verificador. */
				? sprintf( __( 'El verificador rechazó la firma (%s): revisa que el id y la clave coincidan con sites.json y que los relojes estén en hora.', 'imagina-turbo' ), $code )
				: ( is_string( $data['message'] ?? null ) ? $data['message'] : $code );
		}
		return [
			'status' => $status,
			'body'   => $data,
			'error'  => $error,
		];
	}

	/**
	 * Páginas clave del sitio: portada, entrada, página, producto (con el flujo de añadir al carrito),
	 * tienda, la categoría con más productos, carrito, pago, cuenta y una que no existe (debe dar 404).
	 *
	 * @return list<array{label: string, url: string, flow?: string, expect?: int}>
	 */
	public static function defaultPages(): array {
		$home  = untrailingslashit( home_url() );
		$rel   = static fn( mixed $url ): string => is_string( $url ) && str_starts_with( $url, $home ) ? '/' . ltrim( substr( $url, strlen( $home ) ), '/' ) : '';
		$pages = [
			[
				'label' => __( 'Portada', 'imagina-turbo' ),
				'url'   => '/',
			],
		];
		foreach ( [
			'post' => __( 'Entrada', 'imagina-turbo' ),
			'page' => __( 'Página', 'imagina-turbo' ),
		] as $type => $label ) {
			$exclude = 'page' === $type ? array_filter(
				[ (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ] + ( function_exists( 'wc_get_page_id' ) ? [
					2 => (int) wc_get_page_id( 'cart' ),
					3 => (int) wc_get_page_id( 'checkout' ),
					4 => (int) wc_get_page_id( 'myaccount' ),
					5 => (int) wc_get_page_id( 'shop' ),
				] : [] ),
				static fn( int $id ): bool => $id > 0
			) : [];
			$ids     = get_posts(
				[
					'post_type'   => $type,
					'post_status' => 'publish',
					'numberposts' => 1,
					'exclude'     => $exclude, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- una consulta en el admin.
					'fields'      => 'ids',
				]
			);
			$url     = [] !== $ids ? $rel( get_permalink( (int) $ids[0] ) ) : '';
			if ( '' !== $url ) {
				$pages[] = [
					'label' => $label,
					'url'   => $url,
				];
			}
		}
		if ( function_exists( 'wc_get_products' ) && function_exists( 'wc_get_page_permalink' ) ) {
			// Un producto simple, publicado y con existencias: el que se puede añadir sin elegir nada.
			$products = wc_get_products(
				[
					'status'       => 'publish',
					'type'         => 'simple',
					'stock_status' => 'instock',
					'limit'        => 1,
					'orderby'      => 'date',
					'order'        => 'DESC',
					'return'       => 'ids',
				]
			);
			$url      = is_array( $products ) && [] !== $products ? $rel( get_permalink( (int) $products[0] ) ) : '';
			if ( '' !== $url ) {
				$pages[] = [
					'label' => __( 'Producto', 'imagina-turbo' ),
					'url'   => $url,
					'flow'  => 'add_to_cart',
				];
			}
			$terms = taxonomy_exists( 'product_cat' ) ? get_terms(
				[
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'number'     => 1,
					'orderby'    => 'count',
					'order'      => 'DESC',
				]
			) : [];
			$cat   = is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ? get_term_link( $terms[0] ) : '';
			foreach ( [
				[ __( 'Tienda', 'imagina-turbo' ), wc_get_page_permalink( 'shop' ) ],
				[ __( 'Categoría', 'imagina-turbo' ), is_string( $cat ) ? $cat : '' ],
				[ __( 'Carrito', 'imagina-turbo' ), wc_get_page_permalink( 'cart' ) ],
				[ __( 'Pago', 'imagina-turbo' ), wc_get_page_permalink( 'checkout' ) ],
				[ __( 'Mi cuenta', 'imagina-turbo' ), wc_get_page_permalink( 'myaccount' ) ],
			] as [ $label, $link ] ) {
				$url = $rel( $link );
				if ( '' !== $url && '/' !== $url ) {
					$pages[] = [
						'label' => $label,
						'url'   => $url,
					];
				}
			}
		}
		$pages[] = [
			'label'  => __( 'Página inexistente', 'imagina-turbo' ),
			'url'    => '/itb-verificador-404/',
			'expect' => 404,
		];
		$seen    = [];
		$out     = [];
		foreach ( $pages as $page ) {
			if ( ! isset( $seen[ $page['url'] ] ) ) {
				$seen[ $page['url'] ] = true;
				$out[]                = $page;
			}
		}
		return array_slice( $out, 0, 15 );
	}

	/**
	 * Tarea diaria: avisa en el registro de la última corrida nocturna con fallos o caídas de
	 * Lighthouse (una sola vez por corrida).
	 */
	public function daily(): void {
		if ( ! $this->configured() ) {
			return;
		}
		$reply = $this->request( 'GET', '/v1/runs?limit=5' );
		$runs  = is_array( $reply['body']['runs'] ?? null ) ? $reply['body']['runs'] : [];
		foreach ( $runs as $run ) {
			if ( ! is_array( $run ) || 'nightly' !== ( $run['trigger'] ?? '' ) || 'done' !== ( $run['status'] ?? '' ) ) {
				continue;
			}
			$settings = self::settings();
			$id       = is_string( $run['id'] ?? null ) ? $run['id'] : '';
			if ( '' === $id || $id === $settings['seen'] ) {
				return;
			}
			update_option( self::OPTION, [ 'seen' => $id ] + $settings, false );
			$alerts = array_values( array_filter( is_array( $run['alerts'] ?? null ) ? $run['alerts'] : [], 'is_string' ) );
			if ( [] !== $alerts ) {
				$this->events->warning(
					'verifier',
					'nightly',
					/* translators: %s: avisos de la corrida. */
					sprintf( __( 'Verificador (nocturna): %s', 'imagina-turbo' ), implode( '; ', array_slice( $alerts, 0, 3 ) ) ),
					[
						'run'    => $id,
						'alerts' => array_slice( $alerts, 0, 20 ),
					]
				);
			}
			return;
		}
	}
}
