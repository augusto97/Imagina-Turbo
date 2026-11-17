<?php
/**
 * Plantillas del firewall en el edge (docs/03, sección 6; fase 4).
 *
 * Verificado en la documentación de Cloudflare (2026-10-13):
 * - Reglas personalizadas (http_request_firewall_custom): Free 5, Pro 20, Business 100. Acciones
 *   block, managed_challenge, js_challenge, challenge y skip; log solo en Enterprise. Sin regex por
 *   debajo de Business: solo contains, starts_with, ends_with, in, lower(), url_decode().
 * - Límite de peticiones (http_ratelimit): el objeto ratelimit va en la regla (no en
 *   action_parameters); cf.colo.id es obligatorio en characteristics. Free: 1 regla, solo campos de
 *   ruta (y bot verificado), periodo y bloqueo de 10 s.
 *
 * Decisiones:
 * - Sin regla "skip" para los webhooks: en reglas personalizadas, skip salta también las reglas del
 *   cliente que vienen después. Cada regla propia lleva su "and not (webhooks)".
 * - Modo prueba: la acción log es solo Enterprise. Las reglas nuevas entran con managed_challenge (una
 *   persona pasa, un bot no) y a los 7 días el plan propone pasarlas a block.
 * - Patrones inspirados en el firewall 8G de Jeff Starr (Perishable Press), reescritos para el
 *   lenguaje de reglas de Cloudflare; no se copia su código.
 * - Combinaciones de filtros para crawlers (0.21.0, apagada por defecto): misma definición que la
 *   guardia PHP (EarlyGuard). Usa el operador wildcard, disponible en todos los planes (los regex con
 *   matches son de Business en adelante), y cf.client.bot (bots verificados, todos los planes).
 * - Ajustes manuales (0.23.0, EdgeOptions): IPs y rutas que se saltan todas las reglas (van en el
 *   mismo "and not" que los webhooks, sin gastar reglas del plan), la acción de cada regla, escáneres
 *   añadidos o quitados, países (regla propia, apagada por defecto; los bots verificados siempre pasan
 *   para no perder posicionamiento) y el límite de peticiones dentro de lo que admite el plan. Sin
 *   ajustes, cada regla sale exactamente igual que antes: los sitios que ya la aplicaron no ven cambios.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Generación de reglas del firewall.
 */
final class FirewallTemplates {

	public const TEST_DAYS = 7;

	/** Escáneres conocidos (user agent, en minúsculas). */
	/** Reglas que solo existen si se activan a mano. */
	public const OFF_BY_DEFAULT = [ 'facets', 'countries' ];

	/** Crawlers por user agent (en minúsculas), además de los verificados: igual que EarlyGuard::CRAWLER_UA. */
	public const CRAWLER_TOKENS = [ 'bot/', 'bot;', 'bot-', 'spider', 'crawl', '+http', 'facebookexternalhit', 'meta-external', 'mediapartners', 'googleother', 'google-inspectiontool', 'slurp' ];

	/** Señal de cada grupo de filtros en el query, como en robots.txt (ShieldModule). */
	public const FACET_TOKENS = [ 'filter_', 'rating_filter=', '_price=', 'stock_status=' ];

	/** Orden y vista del listado. */
	public const VIEW_TOKENS = [ 'orderby=', 'per_page=', 'per_row=', 'shop_view=' ];

