<?php
/**
 * Guarda la muestra del request al final (shutdown). Un único INSERT, solo en requests muestreados.
 *
 * Se descartó fastcgi_finish_request(): cerrar la respuesta antes del final hace que la salida de
 * otros plugins en shutdown se pierda, y ese fallo aparecería solo en el 1 % de los requests.
 * El INSERT cuesta décimas de milisegundo y solo en la muestra.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Profiler;

use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Repository\SampleRepository;
use ImaginaTurbo\Support\Filesystem;

/**
 * Construye y guarda la fila.
 */
final class SampleWriter {

	/** Componentes que se guardan por muestra (los de mayor costo). */
	private const TOP_COMPONENTS = 15;

	public function __construct( private readonly SampleRepository $repository ) {
	}

	/**
	 * Guarda la muestra. Nunca lanza.
	 */
	public function write( Collector $collector, RequestContext $request ): void {
		try {
			$this->repository->insert( $this->row( $collector, $request, headers_list(), $this->status() ) );
			self::saveSymbols( $collector );
		} catch ( \Throwable $e ) {
			error_log( '[imagina-turbo] No se pudo guardar la muestra del perfilador: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Guarda la caché de símbolos si este request resolvió alguno nuevo (escritura atómica; si el
	 * directorio no es escribible, no pasa nada: la siguiente muestra vuelve a resolverlos).
	 */
	public static function saveSymbols( Collector $collector ): bool {
		$update = $collector->symbolCacheUpdate();
		if ( null === $update ) {
			return false;
		}
		// var_export escapa claves y valores: nombres de funciones y clases y rutas relativas del componente.
		return Filesystem::atomicWrite( $update['file'], "<?php\n// Caché del perfilador de Imagina Turbo (se regenera sola).\nreturn " . var_export( $update['data'], true ) . ";\n" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	}

	/**
	 * Fila de la muestra (pura salvo por $wpdb->num_queries y las cookies del request).
	 *
	 * @param list<string> $headers Cabeceras de la respuesta (headers_list()).
	 * @return array<string, int|string|null>
	 */
	public function row( Collector $collector, RequestContext $request, array $headers, int $status ): array {
		$response   = self::parseHeaders( $headers );
		$components = array_slice( $collector->components(), 0, self::TOP_COMPONENTS, true );
		$compact    = [];
		foreach ( $components as $name => $cost ) {
			$compact[] = [
				'c' => $name,
				'l' => $cost['load_ms'],
				'h' => $cost['hook_ms'],
				'k' => $cost['kb'],
			];
		}

		$queries = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \wpdb ? (int) $GLOBALS['wpdb']->num_queries : 0;

		return [
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'request_type'  => $request->type->value,
			'route_key'     => RouteKey::current( $request ),
			'path_hash'     => substr( hash( 'sha256', $request->path ), 0, 16 ),
			'status'        => $status,
			'ms'            => (int) min( 16777215, round( $collector->elapsedMs() ) ),
			'mem_kb'        => (int) round( memory_get_peak_usage() / 1024 ),
			'queries'       => min( 65535, $queries ),
			'cacheable'     => self::looksCacheable( $request, $status, $response ) ? 1 : 0,
			'via_cf'        => isset( $_SERVER['HTTP_CF_RAY'] ) ? 1 : 0,
			'source'        => $collector->source,
			'overhead_us'   => $collector->overheadUs(),
			'set_cookies'   => [] === $response['cookies'] ? null : substr( implode( ',', $response['cookies'] ), 0, 255 ),
			'cache_control' => '' === $response['cache_control'] ? null : substr( $response['cache_control'], 0, 100 ),
			'phases'        => (string) wp_json_encode( $collector->phases() ),
			'plugins_cost'  => (string) wp_json_encode( $compact ),
		];
	}

	/**
	 * Nombres de cookies enviadas y Cache-Control de la respuesta.
	 *
	 * @param list<string> $headers Cabeceras.
	 * @return array{cookies: list<string>, cache_control: string}
	 */
	public static function parseHeaders( array $headers ): array {
		$cookies = [];
		$cache   = '';
		foreach ( $headers as $header ) {
			$colon = strpos( $header, ':' );
			if ( false === $colon ) {
				continue;
			}
			$name  = strtolower( trim( substr( $header, 0, $colon ) ) );
			$value = trim( substr( $header, $colon + 1 ) );
			if ( 'set-cookie' === $name ) {
				$cookieName = trim( explode( '=', $value, 2 )[0] );
				if ( '' !== $cookieName && 1 === preg_match( '/^[A-Za-z0-9_\-.%]+$/', $cookieName ) ) {
					$cookies[] = $cookieName;
				}
			} elseif ( 'cache-control' === $name ) {
				$cache = strtolower( $value );
			}
		}
		return [
			'cookies'       => array_values( array_unique( $cookies ) ),
			'cache_control' => $cache,
		];
	}

	/**
	 * Visitante anónimo, GET/HEAD, 200, sin Set-Cookie ni Cache-Control privado.
	 *
	 * @param array{cookies: list<string>, cache_control: string} $response Cabeceras parseadas.
	 */
	public static function looksCacheable( RequestContext $request, int $status, array $response ): bool {
		if ( ! $request->type->isPotentiallyCacheable() || ( 'GET' !== $request->method && 'HEAD' !== $request->method ) || 200 !== $status ) {
			return false;
		}
		foreach ( array_keys( $_COOKIE ) as $cookie ) {
			if ( is_string( $cookie ) && ( str_starts_with( $cookie, 'wordpress_logged_in_' ) || str_starts_with( $cookie, 'wp-postpass_' ) ) ) {
				return false;
			}
		}
		if ( [] !== $response['cookies'] ) {
			return false;
		}
		return 1 !== preg_match( '/\b(private|no-store|no-cache)\b/', $response['cache_control'] );
	}

	private function status(): int {
		$code = http_response_code();
		return is_int( $code ) ? $code : 200;
	}
}
