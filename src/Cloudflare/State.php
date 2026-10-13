<?php
/**
 * Estado de la conexión con Cloudflare (opción itb_cf_state, autoload = no).
 *
 * Guarda la zona, el plan, los permisos detectados y, por cada regla y ajuste propios, el hash de lo
 * último que aplicamos: así se detecta la deriva (alguien lo cambió a mano en el panel). También el
 * valor que tenía cada ajuste de zona antes del primer cambio del plugin, para poder devolverlo
 * ("Quitar todo lo de Imagina Turbo").
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Lectura y escritura del estado.
 */
final class State {

	public const OPTION = 'itb_cf_state';

	/**
	 * @return array{zone_id: string, zone_name: string, account_id: string, plan: string, plan_name: string, status: string, connected_at: int, checked_at: int, permissions: array<string, bool>, applied: array<string, array{hash: string, at: int}>, error: string}
	 */
	public function all(): array {
		$s     = get_option( self::OPTION, [] );
		$s     = is_array( $s ) ? $s : [];
		$perms = [];
		foreach ( is_array( $s['permissions'] ?? null ) ? $s['permissions'] : [] as $k => $v ) {
			if ( is_string( $k ) ) {
				$perms[ $k ] = true === $v;
			}
		}
		$applied = [];
		foreach ( is_array( $s['applied'] ?? null ) ? $s['applied'] : [] as $k => $v ) {
			if ( is_string( $k ) && is_array( $v ) && is_string( $v['hash'] ?? null ) ) {
				$applied[ $k ] = [
					'hash' => $v['hash'],
					'at'   => is_int( $v['at'] ?? null ) ? $v['at'] : 0,
				];
			}
		}
		$str = static fn( string $key ): string => is_string( $s[ $key ] ?? null ) ? $s[ $key ] : '';
		$int = static fn( string $key ): int => is_int( $s[ $key ] ?? null ) ? $s[ $key ] : 0;
		return [
			'zone_id'      => $str( 'zone_id' ),
			'zone_name'    => $str( 'zone_name' ),
			'account_id'   => $str( 'account_id' ),
			'plan'         => $str( 'plan' ),
			'plan_name'    => $str( 'plan_name' ),
			'status'       => $str( 'status' ),
			'connected_at' => $int( 'connected_at' ),
			'checked_at'   => $int( 'checked_at' ),
			'permissions'  => $perms,
			'applied'      => $applied,
			'error'        => $str( 'error' ),
		];
	}

	public function zoneId(): string {
		return $this->all()['zone_id'];
	}

	public function plan(): string {
		$plan = $this->all()['plan'];
		return '' === $plan ? 'free' : $plan;
	}

	/**
	 * @param array<string, mixed> $changes Cambios (se mezclan con lo guardado).
	 */
	public function update( array $changes ): void {
		$raw = get_option( self::OPTION, [] );
		// Sobre lo guardado (no sobre all()): así se conservan claves que all() no expone, como originals.
		update_option( self::OPTION, array_merge( is_array( $raw ) ? $raw : [], $this->all(), $changes ), false );
	}

	/**
	 * Hash de lo último aplicado para una clave (ref de regla o "setting:{id}").
	 */
	public function appliedHash( string $key ): ?string {
		return $this->all()['applied'][ $key ]['hash'] ?? null;
	}

	public function markApplied( string $key, ?string $hash ): void {
		$applied = $this->all()['applied'];
		if ( null === $hash ) {
			unset( $applied[ $key ] );
		} else {
			$applied[ $key ] = [
				'hash' => $hash,
				'at'   => time(),
			];
		}
		$this->update( [ 'applied' => $applied ] );
	}

	/**
	 * Valor de cada ajuste antes del primer cambio del plugin y si ahora mismo está cambiado
	 * (id => {value, active}). El valor se guarda una vez y se conserva: quitar y volver a aplicar
	 * (o restaurar un snapshot) solo cambia la marca.
	 *
	 * @return array<string, array{value: mixed, active: bool}>
	 */
	public function originals(): array {
		$s   = get_option( self::OPTION, [] );
		$out = [];
		foreach ( is_array( $s ) && is_array( $s['originals'] ?? null ) ? $s['originals'] : [] as $id => $item ) {
			if ( is_string( $id ) && is_array( $item ) && array_key_exists( 'value', $item ) ) {
				$out[ $id ] = [
					'value'  => $item['value'],
					'active' => true === ( $item['active'] ?? false ),
				];
			}
		}
		return $out;
	}

	/**
	 * Antes de cambiar un ajuste: guarda su valor si es la primera vez y lo marca como cambiado.
	 */
	public function rememberOriginal( string $id, mixed $value ): void {
		$originals = $this->originals();
		if ( ! isset( $originals[ $id ] ) ) {
			if ( null === $value ) {
				return;
			}
			$originals[ $id ] = [
				'value'  => $value,
				'active' => true,
			];
		} else {
			$originals[ $id ]['active'] = true;
		}
		$this->update( [ 'originals' => $originals ] );
	}

	/**
	 * Marca un ajuste como cambiado o devuelto a su valor original.
	 */
	public function setOriginalActive( string $id, bool $active ): void {
		$originals = $this->originals();
		if ( isset( $originals[ $id ] ) && $originals[ $id ]['active'] !== $active ) {
			$originals[ $id ]['active'] = $active;
			$this->update( [ 'originals' => $originals ] );
		}
	}

	/**
	 * Lo que el plugin tiene en la zona: reglas propias aplicadas y ajustes cambiados. Sin llamar a
	 * la API (se usa en la pantalla de plugins para avisar antes de desactivar).
	 *
	 * @return array{rules: int, settings: int}
	 */
	public function footprint(): array {
		$rules = 0;
		foreach ( array_keys( $this->all()['applied'] ) as $key ) {
			if ( ! str_starts_with( $key, 'setting:' ) ) {
				++$rules;
			}
		}
		return [
			'rules'    => $rules,
			'settings' => count( array_filter( $this->originals(), static fn( array $o ): bool => $o['active'] ) ),
		];
	}

	public function forget(): void {
		delete_option( self::OPTION );
	}
}
