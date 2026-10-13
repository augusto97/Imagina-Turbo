<?php
/**
 * Lee el estado actual de la zona: reglas de las fases gestionadas, ajustes y Smart Tiered Cache.
 *
 * Ajustes uno por uno (GET /zones/{id}/settings/{setting}): el GET masivo está obsoleto.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Lectura de la zona.
 */
final class Inspector {

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
	 * Smart Tiered Cache tiene su propio endpoint; el resto, /settings/{id}.
	 */
	public static function settingPath( string $zoneId, string $id ): string {
		$zone = '/zones/' . rawurlencode( $zoneId );
		return 'tiered_cache_smart_topology_enable' === $id ? $zone . '/cache/tiered_cache_smart_topology_enable' : $zone . '/settings/' . rawurlencode( $id );
	}
}
