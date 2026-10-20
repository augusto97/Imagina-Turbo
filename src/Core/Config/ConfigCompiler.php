<?php
/**
 * Genera wp-content/imagina-turbo/config.php a partir de ajustes, entorno y matriz.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Config;

use ImaginaTurbo\Compat\Matrix;
use ImaginaTurbo\Compat\MatrixException;
use ImaginaTurbo\Compat\Resolver;
use ImaginaTurbo\Core\EarlyLoader;
use ImaginaTurbo\Core\Environment\Detector;
use ImaginaTurbo\Modules\Tweaks\TweakCatalog;
use ImaginaTurbo\Modules\WooCart\ThemeSelectors;
use ImaginaTurbo\Core\Environment\OverlapGuard;
use ImaginaTurbo\Core\ModuleCatalog;
use ImaginaTurbo\Core\ModuleRegistry;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Hub\HubClient;
use ImaginaTurbo\Integrations\ImaginaSearch;
use ImaginaTurbo\Modules\AssetManager\AssetRules;
use ImaginaTurbo\Modules\Rum\RumService;
use ImaginaTurbo\Modules\PluginFilter\RuleStore;
use ImaginaTurbo\Support\Filesystem;

/**
 * Compilador de la configuración del frontend.
 */
final class ConfigCompiler {

	private bool $pending = false;

	private bool $halted = false;

	/** @var array<string, mixed>|null Último resultado. */
	private ?array $lastResult = null;

	/**
	 * @param string $dataDir    Directorio de datos.
	 * @param string $matrixFile Ruta de la matriz incluida en el plugin.
	 * @param string $version    Versión del plugin.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Detector $detector,
		private readonly SitePaths $sitePaths,
		private readonly ModuleCatalog $catalog,
		private readonly string $dataDir,
		private readonly string $matrixFile,
		private readonly string $version,
	) {
	}

	/**
	 * Ruta del archivo compilado del sitio actual.
	 */
	public function file(): string {
		return CompiledConfig::path( $this->dataDir, get_current_blog_id(), is_multisite() );
	}

	/**
	 * Pide recompilar al final del request (agrupa varios cambios en una sola compilación).
	 */
	public function schedule(): void {
		if ( $this->pending || $this->halted ) {
			return;
		}
		$this->pending = true;
		add_action( 'shutdown', [ $this, 'runScheduled' ], 0 );
	}

	/**
	 * Cancela la compilación pendiente y bloquea nuevas durante el resto del request. Se usa al
	 * desactivar el plugin: después del hook de desactivación WordPress dispara deactivated_plugin,
	 * que volvería a programar la compilación y recrearía el archivo recién borrado.
	 */
	public function halt(): void {
		$this->pending = false;
		$this->halted  = true;
	}

	/**
	 * Callback de shutdown.
	 */
	public function runScheduled(): void {
		if ( $this->pending && ! $this->halted ) {
			$this->pending = false;
			$this->compile();
		}
	}

	/**
	 * Compila y escribe. Nunca lanza: devuelve el resultado con errores si los hubo.
	 *
	 * @return array{ok: bool, hash: string, file: string, errors: list<string>, warnings: list<string>}
	 */
	public function compile(): array {
		$errors   = [];
		$warnings = [];
		$file     = $this->file();

		// Con plugins ocultos (carga condicional o experimento) la lista de activos está recortada:
		// compilar ahora dejaría fuera sus integraciones y sus reglas. Se compila en otro request.
		if ( Runtime::$pluginsFiltered || null !== Runtime::$experiment ) {
			$result           = [
				'ok'       => false,
				'hash'     => '',
				'file'     => $file,
				'errors'   => [ __( 'Compilación aplazada: este request tiene plugins ocultos.', 'imagina-turbo' ) ],
				'warnings' => [],
			];
			$this->lastResult = $result;
			return $result;
		}

		try {
			$data = $this->build( $warnings );
		} catch ( \Throwable $e ) {
			$result           = [
				'ok'       => false,
				'hash'     => '',
				'file'     => $file,
				'errors'   => [ $e->getMessage() ],
				'warnings' => $warnings,
			];
			$this->lastResult = $result;
			return $result;
		}

		if ( ! Filesystem::ensureDataDir( $this->dataDir ) ) {
			$errors[] = __( 'No se puede escribir en wp-content/imagina-turbo/.', 'imagina-turbo' );
		} else {
			$hash = (string) $data['hash'];
			$ok   = Filesystem::atomicWrite(
				$file,
				self::render( $data, $this->version ),
				static function ( string $tmp ) use ( $hash ): bool {
					$loaded = CompiledConfig::load( $tmp );
					return null !== $loaded && $loaded->hash() === $hash;
				}
			);
			if ( ! $ok ) {
				$errors[] = __( 'No se pudo escribir la configuración compilada.', 'imagina-turbo' );
			}
		}

		$result           = [
			'ok'       => [] === $errors,
			'hash'     => (string) $data['hash'],
			'file'     => $file,
			'errors'   => $errors,
			'warnings' => $warnings,
		];
		$this->lastResult = $result;
		return $result;
	}

