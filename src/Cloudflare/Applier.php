<?php
/**
 * Aplicar cambios y restaurar snapshots (docs/03, sección 8; reglas 3 y 4).
 *
 * Aplicar:
 * 1. Se vuelve a leer la zona y a calcular el plan (lo que vio el usuario puede estar viejo).
 * 2. Cada operación de reglas se valida con ?dry_run=true (sintaxis, campos, plan y cupos) sin
 *    persistir nada. Si una falla, no se toca la zona.
 * 3. Snapshot del estado actual (reglas propias de todas las fases gestionadas y ajustes).
 * 4. Eliminar → modificar → crear (así se liberan cupos) y luego ajustes. Si algo falla a mitad,
 *    se restaura el snapshot.
 *
 * Las reglas nuevas se insertan al principio de la fase: en caché, las reglas posteriores mandan,
 * así una regla del cliente que excluye algo sigue ganando sobre las nuestras.
 *
 * Restaurar: deja las reglas propias y los ajustes como en el snapshot, sin tocar las del cliente.
 * Las reglas recreadas tienen otro id (Cloudflare lo asigna), el contenido y la posición coinciden.
 *
 * Nunca se usa PUT sobre un ruleset que ya existe: reemplazaría las reglas del cliente.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Repository\SnapshotRepository;

/**
 * Escritura en la zona.
 */
final class Applier {

	public function __construct(
		private readonly Client $client,
		private readonly string $zoneId,
		private readonly string $refPrefix,
		private readonly Inspector $inspector,
		private readonly SnapshotRepository $snapshots,
		private readonly State $state,
		private readonly EventLog $events,
	) {
	}

	/**
	 * @param list<array{id: string, kind: string, action: string, phase: string, ref: string, setting: string, desired: mixed, current: mixed, title: string}> $plan Plan recién calculado.
	 * @param list<string>                                                                                                                                    $ids  Cambios elegidos.
	 * @return array{applied: list<string>, failed: list<array{id: string, error: string}>, snapshot_id: int, rolled_back: bool}
	 */
	public function apply( array $plan, array $ids, string $reason ): array {
		$selected = array_values( array_filter( $plan, static fn( array $c ): bool => in_array( $c['id'], $ids, true ) && in_array( $c['action'], [ 'create', 'update', 'delete', 'drifted' ], true ) ) );
		$result   = [
			'applied'     => [],
			'failed'      => [],
			'snapshot_id' => 0,
			'rolled_back' => false,
		];
		if ( [] === $selected ) {
			return $result;
		}

		$zone = $this->inspector->read();

		// 2. Validación sin cambios.
		foreach ( $selected as $change ) {
			if ( 'rule' !== $change['kind'] ) {
				continue;
			}
			$check = $this->ruleOperation( $change, $zone, true );
			if ( ! $check->ok() ) {
				$result['failed'][] = [
					'id'    => $change['id'],
					'error' => $check->message(),
				];
			}
		}
		if ( [] !== $result['failed'] ) {
			$this->events->warning( 'cloudflare', 'dry_run', __( 'Cloudflare rechazó los cambios en la validación previa; no se tocó la zona.', 'imagina-turbo' ), [ 'failed' => $result['failed'] ] );
			return $result;
		}

		// 3. Snapshot.
		$snapshotId = $this->snapshots->insert( $this->zoneId, $reason, $this->capture( $zone ) );
		if ( 0 === $snapshotId ) {
			$result['failed'][] = [
				'id'    => 'snapshot',
				'error' => __( 'No se pudo guardar el snapshot previo; no se aplicó nada.', 'imagina-turbo' ),
			];
			return $result;
		}
		$result['snapshot_id'] = $snapshotId;

		// 4. Eliminar, modificar, crear; después ajustes.
		$order = [
			'delete'  => 0,
			'update'  => 1,
			'drifted' => 1,
			'create'  => 2,
		];
		usort( $selected, static fn( array $a, array $b ): int => ( 'setting' === $a['kind'] ? 3 : $order[ $a['action'] ] ) <=> ( 'setting' === $b['kind'] ? 3 : $order[ $b['action'] ] ) );
		foreach ( $selected as $change ) {
			if ( 'setting' === $change['kind'] ) {
				// El valor de antes del primer cambio, para "Quitar todo lo de Imagina Turbo".
				$this->state->rememberOriginal( $change['setting'], $change['current'] );
			}
			$ok = 'rule' === $change['kind'] ? $this->applyRule( $change ) : $this->applySetting( $change['setting'], $change['desired'] );
			if ( true !== $ok ) {
				$result['failed'][] = [
					'id'    => $change['id'],
					'error' => $ok,
				];
				break;
			}
			$result['applied'][] = $change['id'];
		}

		if ( [] !== $result['failed'] ) {
			$restore               = $this->restore( $snapshotId, false );
			$result['rolled_back'] = [] === $restore['failed'];
			$this->events->error(
				'cloudflare',
				'apply_failed',
				$result['rolled_back'] ? __( 'Un cambio en Cloudflare falló; se restauró el snapshot previo.', 'imagina-turbo' ) : __( 'Un cambio en Cloudflare falló y la restauración automática no se completó. Revisa la zona.', 'imagina-turbo' ),
				[
					'snapshot' => $snapshotId,
					'failed'   => $result['failed'],
					'restore'  => $restore['failed'],
				]
			);
			return $result;
		}

		$this->events->warning(
			'cloudflare',
			'apply',
			__( 'Cambios aplicados en Cloudflare.', 'imagina-turbo' ),
			[
				'user'     => get_current_user_id(),
				'snapshot' => $snapshotId,
				'applied'  => $result['applied'],
			]
		);
		return $result;
	}

