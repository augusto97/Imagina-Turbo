<?php
/**
 * Etapa temprana: se ejecuta desde el loader MU, antes de cargar los plugins.
 *
 * Presupuesto: < 0,3 ms y < 150 KB. Solo carga los archivos estrictamente necesarios
 * (sin autoloader de Composer) y no usa funciones de WordPress ni la base de datos.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Request\RequestClassifier;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Safety\AdminBypassCookie;
use ImaginaTurbo\Modules\Profiler\Collector;
use ImaginaTurbo\Core\Safety\KillSwitch;
use ImaginaTurbo\Core\Safety\SafeMode;

/**
 * Orquesta kill switch, modo seguro, config compilada y clasificación.
 */
final class EarlyLoader {

	/** Igual que AdminBypassCookie::DIAG_NAME (repetido para no cargar esa clase en cada request). */
	public const DIAG_COOKIE = 'itb_diag';

	/** Debe coincidir con SafeMode::FLAG (se repite para no cargar la clase en cada request). */
	public const SAFE_MODE_FLAG = 'safe-mode.flag';

	/** Tipos fatales (igual que SafeMode::FATAL_TYPES). */
	private const FATAL_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	private static bool $shutdownRegistered = false;

	/**
	 * Punto de entrada desde mu-plugins.
	 *
	 * @param string $pluginDir Directorio del plugin principal.
	 */
	public static function bootFromMu( string $pluginDir ): void {
		Runtime::$fromMuLoader = true;
		self::boot( $pluginDir );
	}

	/**
	 * Ejecuta la etapa temprana una sola vez por request.
	 *
	 * @param string $pluginDir Directorio del plugin principal.
	 */
	public static function boot( string $pluginDir ): void {
		if ( Runtime::$earlyRan ) {
			return;
		}
		$startNs   = hrtime( true );
		$startWall = microtime( true );
		$startMem  = memory_get_usage();

		Runtime::$earlyRan  = true;
		Runtime::$pluginDir = rtrim( $pluginDir, '/\\' );
		Runtime::$dataDir   = self::dataDir();

		self::requireFiles( Runtime::$pluginDir );

		$reason = KillSwitch::reason( Runtime::$dataDir, $_COOKIE );
		if ( null !== $reason ) {
			Runtime::$killReason = $reason;
			return;
		}

		if ( ! self::$shutdownRegistered ) {
			self::$shutdownRegistered = true;
			register_shutdown_function( [ self::class, 'onShutdown' ] );
		}

		if ( is_file( Runtime::$dataDir . '/' . self::SAFE_MODE_FLAG ) ) {
			Runtime::$safeMode = true;
			return;
		}

		$config = CompiledConfig::load( CompiledConfig::path( Runtime::$dataDir, self::blogId(), self::isMultisite() ) );
		if ( null === $config ) {
			// Sin config no se aplica nada en el frontend. El plugin la recompila en admin o cron.
			return;
		}
		Runtime::$config  = $config;
		Runtime::$request = ( new RequestClassifier( $config->classifierConfig() ) )->classifyCurrent();

		self::maybeShield( $config );
		self::maybeStartProfiler( $config, $startNs, $startMem, $startWall );

		// Fase 5: aquí se ejecutará el filtro de active_plugins.
	}

