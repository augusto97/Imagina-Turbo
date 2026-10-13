<?php
/**
 * Estado del plugin para el admin: módulos, compatibilidad, solapamientos y alertas.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

use ImaginaTurbo\Compat\Matrix;
use ImaginaTurbo\Compat\MatrixException;
use ImaginaTurbo\Compat\Resolver;
use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Environment\Detector;
use ImaginaTurbo\Core\Environment\OverlapGuard;
use ImaginaTurbo\Core\Safety\AdminBypassCookie;
use ImaginaTurbo\Core\Safety\KillSwitch;
use ImaginaTurbo\Core\Safety\SafeMode;
use ImaginaTurbo\Licensing\ImaginaUpdater;

/**
 * Agrega información de varios servicios. Solo admin/REST.
 */
final class Diagnostics {

	private ?Matrix $matrix = null;

	private string $matrixError = '';

	private bool $matrixLoaded = false;

	public function __construct( private readonly Container $container, private readonly string $matrixFile ) {
	}

	public function matrix(): ?Matrix {
		if ( ! $this->matrixLoaded ) {
			$this->matrixLoaded = true;
			try {
				$this->matrix = Matrix::fromFile( $this->matrixFile );
			} catch ( MatrixException $e ) {
				$this->matrixError = $e->getMessage();
			}
		}
		return $this->matrix;
	}

	/**
	 * @return array{forced_off: array<string, list<string>>, conflicts: list<array{slug: string, plugin_file: string, category: string, modules: list<string>}>, coordinated: list<string>, multiple_page_caches: bool}
	 */
	public function overlap(): array {
		$matrix = $this->matrix();
		if ( null === $matrix ) {
			return [
				'forced_off'           => [],
				'conflicts'            => [],
				'coordinated'          => [],
				'multiple_page_caches' => false,
			];
		}
		return ( new OverlapGuard() )->evaluate( $matrix->overlapping(), $this->container->get( Detector::class )->activePlugins() );
	}

	/**
	 * Módulos con metadatos y estado.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function modules(): array {
		$catalog = $this->container->get( ModuleCatalog::class );
		$states  = ModuleRegistry::states( $catalog, $this->container->get( Settings::class )->modules(), $this->overlap()['forced_off'] );
		$out     = [];
		foreach ( $catalog->all() as $id => $definition ) {
			$out[] = array_merge( $definition->toArray(), $states[ $id ] );
		}
		return $out;
	}

	/**
	 * Informe de compatibilidad: integraciones aplicadas y conflictos.
	 *
	 * @return array<string, mixed>
	 */
	public function compatReport(): array {
		$matrix   = $this->matrix();
		$detector = $this->container->get( Detector::class );
		$env      = $detector->detect();
		$theme    = is_array( $env['theme'] ?? null ) ? $env['theme'] : [];
		$resolved = null;
		$titles   = [];
		if ( null !== $matrix ) {
			$resolved = ( new Resolver() )->resolve( $matrix, $detector->activePlugins(), (string) ( $theme['stylesheet'] ?? '' ), (string) ( $theme['template'] ?? '' ) );
			foreach ( $matrix->integrations() as $integration ) {
				if ( in_array( $integration['id'], $resolved['integrations'], true ) ) {
					$titles[] = [
						'id'    => (string) $integration['id'],
						'title' => is_string( $integration['title'] ?? null ) ? $integration['title'] : (string) $integration['id'],
						'type'  => (string) $integration['type'],
						'notes' => is_string( $integration['notes'] ?? null ) ? $integration['notes'] : '',
					];
				}
			}
		}
		return [
			'matrix'       => [
				'version'   => null !== $matrix ? $matrix->version() : '',
				'generated' => null !== $matrix ? $matrix->generated() : '',
				'error'     => $this->matrixError,
				'signed'    => false,
			],
			'integrations' => $titles,
			'rules'        => $resolved,
			'overlap'      => $this->overlap(),
		];
	}

	/**
	 * Estado de seguridad operativa.
	 *
	 * @return array<string, mixed>
	 */
	public function safety(): array {
		$dataDir  = Runtime::$dataDir;
		$safeMode = $this->container->get( SafeMode::class );
		$cookie   = null;
		$key      = AdminBypassCookie::key();
		if ( null !== $key && isset( $_COOKIE[ AdminBypassCookie::NAME ] ) ) {
			$raw = sanitize_text_field( wp_unslash( (string) $_COOKIE[ AdminBypassCookie::NAME ] ) );
			$uid = AdminBypassCookie::validate( $raw, $key, time() );
			if ( null !== $uid ) {
				$parts  = explode( '|', $raw );
				$cookie = [
					'user'    => $uid,
					'expires' => (int) ( $parts[1] ?? 0 ),
				];
			}
		}
		return [
			'kill_switch'   => [
				'active'   => null !== Runtime::$killReason || KillSwitch::constantActive() || KillSwitch::fileActive( $dataDir ),
				'reason'   => Runtime::$killReason,
				'constant' => KillSwitch::constantActive(),
				'file'     => KillSwitch::fileActive( $dataDir ),
			],
			'bypass_cookie' => [
				'available' => null !== $key,
				'active'    => null !== $cookie,
				'expires'   => $cookie['expires'] ?? null,
			],
			'safe_mode'     => [
				'active'  => $safeMode->isActive(),
				'details' => $safeMode->details(),
			],
			'data_dir'      => [
				'path'     => str_replace( ABSPATH, '', $dataDir ),
				'exists'   => is_dir( $dataDir ),
				'writable' => is_dir( $dataDir ) && wp_is_writable( $dataDir ),
			],
		];
	}

