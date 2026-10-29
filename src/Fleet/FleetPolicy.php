<?php
/**
 * Plantilla de flota (docs/04, protocolo con el hub): la configuración estándar que el hub propone
 * para un grupo de sitios. Validación y desviaciones (cálculo puro).
 *
 * Una plantilla solo puede tocar:
 * - ajustes de una lista blanca (nunca el hub, el prefijo de tags, los dominios de staging ni estados
 *   temporales);
 * - interruptores de módulos y de ajustes rápidos seguros o moderados. Lo invasivo (carga
 *   condicional, quitar jQuery Migrate) nunca entra por plantilla: se ignora y se informa;
 * - reglas y ajustes de Cloudflare por su nombre de plantilla (cache-html, fw-exploits…) o id de
 *   ajuste. El sitio genera las reglas con sus propios datos (host, rutas, prefijo); la plantilla
 *   solo dice cuáles quiere.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Fleet;

use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\ModuleCatalog;
use ImaginaTurbo\Core\RiskLevel;
use ImaginaTurbo\Modules\Tweaks\TweakCatalog;

/**
 * Validación y comparación.
 *
 * @phpstan-type Policy array{id: string, version: int, name: string, settings: array<string, mixed>, modules: array<string, bool>, tweaks: array<string, bool>, cloudflare: array{rules: list<string>, settings: list<string>}, ignored: list<string>}
 * @phpstan-type Drift array{kind: string, key: string, label: string, current: mixed, desired: mixed, risk: string}
 */
final class FleetPolicy {

	/** Ajustes que una plantilla puede fijar. */
	public const SETTINGS = [
		'profiler_sample_rate',
		'cache_mode',
		'cache_edge_ttl',
		'cache_swr',
		'cache_sie',
		'cache_nonce_refresh',
		'cache_warm',
		'woo_recently_viewed',
		'shield_static',
		'shield_author',
		'shield_xmlrpc',
		'shield_rest_users',
		'shield_heartbeat',
		'shield_heartbeat_admin',
		'shield_heartbeat_editor',
		'shield_filter_limit',
		'shield_facets',
		'shield_facets_views',
		'shield_facets_robots',
		'tweak_revisions',
		'tweak_autosave',
		'firewall_rules',
		'firewall_ratelimit',
		'rum_rate',
		'spec_mode',
		'spec_eagerness',
		'spec_predictive',
		'spec_fallback',
		'view_transitions',
		'hints_lcp',
		'hints_fonts',
		'hints_facades',
		'db_keep_revisions',
		'db_schedule',
		'db_schedule_days',
		'budgets',
	];

