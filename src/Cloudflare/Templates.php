<?php
/**
 * Plantillas: qué reglas y ajustes recomienda Imagina Turbo para este sitio (docs/03, secciones 4–5).
 *
 * Todas las reglas se limitan al host del sitio (una zona puede servir varios sitios) y su `ref`
 * lleva el prefijo propio del sitio, así dos sitios de la misma zona no se pisan.
 *
 * Verificado en la documentación de Cloudflare (2026-10-13):
 * - Cache Rules: set_cache_settings con cache, edge_ttl/browser_ttl {mode}, serve_stale,
 *   respect_strong_etags; la llave con lista de parámetros excluidos es solo Enterprise.
 * - En reglas de caché no consta el campo http.request.method (Cloudflare no cachea POST) ni
 *   http.request.uri.args.names: se usan path, query y cookie.
 * - Transform Rules: rewrite con uri.query.expression y remove_query_args() (solo en reescritura).
 * - override_origin quita el Set-Cookie y guarda la respuesta: nunca en el HTML (regla 9).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Generación de lo deseado.
 */
final class Templates {

	/** Límite de longitud de expresión de Cloudflare. */
	public const MAX_EXPRESSION = 4096;

	public const STATIC_TTL = 30 * DAY_IN_SECONDS;

	public const STATIC_EXTENSIONS = [ 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'woff', 'woff2', 'ttf', 'ico', 'mp4', 'webm', 'pdf' ];

	/** Parámetros de acciones y endpoints dinámicos (por nombre completo: sin "s=", ver docs/03 4.1). */
	private const DYNAMIC_QUERY = [ 'wc-ajax=', 'add-to-cart=', 'remove_item=', 'removed_item=', 'undo_item=', '_wpnonce=', 'preview=', 'rest_route=', 'action=' ];

	public function __construct( private readonly SiteContext $site, private readonly PlanLimits $limits ) {
	}

	/**
	 * Reglas deseadas. Las que no se pueden proponer todavía vuelven con el motivo.
	 *
	 * @return list<array{rule: Rule, risk: string, title: string, description: string, blocked: string}>
	 */
	public function rules(): array {
		$p   = $this->site->refPrefix;
		$out = [];

		$out[] = [
			'rule'        => new Rule( Phases::CACHE, $p . 'cache-static', Rule::DESCRIPTION_PREFIX . 'Assets estáticos', $this->staticExpression(), 'set_cache_settings', $this->staticParameters() ),
			'risk'        => 'safe',
			'title'       => __( 'Caché de assets estáticos', 'imagina-turbo' ),
			'description' => __( 'CSS, JS, imágenes, fuentes y videos del sitio se guardan 30 días en el edge (los errores, 10 minutos). El navegador sigue las cabeceras del sitio.', 'imagina-turbo' ),
			'blocked'     => '',
		];

		$html  = $this->htmlExpression();
		$out[] = [
			'rule'        => new Rule( Phases::CACHE, $p . 'cache-html', Rule::DESCRIPTION_PREFIX . 'HTML anónimo', $html, 'set_cache_settings', $this->htmlParameters() ),
			'risk'        => 'moderate',
			'title'       => __( 'Caché del HTML anónimo', 'imagina-turbo' ),
			'description' => __( 'Hace elegibles las páginas para el edge. El tiempo lo decide el sitio con Cloudflare-CDN-Cache-Control; carrito, checkout, cuenta, login, admin, REST, acciones y visitantes con sesión quedan fuera.', 'imagina-turbo' ),
			'blocked'     => match ( true ) {
				! $this->site->edgeHeaders => __( 'Primero activa el coordinador de caché en modo «Emitir cabeceras para el edge». Sin esas cabeceras, Cloudflare aplicaría su tiempo por defecto a páginas que no lo dicen.', 'imagina-turbo' ),
				// Recortar cookies o rutas privadas sería inseguro: mejor no proponer la regla.
				strlen( $html ) > self::MAX_EXPRESSION => __( 'El sitio tiene demasiadas cookies y rutas privadas para una sola regla de Cloudflare (máximo 4.096 caracteres).', 'imagina-turbo' ),
				default => '',
			},
		];

		if ( ! $this->limits->bool( 'cache_key_query_lists' ) && [] !== $this->site->ignoreParams ) {
			$out[] = [
				'rule'        => new Rule( Phases::TRANSFORM, $p . 'strip-campaign', Rule::DESCRIPTION_PREFIX . 'Parámetros de campañas', $this->campaignExpression(), 'rewrite', $this->campaignParameters() ),
				'risk'        => 'moderate',
				'title'       => __( 'Quitar parámetros de campañas', 'imagina-turbo' ),
				'description' => __( '?utm_source, ?fbclid, ?gclid… ya no crean copias nuevas en el edge. El navegador conserva la URL original (la analítica en JavaScript los sigue leyendo), pero el servidor recibe la URL sin ellos.', 'imagina-turbo' ),
				'blocked'     => $this->site->edgeHeaders ? '' : __( 'Solo tiene sentido con la caché del HTML activa.', 'imagina-turbo' ),
			];
		}
		return $out;
	}