	/**
	 * Estado completo para el panel.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$detector = $this->container->get( Detector::class );
		$loader   = $this->container->get( MuLoaderInstaller::class )->status();
		$config   = $this->container->get( ConfigCompiler::class )->status();
		$safety   = $this->safety();
		$overlap  = $this->overlap();
		$license  = $this->container->get( ImaginaUpdater::class )->status();
		$env      = $detector->detect();

		$loader['ran_this_request'] = Runtime::$fromMuLoader;

		return [
			'version'     => ITB_VERSION,
			'environment' => $env,
			'safety'      => $safety,
			'loader'      => $loader,
			'config'      => $config,
			'modules'     => $this->modules(),
			'overlap'     => $overlap,
			'license'     => $license,
			'matrix'      => [
				'version' => null !== $this->matrix() ? $this->matrix()->version() : '',
				'error'   => $this->matrixError,
			],
			'alerts'      => $this->alerts( $env, $safety, $loader, $config, $overlap, $license ),
		];
	}

	/**
	 * Alertas ordenadas por gravedad.
	 *
	 * @param array<string, mixed> $env     Entorno.
	 * @param array<string, mixed> $safety  Seguridad.
	 * @param array<string, mixed> $loader  Loader.
	 * @param array<string, mixed> $config  Config compilada.
	 * @param array<string, mixed> $overlap Solapamientos.
	 * @param array<string, mixed> $license Licencia.
	 * @return list<array{level: string, code: string, message: string}>
	 */
	private function alerts( array $env, array $safety, array $loader, array $config, array $overlap, array $license ): array {
		$alerts = [];
		$add    = static function ( string $level, string $code, string $message ) use ( &$alerts ): void {
			$alerts[] = [
				'level'   => $level,
				'code'    => $code,
				'message' => $message,
			];
		};

		if ( true === ( $safety['safe_mode']['active'] ?? false ) ) {
			$add( 'error', 'safe_mode', __( 'El plugin está en modo seguro: todos los módulos están apagados. Revisa el registro y sal del modo seguro en Avanzado.', 'imagina-turbo' ) );
		}
		if ( true === ( $safety['kill_switch']['active'] ?? false ) ) {
			$add( 'warning', 'kill_switch', __( 'El kill switch está activo: el plugin no actúa en el sitio.', 'imagina-turbo' ) );
		}
		if ( true !== ( $safety['data_dir']['writable'] ?? false ) ) {
			$add( 'error', 'data_dir', __( 'No se puede escribir en wp-content/imagina-turbo/. Sin ese directorio no hay configuración compilada.', 'imagina-turbo' ) );
		}
		if ( true !== ( $loader['installed'] ?? false ) ) {
			$add( 'warning', 'loader_missing', true === ( $loader['writable'] ?? false ) ? __( 'El loader MU no está instalado. Se reinstalará en la próxima carga del admin.', 'imagina-turbo' ) : __( 'No se puede escribir en wp-content/mu-plugins/: la etapa temprana no está disponible. Da permisos de escritura a esa carpeta.', 'imagina-turbo' ) );
		} elseif ( true !== ( $loader['current'] ?? false ) ) {
			$add( 'info', 'loader_outdated', __( 'El loader MU está desactualizado. Se actualizará en la próxima carga del admin.', 'imagina-turbo' ) );
		}
		if ( true !== ( $config['exists'] ?? false ) && true !== ( $safety['safe_mode']['active'] ?? false ) ) {
			$add( 'warning', 'config_missing', __( 'No hay configuración compilada. Usa "Recompilar configuración" en Avanzado.', 'imagina-turbo' ) );
		}
		if ( '' !== $this->matrixError ) {
			$add( 'error', 'matrix', $this->matrixError );
		}
		if ( true === ( $overlap['multiple_page_caches'] ?? false ) ) {
			$add( 'warning', 'multiple_caches', __( 'Hay más de un plugin de caché de página activo. Deja solo uno.', 'imagina-turbo' ) );
		}
		foreach ( is_array( $overlap['conflicts'] ?? null ) ? $overlap['conflicts'] : [] as $conflict ) {
			if ( [] !== $conflict['modules'] ) {
				/* translators: 1: plugin, 2: módulos */
				$add( 'info', 'overlap', sprintf( __( '%1$s está activo: se apagan los módulos equivalentes (%2$s).', 'imagina-turbo' ), $conflict['slug'], implode( ', ', $conflict['modules'] ) ) );
			}
		}
		if ( null === ( $env['cache_plugin'] ?? null ) ) {
			$add( 'info', 'no_cache_plugin', __( 'No se detectó LiteSpeed Cache ni WP Rocket. Imagina Turbo funcionará solo con el edge de Cloudflare.', 'imagina-turbo' ) );
		}
		if ( true !== ( $env['opcache'] ?? false ) ) {
			$add( 'warning', 'opcache', __( 'OPcache no está activo: la configuración compilada se leerá desde disco en cada request.', 'imagina-turbo' ) );
		}
		if ( true === ( $license['protected'] ?? false ) && false === ( $license['licensed'] ?? null ) ) {
			$add( 'error', 'license', __( 'La licencia de Imagina Turbo no está activa.', 'imagina-turbo' ) );
		}

		$order = [
			'error'   => 0,
			'warning' => 1,
			'info'    => 2,
		];
		usort( $alerts, static fn( array $a, array $b ): int => $order[ $a['level'] ] <=> $order[ $b['level'] ] );
		return $alerts;
	}
}
