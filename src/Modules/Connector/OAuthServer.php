<?php
/**
 * Servidor de autorización del conector (OAuth 2.1 con PKCE, solo clientes CIMD de Anthropic).
 *
 * - Metadatos: RFC 9728 (recurso) y RFC 8414 (servidor), servidos en las rutas que prueban los
 *   clientes MCP: inserción de ruta en /.well-known/ y la variante de OpenID con la ruta añadida.
 * - /authorize: valida cliente y dirección de vuelta antes de redirigir nada (un error ahí se muestra,
 *   no se envía a una dirección sin validar); pide iniciar sesión en WordPress, solo deja pasar a quien
 *   administra Imagina Turbo y muestra el consentimiento con el host que pide acceso y el nivel.
 * - /token: código de un solo uso (10 minutos), PKCE S256, la misma dirección de vuelta y el mismo
 *   resource; refresh tokens rotativos. Límite de 30 peticiones por minuto e IP.
 * - /revoke: RFC 7009, siempre responde 200.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Connector;

use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\Rum\RumCollector;

/**
 * Endpoints de OAuth.
 */
final class OAuthServer {

	private const CODE_PREFIX  = 'itb_oc_';
	private const NONCE_ACTION = 'itb_connector_authorize';

	public function __construct( private readonly TokenStore $tokens, private readonly EventLog $events ) {
	}

	/**
	 * Metadatos del recurso protegido (RFC 9728).
	 *
	 * @return array<string, mixed>
	 */
	public static function resourceMetadata(): array {
		return [
			'resource'                 => Connector::resource(),
			'authorization_servers'    => [ Connector::issuer() ],
			'scopes_supported'         => [ Connector::SCOPE_READ, Connector::SCOPE_ADJUST ],
			'bearer_methods_supported' => [ 'header' ],
			'resource_name'            => 'Imagina Turbo · ' . wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		];
	}

	/**
	 * Metadatos del servidor de autorización (RFC 8414).
	 *
	 * @return array<string, mixed>
	 */
	public static function serverMetadata(): array {
		$issuer = Connector::issuer();
		return [
			'issuer'                                     => $issuer,
			'authorization_endpoint'                     => $issuer . '/authorize',
			'token_endpoint'                             => $issuer . '/token',
			'revocation_endpoint'                        => $issuer . '/revoke',
			'response_types_supported'                   => [ 'code' ],
			'grant_types_supported'                      => [ 'authorization_code', 'refresh_token' ],
			'code_challenge_methods_supported'           => [ 'S256' ],
			'token_endpoint_auth_methods_supported'      => [ 'none' ],
			'revocation_endpoint_auth_methods_supported' => [ 'none' ],
			'scopes_supported'                           => [ Connector::SCOPE_READ, Connector::SCOPE_ADJUST ],
			'client_id_metadata_document_supported'      => true,
			'authorization_response_iss_parameter_supported' => true,
		];
	}