	/**
	 * Valida la plantilla recibida. Null si no tiene la forma esperada.
	 *
	 * @param mixed $raw JSON decodificado.
	 * @return Policy|null
	 */
	public static function normalize( mixed $raw, ModuleCatalog $catalog ): ?array {
		if ( ! is_array( $raw ) || 1 !== ( $raw['schema'] ?? null ) ) {
			return null;
		}
		$id      = is_string( $raw['id'] ?? null ) ? $raw['id'] : '';
		$version = $raw['version'] ?? null;
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $id ) || ! is_int( $version ) || $version < 1 ) {
			return null;
		}
		$ignored = [];

		$settings = [];
		$input    = is_array( $raw['settings'] ?? null ) ? $raw['settings'] : [];
		$clean    = Settings::sanitize( $input );
		foreach ( $input as $key => $value ) {
			if ( in_array( $key, self::SETTINGS, true ) ) {
				$settings[ $key ] = $clean[ $key ];
			} else {
				$ignored[] = 'setting:' . $key;
			}
		}

		$modules = [];
		foreach ( is_array( $raw['modules'] ?? null ) ? $raw['modules'] : [] as $module => $on ) {
			$definition = is_string( $module ) ? $catalog->get( $module ) : null;
			if ( null === $definition || ! is_bool( $on ) || RiskLevel::Invasive === $definition->risk ) {
				$ignored[] = 'module:' . $module;
				continue;
			}
			$modules[ $module ] = $on;
		}

		$tweaks  = [];
		$catalog = TweakCatalog::all();
		foreach ( is_array( $raw['tweaks'] ?? null ) ? $raw['tweaks'] : [] as $tweak => $on ) {
			if ( ! is_string( $tweak ) || ! isset( $catalog[ $tweak ] ) || ! is_bool( $on ) || 'invasive' === $catalog[ $tweak ]['risk'] ) {
				$ignored[] = 'tweak:' . $tweak;
				continue;
			}
			$tweaks[ $tweak ] = $on;
		}

		$cf    = is_array( $raw['cloudflare'] ?? null ) ? $raw['cloudflare'] : [];
		$names = static fn( mixed $list, string $pattern ): array => array_values( array_unique( array_filter( is_array( $list ) ? $list : [], static fn( $v ): bool => is_string( $v ) && 1 === preg_match( $pattern, $v ) ) ) );

		return [
			'id'         => $id,
			'version'    => $version,
			'name'       => is_string( $raw['name'] ?? null ) ? substr( wp_strip_all_tags( $raw['name'] ), 0, 100 ) : $id,
			'settings'   => $settings,
			'modules'    => $modules,
			'tweaks'     => $tweaks,
			'cloudflare' => [
				'rules'    => $names( $cf['rules'] ?? null, '/^[a-z0-9-]{2,40}$/' ),
				'settings' => $names( $cf['settings'] ?? null, '/^[a-z0-9_]{2,60}$/' ),
			],
			'ignored'    => $ignored,
		];
	}

	/**
	 * Diferencias entre la plantilla y la configuración del sitio (sin Cloudflare).
	 *
	 * @param Policy               $policy   Plantilla.
	 * @param array<string, mixed> $current  Ajustes del sitio (Settings::all()).
	 * @param array<string, bool>  $defaults Módulos activos por defecto (id => bool).
	 * @return list<Drift>
	 */
	public static function drift( array $policy, array $current, array $defaults, ModuleCatalog $catalog ): array {
		$out = [];
		foreach ( $policy['settings'] as $key => $desired ) {
			$value = $current[ $key ] ?? null;
			if ( ! self::same( $value, $desired ) ) {
				$out[] = [
					'kind'    => 'setting',
					'key'     => $key,
					'label'   => $key,
					'current' => $value,
					'desired' => $desired,
					'risk'    => 'spec_mode' === $key && 'prerender' === $desired ? 'moderate' : 'safe',
				];
			}
		}
		$modules = is_array( $current['modules'] ?? null ) ? $current['modules'] : [];
		foreach ( $policy['modules'] as $id => $desired ) {
			$value      = (bool) ( $modules[ $id ] ?? $defaults[ $id ] ?? false );
			$definition = $catalog->get( $id );
			if ( null !== $definition && $value !== $desired ) {
				$out[] = [
					'kind'    => 'module',
					'key'     => $id,
					'label'   => $definition->label,
					'current' => $value,
					'desired' => $desired,
					// Apagar nunca es arriesgado; encender, lo que diga el catálogo.
					'risk'    => $desired ? $definition->risk->value : 'safe',
				];
			}
		}
		$tweaks  = is_array( $current['tweaks'] ?? null ) ? $current['tweaks'] : [];
		$catalog = TweakCatalog::all();
		foreach ( $policy['tweaks'] as $id => $desired ) {
			$value = (bool) ( $tweaks[ $id ] ?? $catalog[ $id ]['default'] );
			if ( $value !== $desired ) {
				$out[] = [
					'kind'    => 'tweak',
					'key'     => $id,
					'label'   => $catalog[ $id ]['label'],
					'current' => $value,
					'desired' => $desired,
					'risk'    => $desired ? $catalog[ $id ]['risk'] : 'safe',
				];
			}
		}
		return $out;
	}

	/**
	 * Cambios de Cloudflare del plan del sitio que la plantilla pide y que faltan. Las reglas que
	 * alguien cambió a mano en el panel ("drifted") se informan pero nunca se aplican por plantilla.
	 *
	 * @param Policy                           $policy  Plantilla.
	 * @param list<array<string, mixed>>       $changes Plan del sitio (CloudflareService::plan()['changes']).
	 * @return list<Drift>
	 */
	public static function cloudflareDrift( array $policy, array $changes, string $refPrefix ): array {
		$out = [];
		foreach ( $changes as $change ) {
			$action = (string) ( $change['action'] ?? '' );
			if ( 'rule' === ( $change['kind'] ?? '' ) ) {
				$ref  = (string) ( $change['ref'] ?? '' );
				$name = str_starts_with( $ref, $refPrefix ) ? substr( $ref, strlen( $refPrefix ) ) : '';
				if ( ! in_array( $name, $policy['cloudflare']['rules'], true ) ) {
					continue;
				}
			} elseif ( ! in_array( (string) ( $change['setting'] ?? '' ), $policy['cloudflare']['settings'], true ) ) {
				continue;
			}
			if ( ! in_array( $action, [ 'create', 'update', 'drifted', 'blocked' ], true ) ) {
				continue;
			}
			$out[] = [
				'kind'    => 'cloudflare',
				'key'     => (string) ( $change['id'] ?? '' ),
				'label'   => (string) ( $change['title'] ?? '' ),
				'current' => $action,
				'desired' => 'create' === $action || 'update' === $action ? 'apply' : 'manual',
				'risk'    => (string) ( $change['risk'] ?? 'moderate' ),
			];
		}
		return $out;
	}

	/**
	 * Comparación estable (los ajustes vienen de JSON: 1 y 1.0, listas con el mismo contenido).
	 */
	public static function same( mixed $a, mixed $b ): bool {
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return abs( (float) $a - (float) $b ) < 1e-9;
		}
		if ( is_array( $a ) && is_array( $b ) ) {
			if ( array_is_list( $a ) && array_is_list( $b ) ) {
				sort( $a );
				sort( $b );
			} else {
				ksort( $a );
				ksort( $b );
			}
			return wp_json_encode( $a ) === wp_json_encode( $b );
		}
		return $a === $b;
	}
}
