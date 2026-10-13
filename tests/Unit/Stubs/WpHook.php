<?php
/**
 * WP_Hook mínimo para probar el envoltorio de callbacks del perfilador sin WordPress.
 *
 * Reproduce lo que importa del core: callbacks[prioridad][id] = [function, accepted_args] y
 * la iteración por prioridades en orden, que relee el array en cada prioridad.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Hook', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ValidClassName.NotCamelCaps
	final class WP_Hook {

		/** @var array<int, array<string, array{function: callable, accepted_args: int}>> */
		public array $callbacks = [];

		public function add( callable $callback, int $priority = 10, string $id = '', int $acceptedArgs = 1 ): void {
			$id                                  = '' !== $id ? $id : spl_object_hash( (object) [] );
			$this->callbacks[ $priority ][ $id ] = [
				'function'      => $callback,
				'accepted_args' => $acceptedArgs,
			];
			ksort( $this->callbacks );
		}

		public function doAction( mixed ...$args ): void {
			foreach ( array_keys( $this->callbacks ) as $priority ) {
				foreach ( $this->callbacks[ $priority ] as $the ) {
					( $the['function'] )( ...array_slice( $args, 0, $the['accepted_args'] ) );
				}
			}
		}
	}
}
