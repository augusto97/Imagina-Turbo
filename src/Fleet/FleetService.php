<?php
/**
 * Flota (docs/05, fase 8): plantilla estándar del hub, desviaciones y aplicación.
 *
 * - La plantilla se descarga del hub (firmada) en la tarea diaria o a petición, y se guarda en
 *   itb_fleet con las desviaciones calculadas. Si el hub falla, se conserva la anterior (regla 7).
 * - Aplicar guarda antes un snapshot de lo que la plantilla puede tocar (itb_fleet_snapshots, los
 *   últimos 10) y deja un evento; Cloudflare además guarda su propio snapshot de la zona (regla 4).
 * - Desde el admin se aplica todo lo no invasivo tras confirmar. Desde el hub (lotes) solo si el
 *   administrador del sitio lo autorizó (fleet_remote) y nunca reglas de Cloudflare que alguien
 *   cambió a mano en el panel.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Fleet;

use ImaginaTurbo\Cloudflare\CloudflareService;
use ImaginaTurbo\Core\Config\CompiledConfig;
use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\ModuleCatalog;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Hub\HubClient;

/**
 * Sincronización, desviaciones, aplicación y reversión.
 *
 * @phpstan-import-type Policy from FleetPolicy
 * @phpstan-import-type Drift from FleetPolicy
 */
final class FleetService {

	public const OPTION = 'itb_fleet';

	public const SNAPSHOTS_OPTION = 'itb_fleet_snapshots';

	public const SNAPSHOTS_KEEP = 10;

	/**
	 * @param \Closure(): CloudflareService $cloudflare Servicio de Cloudflare recién construido (con la config vigente).
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ModuleCatalog $catalog,
		private readonly HubClient $hub,
		private readonly ConfigCompiler $compiler,
		private readonly EventLog $events,
		private readonly \Closure $cloudflare,
	) {
	}

	/**
	 * Estado guardado.
	 *
	 * @return array{policy: Policy|null, fetched_at: int, error: string, drift: list<Drift>, cloudflare_error: string, checked_at: int, applied: array{id: string, version: int, at: int, source: string}|null}
	 */
	public function state(): array {
		$raw    = get_option( self::OPTION, [] );
		$raw    = is_array( $raw ) ? $raw : [];
		$policy = FleetPolicy::normalize( $raw['policy_raw'] ?? null, $this->catalog );
		return [
			'policy'           => $policy,
			'fetched_at'       => (int) ( $raw['fetched_at'] ?? 0 ),
			'error'            => is_string( $raw['error'] ?? null ) ? $raw['error'] : '',
			'drift'            => is_array( $raw['drift'] ?? null ) ? array_values( $raw['drift'] ) : [],
			'cloudflare_error' => is_string( $raw['cloudflare_error'] ?? null ) ? $raw['cloudflare_error'] : '',
			'checked_at'       => (int) ( $raw['checked_at'] ?? 0 ),
			'applied'          => is_array( $raw['applied'] ?? null ) ? [
				'id'      => (string) ( $raw['applied']['id'] ?? '' ),
				'version' => (int) ( $raw['applied']['version'] ?? 0 ),
				'at'      => (int) ( $raw['applied']['at'] ?? 0 ),
				'source'  => (string) ( $raw['applied']['source'] ?? '' ),
			] : null,
		];
	}

	/**
	 * @param array<string, mixed> $changes Cambios parciales del estado guardado.
	 */
	private function save( array $changes ): void {
		$raw = get_option( self::OPTION, [] );
		update_option( self::OPTION, array_replace( is_array( $raw ) ? $raw : [], $changes ), false );
	}

