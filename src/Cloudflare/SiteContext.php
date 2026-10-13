<?php
/**
 * Lo que las plantillas de Cloudflare necesitan saber del sitio (solo admin, cron y REST).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

use ImaginaTurbo\Compat\Matrix;
use ImaginaTurbo\Compat\MatrixException;
use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Modules\CacheCoordinator\CacheModule;

/**
 * Datos del sitio, inmutables.
 */
final class SiteContext {

	/**
	 * @param list<string>               $bypassCookies  Prefijos de cookies que impiden la caché.
	 * @param array<string, list<string>> $paths          Rutas privadas por tipo (cart, checkout, account, login).
	 * @param list<string>               $ignoreParams   Parámetros de campañas.
	 * @param list<string>               $stagingDomains Sufijos de dominios de staging.
	 * @param list<string>               $webhookPaths   Rutas de webhooks de pasarelas (fase 4).
	 */
	public function __construct(
		public readonly string $host,
		public readonly string $homePath,
		public readonly string $restPrefix,
		public readonly array $paths,
		public readonly array $bypassCookies,
		public readonly array $ignoreParams,
		public readonly array $stagingDomains,
		public readonly string $refPrefix,
		public readonly bool $edgeHeaders,
		public readonly int $edgeTtl,
		public readonly bool $xmlrpcRequired = false,
		public readonly array $webhookPaths = [],
	) {
	}

	public static function fromRuntime( ?CompiledConfig $config, Settings $settings, string $matrixFile ): self {
		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$get   = static fn( string $key, mixed $default ): mixed => null !== $config ? $config->get( $key, $default ) : $default;
		$list  = static fn( mixed $v ): array => is_array( $v ) ? array_values( array_filter( $v, 'is_string' ) ) : [];
		$paths = [];
		foreach ( (array) $get( 'paths', [] ) as $type => $items ) {
			if ( is_string( $type ) ) {
				$paths[ $type ] = $list( $items );
			}
		}

		$cookies = $list( $get( 'bypass_cookies', [] ) );
		$cookies = array_merge( $cookies, $list( $get( 'vary_cookies', [] ) ) );
		$woo     = null !== $config && $config->moduleEnabled( 'woo_cart' );
		if ( ! $woo ) {
			// Sin el carrito por Store API, tener carrito impide la caché (docs/03, 4.1).
			$cookies = array_merge( $cookies, $list( $get( 'cart_cookies', [] ) ) );
		}
		$cookies = array_values( array_unique( array_filter( array_map( static fn( string $c ): string => rtrim( $c, '*' ), $cookies ), static fn( string $c ): bool => 1 === preg_match( '/^[A-Za-z0-9_\-.]{2,80}$/', $c ) ) ) );

		$ignore  = [];
		$staging = [];
		try {
			$matrix  = Matrix::fromFile( $matrixFile );
			$ignore  = $matrix->coreList( 'ignore_query_params' );
			$staging = $matrix->coreList( 'staging_domains' );
		} catch ( MatrixException ) {
			// Sin matriz: sin parámetros de campaña y solo los dominios de staging propios.
			$ignore = [];
		}
		$custom  = $settings->get( 'staging_domains', [] );
		$staging = array_values( array_unique( array_merge( $staging, is_array( $custom ) ? array_values( array_filter( $custom, 'is_string' ) ) : [] ) ) );

		$requires = (array) $get( 'requires', [] );
		$cache    = CacheModule::settings();
		$prefix   = $get( 'tags', [] );
		$prefix   = is_array( $prefix ) && is_string( $prefix['prefix'] ?? null ) && '' !== $prefix['prefix'] ? $prefix['prefix'] : 'site';

		$headers = null !== $config && $config->moduleEnabled( 'cache_coordinator' ) && 'headers' === $cache['mode'] && $cache['edge_html'];
		$xmlrpc  = isset( $requires['xmlrpc'] ) && true === $requires['xmlrpc'];

		return new self(
			$host,
			(string) $get( 'home_path', '' ),
			(string) $get( 'rest_prefix', 'wp-json' ),
			$paths,
			$cookies,
			array_values( array_filter( $ignore, static fn( string $p ): bool => 1 === preg_match( '/^[A-Za-z0-9_\-.\[\]]{1,60}$/', $p ) ) ),
			$staging,
			Rule::REF_PREFIX . $prefix . '-',
			$headers,
			$cache['edge_ttl'],
			$xmlrpc,
			$list( $requires['webhook_paths'] ?? [] ),
		);
	}

	/**
	 * Motivo por el que no se debe tocar Cloudflare desde este sitio, o '' si se puede.
	 */
	public function guard( string $zoneName ): string {
		foreach ( $this->stagingDomains as $suffix ) {
			$suffix = strtolower( $suffix );
			if ( '' !== $suffix && ( str_ends_with( $this->host, $suffix ) || ltrim( $suffix, '.-' ) === $this->host ) ) {
				/* translators: %s: host. */
				return sprintf( __( '%s es un dominio de staging: no se aplican reglas ni purgas en Cloudflare.', 'imagina-turbo' ), $this->host );
			}
		}
		if ( '' !== $zoneName && $this->host !== $zoneName && ! str_ends_with( $this->host, '.' . $zoneName ) ) {
			/* translators: 1: host del sitio, 2: zona. */
			return sprintf( __( 'El sitio (%1$s) no pertenece a la zona conectada (%2$s).', 'imagina-turbo' ), $this->host, $zoneName );
		}
		return '';
	}
}
