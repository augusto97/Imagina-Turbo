<?php
/**
 * Turnstile (0.21.0): GET /turnstile, PUT /turnstile, DELETE /turnstile, POST /turnstile/test.
 *
 * Ningún formulario se activa sin una prueba real de las claves: el admin resuelve el widget con la
 * clave del sitio y el servidor verifica ese token con la clave secreta guardada. Si después cambia
 * cualquiera de las dos, los formularios se apagan hasta repetir la prueba.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\Rum\RumCollector;
use ImaginaTurbo\Modules\Turnstile\Turnstile;

/**
 * Claves, formularios y prueba.
 */
final class TurnstileController extends Controller {

	/** Otros plugins que ponen captcha en los mismos formularios (dos verificaciones a la vez). */
	private const CAPTCHA_PLUGINS = [
		'simple-cloudflare-turnstile/simple-cloudflare-turnstile.php' => 'Simple Cloudflare Turnstile',
		'advanced-google-recaptcha/advanced-google-recaptcha.php' => 'Advanced Google reCAPTCHA',
		'google-captcha/google-captcha.php'   => 'reCaptcha by BestWebSoft',
		'login-recaptcha/login-nocaptcha.php' => 'Login No Captcha reCAPTCHA',
		'recaptcha-woo/recaptcha-woo.php'     => 'reCAPTCHA for WooCommerce',
	];

