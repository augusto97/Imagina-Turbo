<?php
/**
 * Cliente del hub: registro del sitio y envío horario de telemetría.
 *
 * El sitio nunca depende del hub (regla 7): todo corre en tareas programadas o en acciones del admin,
 * con tiempo de espera corto, y un fallo solo programa un reintento. Si el hub no responde, los datos
 * de más de 24 h se descartan.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Hub;

use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Licensing\ImaginaUpdater;
use ImaginaTurbo\Modules\Profiler\Rollup;

/**
 * Registro, envío y estado.
 */
final class HubClient {

	public const STATE_OPTION = 'itb_hub';

	public const KEY_OPTION = 'itb_site_key';

	/** Nonces de las peticiones del hub ya vistas (anti-replay, 10 minutos). */
	public const NONCES_OPTION = 'itb_hub_nonces';

	public const TIMEOUT = 8;

	/** Ventana máxima de datos pendientes: lo más antiguo se descarta. */
	public const MAX_WINDOW_HOURS = 24;

	/** Espera máxima entre reintentos. */
	private const MAX_BACKOFF = 6 * HOUR_IN_SECONDS;

	public function __construct(
		private readonly Settings $settings,
		private readonly TelemetryBuilder $builder,
		private readonly ImaginaUpdater $license,
		private readonly EventLog $events,
	) {
	}

	public function url(): string {
		$url = $this->settings->get( 'hub_url', '' );
		return is_string( $url ) ? $url : '';
	}

	public function isConfigured(): bool {
		return '' !== $this->url();
	}

	public function isRegistered(): bool {
		return '' !== $this->siteId() && '' !== $this->siteKey();
	}

	/**
	 * @return array{site_id: string, registered_at: int, last_sent_hour: int, failures: int, next_attempt: int, last_error: string, last_ok_at: int, hub: string}
	 */
	public function state(): array {
		$stored = get_option( self::STATE_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		return [
			'site_id'        => is_string( $stored['site_id'] ?? null ) ? $stored['site_id'] : '',
			'registered_at'  => is_int( $stored['registered_at'] ?? null ) ? $stored['registered_at'] : 0,
			'last_sent_hour' => is_int( $stored['last_sent_hour'] ?? null ) ? $stored['last_sent_hour'] : 0,
			'failures'       => is_int( $stored['failures'] ?? null ) ? $stored['failures'] : 0,
			'next_attempt'   => is_int( $stored['next_attempt'] ?? null ) ? $stored['next_attempt'] : 0,
			'last_error'     => is_string( $stored['last_error'] ?? null ) ? $stored['last_error'] : '',
			'last_ok_at'     => is_int( $stored['last_ok_at'] ?? null ) ? $stored['last_ok_at'] : 0,
			'hub'            => is_string( $stored['hub'] ?? null ) ? $stored['hub'] : '',
		];
	}

	/**
	 * Estado para el admin (sin la clave).
	 *
	 * @return array<string, mixed>
	 */
	public function status( ?int $now = null ): array {
		$state = $this->state();
		$now   = $now ?? time();
		$hour  = self::sendableUntil( $now, get_option( Rollup::STATE_OPTION, 0 ) );
		$from  = max( $state['last_sent_hour'], $hour - self::MAX_WINDOW_HOURS * HOUR_IN_SECONDS );
		return [
			'configured'    => $this->isConfigured(),
			'url'           => $this->url(),
			'registered'    => $this->isRegistered() && $state['hub'] === $this->url(),
			'site_id'       => $state['site_id'],
			'registered_at' => $state['registered_at'],
			'last_ok_at'    => $state['last_ok_at'],
			'last_error'    => $state['last_error'],
			'failures'      => $state['failures'],
			'next_attempt'  => $state['next_attempt'],
			'pending_hours' => $state['last_sent_hour'] > 0 ? max( 0, (int) ( ( $hour - $from ) / HOUR_IN_SECONDS ) ) : 0,
			'license'       => '' !== $this->license->licenseKey(),
		];
	}

	/**
	 * Registra el sitio: el hub devuelve site_id y site_key.
	 *
	 * @return true|\WP_Error
	 */
	public function register(): bool|\WP_Error {
		if ( ! $this->isConfigured() ) {
			return new \WP_Error( 'itb_hub_off', __( 'Configura la URL del hub primero.', 'imagina-turbo' ) );
		}
		$body     = (string) wp_json_encode(
			[
				'site_url'       => home_url( '/' ),
				'plugin_version' => ITB_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
			]
		);
		$response = wp_remote_post(
			$this->url() . '/v1/sites/register',
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => [
					'Content-Type'  => 'application/json',
					'X-ITB-License' => $this->license->licenseKey(),
					'User-Agent'    => 'ImaginaTurbo/' . ITB_VERSION,
				],
				'body'        => $body,
			]
		);
		$error    = self::responseError( $response );
		if ( null !== $error ) {
			$this->events->warning( 'hub', 'register', __( 'No se pudo registrar el sitio en el hub.', 'imagina-turbo' ), [ 'error' => $error ] );
			return new \WP_Error( 'itb_hub_register', $error );
		}
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$siteId = is_array( $data ) && is_string( $data['site_id'] ?? null ) ? $data['site_id'] : '';
		$key    = is_array( $data ) && is_string( $data['site_key'] ?? null ) ? $data['site_key'] : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{4,64}$/', $siteId ) || strlen( $key ) < 32 || strlen( $key ) > 256 ) {
			return new \WP_Error( 'itb_hub_register', __( 'El hub respondió sin un site_id o site_key válidos.', 'imagina-turbo' ) );
		}

