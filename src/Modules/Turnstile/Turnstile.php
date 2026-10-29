<?php
/**
 * Cloudflare Turnstile (0.21.0): claves, ajustes y verificación del token.
 *
 * Verificado en la documentación de Turnstile (2026-10-27):
 * - Siteverify: POST https://challenges.cloudflare.com/turnstile/v0/siteverify con secret, response y
 *   remoteip. El token dura 300 s y vale una sola vez (timeout-or-duplicate). Códigos de error:
 *   missing-input-secret, invalid-input-secret, missing-input-response, invalid-input-response,
 *   bad-request, timeout-or-duplicate e internal-error.
 * - api.js se carga desde su URL exacta, sin copiarlo ni cachearlo; con render=explicit y onload si se
 *   inserta después de cargar la página.
 * - Claves de prueba: 1x00000000000000000000AA (pasa) y 2x00000000000000000000AB (falla); secretos
 *   1x0000000000000000000000000000000AA (pasa) y 2x0000000000000000000000000000000AA (falla).
 *
 * Falla abierto (regla 7): si siteverify no responde, responde un error interno o la clave secreta deja
 * de valer, el formulario sigue y queda un aviso en el registro (como mucho uno por hora). La prueba de
 * claves del admin, en cambio, exige una verificación real: sin ella no se activa ningún formulario.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Turnstile;

use ImaginaTurbo\Cloudflare\TokenVault;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Runtime;

/**
 * Cliente de siteverify y lectura de los ajustes compilados.
 */
final class Turnstile {

	/** Opción con la clave secreta cifrada (sodium, como el token de Cloudflare). */
	public const SECRET_OPTION = 'itb_turnstile_secret';

	public const SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	public const API_JS = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

	/** Campo que el widget añade al formulario. */
	public const FIELD = 'cf-turnstile-response';

	/** Formularios que se pueden proteger, cada uno con su interruptor. */
	public const FORMS = [ 'login', 'register', 'lostpassword', 'checkout' ];

	/** Espacio de nombres de los datos de extensión del checkout por bloques. */
	public const EXTENSION = 'imagina-turbo';

	/** Errores de configuración o de Cloudflare: el formulario sigue. */
	private const FAIL_OPEN = [ 'missing-input-secret', 'invalid-input-secret', 'internal-error' ];

	private const FAIL_OPEN_FLAG = 'itb_ts_failopen';

	public function __construct( private readonly TokenVault $vault, private readonly EventLog $events ) {
	}

	/**
	 * Ajustes compilados: vacío si el módulo está apagado o las claves no pasaron la prueba.
	 *
	 * @return array{key: string, forms: array<string, bool>, skip: list<string>}|array{}
	 */
	public static function compiled(): array {
		$c = Runtime::$config?->get( 'turnstile', [] );
		if ( ! is_array( $c ) || ! is_string( $c['key'] ?? null ) || '' === $c['key'] ) {
			return [];
		}
		$forms = [];
		foreach ( self::FORMS as $form ) {
			$forms[ $form ] = true === ( $c['forms'][ $form ] ?? false );
		}
		return [
			'key'   => $c['key'],
			'forms' => $forms,
			'skip'  => is_array( $c['skip'] ?? null ) ? array_values( array_filter( $c['skip'], 'is_string' ) ) : [],
		];
	}

	public static function validSiteKey( string $key ): bool {
		return 1 === preg_match( '/^[0-9A-Za-z_\-]{10,100}$/', $key );
	}

	/**
	 * Firma de la clave del sitio y del secreto guardado: si cambia cualquiera, hay que volver a probarlas.
	 *
	 * @param array{state: string, last4: string, saved: int} $secret Estado del secreto (TokenVault::status).
	 */
	public static function fingerprint( string $siteKey, array $secret ): string {
		return substr( hash( 'sha256', $siteKey . '|' . $secret['last4'] . '|' . $secret['saved'] ), 0, 16 );
	}

	public static function vault(): TokenVault {
		return new TokenVault( null, self::SECRET_OPTION );
	}

