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
use ImaginaTurbo\Core\Environment\OverlapGuard;
use ImaginaTurbo\Core\ModuleCatalog;
use ImaginaTurbo\Core\ModuleRegistry;
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
		try {
			$matrix        = Matrix::fromFile( $this->matrixFile );
			$matrixVersion = $matrix->version();
			$compat        = ( new Resolver() )->resolve( $matrix, $active, (string) ( $theme['stylesheet'] ?? '' ), (string) ( $theme['template'] ?? '' ) );
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
			'filter'                      => [],
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
		];

		$data['hash'] = self::hash( $data );
		return $data;
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
