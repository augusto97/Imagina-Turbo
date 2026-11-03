<?php
/**
 * Clientes del conector: Client ID Metadata Documents (CIMD) alojados por Anthropic.
 *
 * Con CIMD, el client_id de Claude es una URL HTTPS con su registro (nombre y direcciones de vuelta).
 * Se acepta solo si el host es de Anthropic (Connector::TRUSTED_CLIENT_HOSTS, sin filtro: es un control de seguridad): así no hay que guardar
 * clientes, no hay registro dinámico abierto y el sitio no pide URLs arbitrarias (SSRF). El documento
 * se pide con wp_safe_remote_get, se limita a 8 KB y se guarda un día.
 *
 * Direcciones de vuelta: exactas, salvo las de loopback (Claude Code declara http://localhost/callback
 * y http://127.0.0.1/callback y usa un puerto distinto en cada sesión), que se comparan sin el puerto
 * (RFC 8252, sección 7.3).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Connector;

/**
 * Lectura y validación de clientes.
 *
 * @phpstan-type Client array{client_id: string, client_name: string, redirect_uris: list<string>, host: string}
 */
final class ClientMetadata {

	private const CACHE_PREFIX = 'itb_cimd_';

	/** @var \Closure(string): (array<mixed>|string)|null Descarga (pruebas). */
	private static ?\Closure $fetcher = null;

	/**
	 * Hosts de confianza para los documentos de cliente.
	 *
	 * @return list<string>
	 */
	public static function trustedHosts(): array {
		return Connector::TRUSTED_CLIENT_HOSTS;
	}

	/**
	 * La URL puede ser un client_id: https, host de confianza y con ruta.
	 */
	public static function validClientId( string $clientId ): bool {
		$parts = wp_parse_url( $clientId );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( '' === $path || '/' === $path || strlen( $clientId ) > 255 ) {
			return false;
		}
		return in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), self::trustedHosts(), true );
	}

	/**
	 * Cliente validado, o un mensaje de error.
	 *
	 * @return Client|string
	 */
	public static function load( string $clientId ): array|string {
		if ( ! self::validClientId( $clientId ) ) {
			return __( 'Este cliente no es Claude: el conector solo acepta a Claude de Anthropic.', 'imagina-turbo' );
		}
		$key    = self::CACHE_PREFIX . md5( $clientId );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			$client = self::parse( $clientId, $cached );
			if ( is_array( $client ) ) {
				return $client;
			}
		}
		// Un documento que no se pudo leer no se vuelve a pedir en 10 minutos.
		$failKey = self::CACHE_PREFIX . 'f_' . md5( $clientId );
		$failed  = get_transient( $failKey );
		if ( is_string( $failed ) ) {
			return $failed;
		}
		$doc = null !== self::$fetcher ? ( self::$fetcher )( $clientId ) : self::fetch( $clientId );
		if ( is_string( $doc ) ) {
			set_transient( $failKey, $doc, 600 );
			return $doc;
		}
		$client = self::parse( $clientId, $doc );
		if ( is_array( $client ) ) {
			set_transient( $key, $doc, DAY_IN_SECONDS );
		}
		return $client;
	}

	/**
	 * Valida el documento (CIMD: client_id igual a la URL, client_name y redirect_uris).
	 *
	 * @param array<mixed> $doc Documento.
	 * @return Client|string
	 */
	public static function parse( string $clientId, array $doc ): array|string {
		if ( ( $doc['client_id'] ?? null ) !== $clientId ) {
			return __( 'El documento del cliente no coincide con su dirección.', 'imagina-turbo' );
		}
		$uris = [];
		foreach ( is_array( $doc['redirect_uris'] ?? null ) ? $doc['redirect_uris'] : [] as $uri ) {
			if ( is_string( $uri ) && self::redirectAllowed( $uri ) ) {
				$uris[] = $uri;
			}
		}
		if ( [] === $uris ) {
			return __( 'El documento del cliente no trae direcciones de vuelta válidas.', 'imagina-turbo' );
		}
		$name = is_string( $doc['client_name'] ?? null ) ? wp_strip_all_tags( $doc['client_name'] ) : '';
		return [
			'client_id'     => $clientId,
			'client_name'   => '' !== $name ? substr( $name, 0, 100 ) : 'Claude',
			'redirect_uris' => $uris,
			'host'          => strtolower( (string) wp_parse_url( $clientId, PHP_URL_HOST ) ),
		];
	}

	/**
	 * Una dirección de vuelta es HTTPS o de loopback (spec MCP: localhost o HTTPS).
	 */
	private static function redirectAllowed( string $uri ): bool {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || isset( $parts['fragment'] ) || ! isset( $parts['host'] ) ) {
			return false;
		}
		$scheme = (string) ( $parts['scheme'] ?? '' );
		return 'https' === $scheme || ( 'http' === $scheme && self::loopback( (string) $parts['host'] ) );
	}

	private static function loopback( string $host ): bool {
		return in_array( strtolower( $host ), [ 'localhost', '127.0.0.1', '[::1]', '::1' ], true );
	}

	/**
	 * La dirección pedida coincide con una registrada: exacta, o loopback con cualquier puerto.
	 *
	 * @param list<string> $registered Direcciones del documento.
	 */
	public static function redirectMatches( string $requested, array $registered ): bool {
		if ( in_array( $requested, $registered, true ) ) {
			return true;
		}
		$req = wp_parse_url( $requested );
		if ( ! is_array( $req ) || 'http' !== ( $req['scheme'] ?? '' ) || ! self::loopback( (string) ( $req['host'] ?? '' ) ) || isset( $req['user'] ) || isset( $req['fragment'] ) ) {
			return false;
		}
		foreach ( $registered as $uri ) {
			$reg = wp_parse_url( $uri );
			if ( is_array( $reg ) && 'http' === ( $reg['scheme'] ?? '' ) && ! isset( $reg['port'] )
				&& strtolower( (string) ( $reg['host'] ?? '' ) ) === strtolower( (string) ( $req['host'] ?? '' ) )
				&& ( $reg['path'] ?? '' ) === ( $req['path'] ?? '' )
				&& ( $reg['query'] ?? '' ) === ( $req['query'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * La dirección de vuelta es de loopback (Claude Code en el computador del usuario).
	 */
	public static function isLoopback( string $uri ): bool {
		return self::loopback( (string) wp_parse_url( $uri, PHP_URL_HOST ) );
	}

	/**
	 * Descarga del documento.
	 *
	 * @return array<mixed>|string
	 */
	private static function fetch( string $clientId ): array|string {
		$response = wp_safe_remote_get(
			$clientId,
			[
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 8192,
				'headers'             => [ 'Accept' => 'application/json' ],
			]
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return __( 'No se pudo leer el documento del cliente de Claude. Inténtalo de nuevo en un momento.', 'imagina-turbo' );
		}
		$doc = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $doc ) ? $doc : __( 'El documento del cliente no es JSON válido.', 'imagina-turbo' );
	}

	/**
	 * Descarga simulada (pruebas).
	 *
	 * @param \Closure(string): (array<mixed>|string)|null $fetcher Descarga.
	 */
	public static function useFetcher( ?\Closure $fetcher ): void {
		self::$fetcher = $fetcher;
	}
}
