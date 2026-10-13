<?php
/**
 * Detecta el entorno del sitio: servidor, plugin de caché, WooCommerce, idioma, Cloudflare.
 *
 * Se usa en admin, REST y al compilar. No corre en el frontend.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Environment;

/**
 * Inventario del entorno.
 */
final class Detector {

	/** @var array<string, mixed>|null */
	private ?array $cache = null;

	/**
	 * Plugins activos (del sitio y de red), como archivos relativos a WP_PLUGIN_DIR.
	 *
	 * @return list<string>
	 */
	public function activePlugins(): array {
		$plugins = get_option( 'active_plugins', [] );
		$plugins = is_array( $plugins ) ? array_values( array_filter( $plugins, 'is_string' ) ) : [];
		if ( is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', [] );
			if ( is_array( $network ) ) {
				$plugins = array_merge( $plugins, array_filter( array_keys( $network ), 'is_string' ) );
			}
		}
		return array_values( array_unique( $plugins ) );
	}

	/**
	 * Todo el inventario.
	 *
	 * @return array<string, mixed>
	 */
	public function detect(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$active = array_flip( $this->activePlugins() );

		$this->cache = [
			'server'       => self::server( $_SERVER ),
			'php'          => PHP_VERSION,
			'wordpress'    => get_bloginfo( 'version' ),
			'multisite'    => is_multisite(),
			'cache_plugin' => self::cachePlugin( $active ),
			'woocommerce'  => [
				'active'  => isset( $active['woocommerce/woocommerce.php'] ),
				'version' => defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : null,
			],
			'multilingual' => self::multilingual( $active ),
			'cloudflare'   => [
				'proxied_request' => isset( $_SERVER['HTTP_CF_RAY'] ) || isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ),
				'official_plugin' => isset( $active['cloudflare/cloudflare.php'] ),
			],
			'object_cache' => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
			'opcache'      => self::opcacheEnabled(),
			'wp_cron'      => [
				'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			],
			'sodium'       => function_exists( 'sodium_crypto_secretbox' ),
			'theme'        => [
				'stylesheet' => get_stylesheet(),
				'template'   => get_template(),
			],
		];
		return $this->cache;
	}

	/**
	 * Servidor web a partir de SERVER_SOFTWARE y LSWS_EDITION. La edición de LiteSpeed es heurística.
	 *
	 * @param array<mixed> $server $_SERVER.
	 * @return array{software: string, family: string}
	 */
	public static function server( array $server ): array {
		$software = isset( $server['SERVER_SOFTWARE'] ) && is_string( $server['SERVER_SOFTWARE'] ) ? substr( $server['SERVER_SOFTWARE'], 0, 100 ) : '';
		$edition  = isset( $server['LSWS_EDITION'] ) && is_string( $server['LSWS_EDITION'] ) ? strtolower( $server['LSWS_EDITION'] ) : '';
		$lower    = strtolower( $software );

		$family = 'unknown';
		if ( str_contains( $lower, 'litespeed' ) || '' !== $edition ) {
			$family = str_contains( $edition, 'openlitespeed' ) ? 'openlitespeed' : 'litespeed';
		} elseif ( str_contains( $lower, 'nginx' ) ) {
			$family = 'nginx';
		} elseif ( str_contains( $lower, 'apache' ) ) {
			$family = 'apache';
		}

		return [
			'software' => $software,
			'family'   => $family,
		];
	}

	/**
	 * @param array<string, int> $active Plugins activos (archivo => índice).
	 */
	private static function cachePlugin( array $active ): ?string {
		if ( isset( $active['litespeed-cache/litespeed-cache.php'] ) ) {
			return 'litespeed-cache';
		}
		if ( isset( $active['wp-rocket/wp-rocket.php'] ) ) {
			return 'wp-rocket';
		}
		return null;
	}

	/**
	 * @param array<string, int> $active Plugins activos.
	 */
	private static function multilingual( array $active ): ?string {
		$map = [
			'sitepress-multilingual-cms/sitepress.php' => 'wpml',
			'polylang/polylang.php'                    => 'polylang',
			'polylang-pro/polylang.php'                => 'polylang',
			'translatepress-multilingual/index.php'    => 'translatepress',
			'weglot/weglot.php'                        => 'weglot',
		];
		foreach ( $map as $file => $name ) {
			if ( isset( $active[ $file ] ) ) {
				return $name;
			}
		}
		return null;
	}

	private static function opcacheEnabled(): bool {
		if ( ! function_exists( 'opcache_get_status' ) ) {
			return false;
		}
		return (bool) ini_get( 'opcache.enable' );
	}
}
