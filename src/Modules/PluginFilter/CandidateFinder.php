<?php
/**
 * Candidatos a regla (docs/02 módulo 4): endpoints filtrables con muestras del perfilador y los plugins
 * que más cuestan en ellos. Nunca se aplican solos: son la propuesta de un experimento.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

use ImaginaTurbo\Integrations\ImaginaSearch;
use ImaginaTurbo\Modules\Profiler\Report;

/**
 * Agrupación por endpoint.
 *
 * @phpstan-type Candidate array{key: string, label: string, samples: int, ms_avg: int, mem_avg_mb: float, plugins: list<array{plugin: string, total_ms: float, kb: float}>, saving_ms: float, blocked: string}
 */
final class CandidateFinder {

	/** Un plugin se sugiere si cuesta al menos esto por request en el endpoint. */
	public const MIN_MS = 0.5;

	/**
	 * @param list<array<string, mixed>> $rows   Muestras recientes del perfilador.
	 * @param list<string>               $active Plugins activos.
	 * @param list<string>               $taken  Claves que ya tienen regla.
	 * @return list<Candidate>
	 */
	public static function find( array $rows, array $active, string $self, array $taken = [] ): array {
		$groups = [];
		foreach ( $rows as $row ) {
			$type  = (string) ( $row['request_type'] ?? '' );
			$route = (string) ( $row['route_key'] ?? '' );
			if ( ! in_array( $type, RulePolicy::TYPES, true ) ) {
				continue;
			}
			$key              = 'StaticMiss' === $type || '' === $route || '(raíz)' === $route ? $type : $type . ':' . $route;
			$groups[ $key ][] = $row;
		}
		$out = [];
		foreach ( $groups as $key => $list ) {
			if ( in_array( $key, $taken, true ) ) {
				continue;
			}
			$protected = RulePolicy::protectedPlugins( $key, $active, $self );
			$plugins   = [];
			$saving    = 0.0;
			foreach ( Report::plugins( $list ) as $cost ) {
				if ( ! str_starts_with( $cost['component'], 'plugin:' ) || $cost['total_ms'] < self::MIN_MS ) {
					continue;
				}
				$file = self::file( substr( $cost['component'], 7 ), $active );
				if ( null === $file || in_array( $file, $protected, true ) ) {
					continue;
				}
				$plugins[] = [
					'plugin'   => $file,
					'total_ms' => $cost['total_ms'],
					'kb'       => $cost['kb'],
				];
				$saving   += $cost['total_ms'];
			}
			$summary = Report::summary( $list );
			$out[]   = [
				'key'        => $key,
				'label'      => self::label( $key ),
				'samples'    => count( $list ),
				'ms_avg'     => (int) $summary['ms_avg'],
				'mem_avg_mb' => (float) $summary['mem_avg_mb'],
				'plugins'    => $plugins,
				'saving_ms'  => round( $saving, 1 ),
				'blocked'    => RulePolicy::blockedReason( $key ),
			];
		}
		$search = 'Rest:' . ImaginaSearch::REST_NAMESPACE;
		if ( null !== ImaginaSearch::plugin( $active ) && ! in_array( $search, $taken, true ) && ! isset( array_column( $out, null, 'key' )[ $search ] ) ) {
			$protected = RulePolicy::protectedPlugins( $search, $active, $self );
			$out[]     = [
				'key'        => $search,
				'label'      => self::label( $search ),
				'samples'    => 0,
				'ms_avg'     => 0,
				'mem_avg_mb' => 0.0,
				'plugins'    => array_map(
					static fn( string $p ): array => [
						'plugin'   => $p,
						'total_ms' => 0.0,
						'kb'       => 0.0,
					],
					array_values( array_diff( $active, $protected ) )
				),
				'saving_ms'  => 0.0,
				'blocked'    => '',
			];
		}
		usort( $out, static fn( array $a, array $b ): int => [ '' !== $a['blocked'], -$a['saving_ms'] ] <=> [ '' !== $b['blocked'], -$b['saving_ms'] ] );
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Archivo principal del plugin a partir del slug del perfilador (carpeta o archivo sin .php).
	 *
	 * @param list<string> $active Plugins activos.
	 */
	public static function file( string $slug, array $active ): ?string {
		foreach ( $active as $plugin ) {
			if ( str_starts_with( $plugin, $slug . '/' ) || $plugin === $slug . '.php' ) {
				return $plugin;
			}
		}
		return null;
	}

	public static function label( string $key ): string {
		if ( 'Rest:' . ImaginaSearch::REST_NAMESPACE === $key ) {
			return __( 'Imagina Search (búsquedas)', 'imagina-turbo' );
		}
		[ $type, $endpoint ] = array_pad( explode( ':', $key, 2 ), 2, '' );
		return match ( $type ) {
			'WcAjax'     => 'wc-ajax: ' . $endpoint,
			'AdminAjax'  => 'admin-ajax: ' . $endpoint,
			'Rest'       => 'REST: ' . $endpoint,
			'StaticMiss' => __( 'Assets inexistentes', 'imagina-turbo' ),
			default      => $key,
		};
	}
}