	/**
	 * Deja reglas propias y ajustes como en el snapshot.
	 *
	 * @return array{applied: list<string>, failed: list<array{id: string, error: string}>, snapshot_id: int, rolled_back: bool}
	 */
	public function restore( int $snapshotId, bool $saveCurrent = true ): array {
		$result   = [
			'applied'     => [],
			'failed'      => [],
			'snapshot_id' => 0,
			'rolled_back' => false,
		];
		$snapshot = $this->snapshots->find( $snapshotId );
		if ( null === $snapshot || $snapshot['zone_id'] !== $this->zoneId ) {
			$result['failed'][] = [
				'id'    => 'snapshot',
				'error' => __( 'El snapshot no existe o es de otra zona.', 'imagina-turbo' ),
			];
			return $result;
		}
		$payload = $snapshot['payload'];
		$zone    = $this->inspector->read();
		if ( $saveCurrent ) {
			/* translators: %d: id del snapshot. */
			$result['snapshot_id'] = $this->snapshots->insert( $this->zoneId, sprintf( __( 'Antes de restaurar el snapshot #%d', 'imagina-turbo' ), $snapshotId ), $this->capture( $zone ) );
		}

		foreach ( is_array( $payload['phases'] ?? null ) ? $payload['phases'] : [] as $phase => $saved ) {
			if ( ! is_string( $phase ) || ! in_array( $phase, Phases::MANAGED, true ) || ! is_array( $saved ) ) {
				continue;
			}
			$error = $this->restorePhase( $phase, is_array( $saved['own'] ?? null ) ? $saved['own'] : [], $zone['phases'][ $phase ] );
			if ( '' !== $error ) {
				$result['failed'][] = [
					'id'    => 'phase:' . $phase,
					'error' => $error,
				];
			} else {
				$result['applied'][] = 'phase:' . $phase;
			}
		}

		foreach ( is_array( $payload['settings'] ?? null ) ? $payload['settings'] : [] as $id => $value ) {
			if ( ! is_string( $id ) || null === $value ) {
				continue;
			}
			$current  = $zone['settings'][ $id ]['value'] ?? null;
			$original = $this->state->originals()[ $id ] ?? null;
			if ( Planner::same( $current, $value ) ) {
				continue;
			}
			$ok = $this->applySetting( $id, $value );
			if ( true === $ok ) {
				// Si el ajuste es uno que el plugin cambió alguna vez, queda cambiado o no según el valor restaurado.
				if ( null !== $original ) {
					$this->state->setOriginalActive( $id, ! Planner::same( $value, $original['value'] ) );
				}
				$result['applied'][] = 'setting:' . $id;
			} else {
				$result['failed'][] = [
					'id'    => 'setting:' . $id,
					'error' => $ok,
				];
			}
		}

		$this->events->warning(
			'cloudflare',
			'restore',
			[] === $result['failed'] ? __( 'Snapshot de Cloudflare restaurado.', 'imagina-turbo' ) : __( 'La restauración del snapshot de Cloudflare no se completó.', 'imagina-turbo' ),
			[
				'user'     => get_current_user_id(),
				'snapshot' => $snapshotId,
				'failed'   => $result['failed'],
			]
		);
		return $result;
	}

