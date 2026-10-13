<?php
/**
 * Cliente de la API v4 de Cloudflare (docs/03, sección 2).
 *
 * - Timeout de 15 s; reintentos con espera exponencial ante 429, 5xx y errores de red (máximo 3).
 * - Un 429 con Retry-After largo no se espera: se devuelve para que la cola reprograme.
 * - Toda respuesta se valida (success, errors[]); los fallos se registran sin el token.
 * - Nunca corre en el frontend: solo admin, REST del admin, cron y CLI (regla 7).
 *
 * La URL base solo se puede cambiar con la constante ITB_CF_API_BASE en wp-config.php (simulador de
 * pruebas): un ajuste editable desde el admin permitiría enviar el token a otro servidor.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

use ImaginaTurbo\Core\Events\EventLog;

/**
 * Peticiones a la API.
 */
final class Client {

	public const BASE = 'https://api.cloudflare.com/client/v4';

	public const TIMEOUT = 15;

	public const MAX_RETRIES = 3;

	/** Esperas más largas no se hacen dentro del request: se devuelve el 429 a quien llama. */
	public const MAX_INLINE_WAIT = 8;

	/** @var \Closure(int): void */
	private \Closure $sleep;

	/**
	 * @param \Closure(): ?string     $token  Fuente del token (la bóveda, o uno nuevo al conectar).
	 * @param (\Closure(int): void)|null $sleep Espera (sustituible en pruebas).
	 */
	public function __construct(
		private readonly \Closure $token,
		private readonly ?EventLog $events = null,
		?\Closure $sleep = null,
	) {
		$this->sleep = $sleep ?? static function ( int $seconds ): void {
			if ( $seconds > 0 ) {
				sleep( $seconds );
			}
		};
	}

	/**
	 * Cliente con un token concreto (verificación antes de guardarlo).
	 */
	public function withToken( string $token ): self {
		return new self( static fn(): ?string => $token, $this->events, $this->sleep );
	}

	public static function base(): string {
		if ( defined( 'ITB_CF_API_BASE' ) && is_string( ITB_CF_API_BASE ) && 1 === preg_match( '#^https?://[^\s/]+(/[^\s]*)?$#', ITB_CF_API_BASE ) ) {
			return rtrim( ITB_CF_API_BASE, '/' );
		}
		return self::BASE;
	}

	public function get( string $path, array $query = [] ): Response {
		return $this->request( 'GET', $path, null, $query );
	}

	/**
	 * @param array<string, mixed>|list<mixed>|null $body Cuerpo JSON.
	 */
	public function post( string $path, ?array $body = null ): Response {
		return $this->request( 'POST', $path, $body );
	}

	/**
	 * @param array<string, mixed>|list<mixed>|null $body Cuerpo JSON.
	 */
	public function patch( string $path, ?array $body = null ): Response {
		return $this->request( 'PATCH', $path, $body );
	}

	/**
	 * @param array<string, mixed>|list<mixed>|null $body Cuerpo JSON.
	 */
	public function put( string $path, ?array $body = null ): Response {
		return $this->request( 'PUT', $path, $body );
	}

	public function delete( string $path ): Response {
		return $this->request( 'DELETE', $path );
	}

	/**
	 * @param array<string, mixed>|list<mixed>|null $body  Cuerpo JSON.
	 * @param array<string, scalar>                 $query Parámetros de la URL.
	 */
	public function request( string $method, string $path, ?array $body = null, array $query = [] ): Response {
		$token = ( $this->token )();
		if ( null === $token || '' === $token ) {
			return Response::failure( 0, 'itb_no_token', __( 'Cloudflare no está conectado.', 'imagina-turbo' ) );
		}
		if ( 1 !== preg_match( '#^/[A-Za-z0-9_\-./]*$#', $path ) || str_contains( $path, '..' ) ) {
			return Response::failure( 0, 'itb_bad_path', 'Ruta no válida.' );
		}
		$url = self::base() . $path;
		if ( [] !== $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		$args = [
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => [
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			],
			'user-agent'  => 'ImaginaTurbo/' . ( defined( 'ITB_VERSION' ) ? ITB_VERSION : '0' ),
		];
		if ( null !== $body ) {
			$json = wp_json_encode( $body );
			if ( false === $json ) {
				return Response::failure( 0, 'itb_json', 'No se pudo codificar la petición.' );
			}
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $json;
		}

		$attempt = 0;
		while ( true ) {
			$raw      = wp_remote_request( $url, $args );
			$response = is_wp_error( $raw ) ? Response::failure( 0, 'itb_http', $raw->get_error_message() ) : Response::fromHttp( (int) wp_remote_retrieve_response_code( $raw ), (string) wp_remote_retrieve_body( $raw ), self::retryAfter( $raw ) );
			if ( ! $response->retryable() || $attempt >= self::MAX_RETRIES ) {
				break;
			}
			$wait = 429 === $response->status && $response->retryAfter > 0 ? $response->retryAfter : 2 ** $attempt;
			if ( $wait > self::MAX_INLINE_WAIT ) {
				break;
			}
			( $this->sleep )( $wait );
			++$attempt;
		}

		if ( ! $response->ok() && null !== $this->events ) {
			$this->events->warning(
				'cloudflare',
				'api_error',
				__( 'La API de Cloudflare respondió con un error.', 'imagina-turbo' ),
				[
					'method' => $method,
					'path'   => self::redact( $path ),
					'status' => $response->status,
					'errors' => array_slice( $response->errors, 0, 5 ),
				]
			);
		}
		return $response;
	}

	/**
	 * Ruta para el registro sin identificadores largos (no son secretos, pero no aportan).
	 */
	public static function redact( string $path ): string {
		return (string) preg_replace( '/[a-f0-9]{32}/', '…', $path );
	}

	/**
	 * @param array<string, mixed> $raw Respuesta de wp_remote_request.
	 */
	private static function retryAfter( array $raw ): int {
		$value = wp_remote_retrieve_header( $raw, 'retry-after' );
		$value = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		return ctype_digit( $value ) ? (int) $value : 0;
	}
}
