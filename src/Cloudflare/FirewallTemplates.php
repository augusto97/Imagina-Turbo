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
	public const BAD_BOTS = [ 'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'wpscan', 'acunetix', 'nessus', 'netsparker', 'openvas', 'w3af', 'dirbuster', 'gobuster', 'nuclei', 'jorgee', 'zmeu', 'fimap', 'havij' ];

	/**
	 * @param array<string, bool> $enabled   Reglas activadas (exploits, surface, bots).
	 * @param array<string, int>  $challenge Desde cuándo cada ref está en desafío (para pasar a block).
	 */
	public function __construct(
		private readonly SiteContext $site,
		private readonly PlanLimits $limits,
		private readonly array $enabled,
		private readonly bool $ratelimit,
		private readonly array $challenge = [],
		private readonly ?int $now = null,
	) {
	}

	/**
	 * @return list<array{rule: Rule, risk: string, title: string, description: string, blocked: string}>
	 */
	public function rules(): array {
		$p    = $this->site->refPrefix;
		$out  = [];
		$defs = [
			'exploits' => [ __( 'Firewall: ataques en la URL', 'imagina-turbo' ), __( 'Recorridos de directorios, inyección SQL y XSS en el query, archivos sensibles (.git, .env, .sql, .bak, phpunit, phpinfo) y métodos TRACE/TRACK/DEBUG.', 'imagina-turbo' ), $this->exploitsExpression() ],
			'surface'  => [ __( 'Firewall: superficie de WordPress', 'imagina-turbo' ), __( 'Enumeración de usuarios (/?author=), PHP dentro de uploads, readme.html, license.txt y copias de wp-config. XML-RPC también, salvo que un plugin activo lo necesite.', 'imagina-turbo' ), $this->surfaceExpression() ],
			'bots'     => [ __( 'Firewall: escáneres conocidos', 'imagina-turbo' ), __( 'sqlmap, nikto, wpscan, nuclei y otros escáneres por su user agent.', 'imagina-turbo' ), $this->botsExpression() ],
		];
		foreach ( $defs as $id => [ $title, $description, $expression ] ) {
			if ( ! ( $this->enabled[ $id ] ?? true ) ) {
				continue;
			}
			$ref    = $p . 'fw-' . $id;
			$action = $this->action( $ref );
			$out[]  = [
				'rule'        => new Rule( Phases::WAF, $ref, Rule::DESCRIPTION_PREFIX . $title, $expression, $action ),
				'risk'        => 'moderate',
				'title'       => $title,
				'description' => $description . ' ' . ( 'block' === $action ? __( 'Tras 7 días en desafío, pasa a bloqueo.', 'imagina-turbo' ) : __( 'Entra en modo desafío (managed challenge) durante 7 días.', 'imagina-turbo' ) ),
				'blocked'     => strlen( $expression ) > Templates::MAX_EXPRESSION ? __( 'La expresión no cabe en una regla.', 'imagina-turbo' ) : '',
			];
		}

		if ( $this->ratelimit ) {
			$rule  = $this->rateLimitRule( $p . 'rl-login' );
			$out[] = [
				'rule'        => $rule,
				'risk'        => 'moderate',
				'title'       => __( 'Límite de peticiones: login y checkout', 'imagina-turbo' ),
				'description' => 'free' === $this->limits->plan()
					? __( 'Más de 5 peticiones en 10 s por IP a wp-login.php, al login personalizado o al checkout por bloques: bloqueo de 10 s (lo máximo en Free). Protege de fuerza bruta y de card testing. En Free no se puede cubrir el checkout clásico (?wc-ajax=checkout): queda para la pasarela, CrowdSec y la guardia PHP.', 'imagina-turbo' )
					: __( 'Más de 5 peticiones en 10 s por IP a wp-login.php, al login personalizado o al checkout (por bloques y clásico): bloqueo de 10 minutos. Protege de fuerza bruta y de card testing.', 'imagina-turbo' ),
				'blocked'     => '',
			];
		}
		return $out;
	}

	/**
	 * Desafío los primeros 7 días; después, bloqueo.
	 */
	public function action( string $ref ): string {
		$since = $this->challenge[ $ref ] ?? 0;
		$now   = $this->now ?? time();
		return $since > 0 && $now - $since >= self::TEST_DAYS * DAY_IN_SECONDS ? 'block' : 'managed_challenge';
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
		$parts = array_map( static fn( string $bot ): string => 'lower(http.user_agent) contains ' . Templates::quote( $bot ), self::BAD_BOTS );
		return $this->wrap( $parts );
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
		$paths  = array_values( array_unique( $paths ) );
		$byPath = 'http.request.uri.path in {' . implode( ' ', array_map( [ Templates::class, 'quote' ], $paths ) ) . '}';
		$free   = 'free' === $this->limits->plan();
		$expr   = $free
			? '(' . $byPath . ')'
			: sprintf( '(http.host eq %1$s and (%2$s or (http.request.uri.path eq %3$s and http.request.uri.query contains "wc-ajax=checkout")))', Templates::quote( $this->site->host ), $byPath, Templates::quote( $home . '/' ) );
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
				'period'              => 10,
				'requests_per_period' => 5,
				'mitigation_timeout'  => $free ? 10 : 600,
			]
		);
	}

	/**
	 * Host del sitio, sin webhooks, y alguna de las condiciones.
	 *
	 * @param list<string> $parts Condiciones.
	 */
	private function wrap( array $parts ): string {
		return sprintf( '(http.host eq %1$s and not %2$s and (%3$s))', Templates::quote( $this->site->host ), $this->webhooks(), implode( ' or ', $parts ) );
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
