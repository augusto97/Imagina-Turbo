<?php
/**
 * Lee el estado actual de la zona: reglas de las fases gestionadas, ajustes y Smart Tiered Cache.
 *
 * Ajustes uno por uno (GET /zones/{id}/settings/{setting}): el GET masivo está obsoleto. Las políticas
 * de bots de IA viven en /zones/{id}/bot_management, que se lee una sola vez (0.21.0).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Lectura de la zona.
 */
final class Inspector {

	/** Respuesta de bot_management, leída una vez por lectura de la zona. */
	private ?Response $bots = null;

	public function __construct( private readonly Client $client, private readonly string $zoneId ) {
	}

	/**
	 * @return array{phases: array<string, array{readable: bool, exists: bool, id: string, rules: list<array<string, mixed>>, error: string}>, settings: array<string, array{readable: bool, value: mixed, editable: bool, error: string}>}
	 */
	public function read(): array {
		$phases = [];
		foreach ( Phases::MANAGED as $phase ) {
			$phases[ $phase ] = $this->phase( $phase );
		}
		$settings = [];
		foreach ( Templates::settings() as $setting ) {
			$settings[ $setting['id'] ] = $this->setting( $setting['id'] );
		}
		return [
			'phases'   => $phases,
			'settings' => $settings,
		];
	}

	/**
	 * @return array{readable: bool, exists: bool, id: string, rules: list<array<string, mixed>>, error: string}
	 */
	public function phase( string $phase ): array {
		$response = $this->client->get( Phases::entrypoint( $this->zoneId, $phase ) );
		if ( 404 === $response->status ) {
			return [
				'readable' => true,
				'exists'   => false,
				'id'       => '',
				'rules'    => [],
				'error'    => '',
			];
		}
		if ( ! $response->ok() ) {
			return [
				'readable' => false,
				'exists'   => false,
				'id'       => '',
				'rules'    => [],
				'error'    => $response->message(),
			];
		}
		return self::ruleset( $response->resultArray() );
	}

	/**
	 * Ruleset de la API (también lo devuelven POST/PATCH/DELETE de reglas).
	 *
	 * @param array<mixed> $result Ruleset.
	 * @return array{readable: bool, exists: bool, id: string, rules: list<array<string, mixed>>, error: string}
	 */
	public static function ruleset( array $result ): array {
		$rules = [];
		foreach ( is_array( $result['rules'] ?? null ) ? $result['rules'] : [] as $rule ) {
			if ( is_array( $rule ) ) {
				$rules[] = $rule;
			}
		}
		return [
			'readable' => true,
			'exists'   => true,
			'id'       => is_string( $result['id'] ?? null ) ? $result['id'] : '',
			'rules'    => $rules,
			'error'    => '',
		];
	}

	/**
	 * @return array{readable: bool, value: mixed, editable: bool, error: string}
	 */
	public function setting( string $id ): array {
		if ( in_array( $id, Templates::BOT_FIELDS, true ) ) {
			return $this->botSetting( $id );
		}
		$response = $this->client->get( self::settingPath( $this->zoneId, $id ) );
		if ( ! $response->ok() ) {
			return [
				'readable' => false,
				'value'    => null,
				'editable' => false,
				'error'    => $response->message(),
			];
		}
		$result = $response->resultArray();
		return [
			'readable' => true,
			'value'    => $result['value'] ?? null,
			'editable' => false !== ( $result['editable'] ?? true ),
			'error'    => '',
		];
	}

	/**
	 * Un campo de bot_management (necesita el permiso Bot Management en el token).
	 *
	 * @return array{readable: bool, value: mixed, editable: bool, error: string}
	 */
	private function botSetting( string $id ): array {
		$this->bots ??= $this->client->get( self::settingPath( $this->zoneId, $id ) );
		$result       = $this->bots->ok() ? $this->bots->resultArray() : [];
		if ( ! $this->bots->ok() || ! array_key_exists( $id, $result ) ) {
			return [
				'readable' => false,
				'value'    => null,
				'editable' => false,
				'error'    => $this->bots->ok() ? __( 'Cloudflare no devolvió esta política.', 'imagina-turbo' ) : $this->bots->message(),
			];
		}
		return [
			'readable' => true,
			'value'    => $result[ $id ],
			'editable' => true,
			'error'    => '',
		];
	}

	/**
	 * Smart Shield (Smart Tiered Cache, Argo, Cache Reserve y Regional Tiered Cache), solo lectura.
	 *
	 * @return array<string, array{value: string, editable: bool}>|null Null si no se pudo leer.
	 */
	public function smartShield(): ?array {
		$response = $this->client->get( '/zones/' . rawurlencode( $this->zoneId ) . '/smart_shield' );
		if ( ! $response->ok() ) {
			return null;
		}
		$out = [];
		foreach ( $response->resultArray() as $key => $item ) {
			if ( is_string( $key ) && is_array( $item ) && is_string( $item['value'] ?? null ) ) {
				$out[ $key ] = [
					'value'    => $item['value'],
					'editable' => true === ( $item['editable'] ?? false ),
				];
			}
		}
		return $out;
	}

	/**
	 * Smart Tiered Cache tiene su propio endpoint y las políticas de bots de IA van en
	 * bot_management; el resto, /settings/{id}.
	 */
	public static function settingPath( string $zoneId, string $id ): string {
		$zone = '/zones/' . rawurlencode( $zoneId );
		if ( in_array( $id, Templates::BOT_FIELDS, true ) ) {
			return $zone . '/bot_management';
		}
		return 'tiered_cache_smart_topology_enable' === $id ? $zone . '/cache/tiered_cache_smart_topology_enable' : $zone . '/settings/' . rawurlencode( $id );
	}
}
