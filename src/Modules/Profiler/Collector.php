<?php
/**
 * Recolector del perfilador para un request muestreado.
 *
 * Se carga desde la etapa temprana (sin autoloader) y solo cuando el request salió en la muestra.
 * Mide:
 * - fases del request (marcas al final de hooks clave del core);
 * - costo de carga de cada plugin (tiempo y memoria entre eventos plugin_loaded);
 * - tiempo exclusivo de los callbacks de cada plugin en unos pocos hooks clave, envolviendo sus
 *   callbacks solo mientras ese hook se ejecuta y restaurándolos al terminar;
 * - tiempo de render de los bloques dinámicos de cada plugin (su render_callback).
 *
 * Los callbacks del núcleo (definidos en wp-includes o wp-admin) no se envuelven: el ranking es por
 * plugin y envolverlos duplicaba el costo de la medición. Su tiempo no se desglosa.
 *
 * Saber a quién pertenece cada función o clase exige reflexión, y en un WooCommerce los callbacks de
 * init viven en ~170 archivos distintos: era la mayor parte del costo de una muestra. El resultado se
 * guarda en una caché (archivo PHP en el directorio de datos, servido desde OPcache) atada al hash de
 * la config: cambia con los plugins activos y caduca a las 24 h.
 *
 * No escribe nada: SampleWriter guarda la muestra al final del request.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Profiler;

/**
 * Estado de medición del request actual.
 */
final class Collector {

	public const SOURCE_RANDOM    = 0;
	public const SOURCE_INTENSIVE = 1;
	public const SOURCE_DIAG      = 2;

	/** Hooks cuyo final marca una fase, en orden. */
	public const PHASE_HOOKS = [ 'muplugins_loaded', 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'template_redirect', 'admin_init' ];

	/** Hooks en los que se atribuye el tiempo de cada callback a su plugin. */
	public const KEY_HOOKS = [ 'plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded', 'rest_api_init', 'admin_init', 'wp', 'template_redirect', 'wp_enqueue_scripts', 'wp_head', 'wp_footer' ];

	/** Tamaño máximo de la caché de símbolos. */
	public const MAX_SYMBOLS = 8000;

	private static ?self $active = null;

	/** @var array<string, float> Fin de cada fase, en ms desde el inicio del request. */
	private array $marks = [];

	/** @var array<string, array{ms: float, kb: float}> Costo de carga por componente. */
	private array $load = [];

	/** @var array<string, float> Tiempo exclusivo de callbacks por componente, en ms. */
	private array $hooks = [];

	/** @var array<string, array<int, array<string, mixed>>> Callbacks originales mientras su hook corre. */
	private array $saved = [];

	/** @var list<array{start: int, child: int}> Pila de callbacks envueltos en ejecución (tiempo exclusivo). */
	private array $stack = [];

	/** @var array<string, string> Caché archivo → componente. */
	private array $fileComponents = [];

	/** @var array<string, string> Caché callback → componente. */
	private array $callbackComponents = [];

	/** @var array<string, string> Caché función (o Clase::método) → componente, persistente entre requests. */
	private array $functions = [];

	/** @var array<string, string> Caché clase → componente, persistente entre requests. */
	private array $classes = [];

	/** Símbolos resueltos en este request que no estaban en la caché persistente. */
	private int $newSymbols = 0;

	/** Archivo de la caché de símbolos ('' = sin caché persistente). */
	private string $symbolFile = '';

	/** Clave de validez de la caché (hash de la config). */
	private string $symbolKey = '';

	/** @var array<string, int> Profundidad por hook (un hook clave puede dispararse anidado). */
	private array $depth = [];

	private int $lastNs;

	private int $lastMem;

	/** Tiempo propio del perfilador, en ns. */
	private int $ownNs = 0;

	/** @var list<string> Bases de componentes: [prefijo, tipo]. */
	private array $bases;

