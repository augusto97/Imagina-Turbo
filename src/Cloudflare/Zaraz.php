<?php
/**
 * Zaraz (0.18.0): Google Analytics 4 y el píxel de Meta desde Cloudflare, sin su JavaScript en la
 * página.
 *
 * Verificado (2026-10-26) en la documentación de Cloudflare (cloudflare-docs fe21aa6), el esquema
 * OpenAPI (cloudflare/api-schemas) y los repositorios de los Managed Components:
 * - GET  /zones/{id}/settings/zaraz/config: la última configuración (borrador o publicada), sin el
 *   valor de las variables secretas. GET …/export: la publicada, con secretos.
 * - PUT  …/config: la configuración completa (tools, triggers, variables, settings, dataLayer,
 *   debugKey, zarazVersion). No hay PATCH.
 * - GET/PUT …/workflow: "realtime" o "preview" (cadena JSON). POST …/publish publica el borrador.
 * - Herramienta (Managed Component): enabled, blockingTriggers, name, defaultFields, component,
 *   settings, permissions, type "component" y actions {actionType, firingTriggers, blockingTriggers,
 *   data}. El disparador del sistema de página vista es "Pageview".
 * - Píxel de Meta: component "facebook-pixel", settings property (id del píxel), accessToken (API de
 *   conversiones, obligatorio) y ecommerce; permisos access_client_kv y server_network_requests.
 * - GA4: settings tid (id de medición) y ecommerce. El nombre del componente ("google-analytics-4",
 *   el espacio de nombres de su Managed Component) no figura en ningún ejemplo de Zaraz: si la API lo
 *   rechaza, el admin explica cómo agregar la herramienta en el panel y Turbo la reconoce.
 * - E-commerce: settings.ecommerce en la configuración y en cada herramienta; zaraz.ecommerce() en la
 *   página (ZarazBridge).
 * - Permiso del token: Zona → Zaraz → Editar. Publicar puede exigir más ("Zaraz Admin"): si la API lo
 *   rechaza, se publica desde el panel.
 * - Gratis hasta 1.000.000 de eventos al mes por cuenta; sin el plan pago, Zaraz se apaga hasta el
 *   ciclo siguiente si se pasa.
 *
 * Solo se tocan las herramientas propias (id itbga4 / itbmeta) y el interruptor de e-commerce; al
 * revertir, se quitan y el interruptor vuelve a su valor anterior. Si la zona tiene variables secretas
 * se parte de la configuración exportada (con secretos) para no borrarlas; con secretos y un borrador
 * sin publicar no se toca nada.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Lectura, plan y escritura de la configuración de Zaraz.
 */
final class Zaraz {

	public const TOOL_GA4 = 'itbga4';

	public const TOOL_META = 'itbmeta';

	public const COMPONENT_GA4 = 'google-analytics-4';

	public const COMPONENT_META = 'facebook-pixel';

	/** Lo que Turbo cambió en Zaraz (para revertir). */
	public const STATE_OPTION = 'itb_zaraz_state';

	public function __construct( private readonly Client $client, private readonly string $zoneId ) {
	}

	private function base(): string {
		return '/zones/' . rawurlencode( $this->zoneId ) . '/settings/zaraz';
	}

	/**
	 * Configuración actual y modo de trabajo.
	 *
	 * @return array{ok: bool, error: string, config: array<string, mixed>, workflow: string, secrets: bool}
	 */
	public function read(): array {
		$config = $this->client->get( $this->base() . '/config' );
		if ( ! $config->ok() ) {
			return [
				'ok'       => false,
				'error'    => 403 === $config->status ? __( 'El token no tiene el permiso Zona → Zaraz → Editar.', 'imagina-turbo' ) : $config->message(),
				'config'   => [],
				'workflow' => '',
				'secrets'  => false,
			];
		}
		$workflow = $this->client->get( $this->base() . '/workflow' );
		$data     = $config->resultArray();
		return [
			'ok'       => true,
			'error'    => '',
			'config'   => $data,
			'workflow' => $workflow->ok() && is_string( $workflow->result ) ? $workflow->result : 'realtime',
			'secrets'  => self::hasSecrets( $data ),
		];
	}

