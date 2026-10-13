<?php
/**
 * Resuelve qué reglas de la matriz aplican al sitio (plugins activos y tema).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Compat;

/**
 * Función pura: matriz + inventario del sitio → reglas aplicables, listas para compilar.
 */
final class Resolver {

	/**
	 * @param list<string> $activePlugins Archivos de plugins activos ("woocommerce/woocommerce.php").
	 * @param string       $stylesheet    Tema activo (hijo).
	 * @param string       $template      Tema padre.
	 * @return array{
	 *     integrations: list<string>,
	 *     bypass_cookies: list<string>,
	 *     bypass_paths: list<string>,
	 *     dynamic_paths: list<string>,
	 *     vary_cookies: list<string>,
	 *     require_cookie_before_cache: list<string>,
	 *     cart_cookies: list<string>,
	 *     requires: array{xmlrpc: bool, rest_users: bool, webhook_paths: list<string>},
	 *     static_passthrough: list<string>
	 * }
	 */
	public function resolve( Matrix $matrix, array $activePlugins, string $stylesheet, string $template ): array {
		$active = [];
		foreach ( $activePlugins as $file ) {
			$active[ strtolower( $file ) ] = true;
		}
		$themes = array_filter( [ strtolower( $stylesheet ), strtolower( $template ) ] );

		$result = [
			'integrations'                => [],
			'bypass_cookies'              => self::cookiePrefixes( $matrix->coreList( 'bypass_cookies' ) ),
			'bypass_paths'                => $matrix->coreList( 'bypass_paths' ),
			'dynamic_paths'               => [],
			'vary_cookies'                => [],
			'require_cookie_before_cache' => [],
			'cart_cookies'                => [],
			'requires'                    => [
				'xmlrpc'        => false,
				'rest_users'    => false,
				'webhook_paths' => [],
			],
			'static_passthrough'          => [],
		];

		foreach ( $matrix->integrations() as $integration ) {
			if ( ! self::applies( $integration, $active, $themes ) ) {
				continue;
			}
			$result['integrations'][] = (string) $integration['id'];

			$cache                    = is_array( $integration['cache'] ?? null ) ? $integration['cache'] : [];
			$result['bypass_paths']   = array_merge( $result['bypass_paths'], self::strings( $cache['bypass_paths'] ?? [] ) );
			$result['dynamic_paths']  = array_merge( $result['dynamic_paths'], self::strings( $cache['bypass_paths_dynamic'] ?? [] ) );
			$result['vary_cookies']   = array_merge( $result['vary_cookies'], self::strings( $cache['vary_cookies'] ?? [] ) );
			$result['cart_cookies']   = array_merge( $result['cart_cookies'], self::cookiePrefixes( self::strings( $cache['cart_cookies'] ?? [] ) ) );
			$result['bypass_cookies'] = array_merge( $result['bypass_cookies'], self::cookiePrefixes( self::strings( $cache['bypass_cookies'] ?? [] ) ) );

			foreach ( is_array( $cache['require_cookie_before_cache'] ?? null ) ? $cache['require_cookie_before_cache'] : [] as $rule ) {
				if ( is_array( $rule ) && is_string( $rule['cookie'] ?? null ) ) {
					$result['require_cookie_before_cache'][] = $rule['cookie'];
				}
			}

			$requires = is_array( $integration['requires'] ?? null ) ? $integration['requires'] : [];
			if ( true === ( $requires['xmlrpc'] ?? false ) ) {
				$result['requires']['xmlrpc'] = true;
			}
			if ( true === ( $requires['rest_users'] ?? false ) ) {
				$result['requires']['rest_users'] = true;
			}
			$result['requires']['webhook_paths'] = array_merge( $result['requires']['webhook_paths'], self::strings( $requires['webhook_paths'] ?? [] ) );
			$result['static_passthrough']        = array_merge( $result['static_passthrough'], self::strings( $integration['static_miss_passthrough'] ?? [] ) );
		}

		foreach ( [ 'integrations', 'bypass_cookies', 'bypass_paths', 'dynamic_paths', 'vary_cookies', 'require_cookie_before_cache', 'cart_cookies', 'static_passthrough' ] as $key ) {
			$result[ $key ] = self::unique( $result[ $key ] );
		}
		$result['requires']['webhook_paths'] = self::unique( $result['requires']['webhook_paths'] );

		return $result;
	}

	/**
	 * @param array<string, mixed> $integration Entrada de la matriz.
	 * @param array<string, true>  $active      Plugins activos (en minúsculas).
	 * @param array<int, string>   $themes      Temas activos (en minúsculas).
	 */
	private static function applies( array $integration, array $active, array $themes ): bool {
		$detect = is_array( $integration['detect'] ?? null ) ? $integration['detect'] : [];
		if ( 'plugin' === ( $integration['type'] ?? '' ) ) {
			if ( is_string( $detect['plugin_file'] ?? null ) ) {
				return isset( $active[ strtolower( $detect['plugin_file'] ) ] );
			}
			if ( is_string( $detect['plugin_dir'] ?? null ) ) {
				$prefix = strtolower( $detect['plugin_dir'] ) . '/';
				foreach ( $active as $file => $_ ) {
					if ( str_starts_with( $file, $prefix ) ) {
						return true;
					}
				}
			}
			return false;
		}
		if ( 'theme' === ( $integration['type'] ?? '' ) ) {
			return is_string( $detect['theme'] ?? null ) && in_array( strtolower( $detect['theme'] ), $themes, true );
		}
		return false;
	}

	/**
	 * "wordpress_logged_in_*" → "wordpress_logged_in_" (las cookies se comparan por prefijo).
	 *
	 * @param list<string> $cookies Patrones.
	 * @return list<string>
	 */
	private static function cookiePrefixes( array $cookies ): array {
		$out = [];
		foreach ( $cookies as $cookie ) {
			$prefix = rtrim( $cookie, '*' );
			if ( '' !== $prefix && 1 === preg_match( '/^[A-Za-z0-9_\-.]+$/', $prefix ) ) {
				$out[] = $prefix;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $value Valor crudo.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		return array_values( array_filter( $value, static fn( mixed $v ): bool => is_string( $v ) && '' !== $v ) );
	}

	/**
	 * @param list<string> $values Lista.
	 * @return list<string>
	 */
	private static function unique( array $values ): array {
		return array_values( array_unique( $values ) );
	}
}
