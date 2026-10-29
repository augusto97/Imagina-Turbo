<?php
/**
 * Ajustes del plugin (opción itb_settings, autoload = no).
 *
 * Solo se leen en admin, cron, REST y al compilar. El frontend usa la config compilada.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Config;

/**
 * Lectura, validación y escritura de los ajustes.
 */
final class Settings {

	public const OPTION = 'itb_settings';

	public const SCHEMA_VERSION = 1;

	/** Muestreo por defecto del perfilador: 1 % (docs/02). */
	public const DEFAULT_SAMPLE_RATE = 0.01;

	/** Duración del muestreo intensivo. */
	public const INTENSIVE_SECONDS = 900;

	/** @var array<string, mixed>|null */
	private ?array $cache = null;

	/**
	 * Ajustes por defecto. El prefijo de tags se genera una vez por sitio.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'version'                  => self::SCHEMA_VERSION,
			'modules'                  => [],
			'tag_prefix'               => '',
			'debug'                    => false,
			'staging_domains'          => [],
			'profiler_sample_rate'     => self::DEFAULT_SAMPLE_RATE,
			'profiler_intensive_until' => 0,
			'hub_url'                  => '',
			'cache_mode'               => 'sync',
			'cache_edge_ttl'           => DAY_IN_SECONDS,
			'cache_swr'                => DAY_IN_SECONDS,
			'cache_sie'                => 3 * DAY_IN_SECONDS,
			'cache_nonce_refresh'      => true,
			'cache_warm'               => true,
			'cache_fragments'          => false,
			'cache_edge_variants'      => false,
			'zaraz_takeover'           => [],
			'budgets'                  => [],
			'woo_count_selectors'      => [],
			'woo_total_selectors'      => [],
			'woo_recently_viewed'      => true,
			'shield_static'            => true,
			'shield_author'            => true,
			'shield_xmlrpc'            => true,
			'shield_rest_users'        => true,
			'shield_heartbeat'         => true,
			'shield_prefetch'          => true,
			'shield_heartbeat_admin'   => 60,
			'shield_heartbeat_editor'  => 15,
			'shield_filter_limit'      => 3,
			'shield_facets'            => false,
			'shield_facets_views'      => true,
			'shield_facets_robots'     => false,
			'shield_facets_scope'      => 'combos',
			'shield_facets_empty404'   => false,
			'tweaks'                   => [],
			'tweak_revisions'          => 5,
			'tweak_autosave'           => 120,
			'firewall_rules'           => [],
			'cf_zone_prefs'            => [],
			'turnstile_site_key'       => '',
			'turnstile_forms'          => [],
			'turnstile_skip'           => [],
			'turnstile_verified'       => '',
			'firewall_ratelimit'       => true,
			'rum_rate'                 => 0.05,
			'spec_mode'                => 'prefetch',
			'spec_eagerness'           => 'moderate',
			'spec_predictive'          => true,
			'spec_fallback'            => true,
			'view_transitions'         => false,
			'hints_lcp'                => true,
			'hints_fonts'              => true,
			'ai_write'                 => false,
			'late_styles'              => [],
			'fonts_manual'             => [],
			'hints_facades'            => true,
			'lazy_render'              => [],
			'lazy_elements'            => false,
			'lazy_documents'           => [],
			'db_keep_revisions'        => 5,
			'db_schedule'              => [],
			'db_schedule_days'         => 7,
			'db_as_retention'          => 0,
			'fleet_remote'             => false,
			'script_defer'             => false,
			'script_defer_exclude'     => [],
			'script_delay'             => [],
			'script_delay_timeout'     => 0,
			'delay_js_scope'           => 'compat',
			'lsdelay_timeout'          => 0,
		];
	}

	/**
	 * Todos los ajustes, completados con los valores por defecto.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = self::sanitize( is_array( $stored ) ? $stored : [] );
		}
		return $this->cache;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->all()[ $key ] ?? $default;
	}

	/**
	 * Interruptores de módulos guardados (id => bool).
	 *
	 * @return array<string, bool>
	 */
	public function modules(): array {
		$modules = $this->get( 'modules', [] );
		return is_array( $modules ) ? $modules : [];
	}