	/**
	 * Descarga la plantilla y recalcula las desviaciones.
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function sync(): array {
		$fetched = $this->hub->fetchPolicy();
		if ( is_wp_error( $fetched ) ) {
			$this->save( [ 'error' => $fetched->get_error_message() ] );
			return [
				'ok'    => false,
				'error' => $fetched->get_error_message(),
			];
		}
		if ( null !== $fetched && null === FleetPolicy::normalize( $fetched, $this->catalog ) ) {
			$error = __( 'El hub envió una plantilla con un formato no válido; se conserva la anterior.', 'imagina-turbo' );
			$this->save( [ 'error' => $error ] );
			return [
				'ok'    => false,
				'error' => $error,
			];
		}
		$previous = $this->state()['policy'];
		$this->save(
			[
				'policy_raw' => $fetched,
				'fetched_at' => time(),
				'error'      => '',
			]
		);
		$policy = $this->state()['policy'];
		if ( null !== $policy && ( null === $previous || $previous['id'] !== $policy['id'] || $previous['version'] !== $policy['version'] ) ) {
			$this->events->info(
				'fleet',
				'policy',
				/* translators: 1: plantilla, 2: versión */
				sprintf( __( 'Plantilla de flota recibida: %1$s (versión %2$d).', 'imagina-turbo' ), $policy['name'], $policy['version'] ),
				[
					'policy'  => $policy['id'],
					'version' => $policy['version'],
					'ignored' => $policy['ignored'],
				]
			);
		}
		$this->check();
		return [
			'ok'    => true,
			'error' => '',
		];
	}

	/**
	 * Recalcula las desviaciones (con Cloudflare si está conectado: una lectura de la zona).
	 *
	 * @return list<Drift>
	 */
	public function check(): array {
		$policy = $this->state()['policy'];
		if ( null === $policy ) {
			$this->save(
				[
					'drift'      => [],
					'checked_at' => time(),
					'summary'    => null,
				]
			);
			return [];
		}
		$drift   = FleetPolicy::drift( $policy, $this->settings->all(), $this->defaults(), $this->catalog );
		$cfError = '';
		if ( [] !== $policy['cloudflare']['rules'] || [] !== $policy['cloudflare']['settings'] ) {
			try {
				$cf = ( $this->cloudflare )();
				if ( ! $this->cloudflareModuleOn() ) {
					$cfError = __( 'El módulo Cloudflare está apagado en este sitio: la parte de Cloudflare de la plantilla no se comprueba ni se aplica.', 'imagina-turbo' );
				} elseif ( $cf->ready() ) {
					$drift = array_merge( $drift, FleetPolicy::cloudflareDrift( $policy, $cf->plan()['changes'], $cf->refPrefix() ) );
				} else {
					$cfError = __( 'Cloudflare no está conectado en este sitio: la parte de Cloudflare de la plantilla no se puede comprobar.', 'imagina-turbo' );
				}
			} catch ( \Throwable $e ) {
				$cfError = $e->getMessage();
			}
		}
		$this->save(
			[
				'drift'            => $drift,
				'cloudflare_error' => $cfError,
				'checked_at'       => time(),
			]
		);
		$this->save( [ 'summary' => $this->summary() ] );
		return $drift;
	}

	/**
	 * Aplica la plantilla guardada.
	 *
	 * @param string $source admin o hub.
	 * @return array{applied: list<string>, skipped: list<array{key: string, reason: string}>, cloudflare: array<string, mixed>|null, snapshot: string}|\WP_Error
	 */
	public function apply( string $source, int $expectedVersion = 0 ): array|\WP_Error {
		$state  = $this->state();
		$policy = $state['policy'];
		if ( null === $policy ) {
			return new \WP_Error( 'itb_fleet_none', __( 'No hay plantilla de flota.', 'imagina-turbo' ), [ 'status' => 404 ] );
		}
		if ( $expectedVersion > 0 && $expectedVersion !== $policy['version'] ) {
			return new \WP_Error( 'itb_fleet_version', __( 'La versión de la plantilla no coincide con la pedida.', 'imagina-turbo' ), [ 'status' => 409 ] );
		}
		$remote = 'hub' === $source;
		if ( $remote && true !== $this->settings->get( 'fleet_remote', false ) ) {
			return new \WP_Error( 'itb_fleet_remote_off', __( 'Este sitio no autorizó que el hub aplique la plantilla.', 'imagina-turbo' ), [ 'status' => 403 ] );
		}

		$snapshot = $this->snapshot( $remote ? 'hub' : 'admin', $policy );
		$drift    = FleetPolicy::drift( $policy, $this->settings->all(), $this->defaults(), $this->catalog );
		$applied  = [];
		$skipped  = [];
		$changes  = [];
		foreach ( $drift as $item ) {
			if ( 'module' === $item['kind'] ) {
				$changes['modules'][ $item['key'] ] = $item['desired'];
			} elseif ( 'tweak' === $item['kind'] ) {
				$changes['tweaks'][ $item['key'] ] = $item['desired'];
			} else {
				$changes[ $item['key'] ] = $item['desired'];
			}
			$applied[] = $item['kind'] . ':' . $item['key'];
		}
		if ( isset( $changes['tweaks'] ) ) {
			$stored            = $this->settings->get( 'tweaks', [] );
			$changes['tweaks'] = array_replace( is_array( $stored ) ? $stored : [], $changes['tweaks'] );
		}
		if ( [] !== $changes ) {
			$this->settings->update( $changes );
			$this->compiler->compile();
			// El resto del request (Cloudflare) usa la config nueva.
			$fresh = CompiledConfig::load( $this->compiler->file() );
			if ( null !== $fresh ) {
				Runtime::$config = $fresh;
			}
		}

		$cloudflare = null;
		if ( [] !== $policy['cloudflare']['rules'] || [] !== $policy['cloudflare']['settings'] ) {
			$cloudflare = $this->applyCloudflare( $policy, $skipped );
		}

		$this->save(
			[
				'applied' => [
					'id'      => $policy['id'],
					'version' => $policy['version'],
					'at'      => time(),
					'source'  => $source,
				],
			]
		);
		$this->events->warning(
			'fleet',
			'apply',
			/* translators: 1: plantilla, 2: versión, 3: origen */
			sprintf( __( 'Plantilla de flota %1$s (versión %2$d) aplicada desde %3$s.', 'imagina-turbo' ), $policy['name'], $policy['version'], $remote ? __( 'el hub', 'imagina-turbo' ) : __( 'el admin', 'imagina-turbo' ) ),
			[
				'policy'   => $policy['id'],
				'version'  => $policy['version'],
				'applied'  => $applied,
				'skipped'  => $skipped,
				'snapshot' => $snapshot,
				'user'     => get_current_user_id(),
			]
		);
		$this->check();
		return [
			'applied'    => $applied,
			'skipped'    => $skipped,
			'cloudflare' => $cloudflare,
			'snapshot'   => $snapshot,
		];
	}

	/**
	 * Cambios de Cloudflare de la plantilla: solo crear y actualizar (lo cambiado a mano en el panel
	 * se deja; se decide en la pantalla Cloudflare).
	 *
	 * @param Policy                                    $policy  Plantilla.
	 * @param list<array{key: string, reason: string}> $skipped Lo que no se aplicó.
	 * @return array<string, mixed>
	 */
	private function applyCloudflare( array $policy, array &$skipped ): array {
		try {
			$cf = ( $this->cloudflare )();
			if ( ! $this->cloudflareModuleOn() || ! $cf->ready() ) {
				$skipped[] = [
					'key'    => 'cloudflare',
					'reason' => $this->cloudflareModuleOn() ? __( 'Cloudflare no está conectado.', 'imagina-turbo' ) : __( 'El módulo Cloudflare está apagado.', 'imagina-turbo' ),
				];
				return [ 'ready' => false ];
			}
			$ids = [];
			foreach ( FleetPolicy::cloudflareDrift( $policy, $cf->plan()['changes'], $cf->refPrefix() ) as $item ) {
				if ( 'apply' === $item['desired'] ) {
					$ids[] = $item['key'];
				} else {
					$skipped[] = [
						'key'    => $item['key'],
						'reason' => 'drifted' === $item['current'] ? __( 'Cambiada a mano en Cloudflare: se revisa en la pantalla Cloudflare.', 'imagina-turbo' ) : __( 'Bloqueada por el plan o los permisos del token.', 'imagina-turbo' ),
					];
				}
			}
			if ( [] === $ids ) {
				return [
					'ready'   => true,
					'applied' => [],
				];
			}
			return [ 'ready' => true ] + $cf->apply( $ids );
		} catch ( \Throwable $e ) {
			$skipped[] = [
				'key'    => 'cloudflare',
				'reason' => $e->getMessage(),
			];
			return [
				'ready' => true,
				'error' => $e->getMessage(),
			];
		}
	}

	/**
	 * El módulo Cloudflare está activo (en la config vigente: la plantilla puede acabar de activarlo).
	 */
	private function cloudflareModuleOn(): bool {
		return null !== Runtime::$config && Runtime::$config->moduleEnabled( 'cloudflare' );
	}

	/**
	 * Módulos activos por defecto (para comparar con los que no están guardados).
	 *
	 * @return array<string, bool>
	 */
	private function defaults(): array {
		$out = [];
		foreach ( $this->catalog->all() as $id => $definition ) {
			$out[ $id ] = $definition->defaultEnabled;
		}
		return $out;
	}

	/**
	 * Guarda lo que la plantilla puede tocar antes de aplicarla.
	 *
	 * @param Policy $policy Plantilla.
	 */
	private function snapshot( string $source, array $policy ): string {
		$all  = $this->settings->all();
		$data = [ 'modules' => $all['modules'] ?? [] ];
		foreach ( array_merge( FleetPolicy::SETTINGS, [ 'tweaks' ] ) as $key ) {
			$data[ $key ] = $all[ $key ] ?? null;
		}
		$id     = substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
		$list   = get_option( self::SNAPSHOTS_OPTION, [] );
		$list   = is_array( $list ) ? $list : [];
		$list[] = [
			'id'         => $id,
			'created_at' => time(),
			'source'     => $source,
			'policy'     => $policy['id'] . '@' . $policy['version'],
			'settings'   => $data,
		];
		update_option( self::SNAPSHOTS_OPTION, array_slice( $list, -self::SNAPSHOTS_KEEP ), false );
		return $id;
	}

	/**
	 * Snapshots sin el contenido.
	 *
	 * @return list<array{id: string, created_at: int, source: string, policy: string}>
	 */
	public function snapshots(): array {
		$list = get_option( self::SNAPSHOTS_OPTION, [] );
		$out  = [];
		foreach ( array_reverse( is_array( $list ) ? $list : [] ) as $item ) {
			if ( is_array( $item ) ) {
				$out[] = [
					'id'         => (string) ( $item['id'] ?? '' ),
					'created_at' => (int) ( $item['created_at'] ?? 0 ),
					'source'     => (string) ( $item['source'] ?? '' ),
					'policy'     => (string) ( $item['policy'] ?? '' ),
				];
			}
		}
		return $out;
	}

	/**
	 * Vuelve a los ajustes de un snapshot (Cloudflare se revierte con su propio snapshot).
	 */
	public function revert( string $id ): bool {
		$list = get_option( self::SNAPSHOTS_OPTION, [] );
		foreach ( is_array( $list ) ? $list : [] as $item ) {
			if ( is_array( $item ) && ( $item['id'] ?? null ) === $id && is_array( $item['settings'] ?? null ) ) {
				$this->settings->update( array_filter( $item['settings'], static fn( $v ): bool => null !== $v ) );
				$this->compiler->compile();
				$this->events->warning(
					'fleet',
					'revert',
					__( 'Ajustes devueltos al estado anterior a la plantilla de flota.', 'imagina-turbo' ),
					[
						'snapshot' => $id,
						'user'     => get_current_user_id(),
					]
				);
				$this->check();
				return true;
			}
		}
		return false;
	}

	/**
	 * Resumen guardado en la última comprobación (lo lee la telemetría sin construir este servicio).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function storedSummary(): ?array {
		$raw = get_option( self::OPTION, [] );
		return is_array( $raw ) && is_array( $raw['summary'] ?? null ) ? $raw['summary'] : null;
	}

	/**
	 * Resumen para la telemetría (sin valores, solo qué se desvía).
	 *
	 * @return array{policy: string, version: int, applied_version: int, drift: list<string>, remote: bool}|null
	 */
	public function summary(): ?array {
		$state = $this->state();
		if ( null === $state['policy'] ) {
			return null;
		}
		return [
			'policy'          => $state['policy']['id'],
			'version'         => $state['policy']['version'],
			'applied_version' => null !== $state['applied'] && $state['applied']['id'] === $state['policy']['id'] ? $state['applied']['version'] : 0,
			'drift'           => array_map( static fn( array $d ): string => $d['kind'] . ':' . $d['key'], $state['drift'] ),
			'remote'          => true === $this->settings->get( 'fleet_remote', false ),
		];
	}
}
