<?php
/**
 * Clasificador de requests sin WordPress cargado.
 *
 * Solo usa $_SERVER, $_GET, $_POST['action'], constantes ya definidas y la config compilada.
 * Es código del camino crítico: nada de consultas, nada de funciones de WordPress.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Request;

/**
 * Decide el tipo de request.
 */
final class RequestClassifier {

	/** Extensiones de assets que, si llegan a PHP, son un archivo inexistente. */
	private const STATIC_EXTENSIONS = [
		'jpg'   => true,
		'jpeg'  => true,
		'png'   => true,
		'gif'   => true,
		'webp'  => true,
		'avif'  => true,
		'css'   => true,
		'js'    => true,
		'woff'  => true,
		'woff2' => true,
		'ttf'   => true,
		'svg'   => true,
		'ico'   => true,
		'map'   => true,
	];

	/** Scripts del core que no son páginas (relativos a site_path). */
	private const CORE_SCRIPTS = [
		'/wp-comments-post.php' => true,
		'/wp-signup.php'        => true,
		'/wp-activate.php'      => true,
		'/wp-trackback.php'     => true,
		'/wp-links-opml.php'    => true,
		'/wp-mail.php'          => true,
	];

	private const MAX_PATH_LENGTH = 2048;

	/** @var array<string, list<string>> */
	private array $paths;

	/** @var list<string> */
	private array $passthrough;

	/** @var list<string> */
	private array $langPrefixes;

	private string $homePath;

	private string $sitePath;

	private string $restPrefix;

	/**
	 * @param array<string, mixed> $config Sección de la config compilada: home_path, site_path, rest_prefix,
	 *                                     lang_prefixes, paths (cart, checkout, account, login) y static_passthrough.
	 */
	public function __construct( array $config ) {
		$this->homePath     = self::normalizeBase( $config['home_path'] ?? '' );
		$this->sitePath     = self::normalizeBase( $config['site_path'] ?? $this->homePath );
		$rest               = is_string( $config['rest_prefix'] ?? null ) ? trim( $config['rest_prefix'], '/' ) : '';
		$this->restPrefix   = '' === $rest ? 'wp-json' : strtolower( $rest );
		$this->paths        = self::normalizePathMap( $config['paths'] ?? [] );
		$this->passthrough  = self::stringList( $config['static_passthrough'] ?? [] );
		$this->langPrefixes = self::stringList( $config['lang_prefixes'] ?? [] );
	}

	/**
	 * Clasifica con los superglobales y las constantes reales del request.
	 */
	public function classifyCurrent(): RequestContext {
		return $this->classify(
			$_SERVER, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- solo se leen cadenas, se normalizan abajo.
			$_GET,    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- clasificación, sin efectos.
			$_POST,   // phpcs:ignore WordPress.Security.NonceVerification.Missing -- solo se lee 'action'.
			[
				'cli'    => self::constant( 'WP_CLI' ),
				'cron'   => function_exists( 'wp_doing_cron' ) ? wp_doing_cron() : self::constant( 'DOING_CRON' ),
				'xmlrpc' => self::constant( 'XMLRPC_REQUEST' ),
				'ajax'   => function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : self::constant( 'DOING_AJAX' ),
				'admin'  => function_exists( 'is_admin' ) ? is_admin() : self::constant( 'WP_ADMIN' ),
			]
		);
	}

	/**
	 * Constante booleana definida y verdadera.
	 */
	private static function constant( string $name ): bool {
		return defined( $name ) && (bool) constant( $name );
	}

