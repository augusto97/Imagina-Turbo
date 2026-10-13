<?php
/**
 * Contenedor de dependencias mínimo.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

/**
 * Registra fábricas por id (normalmente el nombre de clase) y comparte la instancia.
 */
final class Container {

	/** @var array<string, callable(self): object> */
	private array $factories = [];

	/** @var array<string, object> */
	private array $instances = [];

	/**
	 * Registra una fábrica. Si ya había una instancia creada, se descarta.
	 *
	 * @param string                 $id      Id del servicio.
	 * @param callable(self): object $factory Fábrica.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Registra una instancia ya construida.
	 */
	public function instance( string $id, object $service ): void {
		$this->instances[ $id ] = $service;
	}

	public function has( string $id ): bool {
		return isset( $this->instances[ $id ] ) || isset( $this->factories[ $id ] );
	}

	/**
	 * Obtiene el servicio (se construye la primera vez).
	 *
	 * @template T of object
	 * @param class-string<T> $id Id del servicio.
	 * @return T
	 * @throws \LogicException Si el servicio no está registrado o no es del tipo esperado.
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->instances[ $id ] ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				throw new \LogicException( sprintf( 'Servicio no registrado: %s', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- mensaje interno.
			}
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		}
		$service = $this->instances[ $id ];
		if ( ! $service instanceof $id ) {
			throw new \LogicException( sprintf( 'El servicio %s no es del tipo esperado.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- mensaje interno.
		}
		return $service;
	}
}
