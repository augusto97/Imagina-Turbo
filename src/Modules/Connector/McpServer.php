<?php
/**
 * Servidor MCP del conector (Streamable HTTP, especificación 2025-11-25).
 *
 * - Solo POST, un mensaje JSON-RPC por petición, respuesta application/json (sin SSE ni sesiones: el
 *   servidor no necesita enviar nada por su cuenta). GET y DELETE responden 405.
 * - Todo exige el access token en Authorization: Bearer. Sin token, 401 con WWW-Authenticate y
 *   resource_metadata, que es lo que hace que Claude abra el inicio de sesión. Un token de otro recurso,
 *   caducado o de un usuario que ya no administra el plugin, también 401.
 * - Si llega la cabecera Origin, tiene que ser la del sitio o la de Claude (protección contra DNS
 *   rebinding que pide la especificación).
 * - 120 peticiones por minuto y acceso.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Connector;

use ImaginaTurbo\Modules\Rum\RumCollector;

/**
 * Transporte y métodos de MCP.
 *
 * @phpstan-import-type Grant from TokenStore
 */
final class McpServer {

	public const VERSIONS = [ '2025-11-25', '2025-06-18', '2025-03-26' ];

	private const MAX_BODY = 1048576;

	public function __construct( private readonly TokenStore $tokens, private readonly Tools $tools ) {
	}

	public function handle(): void {
		Connector::noStore();
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) {
			if ( ! headers_sent() ) {
				header( 'Allow: POST' );
			}
			self::respond( 405, null );
		}
		if ( ! self::originAllowed( isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '' ) ) {
			self::respond( 403, self::error( null, -32600, 'Origin no permitido.' ) );
		}

		$token = self::bearer( $_SERVER );
		if ( '' === $token ) {
			self::challenge( '' );
		}
		$grant = $this->tokens->byAccess( $token );
		if ( null === $grant || Connector::canonical( $grant['resource'] ) !== Connector::canonical( Connector::resource() ) || ! Connector::userCan( $grant['user_id'] ) ) {
			self::challenge( 'invalid_token' );
		}
		$ip = RumCollector::clientIp( $_SERVER );
		if ( ! OAuthServer::allow( 'mcp' . $grant['id'], 'g', 120 ) ) {
			self::respond( 429, self::error( null, -32000, 'Demasiadas peticiones: espera un minuto.' ) );
		}
		if ( $grant['last_used_at'] < time() - 60 ) {
			$this->tokens->touch( $grant['id'], $ip );
		}
		wp_set_current_user( $grant['user_id'] );