	/**
	 * Verifica el token de un formulario. ok también es true cuando falla abierto (code "failopen").
	 *
	 * @return array{ok: bool, code: string}
	 */
	public function verify( string $token, string $action, string $ip ): array {
		$token = trim( $token );
		if ( '' === $token ) {
			return [
				'ok'   => false,
				'code' => 'missing',
			];
		}
		if ( strlen( $token ) > 2048 ) {
			return [
				'ok'   => false,
				'code' => 'invalid',
			];
		}
		$secret = $this->vault->read();
		if ( null === $secret ) {
			$this->failOpen( 'secret_unreadable' );
			return [
				'ok'   => true,
				'code' => 'failopen',
			];
		}
		$reply = $this->siteverify( $secret, $token, $ip );
		if ( null === $reply ) {
			$this->failOpen( 'unreachable' );
			return [
				'ok'   => true,
				'code' => 'failopen',
			];
		}
		return self::judge( $reply, $action, fn( string $why ) => $this->failOpen( $why ) );
	}

	/**
	 * Prueba de las claves desde el admin: verificación real, sin fallar abierto.
	 *
	 * @return array{ok: bool, codes: list<string>}
	 */
	public function test( string $token, string $ip ): array {
		$secret = $this->vault->read();
		if ( null === $secret ) {
			return [
				'ok'    => false,
				'codes' => [ 'missing-input-secret' ],
			];
		}
		$reply = $this->siteverify( $secret, trim( $token ), $ip );
		if ( null === $reply ) {
			return [
				'ok'    => false,
				'codes' => [ 'unreachable' ],
			];
		}
		return [
			'ok'    => true === ( $reply['success'] ?? false ),
			'codes' => self::codes( $reply ),
		];
	}

	/**
	 * Decide con la respuesta de siteverify. Con éxito, la acción del widget tiene que coincidir: un
	 * token del login no sirve para el registro.
	 *
	 * @param array<mixed>                $reply    Respuesta decodificada.
	 * @param callable(string): void|null $failOpen Aviso cuando falla abierto.
	 * @return array{ok: bool, code: string}
	 */
	public static function judge( array $reply, string $action, ?callable $failOpen = null ): array {
		if ( true === ( $reply['success'] ?? false ) ) {
			$got = $reply['action'] ?? '';
			if ( '' !== $action && is_string( $got ) && '' !== $got && $got !== $action ) {
				return [
					'ok'   => false,
					'code' => 'invalid',
				];
			}
			return [
				'ok'   => true,
				'code' => '',
			];
		}
		$codes = self::codes( $reply );
		if ( [] !== array_intersect( $codes, self::FAIL_OPEN ) ) {
			if ( null !== $failOpen ) {
				$failOpen( implode( ',', $codes ) );
			}
			return [
				'ok'   => true,
				'code' => 'failopen',
			];
		}
		return [
			'ok'   => false,
			'code' => in_array( 'timeout-or-duplicate', $codes, true ) ? 'expired' : 'invalid',
		];
	}

	/**
	 * Mensaje para el visitante.
	 */
	public static function message( string $code ): string {
		return 'missing' === $code
			? __( 'Completa la verificación de seguridad e inténtalo de nuevo.', 'imagina-turbo' )
			: __( 'La verificación de seguridad caducó o no es válida. Inténtalo de nuevo.', 'imagina-turbo' );
	}

	/**
	 * @param array<mixed> $reply Respuesta.
	 * @return list<string>
	 */
	private static function codes( array $reply ): array {
		$codes = $reply['error-codes'] ?? [];
		return is_array( $codes ) ? array_values( array_filter( $codes, 'is_string' ) ) : [];
	}

	/**
	 * @return array<mixed>|null Null si Cloudflare no respondió con JSON (red o 5xx).
	 */
	private function siteverify( string $secret, string $token, string $ip ): ?array {
		$body = [
			'secret'   => $secret,
			'response' => $token,
		];
		if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$body['remoteip'] = $ip;
		}
		$response = wp_remote_post(
			self::SITEVERIFY,
			[
				'timeout'     => 5,
				'redirection' => 0,
				'body'        => $body,
			]
		);
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 500 ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}

	private function failOpen( string $why ): void {
		if ( false !== get_transient( self::FAIL_OPEN_FLAG ) ) {
			return;
		}
		set_transient( self::FAIL_OPEN_FLAG, 1, HOUR_IN_SECONDS );
		$this->events->warning(
			'turnstile',
			'fail_open',
			__( 'Turnstile no pudo verificar un formulario (Cloudflare no respondió o la clave secreta ya no vale): se dejó pasar para no bloquear a nadie. Revisa las claves en Firewall → Turnstile.', 'imagina-turbo' ),
			[ 'reason' => $why ]
		);
	}
}