	/**
	 * @param int          $source       Origen de la muestra (SOURCE_*).
	 * @param float        $requestStart REQUEST_TIME_FLOAT.
	 * @param float        $loaderWall   microtime(true) al iniciar el loader.
	 * @param int          $loaderNs     hrtime(true) al iniciar el loader.
	 * @param int          $loaderMem    memory_get_usage() al iniciar el loader.
	 * @param list<string> $extraHooks   Hooks dinámicos del request (wp_ajax_x, wc_ajax_x…).
	 * @param string       $symbolFile   Caché persistente de símbolos ('' = sin caché).
	 * @param string       $symbolKey    Clave de validez de esa caché (hash de la config).
	 */
	private function __construct(
		public readonly int $source,
		public readonly float $requestStart,
		float $loaderWall,
		private readonly int $loaderNs,
		int $loaderMem,
		array $extraHooks,
		string $symbolFile,
		string $symbolKey,
	) {
		$this->lastNs                        = $loaderNs;
		$this->lastMem                       = $loaderMem;
		$this->marks['loader']               = max( 0.0, ( $loaderWall - $requestStart ) * 1000 );
		$this->bases                         = self::buildBases();
		$this->symbolFile                    = $symbolFile;
		$this->symbolKey                     = $symbolKey;
		[ $this->functions, $this->classes ] = self::loadSymbols( $symbolFile, $symbolKey );

		foreach ( self::PHASE_HOOKS as $hook ) {
			add_action( $hook, fn() => $this->mark( $hook ), PHP_INT_MAX );
		}
		add_action( 'shutdown', fn() => $this->mark( 'shutdown' ), PHP_INT_MIN );
		add_action( 'mu_plugin_loaded', [ $this, 'onPluginLoaded' ], PHP_INT_MIN );
		add_action( 'network_plugin_loaded', [ $this, 'onPluginLoaded' ], PHP_INT_MIN );
		add_action( 'plugin_loaded', [ $this, 'onPluginLoaded' ], PHP_INT_MIN );
		// El tiempo entre el último plugin y plugins_loaded no es de ningún plugin.
		add_action( 'plugins_loaded', [ $this, 'resetLoadCursor' ], PHP_INT_MIN );

		add_filter( 'register_block_type_args', [ $this, 'wrapBlockRender' ], PHP_INT_MAX, 2 );

		foreach ( array_unique( array_merge( self::KEY_HOOKS, $extraHooks ) ) as $hook ) {
			add_action( $hook, fn() => $this->beginHook( $hook ), PHP_INT_MIN );
			add_action( $hook, fn() => $this->endHook( $hook ), PHP_INT_MAX );
		}
	}

	/**
	 * Empieza a medir el request. Llamado una sola vez desde la etapa temprana.
	 *
	 * @param list<string> $extraHooks Hooks dinámicos a medir.
	 * @param string       $symbolFile Caché persistente de símbolos ('' = sin caché).
	 * @param string       $symbolKey  Clave de validez de esa caché (hash de la config).
	 */
	public static function start( int $source, int $loaderNs, int $loaderMem, float $loaderWall, array $extraHooks = [], string $symbolFile = '', string $symbolKey = '' ): self {
		if ( null === self::$active ) {
			$start        = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && is_float( $_SERVER['REQUEST_TIME_FLOAT'] ) ? $_SERVER['REQUEST_TIME_FLOAT'] : $loaderWall;
			self::$active = new self( $source, $start, $loaderWall, $loaderNs, $loaderMem, $extraHooks, $symbolFile, $symbolKey );
		}
		return self::$active;
	}

	public static function active(): ?self {
		return self::$active;
	}

	/**
	 * Solo pruebas.
	 */
	public static function reset(): void {
		self::$active = null;
	}

	/**
	 * Fin de una fase.
	 */
	public function mark( string $name ): void {
		if ( ! isset( $this->marks[ $name ] ) ) {
			$this->marks[ $name ] = $this->elapsedMs();
		}
	}

	/**
	 * Un plugin (o mu-plugin) terminó de cargarse: todo lo ocurrido desde el anterior es suyo.
	 *
	 * @param mixed $file Ruta del archivo principal.
	 */
	public function onPluginLoaded( mixed $file ): void {
		$t0  = (int) hrtime( true );
		$mem = memory_get_usage();
		if ( is_string( $file ) ) {
			$component = $this->componentForFile( $file );
			$entry     = $this->load[ $component ] ?? [
				'ms' => 0.0,
				'kb' => 0.0,
			];

			$entry['ms']             += ( $t0 - $this->lastNs ) / 1e6;
			$entry['kb']             += ( $mem - $this->lastMem ) / 1024;
			$this->load[ $component ] = $entry;
		}
		$this->lastMem = $mem;
		$this->lastNs  = (int) hrtime( true );
		$this->ownNs  += $this->lastNs - $t0;
	}

	/**
	 * El cursor de carga vuelve a empezar (después de los mu-plugins y antes de plugins_loaded).
	 */
	public function resetLoadCursor(): void {
		$this->lastNs  = (int) hrtime( true );
		$this->lastMem = memory_get_usage();
	}