	/**
	 * GET muestra el consentimiento; POST lo resuelve.
	 */
	public function authorize(): void {
		Connector::noStore();
		if ( ! headers_sent() ) {
			header( 'X-Frame-Options: DENY' );
			// Página sin recursos externos ni scripts: nada que inyectar, nada que enmarcar.
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'" );
			header( 'Referrer-Policy: no-referrer' );
		}
		if ( ! Connector::budget( 'authorize|' . RumCollector::clientIp( $_SERVER ), 30, 60 ) ) {
			self::page( __( 'Demasiados intentos', 'imagina-turbo' ), '<p>' . esc_html__( 'Espera un minuto y vuelve a intentarlo.', 'imagina-turbo' ) . '</p>', 429 );
		}
		$p = self::params( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- parámetros de OAuth; el POST verifica su nonce.

		$problems = Connector::problems();
		if ( [] !== $problems ) {
			self::page( __( 'El conector no está listo', 'imagina-turbo' ), '<p>' . implode( '</p><p>', array_map( 'esc_html', $problems ) ) . '</p>', 503 );
		}
		// Primero la sesión: un visitante anónimo no hace que el sitio pida documentos a ningún lado.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::selfUrl( $p ) ) );
			exit;
		}
		$user = get_current_user_id();
		if ( ! Connector::userCan( $user ) ) {
			self::page( __( 'Sin permiso', 'imagina-turbo' ), '<p>' . esc_html__( 'Tu usuario no administra Imagina Turbo en este sitio. Entra con una cuenta de administrador.', 'imagina-turbo' ) . '</p>', 403 );
		}
		// Una sesión vieja (o una cookie robada) no basta: hay que haber entrado hace poco.
		if ( ! self::recentLogin( $user ) ) {
			wp_safe_redirect( wp_login_url( self::selfUrl( $p ), true ) );
			exit;
		}

		// Antes de redirigir nada: el cliente y su dirección de vuelta.
		$client = ClientMetadata::load( $p['client_id'] );
		if ( is_string( $client ) ) {
			self::page( __( 'No se puede conectar', 'imagina-turbo' ), '<p>' . esc_html( $client ) . '</p>', 400 );
		}
		if ( '' === $p['redirect_uri'] || ! ClientMetadata::redirectMatches( $p['redirect_uri'], $client['redirect_uris'] ) ) {
			self::page( __( 'No se puede conectar', 'imagina-turbo' ), '<p>' . esc_html__( 'La dirección de vuelta no es una de las que declara Claude.', 'imagina-turbo' ) . '</p>', 400 );
		}
		$back = $p['redirect_uri'];
		if ( 'code' !== $p['response_type'] ) {
			self::back( $back, $p['state'], 'unsupported_response_type', 'Solo response_type=code.' );
		}
		if ( 'S256' !== $p['code_challenge_method'] || 1 !== preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $p['code_challenge'] ) ) {
			self::back( $back, $p['state'], 'invalid_request', 'PKCE S256 obligatorio.' );
		}
		if ( '' !== $p['resource'] && Connector::canonical( $p['resource'] ) !== Connector::canonical( Connector::resource() ) ) {
			self::back( $back, $p['state'], 'invalid_target', 'El recurso no es el conector de este sitio.' );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) {
			$this->consent( $p, $client );
		}
		check_admin_referer( self::NONCE_ACTION );
		if ( 'allow' !== ( isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '' ) ) {
			$this->events->info( 'connector', 'denied', __( 'Conexión de Claude rechazada en el consentimiento.', 'imagina-turbo' ), [ 'user' => $user ] );
			self::back( $back, $p['state'], 'access_denied', 'El usuario no dio acceso.' );
		}
		// Solo ajusta quien lo elige explícitamente y si Claude lo pidió: lo demás, solo lectura.
		$level = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : '';
		$scope = 'adjust' === $level && self::adjustRequested( $p['scope'] ) ? Connector::SCOPE_READ . ' ' . Connector::SCOPE_ADJUST : Connector::SCOPE_READ;
		$code  = Connector::secret( 'itbac_' );
		set_transient(
			self::CODE_PREFIX . Connector::hash( $code ),
			[
				'client_id'   => $client['client_id'],
				'client_name' => $client['client_name'],
				'redirect'    => $back,
				'challenge'   => $p['code_challenge'],
				'user'        => $user,
				'scope'       => $scope,
				'resource'    => Connector::resource(),
			],
			Connector::CODE_TTL
		);
		self::redirect(
			add_query_arg(
				array_map(
					'rawurlencode',
					array_filter(
						[
							'code'  => $code,
							'state' => $p['state'],
							'iss'   => Connector::issuer(),
						],
						static fn( string $v ): bool => '' !== $v
					)
				),
				$back
			)
		);
	}

	/**
	 * Endpoint de tokens.
	 */
	public function token(): void {
		Connector::noStore();
		if ( ! Connector::secureRequest() ) {
			self::json(
				400,
				[
					'error'             => 'invalid_request',
					'error_description' => 'Solo por HTTPS.',
				]
			);
		}
		$ip = RumCollector::clientIp( $_SERVER );
		if ( ! self::allow( 'tok', $ip, 30 ) ) {
			self::json(
				429,
				[
					'error'             => 'slow_down',
					'error_description' => 'Demasiadas peticiones.',
				]
			);
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- OAuth: la autenticación es el código con PKCE o el refresh token.
		$post  = self::params( $_POST );
		$grant = isset( $_POST['grant_type'] ) ? sanitize_key( wp_unslash( $_POST['grant_type'] ) ) : '';
		$code  = isset( $_POST['code'] ) && is_string( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$ver   = isset( $_POST['code_verifier'] ) && is_string( $_POST['code_verifier'] ) ? sanitize_text_field( wp_unslash( $_POST['code_verifier'] ) ) : '';
		$rt    = isset( $_POST['refresh_token'] ) && is_string( $_POST['refresh_token'] ) ? sanitize_text_field( wp_unslash( $_POST['refresh_token'] ) ) : '';
		// phpcs:enable

		if ( '' !== $post['resource'] && Connector::canonical( $post['resource'] ) !== Connector::canonical( Connector::resource() ) ) {
			self::json(
				400,
				[
					'error'             => 'invalid_target',
					'error_description' => 'El recurso no es el conector de este sitio.',
				]
			);
		}

		if ( 'authorization_code' === $grant ) {
			$key  = self::CODE_PREFIX . Connector::hash( $code );
			$data = '' !== $code ? get_transient( $key ) : false;
			// Un solo uso: solo gana quien lo borra. Un código ya canjeado que vuelve a aparecer revoca el
			// acceso que se emitió con él (alguien lo interceptó).
			if ( ! is_array( $data ) || ! delete_transient( $key ) ) {
				$used = '' !== $code ? get_transient( self::CODE_PREFIX . 'u_' . Connector::hash( $code ) ) : false;
				// El transient vuelve de la base de datos como texto.
				if ( is_numeric( $used ) && (int) $used > 0 ) {
					$this->tokens->revoke( (int) $used );
				}
				self::json(
					400,
					[
						'error'             => 'invalid_grant',
						'error_description' => 'Código no válido, usado o caducado.',
					]
				);
			}
			if ( ( $data['client_id'] ?? '' ) !== $post['client_id'] || ( $data['redirect'] ?? '' ) !== $post['redirect_uri'] ) {
				self::json(
					400,
					[
						'error'             => 'invalid_grant',
						'error_description' => 'El cliente o la dirección de vuelta no coinciden.',
					]
				);
			}
			if ( ! self::pkce( $ver, (string) ( $data['challenge'] ?? '' ) ) ) {
				self::json(
					400,
					[
						'error'             => 'invalid_grant',
						'error_description' => 'PKCE no válido.',
					]
				);
			}
			$user = (int) ( $data['user'] ?? 0 );
			if ( ! Connector::userCan( $user ) ) {
				self::json(
					400,
					[
						'error'             => 'invalid_grant',
						'error_description' => 'El usuario ya no puede administrar Imagina Turbo.',
					]
				);
			}
			$scope  = (string) ( $data['scope'] ?? Connector::SCOPE_READ );
			$issued = $this->tokens->create( $user, (string) $data['client_id'], (string) ( $data['client_name'] ?? 'Claude' ), $scope, (string) ( $data['resource'] ?? Connector::resource() ), $ip );
			if ( null === $issued ) {
				self::json(
					500,
					[
						'error'             => 'server_error',
						'error_description' => 'No se pudo guardar el acceso.',
					]
				);
			}
			set_transient( self::CODE_PREFIX . 'u_' . Connector::hash( $code ), $issued['id'], Connector::CODE_TTL );
			$this->events->warning(
				'connector',
				'connected',
				Connector::SCOPE_READ === $scope ? __( 'Claude se conectó al sitio (solo lectura).', 'imagina-turbo' ) : __( 'Claude se conectó al sitio (puede hacer ajustes).', 'imagina-turbo' ),
				[
					'user'   => $user,
					'client' => $data['client_name'] ?? '',
					'grant'  => $issued['id'],
					'ip'     => $ip,
				]
			);
			self::json( 200, self::tokenResponse( $issued['access_token'], $issued['refresh_token'], $scope ) );
		}

		if ( 'refresh_token' === $grant ) {
			$rotated = $this->tokens->rotate( $rt, $post['client_id'], $ip );
			if ( null === $rotated ) {
				self::json(
					400,
					[
						'error'             => 'invalid_grant',
						'error_description' => 'Refresh token no válido, usado o caducado.',
					]
				);
			}
			self::json( 200, self::tokenResponse( $rotated['access_token'], $rotated['refresh_token'], $rotated['grant']['scope'] ) );
		}

		self::json(
			400,
			[
				'error'             => 'unsupported_grant_type',
				'error_description' => 'Solo authorization_code y refresh_token.',
			]
		);
	}

	/**
	 * Revocación (RFC 7009): siempre 200, exista o no el token.
	 */
	public function revoke(): void {
		Connector::noStore();
		if ( ! Connector::secureRequest() ) {
			self::json( 400, [ 'error' => 'invalid_request' ] );
		}
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- RFC 7009: el token es la credencial.
		if ( '' !== $token && self::allow( 'rev', RumCollector::clientIp( $_SERVER ), 30 ) ) {
			$this->tokens->revokeToken( $token );
		}
		self::json( 200, [] );
	}

	/**
	 * PKCE S256: base64url(sha256(verifier)) === challenge.
	 */
	public static function pkce( string $verifier, string $challenge ): bool {
		if ( 1 !== preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) || '' === $challenge ) {
			return false;
		}
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE.
		return hash_equals( $challenge, $computed );
	}

	/**
	 * Límite por IP y minuto (transient; con caché de objetos, en memoria).
	 */
	public static function allow( string $kind, string $ip, int $max ): bool {
		return Connector::budget( $kind . '|' . Connector::ipKey( $ip ), $max, 60 );
	}

	/**
	 * Claude pidió poder ajustar (o no dijo qué: entonces el dueño elige).
	 */
	public static function adjustRequested( string $scope ): bool {
		return '' === trim( $scope ) || in_array( Connector::SCOPE_ADJUST, Connector::scopes( $scope ), true );
	}

	/**
	 * La sesión actual se abrió hace poco (WP_Session_Tokens guarda el momento del login).
	 */
	private static function recentLogin( int $user ): bool {
		$token = wp_get_session_token();
		if ( '' === $token ) {
			return false;
		}
		$session = \WP_Session_Tokens::get_instance( $user )->get( $token );
		$login   = is_array( $session ) && is_int( $session['login'] ?? null ) ? $session['login'] : 0;
		return $login > time() - Connector::RECENT_LOGIN;
	}

	/**
	 * Parámetros de OAuth saneados (cadenas; lo que no lo es, vacío).
	 *
	 * @param array<mixed> $in Entrada ($_GET o $_POST).
	 * @return array{client_id: string, redirect_uri: string, response_type: string, code_challenge: string, code_challenge_method: string, state: string, scope: string, resource: string}
	 */
	public static function params( array $in ): array {
		$out = [];
		foreach ( [ 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' ] as $key ) {
			$value       = $in[ $key ] ?? '';
			$out[ $key ] = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
			if ( strlen( $out[ $key ] ) > 2000 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $out[ $key ] ) ) {
				$out[ $key ] = '';
			}
		}
		/** @var array{client_id: string, redirect_uri: string, response_type: string, code_challenge: string, code_challenge_method: string, state: string, scope: string, resource: string} $out */
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function tokenResponse( string $access, string $refresh, string $scope ): array {
		return [
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => Connector::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => $scope,
		];
	}

	/**
	 * Pantalla de consentimiento.
	 *
	 * @param array{client_id: string, redirect_uri: string, response_type: string, code_challenge: string, code_challenge_method: string, state: string, scope: string, resource: string} $p      Parámetros.
	 * @param array{client_id: string, client_name: string, redirect_uris: list<string>, host: string}                                                                                         $client Cliente.
	 */
	private function consent( array $p, array $client ): never {
		$site      = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$user      = wp_get_current_user();
		$canAdjust = self::adjustRequested( $p['scope'] );
		$backHost  = (string) wp_parse_url( $p['redirect_uri'], PHP_URL_HOST );
		$loopback  = ClientMetadata::isLoopback( $p['redirect_uri'] );
		ob_start();
		?>
		<p class="lead">
			<?php
			/* translators: 1: host del cliente (claude.ai), 2: nombre del sitio */
			printf( esc_html__( '%1$s quiere usar Imagina Turbo en %2$s.', 'imagina-turbo' ), '<strong>' . esc_html( $client['host'] ) . '</strong>', '<strong>' . esc_html( $site ) . '</strong>' );
			?>
		</p>
		<p class="muted">
			<?php
			/* translators: 1: nombre que declara el cliente, 2: host de vuelta, 3: usuario */
			printf( esc_html__( 'Cliente: %1$s · vuelve a %2$s · como %3$s', 'imagina-turbo' ), esc_html( $client['client_name'] ), esc_html( $backHost ), esc_html( $user->user_login ) );
			?>
		</p>
		<?php if ( $loopback ) : ?>
			<p class="warn"><?php esc_html_e( 'La conexión vuelve a un programa en tu computador (Claude Code). Acepta solo si lo acabas de iniciar tú.', 'imagina-turbo' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( self::selfUrl( $p ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<fieldset>
				<label class="opt"><input type="radio" name="level" value="read" checked>
					<span><strong><?php esc_html_e( 'Solo diagnosticar', 'imagina-turbo' ); ?></strong>
					<?php esc_html_e( 'Lee el estado y las métricas y explica qué hacer, sin cambiar nada.', 'imagina-turbo' ); ?></span>
				</label>
				<?php if ( $canAdjust ) : ?>
				<label class="opt"><input type="radio" name="level" value="adjust">
					<span><strong><?php esc_html_e( 'Diagnosticar y ajustar', 'imagina-turbo' ); ?></strong>
					<?php esc_html_e( 'Además cambia ajustes de rendimiento de Imagina Turbo: lo seguro directamente y lo de riesgo moderado con una prueba de las páginas clave y vuelta atrás automática si algo falla. Todo queda en el registro y se puede deshacer.', 'imagina-turbo' ); ?></span>
				</label>
				<?php endif; ?>
			</fieldset>
			<p class="muted"><?php esc_html_e( 'Nunca puede tocar el token de Cloudflare, DNS, archivos, usuarios, pedidos ni otros plugins, ni apagar las protecciones del sitio. Puedes cortar el acceso cuando quieras en Imagina Turbo → Avanzado.', 'imagina-turbo' ); ?></p>
			<div class="actions">
				<button type="submit" name="decision" value="deny" class="secondary"><?php esc_html_e( 'Cancelar', 'imagina-turbo' ); ?></button>
				<button type="submit" name="decision" value="allow" class="primary"><?php esc_html_e( 'Permitir', 'imagina-turbo' ); ?></button>
			</div>
		</form>
		<?php
		self::page( __( 'Conectar Claude con Imagina Turbo', 'imagina-turbo' ), (string) ob_get_clean(), 200 );
	}

	/**
	 * URL de /authorize con los mismos parámetros.
	 *
	 * @param array<string, string> $p Parámetros.
	 */
	private static function selfUrl( array $p ): string {
		return add_query_arg( array_map( 'rawurlencode', array_filter( $p, static fn( string $v ): bool => '' !== $v ) ), Connector::issuer() . '/authorize' );
	}

	/**
	 * Error de vuelta al cliente (dirección ya validada).
	 */
	private static function back( string $uri, string $state, string $error, string $description ): never {
		self::redirect(
			add_query_arg(
				array_map(
					'rawurlencode',
					array_filter(
						[
							'error'             => $error,
							'error_description' => $description,
							'state'             => $state,
							'iss'               => Connector::issuer(),
						],
						static fn( string $v ): bool => '' !== $v
					)
				),
				$uri
			)
		);
	}

	/**
	 * Redirección a una dirección validada contra el documento del cliente (puede ser loopback, que
	 * wp_safe_redirect rechazaría).
	 */
	private static function redirect( string $url ): never {
		wp_redirect( $url, 302, 'Imagina Turbo' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- dirección validada contra el documento del cliente.
		exit;
	}

	/**
	 * Respuesta JSON y fin.
	 *
	 * @param array<string, mixed> $data Datos.
	 */
	public static function json( int $status, array $data ): never {
		status_header( $status );
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		echo wp_json_encode( [] === $data ? new \stdClass() : $data, JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Página HTML propia (sin el tema) y fin.
	 */
	private static function page( string $title, string $body, int $status ): never {
		status_header( $status );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?></title>
<style>
	body{margin:0;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif;background:#f5f5f4;color:#1c1917}
	main{max-width:520px;margin:48px auto;padding:0 16px}
	.card{background:#fff;border:1px solid #e7e5e4;border-radius:12px;padding:28px}
	h1{font-size:20px;margin:0 0 16px}
	.lead{font-size:16px}
	.muted{color:#78716c;font-size:13px}
	.warn{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;font-size:13px}
	fieldset{border:0;padding:0;margin:16px 0}
	.opt{display:flex;gap:10px;align-items:flex-start;border:1px solid #e7e5e4;border-radius:8px;padding:12px;margin-bottom:8px;cursor:pointer}
	.opt span{font-size:13px;color:#57534e}.opt strong{display:block;color:#1c1917;font-size:14px}
	.actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}
	button{font:inherit;border-radius:8px;padding:9px 16px;cursor:pointer;border:1px solid #d6d3d1;background:#fff}
	button.primary{background:#1c1917;color:#fff;border-color:#1c1917}
	/* Contra el doble clic engañoso: Permitir no responde durante el primer segundo. */
	button.primary{animation:itb-arm 1s linear forwards}
	@keyframes itb-arm{0%,99%{pointer-events:none;opacity:.45}100%{pointer-events:auto;opacity:1}}
</style>
</head>
<body><main><div class="card"><h1><?php echo esc_html( $title ); ?></h1><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado al construirlo. ?></div></main></body>
</html>
		<?php
		exit;
	}
}
