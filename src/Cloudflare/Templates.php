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

	/** Cabecera de la variante (en minúsculas, como la pide vary en Cache Rules). */
	public const VARIANT_HEADER = 'x-itb-variant';

	public const MAX_VARIANT_COOKIES = 10;

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

		// Variantes por cookie (0.18.0): la cabecera x-itb-variant lleva las cookies de variación; la regla
		// del HTML la usa en la llave (vary) en lugar de mandar esas visitas al servidor.
		if ( $this->site->edgeVariants ) {
			$params = $this->variantParameters();
			$out[]  = [
				'rule'        => new Rule( Phases::HEADERS, $p . 'variant', Rule::DESCRIPTION_PREFIX . 'Variante por cookies', '(http.host eq ' . self::quote( $this->site->host ) . ')', 'rewrite', $params ),
				'risk'        => 'moderate',
				'title'       => __( 'Variantes por cookie en el edge', 'imagina-turbo' ),
				'description' => sprintf(
					/* translators: %s: nombres de cookies. */
					__( 'Cloudflare guarda una copia del HTML por cada combinación de %s (moneda, idioma o consentimiento de cookies) en lugar de mandar esas visitas al servidor. Esta regla escribe esas cookies en la cabecera X-Itb-Variant (siempre: una visita no puede enviarla a mano) y el sitio la declara en Vary.', 'imagina-turbo' ),
					implode( ', ', array_slice( $this->site->varyCookies, 0, self::MAX_VARIANT_COOKIES ) )
				),
				'blocked'     => strlen( (string) $params['headers'][ self::VARIANT_HEADER ]['expression'] ) > self::MAX_EXPRESSION ? __( 'Demasiadas cookies de variación para una expresión.', 'imagina-turbo' ) : '',
			];
		}

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

	/** Preferencia para que el plan nunca cambie un ajuste. */
	public const KEEP = 'keep';

	/** Campos de /zones/{id}/bot_management (políticas de bots de IA por comportamiento, 0.21.0). */
	public const BOT_FIELDS = [ 'ai_training', 'ai_search', 'ai_user' ];

	private const ON_OFF = [ 'on', 'off' ];

	/**
	 * Ajustes de zona. read_only: solo se leen y se avisa (regla 8).
	 *
	 * Cada uno lleva su valor recomendado (default), las opciones que se pueden elegir y el valor que el
	 * plan aplicará (recommended): el elegido en $prefs si es una opción válida, o el recomendado. Con
	 * "keep" (elegido o recomendado) el ajuste no se gestiona y el plan nunca lo cambia (0.21.0).
	 *
	 * @param array<string, string|int> $prefs Valor elegido por ajuste.
	 * @return list<array{id: string, label: string, recommended: mixed, default: mixed, options: list<string|int>, managed: bool, risk: string, read_only: bool, note: string}>
	 */
	public static function settings( bool $edgeHtml = false, array $prefs = [] ): array {
		$out = [];
		foreach ( self::settingDefs( $edgeHtml ) as $def ) {
			$pref           = $prefs[ $def['id'] ] ?? null;
			$def['default'] = $def['recommended'];
			if ( ! $def['read_only'] && null !== $pref && ( self::KEEP === $pref || in_array( $pref, $def['options'], true ) ) ) {
				$def['recommended'] = $pref;
			}
			$def['managed'] = ! $def['read_only'] && self::KEEP !== $def['recommended'];
			$out[]          = $def;
		}
		return $out;
	}

	/**
	 * @return list<array{id: string, label: string, recommended: mixed, options: list<string|int>, risk: string, read_only: bool, note: string}>
	 */
	private static function settingDefs( bool $edgeHtml ): array {
		return [
			[
				'id'          => 'http3',
				'label'       => 'HTTP/3',
				'recommended' => 'on',
				'options'     => self::ON_OFF,
				'risk'        => 'safe',
				'read_only'   => false,
				'note'        => __( 'Conexiones más rápidas en redes móviles.', 'imagina-turbo' ),
			],
			[
				'id'          => 'early_hints',
				'label'       => 'Early Hints',
				'recommended' => 'on',
				'options'     => self::ON_OFF,
				'risk'        => 'safe',
				'read_only'   => false,
				'note'        => __( 'Adelanta los preload del sitio mientras el origen responde.', 'imagina-turbo' ),
			],
			[
				'id'          => 'tiered_cache_smart_topology_enable',
				'label'       => 'Smart Tiered Cache',
				'recommended' => 'on',
				'options'     => self::ON_OFF,
				'risk'        => 'safe',
				'read_only'   => false,
				'note'        => __( 'Menos peticiones al origen: los centros de datos se piden entre sí antes de ir al servidor.', 'imagina-turbo' ),
			],
			[
				'id'          => 'browser_cache_ttl',
				'label'       => __( 'TTL de caché del navegador', 'imagina-turbo' ),
				'recommended' => 0,
				'options'     => [ 0, 1800, 14400, 86400, 2678400, 31536000 ],
				'risk'        => 'safe',
				'read_only'   => false,
				'note'        => __( 'Respetar las cabeceras del sitio (0) en lugar de imponer 4 horas.', 'imagina-turbo' ),
			],
			[
				'id'          => 'speed_brain',
				'label'       => 'Speed Brain',
				'recommended' => $edgeHtml ? 'on' : 'off',
				'options'     => self::ON_OFF,
				'risk'        => 'safe',
				'read_only'   => false,
				'note'        => $edgeHtml
					? __( 'Precarga al pulsar desde la caché del edge; Imagina Turbo precarga antes, al pasar el cursor.', 'imagina-turbo' )
					: __( 'Sin el HTML en la caché del edge, Speed Brain no precarga nada y Cloudflare rechaza también las precargas de Imagina Turbo (503).', 'imagina-turbo' ),
			],
			[
				'id'          => 'always_online',
				'label'       => 'Always Online',
				'recommended' => 'off',
				'options'     => self::ON_OFF,
				'risk'        => 'safe',
				'read_only'   => false,
				'note'        => __( 'Con Always Online activo, Cloudflare ignora stale-while-revalidate: tras caducar una página, el visitante espera al servidor en lugar de recibir la copia anterior. Si el servidor cae, stale-if-error ya sirve la copia guardada.', 'imagina-turbo' ),
			],
			[
				'id'          => 'rocket_loader',
				'label'       => 'Rocket Loader',
				'recommended' => 'off',
				'options'     => self::ON_OFF,
				'risk'        => 'moderate',
				'read_only'   => false,
				'note'        => __( 'Choca con el retraso de JavaScript del plugin de caché y rompe scripts en línea.', 'imagina-turbo' ),
			],
			[
				'id'          => '0rtt',
				'label'       => '0-RTT',
				'recommended' => 'on',
				'options'     => self::ON_OFF,
				'risk'        => 'moderate',
				'read_only'   => false,
				'note'        => __( 'Quien vuelve al sitio abre la conexión sin esperar el saludo TLS completo. Solo GET, HEAD y OPTIONS, nunca POST; esas peticiones se pueden repetir por diseño y llegan con la cabecera Early-Data: 1.', 'imagina-turbo' ),
			],
			[
				'id'          => 'ai_training',
				'label'       => __( 'Bots de IA: entrenamiento', 'imagina-turbo' ),
				'recommended' => 'block',
				'options'     => [ 'block', 'disallow', 'only_on_ad_pages', 'disabled' ],
				'risk'        => 'safe',
				'read_only'   => false,
				'note'        => __( 'Crawlers que copian el contenido para entrenar modelos (y los de uso mixto, que también entrenan): consumen servidor y no traen visitas. «Bloquear» los corta en Cloudflare; «Solo pedirlo en robots.txt» no los frena si no lo respetan.', 'imagina-turbo' ),
			],
			[
				'id'          => 'ai_search',
				'label'       => __( 'Bots de IA: búsqueda', 'imagina-turbo' ),
				'recommended' => self::KEEP,
				'options'     => [ 'disabled', 'block', 'only_on_ad_pages' ],
				'risk'        => 'moderate',
				'read_only'   => false,
				'note'        => __( 'Los que indexan para responder preguntas (búsqueda de ChatGPT, Perplexity…). Pueden traer compradores: sin gestionar por defecto.', 'imagina-turbo' ),
			],
			[
				'id'          => 'ai_user',
				'label'       => __( 'Bots de IA: agentes', 'imagina-turbo' ),
				'recommended' => self::KEEP,
				'options'     => [ 'disabled', 'block', 'only_on_ad_pages' ],
				'risk'        => 'moderate',
				'read_only'   => false,
				'note'        => __( 'Actúan en tiempo real por una persona (un asistente que consulta un producto o un navegador automático). Bloquearlos puede costar ventas: sin gestionar por defecto.', 'imagina-turbo' ),
			],
			[
				'id'          => 'ssl',
				'label'       => 'SSL/TLS',
				'recommended' => 'strict',
				'options'     => [],
				'risk'        => 'safe',
				'read_only'   => true,
				'note'        => __( 'Solo lectura. Flexible deja el tramo hasta el servidor sin cifrar.', 'imagina-turbo' ),
			],
			[
				'id'          => 'always_use_https',
				'label'       => 'Always Use HTTPS',
				'recommended' => 'on',
				'options'     => [],
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
		// Variantes por cookie: la llave lleva x-itb-variant (passthrough) y accept-encoding (normalizado);
		// cualquier otra cabecera que el sitio declare en Vary impide guardar la copia. Solo se guarda lo
		// que el sitio marca como cacheable (bypass_by_default): sin Imagina Turbo, nada.
		if ( $this->site->edgeVariants ) {
			$params['edge_ttl'] = [ 'mode' => 'bypass_by_default' ];
			$params['vary']     = [
				'default' => [ 'action' => 'bypass' ],
				'headers' => [
					'accept-encoding'    => [ 'action' => 'normalize' ],
					self::VARIANT_HEADER => [ 'action' => 'passthrough' ],
				],
			];
		}
		// El plugin de caché guarda una copia para móviles: el edge separa por tipo de dispositivo
		// (cache_key.cache_by_device_type, todos los planes; Cloudflare envía CF-Device-Type al origen).
		// Sin esto, el primer visitante decide qué versión reciben todos.
		if ( $this->site->deviceCache ) {
			$params['cache_key']                         = is_array( $params['cache_key'] ?? null ) ? $params['cache_key'] : [];
			$params['cache_key']['cache_by_device_type'] = true;
		}
		return $params;
	}

	/**
	 * Cabecera x-itb-variant con las cookies de variación. Siempre se escribe (coalesce da "" si falta
	 * una cookie), así una visita no puede elegir la variante enviando la cabecera.
	 *
	 * @return array{headers: array<string, array{operation: string, expression: string}>}
	 */
	public function variantParameters(): array {
		$parts = [ '"v1"' ];
		foreach ( array_slice( $this->site->varyCookies, 0, self::MAX_VARIANT_COOKIES ) as $cookie ) {
			$parts[] = '"|"';
			$parts[] = 'coalesce(http.request.cookies[' . self::quote( $cookie ) . '][0], "")';
		}
		return [
			'headers' => [
				self::VARIANT_HEADER => [
					'operation'  => 'set',
					'expression' => 'concat(' . implode( ', ', $parts ) . ')',
				],
			],
		];
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
