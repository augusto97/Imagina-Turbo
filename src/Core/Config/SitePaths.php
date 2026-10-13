<?php
/**
 * Resuelve rutas del sitio con WordPress cargado: bases, prefijo REST, páginas de WooCommerce
 * (con traducciones), slugs de login personalizados y rutas dinámicas de la matriz.
 *
 * Solo se usa al compilar la configuración.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Config;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtros públicos de WPML (wpml_*), no propios.

/**
 * Datos de rutas para la config compilada.
 */
final class SitePaths {

	/** Tokens wc_page:* → clave del clasificador. */
	private const WC_PAGES = [
		'cart'      => 'cart',
		'checkout'  => 'checkout',
		'myaccount' => 'account',
	];

	/** Tokens edd_page:* → clave en edd_settings. */
	private const EDD_PAGES = [
		'checkout'         => 'purchase_page',
		'success'          => 'success_page',
		'failure'          => 'failure_page',
		'purchase_history' => 'purchase_history_page',
	];

	/** @var list<string> */
	private array $unresolved = [];

	/**
	 * Path de home_url sin barra final ('' si está en la raíz).
	 */
	public function homePath(): string {
		return self::basePath( home_url( '/' ) );
	}

	/**
	 * Path de site_url (donde viven wp-admin y wp-login.php).
	 */
	public function sitePath(): string {
		return self::basePath( site_url( '/' ) );
	}

	public function restPrefix(): string {
		return function_exists( 'rest_get_url_prefix' ) ? trim( rest_get_url_prefix(), '/' ) : 'wp-json';
	}

	/**
	 * Slugs de idioma (WPML o Polylang) para reconocer /en/wp-json/.
	 *
	 * @return list<string>
	 */
	public function languagePrefixes(): array {
		$slugs = [];
		if ( function_exists( 'pll_languages_list' ) ) {
			$list  = pll_languages_list( [ 'fields' => 'slug' ] );
			$slugs = is_array( $list ) ? $list : [];
		} elseif ( has_filter( 'wpml_active_languages' ) ) {
			$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
			$slugs     = is_array( $languages ) ? array_keys( $languages ) : [];
		}
		$out = [];
		foreach ( $slugs as $slug ) {
			if ( is_string( $slug ) && 1 === preg_match( '/^[a-z]{2,3}([_-][a-z0-9]{2,8})?$/i', $slug ) ) {
				$out[] = strtolower( $slug );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Resuelve los tokens dinámicos de la matriz.
	 *
	 * @param list<string> $tokens Tokens (wc_page:cart, option:whl_page, function:sfml_get_slugs...).
	 * @return array{paths: array{cart: list<string>, checkout: list<string>, account: list<string>, login: list<string>}, bypass: list<string>}
	 */
	public function resolveDynamic( array $tokens ): array {
		$paths  = [
			'cart'     => [],
			'checkout' => [],
			'account'  => [],
			'login'    => [],
		];
		$bypass = [];

		foreach ( $tokens as $token ) {
			$clean          = (string) preg_replace( '/\?\*$/', '', $token );
			[ $kind, $arg ] = array_pad( explode( ':', $clean, 2 ), 2, '' );

			switch ( $kind ) {
				case 'wc_page':
					if ( isset( self::WC_PAGES[ $arg ] ) ) {
						$key           = self::WC_PAGES[ $arg ];
						$paths[ $key ] = array_merge( $paths[ $key ], $this->pagePaths( $this->wcPageId( $arg ) ) );
						continue 2;
					}
					break;
				case 'edd_page':
					if ( isset( self::EDD_PAGES[ $arg ] ) && function_exists( 'edd_get_option' ) ) {
						$id     = edd_get_option( self::EDD_PAGES[ $arg ], 0 );
						$bypass = array_merge( $bypass, $this->pagePaths( is_numeric( $id ) ? (int) $id : 0 ) );
						continue 2;
					}
					break;
				case 'option':
					if ( 1 === preg_match( '/^[a-z0-9_]{1,64}$/', $arg ) ) {
						$value = get_option( $arg, '' );
						if ( is_string( $value ) && '' !== trim( $value, '/ ' ) ) {
							$paths['login'][] = '/' . trim( $value, '/ ' );
						}
						continue 2;
					}
					break;
				case 'function':
					$slugs = self::callAllowed( $arg );
					if ( null !== $slugs ) {
						foreach ( is_array( $slugs ) ? $slugs : [] as $slug ) {
							if ( is_string( $slug ) && '' !== trim( $slug, '/ ' ) ) {
								$paths['login'][] = '/' . trim( $slug, '/ ' );
							}
						}
						continue 2;
					}
					break;
			}
			$this->unresolved[] = $token;
		}

		foreach ( $paths as $key => $list ) {
			$paths[ $key ] = array_values( array_unique( $list ) );
		}

		return [
			'paths'  => $paths,
			'bypass' => array_values( array_unique( $bypass ) ),
		];
	}

	/**
	 * Tokens que no se pudieron resolver (plugin desactivado a medias, función inexistente...).
	 *
	 * @return list<string>
	 */
	public function unresolved(): array {
		return $this->unresolved;
	}

	/**
	 * Llama a una función de la lista blanca si su plugin está cargado; null si no.
	 */
	private static function callAllowed( string $name ): mixed {
		return match ( $name ) {
			'sfml_get_slugs' => function_exists( 'sfml_get_slugs' ) ? sfml_get_slugs() : null,
			default          => null,
		};
	}

	private function wcPageId( string $page ): int {
		if ( function_exists( 'wc_get_page_id' ) ) {
			return max( 0, (int) wc_get_page_id( $page ) );
		}
		$id = get_option( 'woocommerce_' . $page . '_page_id', 0 );
		return is_numeric( $id ) ? max( 0, (int) $id ) : 0;
	}

	/**
	 * Rutas de una página y de sus traducciones, relativas a home.
	 *
	 * @return list<string>
	 */
	private function pagePaths( int $pageId ): array {
		if ( $pageId <= 0 || 'page' !== get_post_type( $pageId ) ) {
			return [];
		}
		$ids = [ $pageId ];

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $pageId );
			$ids          = array_merge( $ids, is_array( $translations ) ? array_map( 'intval', $translations ) : [] );
		} elseif ( has_filter( 'wpml_object_id' ) ) {
			$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
			foreach ( is_array( $languages ) ? array_keys( $languages ) : [] as $code ) {
				$translated = apply_filters( 'wpml_object_id', $pageId, 'page', false, $code );
				if ( is_numeric( $translated ) ) {
					$ids[] = (int) $translated;
				}
			}
		}

		$home  = $this->homePath();
		$paths = [];
		foreach ( array_unique( $ids ) as $id ) {
			$url = get_permalink( $id );
			if ( ! is_string( $url ) ) {
				continue;
			}
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( ! is_string( $path ) ) {
				continue;
			}
			$path = '/' . trim( $path, '/' );
			if ( '' !== $home && ( $path === $home || str_starts_with( $path, $home . '/' ) ) ) {
				$path = '/' . ltrim( substr( $path, strlen( $home ) ), '/' );
			}
			// La portada no se clasifica como carrito/checkout aunque lo sea.
			if ( '/' !== $path ) {
				$paths[] = strtolower( rawurldecode( $path ) );
			}
		}
		return $paths;
	}

	private static function basePath( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '';
		}
		$path = '/' . trim( strtolower( $path ), '/' );
		return '/' === $path ? '' : $path;
	}
}