	/**
	 * Inicio de un hook clave: envuelve sus callbacks para medirlos.
	 */
	public function beginHook( string $hook ): void {
		$t0                   = (int) hrtime( true );
		$this->depth[ $hook ] = ( $this->depth[ $hook ] ?? 0 ) + 1;
		if ( 1 === $this->depth[ $hook ] ) {
			$this->wrap( $hook );
		}
		$this->ownNs += (int) hrtime( true ) - $t0;
	}

	/**
	 * Fin de un hook clave: deja los callbacks como estaban.
	 */
	public function endHook( string $hook ): void {
		$t0                   = (int) hrtime( true );
		$this->depth[ $hook ] = max( 0, ( $this->depth[ $hook ] ?? 1 ) - 1 );
		if ( 0 === $this->depth[ $hook ] ) {
			$this->unwrap( $hook );
		}
		$this->ownNs += (int) hrtime( true ) - $t0;
	}

	/**
	 * Reemplaza cada callback del hook por un envoltorio que mide su tiempo exclusivo.
	 * Las claves del array de callbacks no cambian, así que remove_action y has_action siguen funcionando.
	 */
	private function wrap( string $hook ): void {
		$wpHook = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $wpHook instanceof \WP_Hook ) {
			return;
		}
		$this->saved[ $hook ] = [];
		foreach ( $wpHook->callbacks as $priority => $callbacks ) {
			if ( PHP_INT_MIN === $priority || PHP_INT_MAX === $priority ) {
				continue;
			}
			foreach ( $callbacks as $id => $callback ) {
				if ( ! is_array( $callback ) || ! isset( $callback['function'] ) ) {
					continue;
				}
				$original  = $callback['function'];
				$component = $this->componentForCallback( $original, (string) $id );
				// El núcleo no se mide. Lo que no es invocable se deja como está: que falle donde fallaría sin el perfilador.
				if ( 'core' === $component || ! is_callable( $original ) ) {
					continue;
				}
				$this->saved[ $hook ][ $priority ][ $id ] = $original;

				$wpHook->callbacks[ $priority ][ $id ]['function'] = $this->measured( $original, $component );
			}
		}
	}

	/**
	 * Envoltorio que mide el tiempo exclusivo de un callback (sin contar los envoltorios anidados).
	 * Estático, con el recolector por use(): más barato de crear que una closure ligada a $this.
	 *
	 * @param callable $original  Callback original.
	 * @param string   $component Componente al que se atribuye.
	 */
	private function measured( callable $original, string $component ): \Closure {
		$collector = $this;
		return static function ( ...$args ) use ( $original, $component, $collector ) {
			$collector->stack[] = [
				'start' => (int) hrtime( true ),
				'child' => 0,
			];
			try {
				return $original( ...$args );
			} finally {
				$frame   = array_pop( $collector->stack );
				$elapsed = (int) hrtime( true ) - $frame['start'];
				$last    = array_key_last( $collector->stack );
				if ( null !== $last ) {
					$collector->stack[ $last ]['child'] += $elapsed;
				}
				$collector->hooks[ $component ] = ( $collector->hooks[ $component ] ?? 0.0 ) + ( $elapsed - $frame['child'] ) / 1e6;
			}
		};
	}

	/**
	 * Mide el render de los bloques dinámicos de plugins y temas: su render_callback no pasa por
	 * ningún hook y en tiendas con bloques es una parte grande del costo de WooCommerce.
	 * El envoltorio queda durante todo el request (el registro de bloques vive lo mismo que él).
	 *
	 * @param mixed $args  Argumentos del bloque.
	 * @param mixed $name  Nombre del bloque.
	 * @return mixed
	 */
	public function wrapBlockRender( mixed $args, mixed $name = '' ): mixed {
		if ( ! is_array( $args ) || ! isset( $args['render_callback'] ) || ! is_callable( $args['render_callback'] ) || ! is_string( $name ) ) {
			return $args;
		}
		$t0     = (int) hrtime( true );
		$symbol = 'block:' . $name;
		if ( ! isset( $this->functions[ $symbol ] ) ) {
			$this->functions[ $symbol ] = $this->blockComponent( $args['render_callback'] );
			++$this->newSymbols;
		}
		$component = $this->functions[ $symbol ];
		if ( 'core' !== $component && 'other' !== $component ) {
			$args['render_callback'] = $this->measured( $args['render_callback'], $component );
		}
		$this->ownNs += (int) hrtime( true ) - $t0;
		return $args;
	}

	/**
	 * Componente de un render_callback. Los bloques registrados con block.json usan una closure del
	 * núcleo que incluye el render.php del plugin: se atribuye por esa plantilla.
	 *
	 * @param callable $callback Callback.
	 */
	private function blockComponent( callable $callback ): string {
		if ( $callback instanceof \Closure ) {
			try {
				$template = ( new \ReflectionFunction( $callback ) )->getStaticVariables()['template_path'] ?? null;
				if ( is_string( $template ) ) {
					return $this->componentForFile( $template );
				}
			} catch ( \Throwable ) {
				return 'other';
			}
		}
		return $this->componentForCallback( $callback, '' );
	}

	/**
	 * Restaura los callbacks originales que sigan registrados.
	 */
	private function unwrap( string $hook ): void {
		$wpHook = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $wpHook instanceof \WP_Hook || ! isset( $this->saved[ $hook ] ) ) {
			return;
		}
		foreach ( $this->saved[ $hook ] as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $original ) {
				if ( isset( $wpHook->callbacks[ $priority ][ $id ] ) ) {
					$wpHook->callbacks[ $priority ][ $id ]['function'] = $original;
				}
			}
		}
		unset( $this->saved[ $hook ] );
	}

	/**
	 * Componente dueño de un callback (por el archivo donde está definido). Se cachea por id y, para
	 * métodos, por clase: una clase vive en un solo archivo.
	 *
	 * @param mixed $callback Callback (puede no ser invocable).
	 */
	private function componentForCallback( mixed $callback, string $id ): string {
		// Funciones y Clase::método por su nombre tal cual (sin normalizar: es el camino caliente).
		if ( is_string( $callback ) ) {
			if ( ! isset( $this->functions[ $callback ] ) ) {
				$this->functions[ $callback ] = $this->resolve( $callback );
				++$this->newSymbols;
			}
			return $this->functions[ $callback ];
		}
		// [objeto|clase, método]: por la clase, que vive en un solo archivo.
		if ( is_array( $callback ) && isset( $callback[0] ) && ( is_object( $callback[0] ) || is_string( $callback[0] ) ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];
			if ( ! isset( $this->classes[ $class ] ) ) {
				$this->classes[ $class ] = $this->resolve( $class, true );
				++$this->newSymbols;
			}
			return $this->classes[ $class ];
		}
		// Closures y objetos invocables: por id dentro del request (no se guardan entre requests).
		if ( ! isset( $this->callbackComponents[ $id ] ) ) {
			$this->callbackComponents[ $id ] = $this->resolve( $callback );
		}
		return $this->callbackComponents[ $id ];
	}

	/**
	 * Componente por reflexión. Sin archivo (función o clase interna de PHP) cuenta como el núcleo;
	 * si no existe o un autoloader falla, queda sin atribuir.
	 *
	 * @param mixed $target  Callback, o nombre de clase si $isClass.
	 * @param bool  $isClass El objetivo es un nombre de clase.
	 */
	private function resolve( mixed $target, bool $isClass = false ): string {
		try {
			if ( $isClass && is_string( $target ) ) {
				/** @var class-string $target */
				$file = ( new \ReflectionClass( $target ) )->getFileName();
			} elseif ( $target instanceof \Closure ) {
				$file = ( new \ReflectionFunction( $target ) )->getFileName();
			} elseif ( is_string( $target ) ) {
				$file = str_contains( $target, '::' ) ? ( new \ReflectionMethod( $target ) )->getFileName() : ( new \ReflectionFunction( $target ) )->getFileName();
			} elseif ( is_object( $target ) ) {
				$file = ( new \ReflectionClass( $target ) )->getFileName();
			} else {
				return 'other';
			}
		} catch ( \Throwable ) {
			return 'other';
		}
		return is_string( $file ) ? $this->componentForFile( $file ) : 'core';
	}

	/**
	 * Caché de símbolos guardada, si sigue valiendo para esta config y no tiene más de 24 h.
	 *
	 * @return array{0: array<string, string>, 1: array<string, string>} Funciones y clases.
	 */
	private static function loadSymbols( string $file, string $key ): array {
		if ( '' === $file || '' === $key || ! is_file( $file ) ) {
			return [ [], [] ];
		}
		try {
			$data = include $file;
		} catch ( \Throwable ) {
			return [ [], [] ];
		}
		if ( ! is_array( $data ) || ( $data['k'] ?? null ) !== $key || ! is_int( $data['at'] ?? null ) || $data['at'] < time() - 86400 || ! is_array( $data['f'] ?? null ) || ! is_array( $data['c'] ?? null ) ) {
			return [ [], [] ];
		}
		return [ $data['f'], $data['c'] ];
	}

	/**
	 * Contenido a guardar de la caché de símbolos, o null si no hubo símbolos nuevos.
	 *
	 * @return array{file: string, data: array{k: string, at: int, f: array<string, string>, c: array<string, string>}}|null
	 */
	public function symbolCacheUpdate(): ?array {
		if ( 0 === $this->newSymbols || '' === $this->symbolFile || '' === $this->symbolKey || count( $this->functions ) + count( $this->classes ) > self::MAX_SYMBOLS ) {
			return null;
		}
		return [
			'file' => $this->symbolFile,
			'data' => [
				'k'  => $this->symbolKey,
				'at' => time(),
				'f'  => $this->functions,
				'c'  => $this->classes,
			],
		];
	}

	/**
	 * plugin:slug, mu:archivo, theme:slug, core u other.
	 */
	public function componentForFile( string $file ): string {
		if ( isset( $this->fileComponents[ $file ] ) ) {
			return $this->fileComponents[ $file ];
		}
		$normalized = str_replace( '\\', '/', $file );
		$component  = 'other';
		foreach ( $this->bases as $base ) {
			[ $prefix, $kind ] = explode( '|', $base, 2 );
			if ( ! str_starts_with( $normalized, $prefix ) ) {
				continue;
			}
			$rest = substr( $normalized, strlen( $prefix ) );
			if ( 'core' === $kind ) {
				$component = 'core';
			} else {
				$slash     = strpos( $rest, '/' );
				$slug      = false === $slash ? basename( $rest, '.php' ) : substr( $rest, 0, $slash );
				$component = $kind . ':' . $slug;
			}
			break;
		}
		$this->fileComponents[ $file ] = $component;
		return $component;
	}

	/**
	 * Prefijos de ruta → tipo de componente, de más específico a menos. Se incluye la ruta real
	 * para los directorios que sean enlaces simbólicos.
	 *
	 * @return list<string>
	 */
	private static function buildBases(): array {
		$map = [];
		$add = static function ( string $dir, string $kind ) use ( &$map ): void {
			foreach ( array_unique( array_filter( [ $dir, (string) realpath( $dir ) ] ) ) as $path ) {
				$map[] = rtrim( str_replace( '\\', '/', $path ), '/' ) . '/|' . $kind;
			}
		};
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$add( (string) constant( 'WPMU_PLUGIN_DIR' ), 'mu' );
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$add( (string) constant( 'WP_PLUGIN_DIR' ), 'plugin' );
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$add( (string) constant( 'WP_CONTENT_DIR' ) . '/themes', 'theme' );
		}
		if ( defined( 'ABSPATH' ) ) {
			$add( (string) constant( 'ABSPATH' ) . 'wp-includes', 'core' );
			$add( (string) constant( 'ABSPATH' ) . 'wp-admin', 'core' );
		}
		// Los prefijos más largos primero: mu-plugins y plugins viven dentro de wp-content.
		usort( $map, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		return $map;
	}

	/**
	 * Milisegundos desde el inicio del request.
	 */
	public function elapsedMs(): float {
		return $this->marks['loader'] + ( (int) hrtime( true ) - $this->loaderNs ) / 1e6;
	}

	/**
	 * Fases como duraciones (cada fase termina en su marca).
	 *
	 * @return array<string, float>
	 */
	public function phases(): array {
		$marks = $this->marks;
		asort( $marks );
		$phases = [];
		$prev   = 0.0;
		foreach ( $marks as $name => $at ) {
			$phases[ $name ] = round( max( 0.0, $at - $prev ), 2 );
			$prev            = $at;
		}
		return $phases;
	}

	/**
	 * Costo por componente: carga + callbacks en hooks clave, ordenado de mayor a menor.
	 *
	 * @return array<string, array{load_ms: float, hook_ms: float, kb: float}>
	 */
	public function components(): array {
		$out = [];
		foreach ( $this->load as $component => $entry ) {
			$out[ $component ] = [
				'load_ms' => round( $entry['ms'], 3 ),
				'hook_ms' => 0.0,
				'kb'      => round( $entry['kb'], 1 ),
			];
		}
		foreach ( $this->hooks as $component => $ms ) {
			$out[ $component ]            = $out[ $component ] ?? [
				'load_ms' => 0.0,
				'hook_ms' => 0.0,
				'kb'      => 0.0,
			];
			$out[ $component ]['hook_ms'] = round( $ms, 3 );
		}
		uasort( $out, static fn( array $a, array $b ): int => ( $b['load_ms'] + $b['hook_ms'] ) <=> ( $a['load_ms'] + $a['hook_ms'] ) );
		return $out;
	}

	/**
	 * Tiempo propio del perfilador en microsegundos (sin contar los envoltorios, que se miden aparte
	 * comparando requests con y sin muestreo).
	 */
	public function overheadUs(): int {
		return (int) round( $this->ownNs / 1000 );
	}
}