	/**
	 * Borra el archivo compilado del sitio actual.
	 */
	public function delete(): void {
		$file = $this->file();
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Construye el array de configuración (sin escribirlo).
	 *
	 * @param list<string> $warnings Avisos acumulados.
	 * @return array<string, mixed>
	 */
	public function build( array &$warnings = [] ): array {
		$active = $this->detector->activePlugins();
		$env    = $this->detector->detect();
		$theme  = is_array( $env['theme'] ?? null ) ? $env['theme'] : [];

		$compat        = null;
		$overlap       = [
			'forced_off'           => [],
			'conflicts'            => [],
			'coordinated'          => [],
			'multiple_page_caches' => false,
		];
		$matrixVersion = '';
		$coreBypass    = [];
		try {
			$matrix        = Matrix::fromFile( $this->matrixFile );
			$matrixVersion = $matrix->version();
			$compat        = ( new Resolver() )->resolve( $matrix, $active, (string) ( $theme['stylesheet'] ?? '' ), (string) ( $theme['template'] ?? '' ) );
			$coreBypass    = $matrix->coreList( 'bypass_paths' );
			$overlap       = ( new OverlapGuard() )->evaluate( $matrix->overlapping(), $active );
		} catch ( MatrixException $e ) {
			$warnings[] = $e->getMessage();
		}

		$dynamic = $this->sitePaths->resolveDynamic( $compat['dynamic_paths'] ?? [] );
		foreach ( $this->sitePaths->unresolved() as $token ) {
			/* translators: %s: token de la matriz */
			$warnings[] = sprintf( __( 'Ruta dinámica sin resolver: %s', 'imagina-turbo' ), $token );
		}

		$states  = ModuleRegistry::states( $this->catalog, $this->settings->modules(), $overlap['forced_off'] );
		$modules = [];
		foreach ( $states as $id => $state ) {
			$modules[ $id ] = $state['enabled'];
		}

		// Carga condicional: solo con el módulo activo (también lo apaga otro plugin que filtre active_plugins).
		$filter = true === ( $modules['plugin_filter'] ?? false )
			? ( new RuleStore() )->compile( $active )
			: [
				'filter'      => [],
				'experiments' => [],
			];

		$bypassCookies   = $compat['bypass_cookies'] ?? [ 'wordpress_logged_in_', 'wp-postpass_', 'comment_author_' ];
		$bypassCookies[] = 'itb_bypass';

		$data = [
			'v'                           => CompiledConfig::FORMAT_VERSION,
			'hash'                        => '',
			'generated'                   => time(),
			'plugin_version'              => $this->version,
			'matrix_version'              => $matrixVersion,
			'blog_id'                     => get_current_blog_id(),
			'home_path'                   => $this->sitePaths->homePath(),
			'site_path'                   => $this->sitePaths->sitePath(),
			'rest_prefix'                 => $this->sitePaths->restPrefix(),
			'lang_prefixes'               => $this->sitePaths->languagePrefixes(),
			'paths'                       => $dynamic['paths'],
			'modules'                     => $modules,
			'filter'                      => $filter['filter'],
			'experiments'                 => $filter['experiments'],
			'imagina_search'              => ImaginaSearch::config( $active ),
			'tags'                        => [ 'prefix' => (string) $this->settings->get( 'tag_prefix', '' ) ],
			'bypass_cookies'              => array_values( array_unique( $bypassCookies ) ),
			'bypass_paths'                => array_values( array_unique( array_merge( $compat['bypass_paths'] ?? [], $dynamic['bypass'] ) ) ),
			'vary_cookies'                => $compat['vary_cookies'] ?? [],
			'require_cookie_before_cache' => $compat['require_cookie_before_cache'] ?? [],
			'cart_cookies'                => $compat['cart_cookies'] ?? [],
			'requires'                    => $compat['requires'] ?? [
				'xmlrpc'        => false,
				'rest_users'    => false,
				'webhook_paths' => [],
			],
			'static_passthrough'          => array_map( 'strtolower', $compat['static_passthrough'] ?? [] ),
			'integrations'                => $compat['integrations'] ?? [],
			'woo'                         => $this->wooConfig( $theme ),
			'shield'                      => $this->shieldConfig( $compat['requires'] ?? [] ),
			'tweaks'                      => $this->tweaksConfig(),
			'cache'                       => $this->cacheConfig( $env, $compat, array_values( array_diff( array_merge( $compat['bypass_paths'] ?? [], $dynamic['bypass'] ), $coreBypass ) ) ),
			// Exclusiones de delay JS para WP Rocket o LSCache (solo si alguno está activo).
			// Gestor de assets: solo con el módulo activo (las reglas "testing" solo las aplica la previsualización).
			'assets'                      => true === ( $modules['asset_manager'] ?? false ) ? ( new AssetRules() )->compile() : [],
			// Hojas tardías que se adelantan al head (0.16.0), con el gestor de assets activo.
			'late_styles'                 => true === ( $modules['asset_manager'] ?? false ) ? (array) $this->settings->get( 'late_styles', [] ) : [],
			// "essential" (0.14.3): solo las globales y las de pagos y servicios, sin las de cada integración.
			'delay_js'                    => in_array( $env['cache_plugin'] ?? null, [ 'wp-rocket', 'litespeed-cache' ], true ) ? ( 'essential' === $this->settings->get( 'delay_js_scope', 'compat' ) ? ( $compat['delay_js_essential'] ?? [] ) : ( $compat['delay_js'] ?? [] ) ) : [],
			'rum'                         => $this->rumConfig(),
			// Carga de scripts: solo con el módulo activo.
			'scripts'                     => true === ( $modules['script_loading'] ?? false ) ? [
				'defer'         => (bool) $this->settings->get( 'script_defer', false ),
				'defer_exclude' => Settings::scriptHandles( (array) $this->settings->get( 'script_defer_exclude', [] ) ),
				'delay'         => Settings::delayPatterns( (array) $this->settings->get( 'script_delay', [] ) ),
				'delay_timeout' => (int) $this->settings->get( 'script_delay_timeout', 0 ),
			] : [],
			// Retención de Action Scheduler: solo con el módulo Base de datos activo (0 = la de Action Scheduler).
			'database'                    => [ 'as_retention' => true === ( $modules['database'] ?? false ) ? (int) $this->settings->get( 'db_as_retention', 0 ) : 0 ],
			// Plazo del JS retrasado de LiteSpeed Cache sin interacción (0.14.7; 0 = solo al interactuar).
			'lsdelay'                     => [ 't' => (int) $this->settings->get( 'lsdelay_timeout', 0 ) ],
			'speculation'                 => $this->speculationConfig( $dynamic['paths'], array_merge( $compat['bypass_paths'] ?? [], $dynamic['bypass'] ) ),
			'hints'                       => [
				'lcp'       => (bool) $this->settings->get( 'hints_lcp', true ),
				'facades'   => (bool) $this->settings->get( 'hints_facades', true ),
				// Fuentes del primer render por plantilla (RUM, 0.15.0).
				'fonts'     => (bool) $this->settings->get( 'hints_fonts', true ) ? RumService::stored()['fonts'] : [],
				// Fuentes elegidas a mano para todo el sitio (0.16.0).
				'fonts_all' => Settings::fontPaths( (array) $this->settings->get( 'fonts_manual', [] ), home_url( '/' ) ),
				'lazy'      => true === ( $modules['frontend_hints'] ?? true ) ? Settings::lazyRender( (array) $this->settings->get( 'lazy_render', [] ) ) : [],
				'images'    => array_map(
					static fn( array $i ): array => [
						'kind' => $i['kind'],
						'id'   => $i['id'],
						'src'  => $i['src'],
					],
					RumService::stored()['lcp']
				),
			],
			'profiler'                    => [
				'rate'            => (float) $this->settings->get( 'profiler_sample_rate', Settings::DEFAULT_SAMPLE_RATE ),
				'intensive_until' => (int) $this->settings->get( 'profiler_intensive_until', 0 ),
			],
		];

		$data['hash'] = self::hash( $data );
		return $data;
	}

	/**
	 * RUM: tasa y destino de los beacons (el hub si el sitio está registrado; si no, el endpoint local).
	 *
	 * @return array{rate: float, endpoint: string}
	 */
	private function rumConfig(): array {
		$endpoint = '';
		$hub      = (string) $this->settings->get( 'hub_url', '' );
		$state    = get_option( HubClient::STATE_OPTION, [] );
		$siteId   = is_array( $state ) && is_string( $state['site_id'] ?? null ) ? $state['site_id'] : '';
		if ( '' !== $hub && '' !== $siteId ) {
			$endpoint = rtrim( $hub, '/' ) . '/v1/rum?site=' . rawurlencode( $siteId );
		}
		return [
			'rate'     => (float) $this->settings->get( 'rum_rate', 0.05 ),
			'endpoint' => $endpoint,
		];
	}

	/**
	 * Navegación instantánea: modo, exclusiones (solo se especula lo cacheable) y páginas predichas.
	 *
	 * @param array<string, list<string>> $paths  Rutas de Woo y login.
	 * @param list<string>                $bypass Rutas que nunca se cachean.
	 * @return array<string, mixed>
	 */
	private function speculationConfig( array $paths, array $bypass ): array {
		$exclude = [];
		foreach ( [ 'cart', 'checkout', 'account', 'login' ] as $key ) {
			foreach ( $paths[ $key ] ?? [] as $path ) {
				$path      = '/' . trim( (string) $path, '/' );
				$exclude[] = $path;
				$exclude[] = $path . '/*';
			}
		}
		foreach ( $bypass as $path ) {
			// El core ya excluye wp-admin, wp-*.php y toda URL con query.
			if ( is_string( $path ) && str_starts_with( $path, '/' ) && ! str_starts_with( $path, '/wp-' ) && ! str_starts_with( $path, '/?' ) ) {
				$exclude[] = $path;
			}
		}
		$predicted = (bool) $this->settings->get( 'spec_predictive', true ) ? RumService::stored()['predicted'] : [];
		return [
			'mode'             => (string) $this->settings->get( 'spec_mode', 'prefetch' ),
			'eagerness'        => (string) $this->settings->get( 'spec_eagerness', 'moderate' ),
			'predictive'       => (bool) $this->settings->get( 'spec_predictive', true ),
			'fallback'         => (bool) $this->settings->get( 'spec_fallback', true ),
			'view_transitions' => (bool) $this->settings->get( 'view_transitions', false ),
			'exclude'          => array_values( array_unique( $exclude ) ),
			'predicted'        => $predicted,
		];
	}

	/**
	 * Ajustes del coordinador de caché para el frontend y las tareas.
	 *
	 * @param array<string, mixed>      $env         Entorno detectado.
	 * @param array<string, mixed>|null $compat      Reglas de la matriz.
	 * @param list<string>              $bypassPaths Rutas que nunca se cachean, de integraciones y páginas dinámicas
	 *                                               (las del núcleo ya las cubren los tipos de request).
	 * @return array<string, mixed>
	 */
	private function cacheConfig( array $env, ?array $compat, array $bypassPaths = [] ): array {
		$plugin  = $env['cache_plugin'] ?? null;
		$adapter = match ( $plugin ) {
			'litespeed-cache' => 'litespeed',
			'wp-rocket'       => 'wp-rocket',
			default           => 'none',
		};
		$server = is_array( $env['server'] ?? null ) && is_string( $env['server']['family'] ?? null ) ? $env['server']['family'] : 'unknown';
		return [
			'mode'            => (string) $this->settings->get( 'cache_mode', 'sync' ),
			'edge_ttl'        => (int) $this->settings->get( 'cache_edge_ttl', DAY_IN_SECONDS ),
			'swr'             => (int) $this->settings->get( 'cache_swr', DAY_IN_SECONDS ),
			'sie'             => (int) $this->settings->get( 'cache_sie', 3 * DAY_IN_SECONDS ),
			'nonce_refresh'   => (bool) $this->settings->get( 'cache_nonce_refresh', true ),
			'warm'            => (bool) $this->settings->get( 'cache_warm', true ),
			'adapter'         => $adapter,
			'server'          => $server,
			// Con WP Rocket fuera de Apache, sus aciertos no llevan cabeceras: el HTML no se deja en el edge.
			'edge_html'       => 'wp-rocket' !== $adapter || 'apache' === $server,
			// El plugin de caché guarda una copia para móviles: el edge también debe separar por dispositivo.
			'mobile_split'    => self::mobileSplit( $adapter ),
			'purge_all_hooks' => $compat['purge_all_hooks'] ?? [],
			'sitemaps'        => $compat['sitemaps'] ?? [],
			'preload_exclude' => $compat['preload_exclude'] ?? [],
			'bypass_paths'    => array_values( array_unique( $bypassPaths ) ),
		];
	}

	/**
	 * ¿El plugin de caché sirve HTML distinto a los móviles? LiteSpeed Cache: «Caché para móviles»
	 * (litespeed.conf.cache-mobile). WP Rocket: «Caché para móviles» + «Caché separada para móviles»
	 * (cache_mobile y do_caching_mobile_files en wp_rocket_settings, verificado en 3.23.5.1).
	 */
	public static function mobileSplit( string $adapter ): bool {
		if ( 'litespeed' === $adapter ) {
			return (bool) get_option( 'litespeed.conf.cache-mobile' );
		}
		if ( 'wp-rocket' === $adapter ) {
			$rocket = get_option( 'wp_rocket_settings', [] );
			return is_array( $rocket ) && ! empty( $rocket['cache_mobile'] ) && ! empty( $rocket['do_caching_mobile_files'] );
		}
		return false;
	}

	/**
	 * Escudo: lo moderado (XML-RPC, usuarios de la REST) solo se bloquea si ningún plugin activo lo
	 * requiere según la matriz (docs/02 módulo 6).
	 *
	 * @param array<string, mixed> $requires Requisitos de las integraciones activas.
	 * @return array<string, mixed>
	 */
	private function shieldConfig( array $requires ): array {
		$xmlrpcRequired = true === ( $requires['xmlrpc'] ?? false );
		$usersRequired  = true === ( $requires['rest_users'] ?? false );
		$on             = fn( string $key ): bool => true === $this->settings->get( $key, true );
		return [
			'static'           => $on( 'shield_static' ),
			'author'           => $on( 'shield_author' ),
			'xmlrpc'           => $on( 'shield_xmlrpc' ) && ! $xmlrpcRequired,
			'rest_users'       => $on( 'shield_rest_users' ) && ! $usersRequired,
			'heartbeat'        => $on( 'shield_heartbeat' ),
			'heartbeat_admin'  => (int) $this->settings->get( 'shield_heartbeat_admin', 60 ),
			'heartbeat_editor' => (int) $this->settings->get( 'shield_heartbeat_editor', 15 ),
			'filter_limit'     => (int) $this->settings->get( 'shield_filter_limit', 3 ),
			'prefetch'         => $on( 'shield_prefetch' ),
			'prefetch_paths'   => $this->prefetchPaths(),
			'required'         => [
				'xmlrpc'     => $xmlrpcRequired,
				'rest_users' => $usersRequired,
			],
		];
	}

	/**
	 * Rutas que nunca se precargan: carrito, pago y cuenta de WooCommerce (sin comodines).
	 *
	 * @return list<string>
	 */
	private function prefetchPaths(): array {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return [];
		}
		$out = [];
		foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
			$id   = (int) wc_get_page_id( $page );
			$link = $id > 0 ? get_permalink( $id ) : false;
			$path = is_string( $link ) ? (string) wp_parse_url( $link, PHP_URL_PATH ) : '';
			$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			if ( '' !== $home && '/' !== $home && str_starts_with( $path, rtrim( $home, '/' ) ) ) {
				$path = substr( $path, strlen( rtrim( $home, '/' ) ) );
			}
			$path = rtrim( $path, '/' );
			if ( '' !== $path ) {
				$out[] = $path;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function tweaksConfig(): array {
		$stored = $this->settings->get( 'tweaks', [] );
		return [
			'on'        => TweakCatalog::resolve( is_array( $stored ) ? $stored : [] ),
			'revisions' => (int) $this->settings->get( 'tweak_revisions', 5 ),
			'autosave'  => (int) $this->settings->get( 'tweak_autosave', 120 ),
		];
	}

	/**
	 * Carrito por Store API: selectores del mini-carrito del tema, más los propios.
	 *
	 * @param array<string, mixed> $theme Tema detectado.
	 * @return array<string, mixed>
	 */
	private function wooConfig( array $theme ): array {
		$count    = $this->settings->get( 'woo_count_selectors', [] );
		$total    = $this->settings->get( 'woo_total_selectors', [] );
		$resolved = ThemeSelectors::resolve(
			strtolower( (string) ( $theme['template'] ?? '' ) ),
			strtolower( (string) ( $theme['stylesheet'] ?? '' ) ),
			is_array( $count ) ? Settings::selectors( $count ) : [],
			is_array( $total ) ? Settings::selectors( $total ) : []
		);
		return [
			'count'           => $resolved['count'],
			'total'           => $resolved['total'],
			'menu'            => $resolved['menu'],
			'theme'           => $resolved['theme'],
			'verified'        => $resolved['verified'],
			'recently_viewed' => (bool) $this->settings->get( 'woo_recently_viewed', true ),
			'tax_incl'        => 'incl' === get_option( 'woocommerce_tax_display_cart', 'excl' ),
		];
	}

	/**
	 * Hash estable de la config (sin la fecha de generación).
	 *
	 * @param array<string, mixed> $data Config.
	 */
	public static function hash( array $data ): string {
		unset( $data['hash'], $data['generated'] );
		return substr( hash( 'sha256', (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) ), 0, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- hash puro, testeable sin WordPress.
	}

	/**
	 * Código PHP del archivo compilado.
	 *
	 * @param array<string, mixed> $data Config.
	 */
	public static function render( array $data, string $version ): string {
		return "<?php\n"
			. '// Generado por Imagina Turbo ' . preg_replace( '/[^0-9A-Za-z.\-]/', '', $version ) . ". No editar: se regenera automáticamente.\n"
			. "if ( ! defined( 'ABSPATH' ) ) {\n\treturn array();\n}\n"
			. 'return ' . var_export( $data, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generación de código.
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function lastResult(): ?array {
		return $this->lastResult;
	}

	/**
	 * Estado del archivo compilado actual.
	 *
	 * @return array{exists: bool, hash: string, generated: int, version: string, file: string}
	 */
	public function status(): array {
		$config = CompiledConfig::load( $this->file() );
		return [
			'exists'    => null !== $config,
			'hash'      => null !== $config ? $config->hash() : '',
			'generated' => null !== $config ? $config->generatedAt() : 0,
			'version'   => null !== $config ? $config->pluginVersion() : '',
			'file'      => str_replace( ABSPATH, '', $this->file() ),
		];
	}

	/**
	 * Directorio de datos (para el admin).
	 */
	public static function defaultDataDir(): string {
		return EarlyLoader::dataDir();
	}
}