	/**
	 * Quita todo lo del plugin de la zona: borra sus reglas en todas las fases gestionadas (las del
	 * cliente no se tocan) y devuelve los ajustes que cambió a su valor de antes. Guarda antes un
	 * snapshot, así que también esto se puede deshacer.
	 *
	 * @return array{applied: list<string>, failed: list<array{id: string, error: string}>, snapshot_id: int, rolled_back: bool}
	 */
	public function removeAll(): array {
		$result                = [
			'applied'     => [],
			'failed'      => [],
			'snapshot_id' => 0,
			'rolled_back' => false,
		];
		$zone                  = $this->inspector->read();
		$result['snapshot_id'] = $this->snapshots->insert( $this->zoneId, __( 'Antes de quitar todo lo de Imagina Turbo', 'imagina-turbo' ), $this->capture( $zone ) );
		if ( 0 === $result['snapshot_id'] ) {
			$result['failed'][] = [
				'id'    => 'snapshot',
				'error' => __( 'No se pudo guardar el snapshot previo; no se quitó nada.', 'imagina-turbo' ),
			];
			return $result;
		}
		foreach ( Phases::MANAGED as $phase ) {
			$current = $zone['phases'][ $phase ] ?? null;
			if ( null === $current ) {
				continue;
			}
			$own = array_filter( $current['rules'], fn( array $rule ): bool => $this->isOwn( $rule ) );
			if ( $current['readable'] && [] === $own ) {
				continue;
			}
			$error = $this->restorePhase( $phase, [], $current );
			if ( '' !== $error ) {
				$result['failed'][] = [
					'id'    => 'phase:' . $phase,
					'error' => $error,
				];
			} else {
				$result['applied'][] = 'phase:' . $phase;
			}
		}
		foreach ( $this->state->originals() as $id => $original ) {
			if ( ! $original['active'] ) {
				continue;
			}
			$current = $zone['settings'][ $id ]['value'] ?? null;
			$ok      = Planner::same( $current, $original['value'] ) ? true : $this->applySetting( (string) $id, $original['value'] );
			if ( true === $ok ) {
				$this->state->setOriginalActive( (string) $id, false );
				$result['applied'][] = 'setting:' . $id;
			} else {
				$result['failed'][] = [
					'id'    => 'setting:' . $id,
					'error' => $ok,
				];
			}
		}
		$this->events->warning(
			'cloudflare',
			'remove_all',
			[] === $result['failed'] ? __( 'Se quitó de Cloudflare todo lo de Imagina Turbo (reglas propias y ajustes devueltos a su valor anterior).', 'imagina-turbo' ) : __( 'No se pudo quitar todo lo de Imagina Turbo de Cloudflare. Revisa la zona.', 'imagina-turbo' ),
			[
				'user'     => get_current_user_id(),
				'snapshot' => $result['snapshot_id'],
				'applied'  => $result['applied'],
				'failed'   => $result['failed'],
			]
		);
		return $result;
	}

	/**
	 * Reglas propias (con posición) y ajustes legibles de la zona.
	 *
	 * @param array{phases: array<string, array{readable: bool, exists: bool, id: string, rules: list<array<string, mixed>>, error: string}>, settings: array<string, array{readable: bool, value: mixed, editable: bool, error: string}>} $zone Lectura.
	 * @return array<string, mixed>
	 */
	public function capture( array $zone ): array {
		$phases = [];
		foreach ( $zone['phases'] as $phase => $data ) {
			if ( ! $data['readable'] ) {
				continue;
			}
			$own = [];
			foreach ( $data['rules'] as $index => $rule ) {
				if ( $this->isOwn( $rule ) ) {
					$own[] = [
						'index' => $index + 1,
						'rule'  => Rule::strip( $rule ),
					];
				}
			}
			$phases[ $phase ] = [
				'exists' => $data['exists'],
				'total'  => count( $data['rules'] ),
				'own'    => $own,
			];
		}
		$settings = [];
		foreach ( $zone['settings'] as $id => $data ) {
			if ( $data['readable'] && $data['editable'] ) {
				$settings[ $id ] = $data['value'];
			}
		}
		return [
			'zone_id'    => $this->zoneId,
			'ref_prefix' => $this->refPrefix,
			'phases'     => $phases,
			'settings'   => $settings,
		];
	}