	/**
	 * @param array<string, mixed> $config Configuración.
	 */
	public static function hasSecrets( array $config ): bool {
		foreach ( is_array( $config['variables'] ?? null ) ? $config['variables'] : [] as $variable ) {
			if ( is_array( $variable ) && 'secret' === ( $variable['type'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resumen para el admin: herramientas (sin secretos), e-commerce y si Zaraz se inyecta solo.
	 *
	 * @param array<string, mixed> $config Configuración.
	 * @return array{tools: list<array{id: string, name: string, component: string, enabled: bool, own: bool, id_value: string}>, ecommerce: bool, auto_inject: bool}
	 */
	public static function summary( array $config ): array {
		$tools = [];
		foreach ( is_array( $config['tools'] ?? null ) ? $config['tools'] : [] as $id => $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}
			$settings = is_array( $tool['settings'] ?? null ) ? $tool['settings'] : [];
			$tools[]  = [
				'id'        => (string) $id,
				'name'      => is_string( $tool['name'] ?? null ) ? $tool['name'] : (string) $id,
				'component' => is_string( $tool['component'] ?? null ) ? $tool['component'] : (string) ( $tool['type'] ?? '' ),
				'enabled'   => true === ( $tool['enabled'] ?? false ),
				'own'       => in_array( (string) $id, [ self::TOOL_GA4, self::TOOL_META ], true ),
				'id_value'  => is_string( $settings['tid'] ?? null ) ? $settings['tid'] : ( is_string( $settings['property'] ?? null ) ? $settings['property'] : '' ),
			];
		}
		$settings = is_array( $config['settings'] ?? null ) ? $config['settings'] : [];
		return [
			'tools'       => $tools,
			'ecommerce'   => true === ( $settings['ecommerce'] ?? false ),
			'auto_inject' => false !== ( $settings['autoInjectScript'] ?? true ),
		];
	}

	/**
	 * Herramienta existente (propia o agregada a mano) con ese componente y ese id.
	 *
	 * @param array<string, mixed> $config Configuración.
	 */
	public static function findTool( array $config, string $component, string $key, string $value ): ?string {
		foreach ( is_array( $config['tools'] ?? null ) ? $config['tools'] : [] as $id => $tool ) {
			if ( ! is_array( $tool ) || ! is_string( $tool['component'] ?? null ) || ! str_contains( $tool['component'], $component ) ) {
				continue;
			}
			$settings = is_array( $tool['settings'] ?? null ) ? $tool['settings'] : [];
			if ( '' === $value || ( is_string( $settings[ $key ] ?? null ) && strtoupper( $settings[ $key ] ) === strtoupper( $value ) ) ) {
				return (string) $id;
			}
		}
		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function ga4Tool( string $tid ): array {
		return [
			'blockingTriggers' => [],
			'component'        => self::COMPONENT_GA4,
			'defaultFields'    => (object) [],
			'enabled'          => true,
			'name'             => 'Google Analytics 4 (Imagina Turbo)',
			'permissions'      => [ 'access_client_kv', 'server_network_requests' ],
			'settings'         => [
				'tid'       => $tid,
				'ecommerce' => true,
			],
			'type'             => 'component',
			'actions'          => [
				'itbpv' => self::pageview( [ '__zaraz_setting_name' => 'Page view' ] ),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function metaTool( string $pixel, string $token ): array {
		return [
			'blockingTriggers' => [],
			'component'        => self::COMPONENT_META,
			'defaultFields'    => (object) [],
			'enabled'          => true,
			'name'             => 'Meta Pixel (Imagina Turbo)',
			'permissions'      => [ 'access_client_kv', 'server_network_requests' ],
			'settings'         => [
				'property'    => $pixel,
				'accessToken' => $token,
				'ecommerce'   => true,
			],
			'type'             => 'component',
			'actions'          => [
				'itbpv' => self::pageview(
					[
						'__zaraz_setting_name' => 'Page view',
						'ev'                   => 'PageView',
					]
				),
			],
		];
	}

	/**
	 * Acción de página vista con el disparador del sistema.
	 *
	 * @param array<string, string> $data Datos.
	 * @return array<string, mixed>
	 */
	private static function pageview( array $data ): array {
		return [
			'actionType'       => 'pageview',
			'blockingTriggers' => [],
			'data'             => $data,
			'firingTriggers'   => [ 'Pageview' ],
		];
	}

	/**
	 * Agrega o reemplaza las herramientas propias y enciende e-commerce y la inyección automática.
	 *
	 * @param array<string, mixed>               $config Configuración actual.
	 * @param array<string, array<string, mixed>> $tools  Herramientas propias por id.
	 * @return array<string, mixed>
	 */
	public static function merge( array $config, array $tools ): array {
		$current = is_array( $config['tools'] ?? null ) ? $config['tools'] : [];
		foreach ( $tools as $id => $tool ) {
			$current[ $id ] = $tool;
		}
		$config['tools']                        = $current;
		$config['settings']                     = is_array( $config['settings'] ?? null ) ? $config['settings'] : [];
		$config['settings']['ecommerce']        = true;
		$config['settings']['autoInjectScript'] = $config['settings']['autoInjectScript'] ?? true;
		return self::fillRequired( $config );
	}

	/**
	 * Quita las herramientas propias y devuelve el interruptor de e-commerce a su valor anterior.
	 *
	 * @param array<string, mixed> $config Configuración actual.
	 * @return array<string, mixed>
	 */
	public static function strip( array $config, bool $ecommerceBefore ): array {
		$tools = is_array( $config['tools'] ?? null ) ? $config['tools'] : [];
		unset( $tools[ self::TOOL_GA4 ], $tools[ self::TOOL_META ] );
		$config['tools']                 = [] === $tools ? (object) [] : $tools;
		$config['settings']              = is_array( $config['settings'] ?? null ) ? $config['settings'] : [];
		$config['settings']['ecommerce'] = $ecommerceBefore;
		return self::fillRequired( $config );
	}

	/**
	 * Los campos obligatorios del PUT, con sus valores vacíos si la zona nunca usó Zaraz.
	 *
	 * @param array<string, mixed> $config Configuración.
	 * @return array<string, mixed>
	 */
	public static function fillRequired( array $config ): array {
		foreach ( [ 'tools', 'triggers', 'variables' ] as $key ) {
			if ( ! isset( $config[ $key ] ) || [] === $config[ $key ] ) {
				$config[ $key ] = (object) [];
			}
		}
		$config['dataLayer']    = (bool) ( $config['dataLayer'] ?? false );
		$config['debugKey']     = is_string( $config['debugKey'] ?? null ) ? $config['debugKey'] : bin2hex( random_bytes( 8 ) );
		$config['zarazVersion'] = is_int( $config['zarazVersion'] ?? null ) ? $config['zarazVersion'] : 0;
		return $config;
	}

	/**
	 * Escribe la configuración y, si la zona trabaja con borrador, la publica.
	 *
	 * @param array<string, mixed> $config Configuración completa.
	 * @return array{ok: bool, error: string, published: bool}
	 */
	public function write( array $config, string $workflow ): array {
		$put = $this->client->put( $this->base() . '/config', $config );
		if ( ! $put->ok() ) {
			return [
				'ok'        => false,
				'error'     => $put->message(),
				'published' => false,
			];
		}
		if ( 'preview' !== $workflow ) {
			return [
				'ok'        => true,
				'error'     => '',
				'published' => true,
			];
		}
		$publish = $this->client->post( $this->base() . '/publish' );
		return [
			'ok'        => true,
			'error'     => $publish->ok() ? '' : __( 'La configuración quedó como borrador: publícala en Cloudflare → Zaraz (el token no puede publicar).', 'imagina-turbo' ),
			'published' => $publish->ok(),
		];
	}

	/**
	 * Base para escribir sin borrar secretos: con variables secretas, la exportada (con secretos);
	 * con secretos y borrador, null (no se toca: se perderían los cambios del borrador o los secretos).
	 *
	 * @param array{ok: bool, error: string, config: array<string, mixed>, workflow: string, secrets: bool} $read Lectura.
	 * @return array<string, mixed>|null
	 */
	public function baseConfig( array $read ): ?array {
		if ( ! $read['secrets'] ) {
			return $read['config'];
		}
		if ( 'preview' === $read['workflow'] ) {
			return null;
		}
		$export = $this->client->get( $this->base() . '/export' );
		return $export->ok() ? $export->resultArray() : null;
	}
}