	/**
	 * Clasificación pura (testeable).
	 *
	 * @param array<mixed>        $server Equivalente a $_SERVER.
	 * @param array<mixed>        $get    Equivalente a $_GET.
	 * @param array<mixed>        $post   Equivalente a $_POST (solo se usa 'action').
	 * @param array<string, bool> $flags  cli, cron, xmlrpc, ajax, admin.
	 */
	public function classify( array $server, array $get, array $post, array $flags = [] ): RequestContext {
		$method   = self::method( $server );
		$fullPath = self::normalizePath( $server['REQUEST_URI'] ?? '/' );
		$homeRel  = self::relativeTo( $fullPath, $this->homePath );
		$siteRel  = self::relativeTo( $fullPath, $this->sitePath );

		if ( ! empty( $flags['cli'] ) ) {
			return new RequestContext( RequestType::Cli, '', $homeRel, $method );
		}
		if ( ! empty( $flags['cron'] ) || '/wp-cron.php' === $siteRel ) {
			return new RequestContext( RequestType::Cron, '', $homeRel, $method );
		}
		if ( ! empty( $flags['xmlrpc'] ) || '/xmlrpc.php' === $siteRel ) {
			return new RequestContext( RequestType::XmlRpc, '', $homeRel, $method );
		}
		if ( ! empty( $flags['ajax'] ) || '/wp-admin/admin-ajax.php' === $siteRel ) {
			$action = $post['action'] ?? $get['action'] ?? '';
			return new RequestContext( RequestType::AdminAjax, self::endpoint( $action ), $homeRel, $method );
		}
		if ( ! empty( $flags['admin'] ) || '/wp-admin' === $siteRel || str_starts_with( $siteRel, '/wp-admin/' ) ) {
			return new RequestContext( RequestType::Admin, '', $homeRel, $method );
		}
		if ( '/wp-login.php' === $siteRel || $this->matchesAny( $homeRel, $this->paths['login'] ?? [], false ) ) {
			return new RequestContext( RequestType::Login, '', $homeRel, $method );
		}
		if ( isset( $get['wc-ajax'] ) && is_string( $get['wc-ajax'] ) && '' !== $get['wc-ajax'] ) {
			return new RequestContext( RequestType::WcAjax, self::endpoint( $get['wc-ajax'] ), $homeRel, $method );
		}

		$restRoute = $this->restRoute( $homeRel, $get );
		if ( null !== $restRoute ) {
			$namespace = self::restNamespace( $restRoute );
			$type      = str_starts_with( $namespace, 'wc/store' ) ? RequestType::StoreApi : RequestType::Rest;
			return new RequestContext( $type, $namespace, $homeRel, $method );
		}

		if ( isset( self::CORE_SCRIPTS[ $siteRel ] ) ) {
			return new RequestContext( RequestType::Other, '', $homeRel, $method );
		}

		foreach ( [
			'checkout' => RequestType::Checkout,
			'cart'     => RequestType::Cart,
			'account'  => RequestType::Account,
		] as $key => $type ) {
			if ( $this->matchesAny( $homeRel, $this->paths[ $key ] ?? [], true ) ) {
				return new RequestContext( $type, '', $homeRel, $method );
			}
		}

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return new RequestContext( RequestType::Other, '', $homeRel, $method );
		}

		if ( '/robots.txt' === $homeRel ) {
			return new RequestContext( RequestType::Robots, '', $homeRel, $method );
		}
		if ( self::isSitemap( $homeRel, $get ) ) {
			return new RequestContext( RequestType::Sitemap, '', $homeRel, $method );
		}
		if ( self::isFeed( $homeRel, $get ) ) {
			return new RequestContext( RequestType::Feed, '', $homeRel, $method );
		}
		if ( $this->isStaticMiss( $homeRel ) ) {
			return new RequestContext( RequestType::StaticMiss, '', $homeRel, $method );
		}