	/**
	 * @param array<mixed> $target Reglas propias del snapshot ({index, rule}).
	 * @param array{readable: bool, exists: bool, id: string, rules: list<array<string, mixed>>, error: string} $current Fase actual.
	 */
	private function restorePhase( string $phase, array $target, array $current ): string {
		if ( ! $current['readable'] ) {
			return $current['error'];
		}
		$wanted = [];
		foreach ( $target as $item ) {
			if ( is_array( $item['rule'] ?? null ) && is_string( $item['rule']['ref'] ?? null ) ) {
				$wanted[ $item['rule']['ref'] ] = [
					'index' => (int) ( $item['index'] ?? 0 ),
					'rule'  => $item['rule'],
				];
			}
		}
		$rulesetId = $current['id'];
		$rules     = $current['rules'];

		// Sobran: propias que el snapshot no tenía.
		foreach ( $rules as $rule ) {
			if ( $this->isOwn( $rule ) && ! isset( $wanted[ (string) $rule['ref'] ] ) ) {
				$response = $this->client->delete( '/zones/' . rawurlencode( $this->zoneId ) . '/rulesets/' . rawurlencode( $rulesetId ) . '/rules/' . rawurlencode( (string) ( $rule['id'] ?? '' ) ) );
				if ( ! $response->ok() ) {
					return $response->message();
				}
				$rules = Inspector::ruleset( $response->resultArray() )['rules'];
				$this->state->markApplied( (string) $rule['ref'], null );
			}
		}

		// Faltan o difieren, en el orden original.
		uasort( $wanted, static fn( array $a, array $b ): int => $a['index'] <=> $b['index'] );
		foreach ( $wanted as $ref => $item ) {
			$existing = Planner::findByRef( $rules, $ref );
			if ( null !== $existing && Planner::contains( $existing, $item['rule'] ) && self::sameFields( $existing, $item['rule'] ) ) {
				continue;
			}
			if ( null !== $existing ) {
				$response = $this->client->patch( '/zones/' . rawurlencode( $this->zoneId ) . '/rulesets/' . rawurlencode( $rulesetId ) . '/rules/' . rawurlencode( (string) ( $existing['id'] ?? '' ) ), $item['rule'] );
			} elseif ( '' === $rulesetId || ( ! $current['exists'] && [] === $rules ) ) {
				$response = $this->client->put( Phases::entrypoint( $this->zoneId, $phase ), [ 'rules' => [ $item['rule'] ] ] );
			} else {
				$index    = max( 1, min( $item['index'], count( $rules ) + 1 ) );
				$response = $this->client->post( '/zones/' . rawurlencode( $this->zoneId ) . '/rulesets/' . rawurlencode( $rulesetId ) . '/rules', $item['rule'] + [ 'position' => [ 'index' => $index ] ] );
			}
			if ( ! $response->ok() ) {
				return $response->message();
			}
			$ruleset           = Inspector::ruleset( $response->resultArray() );
			$rulesetId         = $ruleset['id'];
			$rules             = $ruleset['rules'];
			$current['exists'] = true;
			$this->remember( $phase, $ref, $rules );
		}
		return '';
	}

