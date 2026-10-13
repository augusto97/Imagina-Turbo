<?php
/**
 * Estado compartido del request entre el loader MU y el plugin principal.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Request\RequestContext;

/**
 * Solo propiedades estáticas: el loader corre antes que cualquier contenedor.
 */
final class Runtime {

	/** El loader temprano ya corrió en este request (desde mu-plugins o, en su defecto, desde el plugin). */
	public static bool $earlyRan = false;

	/** Corrió desde mu-plugins, antes que los plugins normales. */
	public static bool $fromMuLoader = false;

	/** Motivo del kill switch ('constant', 'file', 'cookie') o null. */
	public static ?string $killReason = null;

	/** Modo seguro activo (bandera en disco). */
	public static bool $safeMode = false;

	public static ?CompiledConfig $config = null;

	public static ?RequestContext $request = null;

	/** Se quitaron plugins de active_plugins en este request (fase 5). */
	public static bool $pluginsFiltered = false;

	/** Regla de carga condicional aplicada en este request ("WcAjax:get_refreshed_fragments"), o vacío. */
	public static string $filterKey = '';

	/**
	 * Experimento de carga condicional de este request (cookie firmada), o null.
	 *
	 * @var array{id: string, mode: int, nonce: string}|null
	 */
	public static ?array $experiment = null;

	/** El archivo principal del plugin llegó a arrancar (no lo detuvo la licencia, la versión de PHP ni un error). */
	public static bool $mainBooted = false;

	/** Directorio de datos (wp-content/imagina-turbo). */
	public static string $dataDir = '';

	/** Directorio del plugin, sin barra final. */
	public static string $pluginDir = '';

	/**
	 * Restablece el estado (solo pruebas).
	 */
	public static function reset(): void {
		self::$earlyRan        = false;
		self::$fromMuLoader    = false;
		self::$killReason      = null;
		self::$safeMode        = false;
		self::$config          = null;
		self::$request         = null;
		self::$pluginsFiltered = false;
		self::$filterKey       = '';
		self::$experiment      = null;
		self::$mainBooted      = false;
		self::$dataDir         = '';
		self::$pluginDir       = '';
	}
}