		$version = isset( $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ) ) : '';
		if ( '' !== $version && ! in_array( $version, self::VERSIONS, true ) ) {
			self::respond( 400, self::error( null, -32600, 'Versión del protocolo no soportada.' ) );
		}

		$raw = (string) file_get_contents( 'php://input', false, null, 0, self::MAX_BODY + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- cuerpo de la petición.
		if ( strlen( $raw ) > self::MAX_BODY ) {
			self::respond( 413, self::error( null, -32600, 'Mensaje demasiado grande.' ) );
		}
		$message = json_decode( $raw, true );
		if ( ! is_array( $message ) ) {
			self::respond( 400, self::error( null, -32700, 'JSON no válido.' ) );
		}
		[ $status, $body ] = $this->dispatch( $message, $grant );
		self::respond( $status, $body );
	}

	/**
	 * Un mensaje JSON-RPC: [código HTTP, cuerpo o null].
	 *
	 * @param array<mixed> $message Mensaje.
	 * @param Grant        $grant   Acceso.
	 * @return array{0: int, 1: array<string, mixed>|null}
	 */
	public function dispatch( array $message, array $grant ): array {
		if ( array_is_list( $message ) ) {
			return [ 400, self::error( null, -32600, 'Un mensaje por petición (sin lotes).' ) ];
		}
		$id     = $message['id'] ?? null;
		$method = $message['method'] ?? null;
		// Notificaciones y respuestas del cliente: aceptadas sin cuerpo.
		if ( ! array_key_exists( 'id', $message ) || ! is_string( $method ) ) {
			return [ 202, null ];
		}
		if ( ! is_string( $id ) && ! is_int( $id ) ) {
			return [ 400, self::error( null, -32600, 'id no válido.' ) ];
		}
		$params = is_array( $message['params'] ?? null ) ? $message['params'] : [];
		$adjust = in_array( Connector::SCOPE_ADJUST, Connector::scopes( $grant['scope'] ), true );

		switch ( $method ) {
			case 'initialize':
				$asked = is_string( $params['protocolVersion'] ?? null ) ? $params['protocolVersion'] : '';
				return [
					200,
					self::result(
						$id,
						[
							'protocolVersion' => in_array( $asked, self::VERSIONS, true ) ? $asked : self::VERSIONS[0],
							'capabilities'    => [
								'tools'   => [ 'listChanged' => false ],
								'prompts' => [ 'listChanged' => false ],
							],
							'serverInfo'      => [
								'name'    => 'imagina-turbo',
								'title'   => 'Imagina Turbo',
								'version' => defined( 'ITB_VERSION' ) ? ITB_VERSION : '0',
							],
							'instructions'    => Playbook::instructions(),
						]
					),
				];
			case 'ping':
				return [ 200, self::result( $id, new \stdClass() ) ];
			case 'tools/list':
				$list = [];
				foreach ( Tools::definitions( $adjust ) as $tool ) {
					$list[] = [
						'name'        => $tool['name'],
						'title'       => $tool['title'],
						'description' => $tool['description'],
						'inputSchema' => $tool['input'],
						'annotations' => [
							'title'           => $tool['title'],
							'readOnlyHint'    => $tool['readonly'],
							'destructiveHint' => (bool) ( $tool['destructive'] ?? false ),
							'idempotentHint'  => $tool['readonly'],
							'openWorldHint'   => false,
						],
					];
				}
				return [ 200, self::result( $id, [ 'tools' => $list ] ) ];
			case 'tools/call':
				$name = is_string( $params['name'] ?? null ) ? $params['name'] : '';
				$args = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [];
				if ( '' === $name ) {
					return [ 200, self::error( $id, -32602, 'Falta el nombre de la herramienta.' ) ];
				}
				$out = $this->tools->call( $name, $args, $grant );
				return [
					200,
					self::result(
						$id,
						[
							'content' => [
								[
									'type' => 'text',
									'text' => Tools::text( $out['data'] ),
								],
							],
							'isError' => ! $out['ok'],
						]
					),
				];
			case 'prompts/list':
				return [ 200, self::result( $id, [ 'prompts' => [ Playbook::prompt() + [ 'arguments' => [] ] ] ] ) ];
			case 'prompts/get':
				if ( Playbook::PROMPT !== ( $params['name'] ?? null ) ) {
					return [ 200, self::error( $id, -32602, 'Prompt desconocido.' ) ];
				}
				return [
					200,
					self::result(
						$id,
						[
							'description' => Playbook::prompt()['description'],
							'messages'    => [
								[
									'role'    => 'user',
									'content' => [
										'type' => 'text',
										'text' => Playbook::promptText(),
									],
								],
							],
						]
					),
				];
		}
		return [ 200, self::error( $id, -32601, 'Método no soportado.' ) ];
	}

	/**
	 * Token de la cabecera Authorization (algunos servidores la pasan como REDIRECT_HTTP_AUTHORIZATION).
	 * Nunca de la URL (lo prohíbe la especificación).
	 *
	 * @param array<mixed> $server $_SERVER.
	 */
	public static function bearer( array $server ): string {
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
			$value = $server[ $key ] ?? '';
			if ( is_string( $value ) && 1 === preg_match( '/^Bearer\s+([A-Za-z0-9._~+\/-]+=*)$/i', trim( $value ), $m ) ) {
				return $m[1];
			}
		}
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( is_string( $name ) && 'authorization' === strtolower( $name ) && is_string( $value ) && 1 === preg_match( '/^Bearer\s+([A-Za-z0-9._~+\/-]+=*)$/i', trim( $value ), $m ) ) {
					return $m[1];
				}
			}
		}
		return '';
	}

	/**
	 * Sin Origin (servidor a servidor) o con el del sitio o de Claude.
	 */
	public static function originAllowed( string $origin ): bool {
		if ( '' === $origin ) {
			return true;
		}
		$site = wp_parse_url( home_url() );
		$own  = is_array( $site ) ? strtolower( ( $site['scheme'] ?? 'https' ) . '://' . ( $site['host'] ?? '' ) . ( isset( $site['port'] ) ? ':' . $site['port'] : '' ) ) : '';
		return in_array( strtolower( rtrim( $origin, '/' ) ), [ $own, 'https://claude.ai', 'https://claude.com' ], true );
	}

	/**
	 * 401 que inicia el inicio de sesión en Claude.
	 */
	private static function challenge( string $error ): never {
		$parts = [ 'resource_metadata="' . Connector::resourceMetadataUrl() . '"', 'scope="' . Connector::SCOPE_READ . ' ' . Connector::SCOPE_ADJUST . '"' ];
		if ( '' !== $error ) {
			array_unshift( $parts, 'error="' . $error . '"', 'error_description="El acceso no es válido o caducó."' );
		}
		if ( ! headers_sent() ) {
			header( 'WWW-Authenticate: Bearer ' . implode( ', ', $parts ) );
		}
		self::respond(
			401,
			[
				'error'             => '' !== $error ? $error : 'invalid_token',
				'error_description' => 'Hace falta conectar Claude con este sitio.',
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function result( int|string $id, mixed $result ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function error( int|string|null $id, int $code, string $message ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}

	/**
	 * @param array<string, mixed>|null $body Cuerpo.
	 */
	private static function respond( int $status, ?array $body ): never {
		status_header( $status );
		if ( null === $body ) {
			exit;
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		echo wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}
}