	/**
	 * Crear, modificar o eliminar una regla (o solo validarlo con dry_run).
	 *
	 * @param array{action: string, phase: string, ref: string, desired: mixed, current: mixed} $change Cambio.
	 * @param array{phases: array<string, array{readable: bool, exists: bool, id: string, rules: list<array<string, mixed>>, error: string}>, settings: array<string, mixed>} $zone Lectura.
	 */
	private function ruleOperation( array $change, array $zone, bool $dryRun ): Response {
		$phase   = $zone['phases'][ $change['phase'] ] ?? null;
		$query   = $dryRun ? [ 'dry_run' => 'true' ] : [];
		$desired = is_array( $change['desired'] ) ? $change['desired'] : [];
		if ( null === $phase || ! $phase['readable'] ) {
			return Response::failure( 0, 'itb_phase', __( 'No se pudieron leer las reglas de la fase.', 'imagina-turbo' ) );
		}
		$existing = Planner::findByRef( $phase['rules'], $change['ref'] );
		$base     = '/zones/' . rawurlencode( $this->zoneId ) . '/rulesets/' . rawurlencode( $phase['id'] ) . '/rules';

		if ( 'delete' === $change['action'] ) {
			if ( null === $existing ) {
				return new Response(
					200,
					true,
					[
						'id'    => $phase['id'],
						'rules' => $phase['rules'],
					]
				);
			}
			return $this->client->request( 'DELETE', $base . '/' . rawurlencode( (string) ( $existing['id'] ?? '' ) ), null, $query );
		}
		if ( null !== $existing ) {
			return $this->client->request( 'PATCH', $base . '/' . rawurlencode( (string) ( $existing['id'] ?? '' ) ), $desired, $query );
		}
		if ( ! $phase['exists'] ) {
			// Solo se crea el ruleset de entrada si no existe: no hay reglas del cliente que pisar.
			return $this->client->request( 'PUT', Phases::entrypoint( $this->zoneId, $change['phase'] ), [ 'rules' => [ $desired ] ], $query );
		}
		return $this->client->request( 'POST', $base, $desired + [ 'position' => [ 'before' => '' ] ], $query );
	}

	/**
	 * @param array{id: string, action: string, phase: string, ref: string, desired: mixed, current: mixed} $change Cambio.
	 * @return true|string
	 */
	private function applyRule( array $change ): bool|string {
		// Se relee la fase: un cambio anterior pudo crear el ruleset o mover reglas.
		$zone     = [
			'phases'   => [ $change['phase'] => $this->inspector->phase( $change['phase'] ) ],
			'settings' => [],
		];
		$response = $this->ruleOperation( $change, $zone, false );
		if ( ! $response->ok() ) {
			return $response->message();
		}
		$rules = Inspector::ruleset( $response->resultArray() )['rules'];
		if ( 'delete' === $change['action'] ) {
			$this->state->markApplied( $change['ref'], null );
		} else {
			$this->remember( $change['phase'], $change['ref'], $rules );
		}
		return true;
	}

	/**
	 * @return true|string
	 */
	private function applySetting( string $id, mixed $value ): bool|string {
		$response = $this->client->patch( Inspector::settingPath( $this->zoneId, $id ), [ 'value' => $value ] );
		return $response->ok() ? true : $response->message();
	}

	/**
	 * Guarda el hash de la regla tal como quedó en Cloudflare (para detectar cambios a mano).
	 *
	 * @param list<array<string, mixed>> $rules Reglas de la fase tras el cambio.
	 */
	private function remember( string $phase, string $ref, array $rules ): void {
		$rule = Planner::findByRef( $rules, $ref );
		$this->state->markApplied( $ref, null === $rule ? null : Rule::fromApi( $phase, $rule )->hash() );
	}

	/**
	 * @param array<mixed> $rule Regla.
	 */
	private function isOwn( array $rule ): bool {
		$ref = $rule['ref'] ?? null;
		return is_string( $ref ) && str_starts_with( $ref, $this->refPrefix );
	}

	/**
	 * Mismos campos gestionados (un campo opcional que sobra también cuenta como diferencia).
	 *
	 * @param array<mixed> $a Regla actual.
	 * @param array<mixed> $b Regla del snapshot.
	 */
	private static function sameFields( array $a, array $b ): bool {
		$keys = static function ( array $rule ): array {
			$list = array_keys( array_filter( $rule, static fn( $v ): bool => [] !== $v && null !== $v ) );
			sort( $list );
			return $list;
		};
		return $keys( Rule::strip( $a ) ) === $keys( Rule::strip( $b ) );
	}
}