		return new RequestContext( RequestType::FrontendHtml, '', $homeRel, $method );
	}

	/**
	 * Ruta REST (sin prefijo) si el request va a la API REST; null si no.
	 *
	 * @param array<mixed> $get Query string.
	 */
	private function restRoute( string $homeRel, array $get ): ?string {
		if ( isset( $get['rest_route'] ) && is_string( $get['rest_route'] ) ) {
			return self::normalizePath( $get['rest_route'] );
		}

		$prefix = '/' . $this->restPrefix;
		$route  = self::stripPrefix( $homeRel, $prefix );
		if ( null !== $route ) {
			return $route;
		}

		foreach ( $this->langPrefixes as $lang ) {
			$route = self::stripPrefix( $homeRel, '/' . $lang . $prefix );
			if ( null !== $route ) {
				return $route;
			}
		}

		return null;
	}

	/**
	 * Quita un prefijo de ruta si coincide por segmento completo.
	 */
	private static function stripPrefix( string $path, string $prefix ): ?string {
		if ( $path === $prefix ) {
			return '/';
		}
		if ( str_starts_with( $path, $prefix . '/' ) ) {
			return substr( $path, strlen( $prefix ) );
		}
		return null;
	}

	/**
	 * Namespace REST: segmentos hasta la versión (v1, v2, v3.1, 1.0) incluida. "wc/store/v1", "wp/v2", "imagina-search/v1".
	 */
	public static function restNamespace( string $route ): string {
		$segments = array_values( array_filter( explode( '/', $route ), static fn( string $s ): bool => '' !== $s ) );
		if ( [] === $segments ) {
			return '';
		}

		$parts = [];
		foreach ( array_slice( $segments, 0, 4 ) as $segment ) {
			$parts[] = $segment;
			if ( 1 === preg_match( '/^v?\d+(\.\d+)*$/', $segment ) ) {
				return self::endpoint( implode( '/', $parts ) );
			}
		}

		return self::endpoint( $segments[0] );
	}

	/**
	 * Feeds del core: /feed/, /feed/rss2/, /categoria/x/feed/, /comments/feed/, ?feed=rss2.
	 *
	 * @param array<mixed> $get Query string.
	 */
	private static function isFeed( string $path, array $get ): bool {
		if ( isset( $get['feed'] ) && is_string( $get['feed'] ) && '' !== $get['feed'] ) {
			return true;
		}
		if ( ! str_contains( $path, 'feed' ) ) {
			return false;
		}
		return 1 === preg_match( '#(^|/)feed(/(rss|rss2|atom|rdf))?/?$#', $path );
	}

	/**
	 * Sitemaps del core y de los plugins SEO comunes, incluidas sus hojas XSL.
	 *
	 * @param array<mixed> $get Query string.
	 */
	private static function isSitemap( string $path, array $get ): bool {
		if ( isset( $get['sitemap'] ) && is_string( $get['sitemap'] ) && '' !== $get['sitemap'] ) {
			return true;
		}
		if ( ! str_contains( $path, 'sitemap' ) ) {
			return false;
		}
		$base = basename( rtrim( $path, '/' ) );
		return 1 === preg_match( '#^[a-z0-9_.-]*sitemap[a-z0-9_.-]*\.(xml|xsl)(\.gz)?$#', $base );
	}

	/**
	 * Asset que llegó a PHP: el servidor no encontró el archivo.
	 */
	private function isStaticMiss( string $path ): bool {
		$slash = strrpos( $path, '/' );
		$dot   = strrpos( $path, '.' );
		if ( false === $dot || ( false !== $slash && $dot < $slash ) ) {
			return false;
		}
		$ext = substr( $path, $dot + 1 );
		if ( ! isset( self::STATIC_EXTENSIONS[ $ext ] ) ) {
			return false;
		}
		// El core redirige /favicon.ico al ícono del sitio (WP 5.4+).
		if ( '/favicon.ico' === $path ) {
			return false;
		}
		foreach ( $this->passthrough as $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Coincidencia exacta o, si $allowChildren, también subrutas (/mi-cuenta/pedidos/).
	 *
	 * @param list<string> $candidates Rutas normalizadas sin barra final.
	 */
	private function matchesAny( string $path, array $candidates, bool $allowChildren ): bool {
		$trimmed = '/' === $path ? '/' : rtrim( $path, '/' );
		foreach ( $candidates as $candidate ) {
			if ( $trimmed === $candidate ) {
				return true;
			}
			if ( $allowChildren && '/' !== $candidate && str_starts_with( $trimmed, $candidate . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ruta relativa a una base (WordPress en subcarpeta). Si no cae bajo la base, se deja tal cual.
	 */
	private static function relativeTo( string $path, string $base ): string {
		if ( '' === $base ) {
			return $path;
		}
		if ( $path === $base ) {
			return '/';
		}
		if ( str_starts_with( $path, $base . '/' ) ) {
			return substr( $path, strlen( $base ) );
		}
		return $path;
	}

	/**
	 * Ruta del URI: sin query ni fragmento, decodificada, en minúsculas, sin barras repetidas.
	 *
	 * @param mixed $uri REQUEST_URI.
	 */
	public static function normalizePath( mixed $uri ): string {
		if ( ! is_string( $uri ) || '' === $uri ) {
			return '/';
		}
		$cut  = strcspn( $uri, '?#' );
		$path = substr( $uri, 0, $cut );

		// Forma absoluta (http://host/ruta), rara pero válida en HTTP/1.1.
		if ( str_starts_with( $path, 'http://' ) || str_starts_with( $path, 'https://' ) ) {
			$start = strpos( $path, '/', strpos( $path, '//' ) + 2 );
			$path  = false === $start ? '/' : substr( $path, $start );
		}

		if ( strlen( $path ) > self::MAX_PATH_LENGTH ) {
			$path = substr( $path, 0, self::MAX_PATH_LENGTH );
		}

		$path = strtolower( rawurldecode( $path ) );
		if ( str_contains( $path, '//' ) ) {
			$path = (string) preg_replace( '#/{2,}#', '/', $path );
		}
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		return $path;
	}

	/**
	 * Identificador de endpoint seguro: minúsculas, [a-z0-9_-/.], máximo 100 caracteres.
	 *
	 * @param mixed $value Valor crudo.
	 */
	public static function endpoint( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$clean = (string) preg_replace( '#[^a-z0-9_\-/.]#', '', strtolower( $value ) );
		return substr( trim( $clean, '/' ), 0, 100 );
	}

	/**
	 * @param array<mixed> $server $_SERVER.
	 */
	private static function method( array $server ): string {
		$method = $server['REQUEST_METHOD'] ?? 'GET';
		return is_string( $method ) && '' !== $method ? strtoupper( substr( $method, 0, 10 ) ) : 'GET';
	}

	/**
	 * @param mixed $base Ruta base (home_path o site_path).
	 */
	private static function normalizeBase( mixed $base ): string {
		if ( ! is_string( $base ) ) {
			return '';
		}
		$base = rtrim( self::normalizePath( $base ), '/' );
		return '/' === $base ? '' : $base;
	}

	/**
	 * @param mixed $map Mapa nombre => lista de rutas.
	 * @return array<string, list<string>>
	 */
	private static function normalizePathMap( mixed $map ): array {
		$out = [];
		if ( ! is_array( $map ) ) {
			return $out;
		}
		foreach ( $map as $key => $list ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$out[ $key ] = [];
			foreach ( self::stringList( $list ) as $path ) {
				$normalized = self::normalizePath( $path );
				$normalized = '/' === $normalized ? '/' : rtrim( $normalized, '/' );
				if ( '/' !== $normalized ) {
					$out[ $key ][] = $normalized;
				}
			}
		}
		return $out;
	}

	/**
	 * @param mixed $value Lista cruda.
	 * @return list<string>
	 */
	private static function stringList( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		return array_values( array_filter( $value, static fn( mixed $v ): bool => is_string( $v ) && '' !== $v ) );
	}
}
