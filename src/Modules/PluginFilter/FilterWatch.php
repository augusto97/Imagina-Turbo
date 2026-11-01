<?php
/**
 * Vigilancia de las reglas activas (docs/02 módulo 4, punto 4).
 *
 * - Un fatal en un request filtrado queda en wp-content/imagina-turbo/filter-faults.php (lo escribe el
 *   loader en shutdown). En el siguiente request de admin o en la tarea horaria, la regla se desactiva.
 * - Cada regla activa se revisa una vez al día con 5 pares; si aparece una diferencia, se desactiva.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

use ImaginaTurbo\Core\EarlyLoader;
use ImaginaTurbo\Core\Safety\DataFile;
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
		if ( '' === $this->dataDir ) {
			return [];
		}
		$faults = [];
		// También el nombre de la 0.21.1 y anteriores, que se borra al leerlo.
		foreach ( [ EarlyLoader::FILTER_FAULTS, EarlyLoader::FILTER_FAULTS_LEGACY ] as $name ) {
			$file = $this->dataDir . '/' . $name;
			if ( ! is_file( $file ) ) {
				continue;
			}
			$rows = DataFile::lines( $file );
			wp_delete_file( $file );
			foreach ( $rows as $row ) {
				if ( is_string( $row['k'] ?? null ) ) {
					$faults[ $row['k'] ] = is_string( $row['m'] ?? null ) ? $row['m'] : '';
				}
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