	public const BAD_BOTS = [ 'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'wpscan', 'acunetix', 'nessus', 'netsparker', 'openvas', 'w3af', 'dirbuster', 'gobuster', 'nuclei', 'jorgee', 'zmeu', 'fimap', 'havij' ];

	/**
	 * Ajustes manuales normalizados (EdgeOptions::firewall).
	 *
	 * @var array{allow_ips: list<string>, allow_paths: list<string>, actions: array<string, string>, bots_extra: list<string>, bots_off: list<string>, countries: list<string>, country_mode: string, rl_requests: int, rl_period: int, rl_timeout: int, rl_paths: list<string>}
	 */
	private readonly array $opts;

	/**
	 * @param array<string, bool> $enabled   Reglas activadas (exploits, surface, bots, facets, countries).
	 * @param array<string, int>  $challenge Desde cuándo cada ref está en desafío (para pasar a block).
	 * @param array<mixed>        $custom    Ajustes manuales (firewall_custom, 0.23.0).
	 */
	public function __construct(
		private readonly SiteContext $site,
		private readonly PlanLimits $limits,
		private readonly array $enabled,
		private readonly bool $ratelimit,
		private readonly array $challenge = [],
		private readonly ?int $now = null,
		private readonly bool $facetViews = true,
		private readonly bool $facetAll = false,
		array $custom = [],
	) {
		$this->opts = EdgeOptions::firewall( $custom );
	}

	/**
	 * @return list<array{rule: Rule, risk: string, title: string, description: string, blocked: string}>
	 */
	public function rules(): array {
		$p    = $this->site->refPrefix;
		$out  = [];
		$defs = [
			'exploits'  => [ __( 'Firewall: ataques en la URL', 'imagina-turbo' ), __( 'Recorridos de directorios, inyección SQL y XSS en el query, archivos sensibles (.git, .env, .sql, .bak, phpunit, phpinfo) y métodos TRACE/TRACK/DEBUG.', 'imagina-turbo' ), $this->exploitsExpression() ],
			'surface'   => [ __( 'Firewall: superficie de WordPress', 'imagina-turbo' ), __( 'Enumeración de usuarios (/?author=), PHP dentro de uploads, readme.html, license.txt y copias de wp-config. XML-RPC también, salvo que un plugin activo lo necesite.', 'imagina-turbo' ), $this->surfaceExpression() ],
			'bots'      => [ __( 'Firewall: escáneres conocidos', 'imagina-turbo' ), __( 'sqlmap, nikto, wpscan, nuclei y otros escáneres por su user agent.', 'imagina-turbo' ), $this->botsExpression() ],
			'facets'    => [ __( 'Firewall: combinaciones de filtros para crawlers', 'imagina-turbo' ), $this->facetAll ? __( 'Google, Bing, Meta y cualquier otro crawler que pida una URL con filtros de la tienda (el escudo está en «todos los filtros»): solo ven las URL reales. Las personas no se ven afectadas.', 'imagina-turbo' ) : __( 'Google, Bing, Meta y cualquier otro crawler que pida dos filtros de la tienda a la vez, varios valores de un filtro o el precio con otro filtro (y el orden o la vista, si se incluyen en el escudo). Cada filtro por separado sigue abierto. Las personas no se ven afectadas.', 'imagina-turbo' ), $this->facetsExpression() ],
			'countries' => [ __( 'Firewall: países', 'imagina-turbo' ), $this->countriesDescription(), $this->countriesExpression() ],
		];
		foreach ( $defs as $id => [ $title, $description, $expression ] ) {
			if ( ! ( $this->enabled[ $id ] ?? ! in_array( $id, self::OFF_BY_DEFAULT, true ) ) ) {
				continue;
			}
			$ref    = $p . 'fw-' . $id;
			$action = $this->action( $ref, $id );
			$out[]  = [
				'rule'        => new Rule( Phases::WAF, $ref, Rule::DESCRIPTION_PREFIX . $title, $expression, $action ),
				'risk'        => 'moderate',
				'title'       => $title,
				'description' => $description . ' ' . $this->actionNote( $id, $action ) . $this->exceptionsNote(),
				'blocked'     => match ( true ) {
					'bots' === $id && [] === $this->botList() => __( 'La lista de escáneres está vacía: agrega alguno o apaga la regla.', 'imagina-turbo' ),
					'countries' === $id && [] === $this->opts['countries'] => __( 'Elige al menos un país.', 'imagina-turbo' ),
					strlen( $expression ) > Templates::MAX_EXPRESSION => __( 'La expresión no cabe en una regla: quita excepciones o escáneres.', 'imagina-turbo' ),
					default => '',
				},
			];
		}

		if ( $this->ratelimit ) {
			$rule  = $this->rateLimitRule( $p . 'rl-login' );
			$out[] = [
				'rule'        => $rule,
				'risk'        => 'moderate',
				'title'       => __( 'Límite de peticiones: login y checkout', 'imagina-turbo' ),
				'description' => $this->rateLimitDescription(),
				'blocked'     => '',
			];
		}
		return $out;
	}

	/**
	 * Acción de la regla. Automática (la de siempre): desafío los primeros 7 días y después bloqueo.
	 * Con una acción elegida (0.23.0), esa siempre. Países: desafío salvo que se elija otra cosa.
	 */
	public function action( string $ref, string $id = '' ): string {
		$mode = $this->opts['actions'][ $id ] ?? ( 'countries' === $id ? 'challenge' : 'auto' );
		if ( 'challenge' === $mode ) {
			return 'managed_challenge';
		}
		if ( 'block' === $mode ) {
			return 'block';
		}
		$since = $this->challenge[ $ref ] ?? 0;
		$now   = $this->now ?? time();
		return $since > 0 && $now - $since >= self::TEST_DAYS * DAY_IN_SECONDS ? 'block' : 'managed_challenge';
	}

	private function actionNote( string $id, string $action ): string {
		$mode = $this->opts['actions'][ $id ] ?? ( 'countries' === $id ? 'challenge' : 'auto' );
		if ( 'challenge' === $mode ) {
			return __( 'Siempre en desafío (managed challenge): una persona pasa, un bot no.', 'imagina-turbo' );
		}
		if ( 'block' === $mode ) {
			return __( 'Bloqueo directo, sin periodo de prueba.', 'imagina-turbo' );
		}
		return 'block' === $action ? __( 'Tras 7 días en desafío, pasa a bloqueo.', 'imagina-turbo' ) : __( 'Entra en modo desafío (managed challenge) durante 7 días.', 'imagina-turbo' );
	}

	private function exceptionsNote(): string {
		$ips   = count( $this->opts['allow_ips'] );
		$paths = count( $this->opts['allow_paths'] );
		if ( 0 === $ips + $paths ) {
			return '';
		}
		/* translators: 1: número de IPs o rangos, 2: número de rutas. */
		return ' ' . sprintf( __( 'Excepciones: %1$d IPs o rangos y %2$d rutas.', 'imagina-turbo' ), $ips, $paths );
	}

	/**
	 * Escáneres de la lista de siempre que siguen activos, más los añadidos a mano.
	 *
	 * @return list<string>
	 */
	public function botList(): array {
		$list = array_values( array_diff( self::BAD_BOTS, $this->opts['bots_off'] ) );
		return array_values( array_unique( array_merge( $list, $this->opts['bots_extra'] ) ) );
	}

	/**
	 * Países: los elegidos («listed») o todos menos ellos («others»). Los bots verificados (Google,
	 * Bing…) siempre pasan: bloquearlos sacaría el sitio de los buscadores en esos países.
	 */
	public function countriesExpression(): string {
		$set = '{' . implode( ' ', array_map( [ Templates::class, 'quote' ], [] === $this->opts['countries'] ? [ 'XX' ] : $this->opts['countries'] ) ) . '}';
		$geo = 'others' === $this->opts['country_mode'] ? 'not ip.src.country in ' . $set : 'ip.src.country in ' . $set;
		return $this->wrap( [ '(' . $geo . ' and not cf.client.bot)' ] );
	}

	private function countriesDescription(): string {
		$list = [] === $this->opts['countries'] ? '—' : implode( ', ', $this->opts['countries'] );
		return 'others' === $this->opts['country_mode']
			/* translators: %s: códigos de país. */
			? sprintf( __( 'Visitas desde cualquier país salvo %s. Google, Bing y los demás bots verificados siempre pasan.', 'imagina-turbo' ), $list )
			/* translators: %s: códigos de país. */
			: sprintf( __( 'Visitas desde %s. Google, Bing y los demás bots verificados siempre pasan.', 'imagina-turbo' ), $list );
	}

	/**
	 * Valores del límite de peticiones dentro de lo que admite el plan.
	 *
	 * @return array{requests: int, period: int, timeout: int, ip: bool}
	 */
	public function rateLimitValues(): array {
		$rl       = $this->limits->ratelimit();
		$ints     = static fn( mixed $v ): array => is_array( $v ) ? array_values( array_filter( array_map( 'intval', $v ), static fn( int $n ): bool => $n > 0 ) ) : [];
		$periods  = $ints( $rl['periods'] ?? null );
		$timeouts = $ints( $rl['mitigation_timeouts'] ?? null );
		$periods  = [] === $periods ? [ 10 ] : $periods;
		$timeouts = [] === $timeouts ? [ 10 ] : $timeouts;
		$fallback = 'free' === $this->limits->plan() ? 10 : 600;
		return [
			'requests' => $this->opts['rl_requests'],
			'period'   => EdgeOptions::pick( $periods, $this->opts['rl_period'] ),
			'timeout'  => EdgeOptions::pick( $timeouts, 0 === $this->opts['rl_timeout'] ? $fallback : $this->opts['rl_timeout'] ),
			'ip'       => in_array( 'ip', is_array( $rl['fields'] ?? null ) ? $rl['fields'] : [], true ),
		];
	}

	private function rateLimitDescription(): string {
		$v    = $this->rateLimitValues();
		$text = sprintf(
			/* translators: 1: peticiones, 2: segundos, 3: duración del bloqueo. */
			__( 'Más de %1$d peticiones en %2$d s por IP al login, al checkout y a las rutas añadidas: bloqueo de %3$s. Protege de fuerza bruta y de card testing.', 'imagina-turbo' ),
			$v['requests'],
			$v['period'],
			$v['timeout'] >= 60 ? sprintf( /* translators: %d: minutos. */ _n( '%d minuto', '%d minutos', intdiv( $v['timeout'], 60 ), 'imagina-turbo' ), intdiv( $v['timeout'], 60 ) ) : $v['timeout'] . ' s'
		);
		if ( 'free' === $this->limits->plan() ) {
			$text .= ' ' . __( 'En Free solo se pueden usar 10 s y no se cubre el checkout clásico (?wc-ajax=checkout): queda para la pasarela, CrowdSec y la guardia PHP.', 'imagina-turbo' );
		}
		if ( [] !== $this->opts['allow_ips'] && ! $v['ip'] ) {
			$text .= ' ' . __( 'Las IPs de las excepciones no se pueden excluir de esta regla en tu plan (Cloudflare solo deja mirar la ruta).', 'imagina-turbo' );
		}
		return $text;
	}

	public function exploitsExpression(): string {
		$q     = 'url_decode(http.request.uri.query)';
		$lq    = 'lower(url_decode(http.request.uri.query))';
		$parts = [
			$q . ' contains "../"',
			$lq . ' contains "base64_"',
			$lq . ' contains "union select"',
			$lq . ' contains "union+select"',
			$lq . ' contains "<script"',
			$lq . ' contains "eval("',
			$q . ' contains "GLOBALS="',
			$q . ' contains "_REQUEST="',
			$q . ' contains "/etc/passwd"',
			'http.request.uri.path contains "/.git/"',
			'ends_with(http.request.uri.path, "/.git")',
			'http.request.uri.path contains "/.env"',
			'http.request.uri.path.extension in {"sql" "bak"}',
			'http.request.uri.path contains "/vendor/phpunit"',
			'ends_with(http.request.uri.path, "phpinfo.php")',
			'http.request.method in {"TRACE" "TRACK" "DEBUG"}',
		];
		return $this->wrap( $parts );
	}

	public function surfaceExpression(): string {
		$home  = rtrim( $this->site->homePath, '/' );
		$parts = [
			sprintf( '(http.request.uri.path eq %s and http.request.uri.query contains "author=")', Templates::quote( $home . '/' ) ),
			sprintf( '(starts_with(http.request.uri.path, %s) and http.request.uri.path.extension in {"php" "phtml" "phar"})', Templates::quote( $home . '/wp-content/uploads/' ) ),
			sprintf( 'http.request.uri.path in {%s %s}', Templates::quote( $home . '/readme.html' ), Templates::quote( $home . '/license.txt' ) ),
			'http.request.uri.path contains "/wp-config."',
		];
		if ( ! $this->site->xmlrpcRequired ) {
			$parts[] = 'http.request.uri.path eq ' . Templates::quote( $home . '/xmlrpc.php' );
		}
		return $this->wrap( $parts );
	}

	public function botsExpression(): string {
		$list  = $this->botList();
		$parts = array_map( static fn( string $bot ): string => 'lower(http.user_agent) contains ' . Templates::quote( $bot ), [] === $list ? [ 'imagina-turbo-sin-escaneres' ] : $list );
		return $this->wrap( $parts );
	}

	/**
	 * Crawler (verificado o por su user agent) y combinación de filtros en el query (o cualquier filtro,
	 * con el alcance "todos"). El wildcard compara el query entero y no distingue mayúsculas, así que
	 * %2C y %2c cuentan igual.
	 */
	public function facetsExpression(): string {
		$q       = 'http.request.uri.query';
		$crawler = array_merge( [ 'cf.client.bot' ], array_map( static fn( string $t ): string => 'lower(http.user_agent) contains ' . Templates::quote( $t ), self::CRAWLER_TOKENS ) );
		$combo   = [];
		foreach ( self::FACET_TOKENS as $a ) {
			// Todos los filtros (0.21.1): basta con que aparezca uno.
			if ( $this->facetAll ) {
				$combo[] = $q . ' contains ' . Templates::quote( $a );
				continue;
			}
			foreach ( self::FACET_TOKENS as $b ) {
				if ( $a !== $b || 'filter_' === $a ) {
					$combo[] = $q . ' wildcard ' . Templates::quote( '*' . $a . '*' . $b . '*' );
				}
			}
			if ( '_price=' !== $a ) {
				$combo[] = $q . ' wildcard ' . Templates::quote( '*' . $a . '*,*' );
				$combo[] = $q . ' wildcard ' . Templates::quote( '*' . $a . '*%2c*' );
			}
		}
		if ( $this->facetViews ) {
			foreach ( self::VIEW_TOKENS as $token ) {
				$combo[] = $q . ' contains ' . Templates::quote( $token );
			}
		}
		return $this->wrap( [ '(' . implode( ' or ', $crawler ) . ') and (' . implode( ' or ', $combo ) . ')' ] );
	}

	/**
	 * Login y checkout. En Free la expresión solo puede usar la ruta (sin host ni query).
	 */
	public function rateLimitRule( string $ref ): Rule {
		$home  = rtrim( $this->site->homePath, '/' );
		$paths = [ $home . '/wp-login.php', $home . '/' . trim( $this->site->restPrefix, '/' ) . '/wc/store/v1/checkout' ];
		foreach ( $this->site->paths['login'] ?? [] as $login ) {
			$paths[] = $home . '/' . trim( $login, '/' );
		}
		// Rutas añadidas a mano (0.23.0), tal como se ven en la URL.
		foreach ( $this->opts['rl_paths'] as $path ) {
			$paths[] = $path;
		}
		$paths  = array_values( array_unique( $paths ) );
		$byPath = 'http.request.uri.path in {' . implode( ' ', array_map( [ Templates::class, 'quote' ], $paths ) ) . '}';
		$free   = 'free' === $this->limits->plan();
		$values = $this->rateLimitValues();
		$expr   = $free
			? '(' . $byPath . ')'
			: sprintf( '(http.host eq %1$s and (%2$s or (http.request.uri.path eq %3$s and http.request.uri.query contains "wc-ajax=checkout")))', Templates::quote( $this->site->host ), $byPath, Templates::quote( $home . '/' ) );
		// Las IPs de las excepciones, solo donde el plan deja mirar la IP en esta fase (Business+).
		if ( $values['ip'] && [] !== $this->opts['allow_ips'] ) {
			$expr = '(' . $expr . ' and not ip.src in {' . implode( ' ', $this->opts['allow_ips'] ) . '})';
		}
		return new Rule(
			Phases::RATELIMIT,
			$ref,
			Rule::DESCRIPTION_PREFIX . 'Login y checkout',
			$expr,
			'block',
			[],
			true,
			[
				'characteristics'     => [ 'cf.colo.id', 'ip.src' ],
				'period'              => $values['period'],
				'requests_per_period' => $values['requests'],
				'mitigation_timeout'  => $values['timeout'],
			]
		);
	}

	/**
	 * Host del sitio, sin webhooks, y alguna de las condiciones.
	 *
	 * @param list<string> $parts Condiciones.
	 */
	private function wrap( array $parts ): string {
		return sprintf( '(http.host eq %1$s and not %2$s and (%3$s))', Templates::quote( $this->site->host ), $this->excluded(), implode( ' or ', $parts ) );
	}

	/**
	 * Lo que ninguna regla del firewall toca: webhooks de pasarelas y, desde 0.23.0, las IPs y rutas
	 * de las excepciones. Sin excepciones es exactamente webhooks().
	 */
	public function excluded(): string {
		$parts = [];
		if ( [] !== $this->opts['allow_ips'] ) {
			$parts[] = 'ip.src in {' . implode( ' ', $this->opts['allow_ips'] ) . '}';
		}
		foreach ( $this->opts['allow_paths'] as $path ) {
			$parts[] = sprintf( '(http.request.uri.path eq %1$s or starts_with(http.request.uri.path, %2$s))', Templates::quote( $path ), Templates::quote( $path . '/' ) );
		}
		$webhooks = $this->webhooks();
		return [] === $parts ? $webhooks : '(' . substr( $webhooks, 1, -1 ) . ' or ' . implode( ' or ', $parts ) . ')';
	}

	/**
	 * Webhooks y APIs de pasarelas (matriz: requires.webhook_paths, más /wc-api/ y ?wc-api=).
	 */
	public function webhooks(): string {
		$home  = rtrim( $this->site->homePath, '/' );
		$parts = [
			'starts_with(http.request.uri.path, ' . Templates::quote( $home . '/wc-api/' ) . ')',
			'http.request.uri.query contains "wc-api="',
		];
		foreach ( $this->site->webhookPaths as $path ) {
			$path = '/' . ltrim( $path, '/' );
			if ( 1 === preg_match( '#^/[A-Za-z0-9_\-./]{1,120}$#', $path ) ) {
				$parts[] = 'starts_with(http.request.uri.path, ' . Templates::quote( $home . $path ) . ')';
			}
		}
		return '(' . implode( ' or ', array_values( array_unique( $parts ) ) ) . ')';
	}
}
