<?php
/**
 * Vigilancia de las reglas activas (docs/02 módulo 4, punto 4).
 *
 * - Un fatal en un request filtrado queda en wp-content/imagina-turbo/filter-faults.log (lo escribe el
 *   loader en shutdown). En el siguiente request de admin o en la tarea horaria, la regla se desactiva.
 * - Cada regla activa se revisa una vez al día con 5 pares; si aparece una diferencia, se desactiva.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

use ImaginaTurbo\Core\EarlyLoader;
use ImaginaTurbo\Core\Events\EventLog;

/**
 * Fallos y revisión periódica.
 */
final class FilterWatch {

	/**
	 * @param \Closure(): void $recompile Recompila la config.
	 */
	public function __construct(
		private readonly RuleStore $store,
		private readonly string $dataDir,
		private readonly EventLog $events,
		private readonly \Closure $recompile,
	) {
	}

	/**
	 * Desactiva las reglas que provocaron fatales. Barato si no hay archivo.
	 *
	 * @return list<string> Claves desactivadas.
	 */
	public function processFaults(): array {
		$file = $this->dataDir . '/' . EarlyLoader::FILTER_FAULTS;
		if ( '' === $this->dataDir || ! is_file( $file ) ) {
			return [];
		}
		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		wp_delete_file( $file );
		$faults = [];
		foreach ( is_array( $lines ) ? $lines : [] as $line ) {
			$row = json_decode( $line, true );
			if ( is_array( $row ) && is_string( $row['k'] ?? null ) ) {
				$faults[ $row['k'] ] = is_string( $row['m'] ?? null ) ? $row['m'] : '';
			}
		}
		$disabled = [];
		foreach ( $faults as $key => $message ) {
			$rule = $this->store->get( RuleStore::id( $key ) );
			if ( null === $rule || 'active' !== $rule['status'] ) {
				continue;
			}
			$rule['status'] = 'disabled';
			$rule['reason'] = __( 'Error fatal en un request filtrado: ', 'imagina-turbo' ) . $message;
			$this->store->save( $rule );
			$disabled[] = $key;
			$this->events->error(
				'plugin_filter',
				'disabled',
				__( 'Regla de carga condicional desactivada por un error fatal.', 'imagina-turbo' ),
				[
					'endpoint' => $key,
					'message'  => $message,
				]
			);
		}
		if ( [] !== $disabled ) {
			( $this->recompile )();
		}
		return $disabled;
	}

	/**
	 * Tarea horaria: fallos y la revisión de una regla que lleve más de un día sin revisar.
	 */
	public function hourly( ExperimentRunner $runner ): void {
		$this->processFaults();
		foreach ( $this->store->all() as $rule ) {
			if ( 'active' === $rule['status'] && $rule['checked_at'] < time() - DAY_IN_SECONDS ) {
				$runner->recheck( $rule['id'] );
				return;
			}
		}
	}
}
