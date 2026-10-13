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
			'woo_count_selectors'      => [],
			'woo_total_selectors'      => [],
			'woo_recently_viewed'      => true,
			'shield_static'            => true,
			'shield_author'            => true,
			'shield_xmlrpc'            => true,
			'shield_rest_users'        => true,
			'shield_heartbeat'         => true,
			'shield_heartbeat_admin'   => 60,
			'shield_heartbeat_editor'  => 15,
			'shield_filter_limit'      => 3,
			'tweaks'                   => [],
			'tweak_revisions'          => 5,
			'tweak_autosave'           => 120,
			'firewall_rules'           => [],
			'firewall_ratelimit'       => true,
			'rum_rate'                 => 0.05,
			'spec_mode'                => 'prefetch',
			'spec_eagerness'           => 'moderate',
			'spec_predictive'          => true,
			'spec_fallback'            => true,
			'view_transitions'         => false,
			'hints_lcp'                => true,
			'hints_facades'            => true,
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
		foreach ( [ 'cache_nonce_refresh', 'cache_warm', 'woo_recently_viewed', 'shield_static', 'shield_author', 'shield_xmlrpc', 'shield_rest_users', 'shield_heartbeat', 'firewall_ratelimit', 'spec_predictive', 'spec_fallback', 'view_transitions', 'hints_lcp', 'hints_facades' ] as $key ) {
			if ( isset( $input[ $key ] ) && is_bool( $input[ $key ] ) ) {
				$out[ $key ] = $input[ $key ];
			}
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