	/**
	 * Mezcla cambios y guarda. Devuelve los ajustes resultantes.
	 *
	 * @param array<string, mixed> $changes Cambios parciales.
	 * @return array<string, mixed>
	 */
	public function update( array $changes ): array {
		$merged = array_replace( $this->all(), $changes );
		if ( isset( $changes['modules'] ) && is_array( $changes['modules'] ) ) {
			$merged['modules'] = array_replace( $this->modules(), $changes['modules'] );
		}
		$clean = self::sanitize( $merged );
		update_option( self::OPTION, $clean, false );
		$this->cache = $clean;
		return $clean;
	}

	/**
	 * Crea la opción con autoload = no si no existe y asegura el prefijo de tags.
	 */
	public function ensureInitialized(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::sanitize( [] ), '', false );
			$this->cache = null;
		}
		if ( '' === $this->get( 'tag_prefix', '' ) ) {
			$this->update( [ 'tag_prefix' => self::generateTagPrefix() ] );
		}
	}

	/**
	 * Ajustes exportables (sin secretos: el token de Cloudflare vive en otra opción).
	 *
	 * @return array<string, mixed>
	 */
	public function export(): array {
		$data = $this->all();
		// El prefijo es propio de cada sitio y el muestreo intensivo es un estado temporal.
		unset( $data['tag_prefix'], $data['profiler_intensive_until'] );
		return [
			'plugin'   => 'imagina-turbo',
			'version'  => self::SCHEMA_VERSION,
			'settings' => $data,
		];
	}

	/**
	 * Importa ajustes exportados. Lanza si el formato no es válido.
	 *
	 * @param mixed $payload Datos importados.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException Si el formato no es válido.
	 */
	public function import( mixed $payload ): array {
		if ( ! is_array( $payload ) || 'imagina-turbo' !== ( $payload['plugin'] ?? null ) || ! is_array( $payload['settings'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Formato de exportación no válido.' );
		}
		if ( ! is_int( $payload['version'] ?? null ) || $payload['version'] > self::SCHEMA_VERSION ) {
			throw new \InvalidArgumentException( 'La exportación es de una versión más nueva del plugin.' );
		}
		$settings = $payload['settings'];
		unset( $settings['tag_prefix'], $settings['profiler_intensive_until'] );
		return $this->update( $settings );
	}

	/**
	 * Valida y normaliza. Las claves desconocidas se descartan.
	 *
	 * @param array<mixed> $input Datos crudos.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$out = self::defaults();

		if ( isset( $input['modules'] ) && is_array( $input['modules'] ) ) {
			foreach ( $input['modules'] as $id => $enabled ) {
				if ( is_string( $id ) && 1 === preg_match( '/^[a-z_]{2,40}$/', $id ) && is_bool( $enabled ) ) {
					$out['modules'][ $id ] = $enabled;
				}
			}
		}

		if ( isset( $input['tag_prefix'] ) && is_string( $input['tag_prefix'] ) && 1 === preg_match( '/^[a-z0-9]{2,6}$/', $input['tag_prefix'] ) ) {
			$out['tag_prefix'] = $input['tag_prefix'];
		}

		$out['debug'] = isset( $input['debug'] ) && true === $input['debug'];

		if ( isset( $input['staging_domains'] ) && is_array( $input['staging_domains'] ) ) {
			$domains = [];
			foreach ( $input['staging_domains'] as $domain ) {
				if ( ! is_string( $domain ) ) {
					continue;
				}
				$domain = strtolower( trim( $domain ) );
				if ( 1 === preg_match( '/^[a-z0-9.-]{2,253}$/', $domain ) ) {
					$domains[ $domain ] = true;
				}
			}
			$out['staging_domains'] = array_slice( array_keys( $domains ), 0, 100 );
		}

		if ( isset( $input['profiler_sample_rate'] ) && is_numeric( $input['profiler_sample_rate'] ) ) {
			$out['profiler_sample_rate'] = round( max( 0.0, min( 1.0, (float) $input['profiler_sample_rate'] ) ), 4 );
		}
		if ( isset( $input['profiler_intensive_until'] ) && is_int( $input['profiler_intensive_until'] ) && $input['profiler_intensive_until'] > 0 ) {
			$out['profiler_intensive_until'] = $input['profiler_intensive_until'];
		}
		if ( isset( $input['hub_url'] ) && is_string( $input['hub_url'] ) ) {
			$out['hub_url'] = self::sanitizeHubUrl( $input['hub_url'] );
		}

		if ( isset( $input['rum_rate'] ) && is_numeric( $input['rum_rate'] ) ) {
			$out['rum_rate'] = round( max( 0.0, min( 1.0, (float) $input['rum_rate'] ) ), 4 );
		}
		if ( isset( $input['spec_mode'] ) && in_array( $input['spec_mode'], [ 'prefetch', 'prerender' ], true ) ) {
			$out['spec_mode'] = $input['spec_mode'];
		}
		if ( isset( $input['spec_eagerness'] ) && in_array( $input['spec_eagerness'], [ 'conservative', 'moderate', 'eager' ], true ) ) {
			$out['spec_eagerness'] = $input['spec_eagerness'];
		}

		if ( isset( $input['cache_mode'] ) && in_array( $input['cache_mode'], [ 'sync', 'headers' ], true ) ) {
			$out['cache_mode'] = $input['cache_mode'];
		}
		// TTL del edge: de 1 minuto a 1 año. SWR hasta 7 días y SIE hasta 30 (límites del sentido común,
		// no de Cloudflare: más allá, un error del origen se ocultaría demasiado tiempo).
		foreach ( [
			'cache_edge_ttl' => [ 60, YEAR_IN_SECONDS ],
			'cache_swr'      => [ 0, 7 * DAY_IN_SECONDS ],
			'cache_sie'      => [ 0, 30 * DAY_IN_SECONDS ],
		] as $key => [ $min, $max ] ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = (int) max( $min, min( $max, (int) $input[ $key ] ) );
			}
		}
		foreach ( [ 'woo_count_selectors', 'woo_total_selectors' ] as $key ) {
			if ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) {
				$out[ $key ] = self::selectors( $input[ $key ] );
			}
		}
		foreach ( [
			'shield_heartbeat_admin'  => [ 15, 120 ],
			'shield_heartbeat_editor' => [ 15, 120 ],
			'shield_filter_limit'     => [ 0, 20 ],
			'tweak_revisions'         => [ 0, 100 ],
			'tweak_autosave'          => [ 30, DAY_IN_SECONDS ],
			'db_keep_revisions'       => [ 1, 100 ],
			'db_schedule_days'        => [ 1, 90 ],
			'db_as_retention'         => [ 0, 30 ],
		] as $key => [ $min, $max ] ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = (int) max( $min, min( $max, (int) $input[ $key ] ) );
			}
		}
		foreach ( [ 'tweaks', 'firewall_rules' ] as $key ) {
			if ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) {
				$map = [];
				foreach ( $input[ $key ] as $id => $enabled ) {
					if ( is_string( $id ) && 1 === preg_match( '/^[a-z_]{2,40}$/', $id ) && is_bool( $enabled ) ) {
						$map[ $id ] = $enabled;
					}
				}
				$out[ $key ] = $map;
			}
		}
		foreach ( [ 'cache_nonce_refresh', 'cache_warm', 'woo_recently_viewed', 'shield_static', 'shield_author', 'shield_xmlrpc', 'shield_rest_users', 'shield_heartbeat', 'shield_prefetch', 'shield_facets', 'shield_facets_views', 'shield_facets_robots', 'shield_facets_empty404', 'firewall_ratelimit', 'spec_predictive', 'spec_fallback', 'view_transitions', 'hints_lcp', 'hints_fonts', 'hints_facades', 'fleet_remote', 'ai_write', 'lazy_elements', 'cache_fragments', 'cache_edge_variants' ] as $key ) {
			if ( isset( $input[ $key ] ) && is_bool( $input[ $key ] ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}

		if ( isset( $input['lazy_render'] ) && is_array( $input['lazy_render'] ) ) {
			$out['lazy_render'] = self::lazyRender( $input['lazy_render'] );
		}
		// Lo que Zaraz reemplaza en el sitio (0.18.0): lo escribe CloudflareService al configurarlo.
		// Presupuestos de rendimiento (0.19.0): se guardan completos y acotados.
		if ( isset( $input['budgets'] ) && is_array( $input['budgets'] ) ) {
			$out['budgets'] = \ImaginaTurbo\Modules\Rum\Budgets::normalize( $input['budgets'] );
		}
		if ( isset( $input['zaraz_takeover'] ) && is_array( $input['zaraz_takeover'] ) ) {
			$out['zaraz_takeover'] = [
				'ga4'  => true === ( $input['zaraz_takeover']['ga4'] ?? false ),
				'meta' => true === ( $input['zaraz_takeover']['meta'] ?? false ),
			];
		}
		// Alcance del corte de filtros para crawlers (0.21.1): solo combinaciones o todos los filtros.
		if ( isset( $input['shield_facets_scope'] ) && in_array( $input['shield_facets_scope'], [ 'combos', 'all' ], true ) ) {
			$out['shield_facets_scope'] = $input['shield_facets_scope'];
		}
		// Turnstile (0.21.0): clave del sitio, formularios activos, pasarelas sin verificación y la firma de
		// las claves que pasaron la prueba (sin ella no se activa ningún formulario).
		if ( isset( $input['turnstile_site_key'] ) && is_string( $input['turnstile_site_key'] ) ) {
			$key                       = trim( $input['turnstile_site_key'] );
			$out['turnstile_site_key'] = 1 === preg_match( '/^[0-9A-Za-z_\-]{10,100}$/', $key ) ? $key : '';
		}
		if ( isset( $input['turnstile_forms'] ) && is_array( $input['turnstile_forms'] ) ) {
			$forms = [];
			foreach ( [ 'login', 'register', 'lostpassword', 'checkout' ] as $form ) {
				$forms[ $form ] = true === ( $input['turnstile_forms'][ $form ] ?? false );
			}
			$out['turnstile_forms'] = $forms;
		}
		if ( isset( $input['turnstile_skip'] ) && is_array( $input['turnstile_skip'] ) ) {
			$out['turnstile_skip'] = array_values( array_unique( array_filter( array_map( static fn( $g ): string => is_string( $g ) ? trim( $g ) : '', $input['turnstile_skip'] ), static fn( string $g ): bool => 1 === preg_match( '/^[A-Za-z0-9_\-]{1,60}$/', $g ) ) ) );
		}
		if ( isset( $input['turnstile_verified'] ) && is_string( $input['turnstile_verified'] ) ) {
			$out['turnstile_verified'] = 1 === preg_match( '/^[a-f0-9]{16}$/', $input['turnstile_verified'] ) ? $input['turnstile_verified'] : '';
		}
		// Valor elegido para cada ajuste de la zona de Cloudflare (0.21.0); Templates descarta lo que no sea una opción.
		if ( isset( $input['cf_zone_prefs'] ) && is_array( $input['cf_zone_prefs'] ) ) {
			$out['cf_zone_prefs'] = self::zonePrefs( $input['cf_zone_prefs'] );
		}
		// Documentos de Elementor diferidos (0.17.0).
		if ( isset( $input['lazy_documents'] ) && is_array( $input['lazy_documents'] ) ) {
			$out['lazy_documents'] = self::lazyDocuments( $input['lazy_documents'] );
		}

		// Carga de scripts (0.14.0).
		if ( isset( $input['script_defer'] ) && is_bool( $input['script_defer'] ) ) {
			$out['script_defer'] = $input['script_defer'];
		}
		if ( isset( $input['script_defer_exclude'] ) && is_array( $input['script_defer_exclude'] ) ) {
			$out['script_defer_exclude'] = self::scriptHandles( $input['script_defer_exclude'] );
		}
		if ( isset( $input['script_delay'] ) && is_array( $input['script_delay'] ) ) {
			$out['script_delay'] = self::delayPatterns( $input['script_delay'] );
		}
		if ( isset( $input['script_delay_timeout'] ) && is_numeric( $input['script_delay_timeout'] ) ) {
			$out['script_delay_timeout'] = (int) max( 0, min( 30, (int) $input['script_delay_timeout'] ) );
		}

		// Exclusiones del retraso de JS (0.14.3): todas las de la matriz o solo las esenciales.
		if ( isset( $input['delay_js_scope'] ) && in_array( $input['delay_js_scope'], [ 'compat', 'essential' ], true ) ) {
			$out['delay_js_scope'] = $input['delay_js_scope'];
		}

		// Plazo para cargar el JS retrasado de LiteSpeed Cache sin interacción (0.14.7; 0 = nunca).
		if ( isset( $input['lsdelay_timeout'] ) && is_numeric( $input['lsdelay_timeout'] ) ) {
			$out['lsdelay_timeout'] = (int) max( 0, min( 30, (int) $input['lsdelay_timeout'] ) );
		}

		// Hojas de estilo tardías al head (0.16.0): plantilla → hojas del propio sitio.
		if ( isset( $input['late_styles'] ) && is_array( $input['late_styles'] ) ) {
			$out['late_styles'] = \ImaginaTurbo\Modules\AssetManager\LateStyles::normalize( $input['late_styles'], function_exists( 'home_url' ) ? (string) home_url( '/' ) : '' );
		}

		// Fuentes a precargar en todo el sitio, además de las del RUM (0.16.0).
		if ( isset( $input['fonts_manual'] ) && is_array( $input['fonts_manual'] ) ) {
			$out['fonts_manual'] = self::fontPaths( $input['fonts_manual'], function_exists( 'home_url' ) ? (string) home_url( '/' ) : '' );
		}

		// Limpiezas programadas: solo las que no necesitan elegir filas (el autoload siempre es manual).
		if ( isset( $input['db_schedule'] ) && is_array( $input['db_schedule'] ) ) {
			$out['db_schedule'] = array_values( array_intersect( [ 'transients', 'action_scheduler', 'revisions' ], $input['db_schedule'] ) );
		}

		return $out;
	}

	/**
	 * URL del hub: https obligatorio salvo en localhost (pruebas). Inválida → vacía (hub apagado).
	 */
	/**
	 * Selectores CSS del mini-carrito: sin caracteres que permitan salir del contexto (no se imprimen en
	 * HTML, pero sí en el JSON de configuración y en querySelectorAll).
	 *
	 * @param array<mixed> $list Selectores.
	 * @return list<string>
	 */
	public static function selectors( array $list ): array {
		$out = [];
		foreach ( $list as $selector ) {
			if ( ! is_string( $selector ) ) {
				continue;
			}
			$selector = trim( preg_replace( '/\s+/', ' ', $selector ) ?? '' );
			if ( 1 === preg_match( '/^[A-Za-z0-9_\-.#\[\]="\' :>()*+~^$|]{1,200}$/', $selector ) ) {
				$out[ $selector ] = true;
			}
		}
		return array_slice( array_keys( $out ), 0, 20 );
	}

	/**
	 * Handles de scripts (exactos), hasta 50.
	 *
	 * @param array<mixed> $input Lista.
	 * @return list<string>
	 */
	public static function scriptHandles( array $input ): array {
		$out = [];
		foreach ( $input as $handle ) {
			if ( is_string( $handle ) && 1 === preg_match( '/^[A-Za-z0-9_\-.\/]{1,80}$/', trim( $handle ) ) ) {
				$out[] = trim( $handle );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 50 );
	}

	/**
	 * Patrones de retraso: subcadenas de 4 a 120 caracteres sin etiquetas, hasta 30.
	 *
	 * @param array<mixed> $input Lista.
	 * @return list<string>
	 */
	public static function delayPatterns( array $input ): array {
		$out = [];
		foreach ( $input as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}
			$pattern = trim( $pattern );
			if ( strlen( $pattern ) >= 4 && strlen( $pattern ) <= 120 && ! preg_match( '/[<>\s]/', $pattern ) ) {
				$out[] = $pattern;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 30 );
	}

	/**
	 * Rutas de fuentes del propio sitio (hasta 3): absolutas del host de la portada o relativas, con
	 * extensión de fuente, sin "..", comillas ni espacios. Se guardan como ruta (con la query si la hay).
	 *
	 * @param array<mixed> $list Entradas.
	 * @return list<string>
	 */
	public static function fontPaths( array $list, string $home ): array {
		$own = strtolower( (string) parse_url( $home, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- sin WordPress en las pruebas.
		$out = [];
		foreach ( $list as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$item = trim( $item );
			if ( 1 === preg_match( '#^(https?:)?//#i', $item ) ) {
				$host = strtolower( (string) parse_url( str_starts_with( $item, '//' ) ? 'https:' . $item : $item, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- idem.
				if ( '' === $own || $host !== $own ) {
					continue;
				}
				$item = (string) preg_replace( '#^(https?:)?//[^/]+#i', '', $item );
			}
			$path = (string) strtok( $item, '?#' );
			if ( ! str_starts_with( $item, '/' ) || str_starts_with( $item, '//' ) || str_contains( $item, '..' ) || 1 === preg_match( '/["\'<>\s\\\\]/', $item ) || 1 !== preg_match( '/\.(woff2?|ttf|otf)$/i', $path ) ) {
				continue;
			}
			$out[ $item ] = true;
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Reglas de renderizado diferido (content-visibility): un selector, las plantillas donde aplica
	 * (vacío = todas) y la altura estimada mientras no se pinta.
	 *
	 * @param array<mixed> $list Reglas.
	 * @return list<array{selector: string, templates: list<string>, height: int}>
	 */
	public static function lazyRender( array $list ): array {
		$out = [];
		foreach ( $list as $rule ) {
			if ( ! is_array( $rule ) || ! is_string( $rule['selector'] ?? null ) ) {
				continue;
			}
			$selector = self::selectors( [ $rule['selector'] ] )[0] ?? '';
			if ( '' === $selector ) {
				continue;
			}
			$templates = [];
			foreach ( is_array( $rule['templates'] ?? null ) ? $rule['templates'] : [] as $template ) {
				if ( is_string( $template ) && 1 === preg_match( '/^[a-z0-9:_\-]{1,40}$/', $template ) ) {
					$templates[ $template ] = true;
				}
			}
			$height           = is_int( $rule['height'] ?? null ) ? $rule['height'] : 600;
			$out[ $selector ] = [
				'selector'  => $selector,
				'templates' => array_slice( array_keys( $templates ), 0, 20 ),
				'height'    => max( 50, min( 3000, $height ) ),
			];
		}
		return array_slice( array_values( $out ), 0, 10 );
	}

	/**
	 * Preferencias de ajustes de la zona: id => valor (texto corto o entero).
	 *
	 * @param array<mixed> $input Entrada.
	 * @return array<string, string|int>
	 */
	public static function zonePrefs( array $input ): array {
		$out = [];
		foreach ( $input as $id => $value ) {
			if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z0-9_]{2,60}$/', $id ) ) {
				continue;
			}
			if ( is_int( $value ) ) {
				$out[ $id ] = $value;
			} elseif ( is_string( $value ) && 1 === preg_match( '/^[a-z0-9_]{1,40}$/', $value ) ) {
				$out[ $id ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Documentos de Elementor diferidos: id, modo ("v" al acercarse, "i" en la primera interacción) y
	 * altura reservada (0 = ninguna).
	 *
	 * @param array<mixed> $list Documentos.
	 * @return list<array{id: int, mode: string, height: int}>
	 */
	public static function lazyDocuments( array $list ): array {
		$out = [];
		foreach ( $list as $doc ) {
			$id = is_array( $doc ) && is_int( $doc['id'] ?? null ) ? $doc['id'] : 0;
			if ( $id <= 0 || isset( $out[ $id ] ) ) {
				continue;
			}
			$height     = is_int( $doc['height'] ?? null ) ? $doc['height'] : 0;
			$out[ $id ] = [
				'id'     => $id,
				'mode'   => 'i' === ( $doc['mode'] ?? '' ) ? 'i' : 'v',
				'height' => max( 0, min( 3000, $height ) ),
			];
		}
		return array_slice( array_values( $out ), 0, \ImaginaTurbo\Modules\FrontendHints\LazyElements::MAX_DOCUMENTS );
	}

	public static function sanitizeHubUrl( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 200 ) {
			return '';
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- función pura, sin WordPress.
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$local  = in_array( $host, [ 'localhost', '127.0.0.1', '[::1]', '::1' ], true );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $local ) ) {
			return '';
		}
		if ( 1 !== preg_match( '/^[a-z0-9.\-\[\]:]+$/', $host ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		if ( '' !== $path && 1 !== preg_match( '#^/[A-Za-z0-9._~\-/]*$#', $path ) ) {
			return '';
		}
		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * Dos caracteres: el primero letra (los tags no empiezan por dígito), el segundo letra o dígito.
	 */
	public static function generateTagPrefix(): string {
		$letters = 'abcdefghijklmnopqrstuvwxyz';
		$chars   = $letters . '0123456789';
		return $letters[ random_int( 0, 25 ) ] . $chars[ random_int( 0, 35 ) ];
	}
}