	public function registerRoutes(): void {
		$this->route( '/turnstile', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$forms = [];
		foreach ( Turnstile::FORMS as $form ) {
			$forms[ $form ] = [ 'type' => 'boolean' ];
		}
		$this->route(
			'/turnstile',
			'PUT',
			[ $this, 'save' ],
			[
				'site_key' => [
					'type'      => 'string',
					'maxLength' => 100,
				],
				'secret'   => [
					'type'      => 'string',
					'maxLength' => 200,
				],
				'forms'    => [
					'type'                 => 'object',
					'properties'           => $forms,
					'additionalProperties' => false,
				],
				'skip'     => [
					'type'     => 'array',
					'items'    => [
						'type'    => 'string',
						'pattern' => '^[A-Za-z0-9_\-]{1,60}$',
					],
					'maxItems' => 30,
				],
			]
		);
		$this->route( '/turnstile', 'DELETE', [ $this, 'remove' ] );
		$this->route(
			'/turnstile/test',
			\WP_REST_Server::CREATABLE,
			[ $this, 'test' ],
			[
				'token' => [
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 2048,
				],
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$settings = $this->container->get( Settings::class );
		$key      = (string) $settings->get( 'turnstile_site_key', '' );
		$secret   = Turnstile::vault()->status();
		$forms    = (array) $settings->get( 'turnstile_forms', [] );
		$on       = [];
		foreach ( Turnstile::FORMS as $form ) {
			$on[ $form ] = true === ( $forms[ $form ] ?? false );
		}
		$verified = '' !== $key && 'ok' === $secret['state'] && (string) $settings->get( 'turnstile_verified', '' ) === Turnstile::fingerprint( $key, $secret );
		$gateways = [];
		if ( function_exists( 'WC' ) && null !== WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				if ( is_object( $gateway ) && 'yes' === ( $gateway->enabled ?? 'no' ) ) {
					$gateways[] = [
						'id'    => (string) $id,
						'title' => wp_strip_all_tags( (string) ( method_exists( $gateway, 'get_method_title' ) ? $gateway->get_method_title() : $id ) ),
					];
				}
			}
		}
		$conflicts = [];
		foreach ( self::CAPTCHA_PLUGINS as $file => $name ) {
			if ( in_array( $file, (array) get_option( 'active_plugins', [] ), true ) ) {
				$conflicts[] = $name;
			}
		}
		$enabled = $this->container->get( Settings::class )->modules()['turnstile'] ?? false;
		return [
			'enabled'   => $enabled,
			// Lo que la config compilada aplicará (la del request actual puede ser anterior al cambio).
			'active'    => $enabled && $verified && in_array( true, $on, true ),
			'site_key'  => $key,
			'secret'    => [
				'state' => $secret['state'],
				'last4' => $secret['last4'],
			],
			'verified'  => $verified,
			'forms'     => $on,
			'skip'      => array_values( array_filter( (array) $settings->get( 'turnstile_skip', [] ), 'is_string' ) ),
			'gateways'  => $gateways,
			'woo'       => function_exists( 'WC' ),
			'conflicts' => $conflicts,
			'api_js'    => Turnstile::API_JS,
			'dashboard' => 'https://dash.cloudflare.com/?to=/:account/turnstile',
		];
	}

	public function save( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$settings = $this->container->get( Settings::class );
		$changes  = [];
		$rekey    = false;
		$key      = $request->get_param( 'site_key' );
		if ( is_string( $key ) && trim( $key ) !== (string) $settings->get( 'turnstile_site_key', '' ) ) {
			if ( ! Turnstile::validSiteKey( trim( $key ) ) ) {
				return $this->error( 'itb_turnstile_key', __( 'La clave del sitio no tiene la forma de una clave de Turnstile.', 'imagina-turbo' ), 400 );
			}
			$changes['turnstile_site_key'] = trim( $key );
			$rekey                         = true;
		}
		$secret = $request->get_param( 'secret' );
		if ( is_string( $secret ) && '' !== trim( $secret ) ) {
			if ( ! Turnstile::vault()->store( $secret ) ) {
				return $this->error( 'itb_turnstile_secret', __( 'No se pudo guardar la clave secreta: revisa que esté completa (o que el servidor tenga sodium).', 'imagina-turbo' ), 400 );
			}
			$rekey = true;
		}
		if ( $rekey ) {
			// Claves nuevas: los formularios se apagan hasta repetir la prueba.
			$changes['turnstile_verified'] = '';
		}
		$forms = $request->get_param( 'forms' );
		if ( is_array( $forms ) ) {
			$current = (array) $settings->get( 'turnstile_forms', [] );
			foreach ( Turnstile::FORMS as $form ) {
				if ( is_bool( $forms[ $form ] ?? null ) ) {
					$current[ $form ] = $forms[ $form ];
				}
			}
			$changes['turnstile_forms'] = $current;
		}
		$skip = $request->get_param( 'skip' );
		if ( is_array( $skip ) ) {
			$changes['turnstile_skip'] = $skip;
		}
		$settings->update( $changes );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info(
			'turnstile',
			'settings',
			$rekey ? __( 'Claves de Turnstile cambiadas: los formularios quedan apagados hasta probarlas.', 'imagina-turbo' ) : __( 'Ajustes de Turnstile guardados.', 'imagina-turbo' ),
			[
				'user'  => get_current_user_id(),
				'forms' => $changes['turnstile_forms'] ?? null,
				'skip'  => $changes['turnstile_skip'] ?? null,
			]
		);
		return $this->ok( $this->status() );
	}

	/**
	 * Quita las claves y apaga todos los formularios.
	 */
	public function remove(): \WP_REST_Response {
		delete_option( Turnstile::SECRET_OPTION );
		$this->container->get( Settings::class )->update(
			[
				'turnstile_site_key' => '',
				'turnstile_verified' => '',
				'turnstile_forms'    => [],
			]
		);
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info( 'turnstile', 'removed', __( 'Claves de Turnstile eliminadas.', 'imagina-turbo' ), [ 'user' => get_current_user_id() ] );
		return $this->ok( $this->status() );
	}

	/**
	 * Token del widget del admin → siteverify con la clave secreta guardada.
	 */
	public function test( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$settings = $this->container->get( Settings::class );
		$key      = (string) $settings->get( 'turnstile_site_key', '' );
		if ( '' === $key || 'ok' !== Turnstile::vault()->status()['state'] ) {
			return $this->error( 'itb_turnstile_keys', __( 'Guarda primero la clave del sitio y la clave secreta.', 'imagina-turbo' ), 409 );
		}
		$result = $this->container->get( Turnstile::class )->test( (string) $request->get_param( 'token' ), RumCollector::clientIp( $_SERVER ) );
		if ( $result['ok'] ) {
			$settings->update( [ 'turnstile_verified' => Turnstile::fingerprint( $key, Turnstile::vault()->status() ) ] );
			$this->container->get( ConfigCompiler::class )->compile();
		}
		$this->container->get( EventLog::class )->info(
			'turnstile',
			'test',
			$result['ok'] ? __( 'Claves de Turnstile probadas: funcionan.', 'imagina-turbo' ) : __( 'La prueba de las claves de Turnstile falló.', 'imagina-turbo' ),
			[
				'user'  => get_current_user_id(),
				'codes' => $result['codes'],
			]
		);
		return $this->ok( $this->status() + [ 'test' => $result ] );
	}
}