	/**
	 * Ajustes de zona recomendados. read_only: solo se leen y se avisa (regla 8).
	 *
	 * @return list<array{id: string, label: string, recommended: mixed, risk: string, read_only: bool, note: string}>
	 */
	public static function settings(): array {
		return [
			[
				'id'          => 'http3',
				'label'       => 'HTTP/3',
				'recommended' => 'on',
				'risk'        => 'safe',
				'read_only'   => false,
				'note'        => __( 'Conexiones más rápidas en redes móviles.', 'imagina-turbo' ),
			],
			[
				'id'          => 'early_hints',
				'label'       => 'Early Hints',
				'recommended' => 'on',
				'risk'        => 'safe',
				'read_only'   => false,
				'note'        => __( 'Adelanta los preload del sitio mientras el origen responde.', 'imagina-turbo' ),
			],
			[
				'id'          => 'tiered_cache_smart_topology_enable',
				'label'       => 'Smart Tiered Cache',
				'recommended' => 'on',
				'risk'        => 'safe',
				'read_only'   => false,
				'note'        => __( 'Menos peticiones al origen: los centros de datos se piden entre sí antes de ir al servidor.', 'imagina-turbo' ),
			],
			[
				'id'          => 'browser_cache_ttl',
				'label'       => __( 'TTL de caché del navegador', 'imagina-turbo' ),
				'recommended' => 0,
				'risk'        => 'safe',
				'read_only'   => false,
				'note'        => __( 'Respetar las cabeceras del sitio (0) en lugar de imponer 4 horas.', 'imagina-turbo' ),
			],
			[
				'id'          => 'rocket_loader',
				'label'       => 'Rocket Loader',
				'recommended' => 'off',
				'risk'        => 'moderate',
				'read_only'   => false,
				'note'        => __( 'Choca con el retraso de JavaScript del plugin de caché y rompe scripts en línea.', 'imagina-turbo' ),
			],
			[
				'id'          => 'ssl',
				'label'       => 'SSL/TLS',
				'recommended' => 'strict',
				'risk'        => 'safe',
				'read_only'   => true,
				'note'        => __( 'Solo lectura. Flexible deja el tramo hasta el servidor sin cifrar.', 'imagina-turbo' ),
			],
			[
				'id'          => 'always_use_https',
				'label'       => 'Always Use HTTPS',
				'recommended' => 'on',
				'risk'        => 'safe',
				'read_only'   => true,
				'note'        => __( 'Solo lectura.', 'imagina-turbo' ),
			],
		];
	}