		update_option( self::KEY_OPTION, $key, false );
		$now = time();
		$this->saveState(
			[
				'site_id'        => $siteId,
				'registered_at'  => $now,
				// El primer lote lleva las últimas 24 h ya agregadas.
				'last_sent_hour' => $now - ( $now % HOUR_IN_SECONDS ) - self::MAX_WINDOW_HOURS * HOUR_IN_SECONDS,
				'failures'       => 0,
				'next_attempt'   => 0,
				'last_error'     => '',
				'hub'            => $this->url(),
			]
		);
		$this->events->info( 'hub', 'register', __( 'Sitio registrado en el hub.', 'imagina-turbo' ), [ 'site_id' => $siteId ] );
		return true;
	}

	/**
	 * Olvida el registro (la clave y el estado). No avisa al hub.
	 */
	public function disconnect(): void {
		delete_option( self::KEY_OPTION );
		delete_option( self::STATE_OPTION );
		$this->events->info( 'hub', 'disconnect', __( 'Conexión con el hub eliminada.', 'imagina-turbo' ) );
	}

	/**
	 * Envía las horas completas pendientes. Respeta el backoff salvo que se fuerce desde el admin.
	 *
	 * @return array{sent: bool, reason: string}
	 */
	public function sendTelemetry( bool $force = false, ?int $now = null ): array {
		$now = $now ?? time();
		if ( ! $this->isConfigured() || ! $this->isRegistered() ) {
			return [
				'sent'   => false,
				'reason' => 'not_registered',
			];
		}
		$state = $this->state();
		if ( $state['hub'] !== $this->url() ) {
			return [
				'sent'   => false,
				'reason' => 'hub_changed',
			];
		}
		if ( ! $force && $state['next_attempt'] > $now ) {
			return [
				'sent'   => false,
				'reason' => 'backoff',
			];
		}

		$to   = self::sendableUntil( $now, get_option( Rollup::STATE_OPTION, 0 ) );
		$from = max( $state['last_sent_hour'], $to - self::MAX_WINDOW_HOURS * HOUR_IN_SECONDS );
		if ( $from >= $to ) {
			return [
				'sent'   => false,
				'reason' => 'nothing_pending',
			];
		}

		$json = (string) wp_json_encode( $this->builder->build( $state['site_id'], $from, $to ) );
		$body = function_exists( 'gzencode' ) ? (string) gzencode( $json, 6 ) : $json;
		$url  = $this->url() . '/v1/telemetry';
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		$headers = array_merge(
			Signer::headers( $state['site_id'], $this->siteKey(), $this->license->licenseKey(), 'POST', $path, $body, $now ),
			[
				'Content-Type' => 'application/json',
				'User-Agent'   => 'ImaginaTurbo/' . ITB_VERSION,
			]
		);
		if ( function_exists( 'gzencode' ) ) {
			$headers['Content-Encoding'] = 'gzip';
		}

		$response = wp_remote_post(
			$url,
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => $body,
			]
		);
		$error    = self::responseError( $response );

		if ( null === $error ) {
			$this->saveState(
				array_merge(
					$state,
					[
						'last_sent_hour' => $to,
						'failures'       => 0,
						'next_attempt'   => 0,
						'last_error'     => '',
						'last_ok_at'     => $now,
					]
				)
			);
			return [
				'sent'   => true,
				'reason' => 'ok',
			];
		}

		$failures = $state['failures'] + 1;
		$this->saveState(
			array_merge(
				$state,
				[
					// Lo que quede fuera de la ventana de 24 h ya no se reintenta.
					'last_sent_hour' => max( $state['last_sent_hour'], $to - self::MAX_WINDOW_HOURS * HOUR_IN_SECONDS ),
					'failures'       => $failures,
					'next_attempt'   => $now + self::backoff( $failures ),
					'last_error'     => $error,
				]
			)
		);
		if ( 1 === $failures || 0 === $failures % 6 ) {
			$this->events->warning( 'hub', 'telemetry', __( 'El hub no recibió la telemetría; se reintentará.', 'imagina-turbo' ), [ 'error' => $error ] );
		}
		return [
			'sent'   => false,
			'reason' => 'error',
		];
	}

	/**
	 * Descarga la plantilla de flota (GET /v1/fleet/policy, firmada). El hub firma también la
	 * respuesta: HMAC con la clave del sitio sobre "RESPONSE\nRUTA\nTIMESTAMP\nNONCE de la
	 * petición\nsha256(cuerpo)" en X-ITB-Timestamp y X-ITB-Signature. Así una respuesta no sirve
	 * para otra petición ni para otro sitio.
	 *
	 * @return array<string, mixed>|null|\WP_Error Plantilla (JSON decodificado), null si el hub no tiene ninguna (204/404).
	 */
	public function fetchPolicy( ?int $now = null ): array|null|\WP_Error {
		if ( ! $this->isConfigured() || ! $this->isRegistered() || $this->state()['hub'] !== $this->url() ) {
			return new \WP_Error( 'itb_hub_off', __( 'El sitio no está registrado en el hub.', 'imagina-turbo' ) );
		}
		$now     = $now ?? time();
		$url     = $this->url() . '/v1/fleet/policy';
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$nonce   = bin2hex( random_bytes( 16 ) );
		$headers = Signer::headers( $this->siteId(), $this->siteKey(), $this->license->licenseKey(), 'GET', $path, '', $now, $nonce );

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => $headers + [ 'User-Agent' => 'ImaginaTurbo/' . ITB_VERSION ],
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 204 === $code || 404 === $code ) {
			return null;
		}
		$error = self::responseError( $response );
		if ( null !== $error ) {
			return new \WP_Error( 'itb_hub_http', $error );
		}
		$body      = (string) wp_remote_retrieve_body( $response );
		$timestamp = wp_remote_retrieve_header( $response, 'x-itb-timestamp' );
		$signature = wp_remote_retrieve_header( $response, 'x-itb-signature' );
		if ( ! is_string( $timestamp ) || ! is_string( $signature ) || ! Signer::verify( $this->siteKey(), 'RESPONSE', $path, $timestamp, $nonce, $body, $signature, $now ) ) {
			$this->events->warning( 'hub', 'fleet_signature', __( 'La plantilla de flota llegó sin una firma válida del hub y se descartó.', 'imagina-turbo' ) );
			return new \WP_Error( 'itb_hub_signature', __( 'La respuesta del hub no tiene una firma válida.', 'imagina-turbo' ) );
		}
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : new \WP_Error( 'itb_hub_json', __( 'La plantilla del hub no es JSON válido.', 'imagina-turbo' ) );
	}

	/**
	 * Verifica una petición del hub al sitio (lotes de flota): sitio, firma, ventana de 5 minutos y
	 * nonce no repetido. Devuelve null si es válida o el motivo del rechazo.
	 *
	 * @param array<string, string> $headers Cabeceras en minúsculas.
	 */
	public function verifyIncoming( string $method, string $path, string $body, array $headers, ?int $now = null ): ?string {
		$now = $now ?? time();
		if ( ! $this->isRegistered() || $this->state()['hub'] !== $this->url() ) {
			return 'not_registered';
		}
		if ( ! hash_equals( $this->siteId(), (string) ( $headers['x-itb-site'] ?? '' ) ) ) {
			return 'site';
		}
		$nonce = (string) ( $headers['x-itb-nonce'] ?? '' );
		if ( ! Signer::verify( $this->siteKey(), $method, $path, (string) ( $headers['x-itb-timestamp'] ?? '' ), $nonce, $body, (string) ( $headers['x-itb-signature'] ?? '' ), $now ) ) {
			return 'signature';
		}
		$seen = get_option( self::NONCES_OPTION, [] );
		$seen = array_filter( is_array( $seen ) ? $seen : [], static fn( $t ): bool => is_int( $t ) && $t > $now - 2 * Signer::MAX_SKEW );
		if ( isset( $seen[ $nonce ] ) ) {
			return 'replay';
		}
		$seen[ $nonce ] = $now;
		update_option( self::NONCES_OPTION, array_slice( $seen, -500, null, true ), false );
		return null;
	}

	/**
	 * Hasta dónde se puede enviar: horas completas que ya pasaron por el agregado horario, para no
	 * marcar como enviada una hora cuyas métricas aún no existen. Si el agregado lleva más de 2 h
	 * parado (perfilador apagado, cron caído), no frena el envío del inventario y los eventos.
	 *
	 * @param mixed $rolledUp Última hora agregada (opción del agregado).
	 */
	public static function sendableUntil( int $now, mixed $rolledUp ): int {
		$hour      = $now - ( $now % HOUR_IN_SECONDS );
		$rolledEnd = is_numeric( $rolledUp ) ? (int) $rolledUp + HOUR_IN_SECONDS : 0;
		return $rolledEnd >= $hour - 2 * HOUR_IN_SECONDS ? min( $hour, $rolledEnd ) : $hour;
	}

	/**
	 * Espera antes del siguiente intento: 1 h, 2 h, 4 h… hasta 6 h.
	 */
	public static function backoff( int $failures ): int {
		return (int) min( self::MAX_BACKOFF, HOUR_IN_SECONDS * ( 2 ** max( 0, $failures - 1 ) ) );
	}

	private function siteId(): string {
		return $this->state()['site_id'];
	}

	private function siteKey(): string {
		$key = get_option( self::KEY_OPTION, '' );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * @param array<string, mixed> $state Estado.
	 */
	private function saveState( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Mensaje de error de una respuesta, o null si fue 2xx.
	 *
	 * @param array<string, mixed>|\WP_Error $response Respuesta de wp_remote_*.
	 */
	private static function responseError( array|\WP_Error $response ): ?string {
		if ( is_wp_error( $response ) ) {
			return substr( $response->get_error_message(), 0, 200 );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return null;
		}
		/* translators: %d: código HTTP */
		return sprintf( __( 'El hub respondió %d.', 'imagina-turbo' ), $code );
	}
}