	/**
	 * Guardia del escudo. El archivo solo se carga si el request es candidato (asset inexistente,
	 * XML-RPC, ?author=, ruta de usuarios de la REST): en una página normal cuesta unas comparaciones.
	 */
	private static function maybeShield( CompiledConfig $config ): void {
		$request = Runtime::$request;
		if ( null === $request || ! $config->moduleEnabled( 'shield' ) ) {
			return;
		}
		$type      = $request->type;
		$candidate = RequestType::StaticMiss === $type
			|| RequestType::XmlRpc === $type
			|| ( RequestType::FrontendHtml === $type && isset( $_GET['author'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se comprueba si existe.
			|| ( RequestType::Rest === $type && ( str_contains( $request->path, '/users' ) || isset( $_GET['rest_route'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se comprueba si existe.
		if ( ! $candidate ) {
			return;
		}
		$shield = $config->get( 'shield' );
		if ( ! is_array( $shield ) ) {
			return;
		}
		require_once Runtime::$pluginDir . '/src/Modules/Shield/EarlyGuard.php';
		$rest     = $config->get( 'rest_prefix', 'wp-json' );
		$response = \ImaginaTurbo\Modules\Shield\EarlyGuard::decide( $type, $request->path, is_string( $rest ) ? $rest : 'wp-json', $shield, $_GET, $_COOKIE, $_SERVER ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura para decidir un bloqueo.
		if ( null !== $response ) {
			\ImaginaTurbo\Modules\Shield\EarlyGuard::respond( $response );
		}
	}

	/**
	 * Decide si este request entra en la muestra del perfilador. El camino normal (no muestreado)
	 * cuesta un número aleatorio y un par de comparaciones; el recolector solo se carga si toca.
	 */
	private static function maybeStartProfiler( CompiledConfig $config, int $startNs, int $startMem, float $startWall ): void {
		$request = Runtime::$request;
		if ( null === $request || RequestType::Cli === $request->type || ! $config->moduleEnabled( 'profiler' ) ) {
			return;
		}
		$profiler = $config->get( 'profiler' );
		if ( ! is_array( $profiler ) ) {
			return;
		}

		$source = null;
		if ( isset( $_COOKIE[ self::DIAG_COOKIE ] ) ) {
			if ( ! class_exists( AdminBypassCookie::class, false ) ) {
				require_once Runtime::$pluginDir . '/src/Core/Safety/AdminBypassCookie.php';
			}
			if ( AdminBypassCookie::isValidRequest( $_COOKIE, null, self::DIAG_COOKIE ) ) {
				$source = 2; // Collector::SOURCE_DIAG (la clase aún no está cargada).
			}
		}
		if ( null === $source && is_int( $profiler['intensive_until'] ?? null ) && $profiler['intensive_until'] > time() ) {
			$source = 1;
		}
		if ( null === $source ) {
			$rate = is_float( $profiler['rate'] ?? null ) || is_int( $profiler['rate'] ?? null ) ? (float) $profiler['rate'] : 0.0;
			if ( $rate <= 0.0 || mt_rand( 1, 1000000 ) > (int) round( $rate * 1000000 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- muestreo estadístico, no seguridad; wp_rand() aún no existe aquí.
				return;
			}
			$source = 0;
		}

		$extra = [];
		if ( RequestType::AdminAjax === $request->type && '' !== $request->endpoint ) {
			$extra = [ 'wp_ajax_' . $request->endpoint, 'wp_ajax_nopriv_' . $request->endpoint ];
		} elseif ( RequestType::WcAjax === $request->type && '' !== $request->endpoint ) {
			$extra = [ 'wc_ajax_' . $request->endpoint ];
		}

		require_once Runtime::$pluginDir . '/src/Modules/Profiler/Collector.php';
		Collector::start( $source, $startNs, $startMem, $startWall, $extra, Runtime::$dataDir . '/profiler-symbols.php', $config->hash() );
	}

	/**
	 * Shutdown: cuenta fatales atribuibles y desarma el loader si el plugin principal no arrancó.
	 */
	public static function onShutdown(): void {
		$error = error_get_last();
		if ( null !== $error && 0 !== ( $error['type'] & self::FATAL_TYPES ) ) {
			if ( ! class_exists( SafeMode::class, false ) ) {
				require_once Runtime::$pluginDir . '/src/Core/Safety/SafeMode.php';
			}
			( new SafeMode( Runtime::$dataDir, Runtime::$pluginDir ) )->handleShutdown( Runtime::$pluginsFiltered );
		}

		self::disarmIfMainDidNotBoot();
	}

	/**
	 * Si en un request de admin el loader corrió pero el plugin principal no arrancó (licencia inválida,
	 * PHP antiguo, desactivado sin pasar por el hook), se borra la config compilada: así el loader deja de
	 * aplicar nada hasta que el plugin vuelva a arrancar y la recompile.
	 */
	private static function disarmIfMainDidNotBoot(): void {
		if ( Runtime::$mainBooted || ! Runtime::$fromMuLoader || null === Runtime::$config ) {
			return;
		}
		if ( ! function_exists( 'is_admin' ) || ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! function_exists( 'did_action' ) || 0 === did_action( 'plugins_loaded' ) ) {
			return;
		}
		$file = CompiledConfig::path( Runtime::$dataDir, self::blogId(), self::isMultisite() );
		if ( is_file( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- sin WordPress garantizado en shutdown.
		}
	}

	/**
	 * wp-content/imagina-turbo (o ITB_DATA_DIR si se define, útil en pruebas).
	 */
	public static function dataDir(): string {
		if ( defined( 'ITB_DATA_DIR' ) && is_string( constant( 'ITB_DATA_DIR' ) ) ) {
			return rtrim( (string) constant( 'ITB_DATA_DIR' ), '/\\' );
		}
		return defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/imagina-turbo' : '';
	}

	/**
	 * Id del sitio actual (multisite). En los MU-plugins ya está definido.
	 */
	public static function blogId(): int {
		$id = $GLOBALS['blog_id'] ?? 1;
		return is_numeric( $id ) ? max( 1, (int) $id ) : 1;
	}

	public static function isMultisite(): bool {
		return function_exists( 'is_multisite' ) ? is_multisite() : ( defined( 'MULTISITE' ) && (bool) constant( 'MULTISITE' ) );
	}

	/**
	 * Carga explícita del código de la etapa temprana (más barato que el autoloader).
	 */
	private static function requireFiles( string $pluginDir ): void {
		$src = $pluginDir . '/src/Core/';
		require_once $src . 'Safety/KillSwitch.php';
		require_once $src . 'Config/CompiledConfig.php';
		require_once $src . 'Request/RequestType.php';
		require_once $src . 'Request/RequestContext.php';
		require_once $src . 'Request/RequestClassifier.php';
	}
}
