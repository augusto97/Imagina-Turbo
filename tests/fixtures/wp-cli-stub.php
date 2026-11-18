<?php
/**
 * Doble mínimo de WP_CLI para las pruebas: solo add_hook(), que guarda los callbacks para dispararlos a mano.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Registro de hooks de WP-CLI.
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- doble de la clase de WP-CLI.
	final class WP_CLI {

		/** @var array<string, list<callable>> */
		public static array $hooks = [];

		public static function add_hook( string $when, callable $callback ): void {
			self::$hooks[ $when ][] = $callback;
		}

		/**
		 * Como WP_CLI::do_hook(): pasa el nombre del comando a cada callback.
		 */
		public static function do_hook( string $when, string $arg ): void {
			foreach ( self::$hooks[ $when ] ?? [] as $callback ) {
				$callback( $arg );
			}
		}
	}
}