	public function staticExpression(): string {
		return sprintf( '(http.host eq %s and http.request.uri.path.extension in {%s})', self::quote( $this->site->host ), implode( ' ', array_map( [ self::class, 'quote' ], self::STATIC_EXTENSIONS ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function staticParameters(): array {
		return [
			'cache'       => true,
			'edge_ttl'    => [
				'mode'            => 'override_origin',
				'default'         => self::STATIC_TTL,
				'status_code_ttl' => [
					[
						'status_code_range' => [
							'from' => 400,
							'to'   => 499,
						],
						'value'             => 600,
					],
					[
						'status_code_range' => [
							'from' => 500,
							'to'   => 599,
						],
						'value'             => 0,
					],
				],
			],
			'browser_ttl' => [ 'mode' => 'respect_origin' ],
		];
	}

	/**
	 * HTML anónimo: el host del sitio, sin rutas privadas, sin acciones y sin cookies de sesión.
	 */
	public function htmlExpression(): string {
		$home  = rtrim( $this->site->homePath, '/' );
		$parts = [
			'http.host eq ' . self::quote( $this->site->host ),
			'not starts_with(http.request.uri.path, ' . self::quote( $home . '/wp-admin' ) . ')',
			'not http.request.uri.path in {' . implode( ' ', array_map( [ self::class, 'quote' ], [ $home . '/wp-login.php', $home . '/xmlrpc.php', $home . '/wp-cron.php' ] ) ) . '}',
			'not starts_with(http.request.uri.path, ' . self::quote( $home . '/' . trim( $this->site->restPrefix, '/' ) . '/' ) . ')',
		];
		foreach ( self::DYNAMIC_QUERY as $arg ) {
			$parts[] = 'not http.request.uri.query contains ' . self::quote( $arg );
		}
		foreach ( $this->site->bypassCookies as $cookie ) {
			$parts[] = 'not http.cookie contains ' . self::quote( $cookie );
		}
		$private = [];
		foreach ( [ 'cart', 'checkout', 'account', 'login' ] as $type ) {
			foreach ( $this->site->paths[ $type ] ?? [] as $path ) {
				$path = '/' . trim( $path, '/' );
				if ( '/' !== $path ) {
					$private[ $path ] = true;
				}
			}
		}
		foreach ( array_keys( $private ) as $path ) {
			$parts[] = sprintf( 'not (http.request.uri.path eq %1$s or starts_with(http.request.uri.path, %2$s))', self::quote( $path ), self::quote( $path . '/' ) );
		}
		$expression = '(' . implode( ' and ', $parts ) . ')';
		// Si no cabe, se quitan las exclusiones por parámetro (el origen marca no-store en todo lo
		// dinámico). Cookies y rutas privadas nunca se quitan: si aun así no cabe, la regla se bloquea.
		if ( strlen( $expression ) > self::MAX_EXPRESSION ) {
			$parts      = array_values( array_filter( $parts, static fn( string $part ): bool => ! str_contains( $part, 'uri.query' ) ) );
			$expression = '(' . implode( ' and ', $parts ) . ')';
		}
		return $expression;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function htmlParameters(): array {
		$params = [
			'cache'                => true,
			'edge_ttl'             => [ 'mode' => 'respect_origin' ],
			'browser_ttl'          => [ 'mode' => 'respect_origin' ],
			'serve_stale'          => [ 'disable_stale_while_updating' => false ],
			'respect_strong_etags' => true,
		];
		if ( $this->limits->bool( 'cache_key_query_lists' ) && [] !== $this->site->ignoreParams ) {
			$params['cache_key'] = [
				'custom_key' => [
					'query_string' => [
						'exclude' => [ 'list' => array_slice( $this->site->ignoreParams, 0, 100 ) ],
					],
				],
			];
		}
		return $params;
	}

	public function campaignExpression(): string {
		$names = array_slice( $this->site->ignoreParams, 0, 100 );
		return sprintf( '(http.host eq %s and any(http.request.uri.args.names[*] in {%s}))', self::quote( $this->site->host ), implode( ' ', array_map( [ self::class, 'quote' ], $names ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function campaignParameters(): array {
		$names = array_slice( $this->site->ignoreParams, 0, 100 );
		$call  = '';
		for ( $n = count( $names ); $n >= 1; $n-- ) {
			$call = 'remove_query_args(http.request.uri.query, ' . implode( ', ', array_map( [ self::class, 'quote' ], array_slice( $names, 0, $n ) ) ) . ')';
			if ( strlen( $call ) <= self::MAX_EXPRESSION ) {
				break;
			}
		}
		return [ 'uri' => [ 'query' => [ 'expression' => $call ] ] ];
	}

	/**
	 * Cadena del lenguaje de reglas: entre comillas dobles, con \ y " escapados.
	 */
	public static function quote( string $value ): string {
		return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value ) . '"';
	}
}
