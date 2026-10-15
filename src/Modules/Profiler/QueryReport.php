<?php
/**
 * Consultas de la base de datos en un request de diagnóstico (cookie de diagnóstico de un administrador),
 * al estilo de Query Monitor pero sin cargar nada en las visitas: solo en ese request se activa
 * SAVEQUERIES (desde la etapa temprana) y se anota qué plugin lanzó cada consulta con el filtro del core
 * log_query_custom_data (WP 5.3+).
 *
 * Nunca se guardan datos: los valores literales (cadenas y números) se cambian por "?", que además agrupa
 * las consultas repetidas con valores distintos (el patrón N+1). El resultado vive en una opción sin
 * autoload y se reemplaza en el siguiente request de diagnóstico.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Profiler;

/**
 * Captura y resumen.
 */
final class QueryReport {

	public const OPTION = 'itb_diag_queries';

	/** Desde aquí una consulta se considera lenta. */
	public const SLOW_MS = 50.0;

	/**
	 * Anota el componente que lanzó la consulta (el primer archivo fuera del núcleo en la pila).
	 */
	public static function register( Collector $collector ): void {
		add_filter(
			'log_query_custom_data',
			static function ( mixed $data ) use ( $collector ): mixed {
				if ( ! is_array( $data ) ) {
					$data = [];
				}
				$data['itb_component'] = 'core';
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- solo en el request de diagnóstico de un administrador.
					$file = $frame['file'] ?? '';
					if ( '' === $file || str_contains( $file, '/imagina-turbo/' ) ) {
						continue;
					}
					$component = $collector->componentForFile( $file );
					if ( 'core' !== $component && 'other' !== $component ) {
						$data['itb_component'] = $component;
						break;
					}
				}
				return $data;
			}
		);
		add_action(
			'shutdown',
			static function (): void {
				$wpdb = $GLOBALS['wpdb'] ?? null;
				if ( $wpdb instanceof \wpdb && is_array( $wpdb->queries ) && [] !== $wpdb->queries ) {
					update_option( self::OPTION, self::build( $wpdb->queries ) + [ 'at' => time() ], false );
				}
			},
			PHP_INT_MAX - 1
		);
	}

	/**
	 * Resumen de $wpdb->queries: [sql, segundos, pila, inicio, datos].
	 *
	 * @param array<mixed> $queries Consultas.
	 * @return array{count: int, ms: float, slow: list<array{sql: string, ms: float, component: string}>, repeated: list<array{sql: string, count: int, ms: float, component: string}>, components: list<array{component: string, count: int, ms: float}>}
	 */
	public static function build( array $queries ): array {
		$slow       = [];
		$groups     = [];
		$components = [];
		$total      = 0.0;
		foreach ( $queries as $q ) {
			if ( ! is_array( $q ) || ! is_string( $q[0] ?? null ) ) {
				continue;
			}
			$ms        = round( 1000 * (float) ( $q[1] ?? 0 ), 2 );
			$component = is_array( $q[4] ?? null ) && is_string( $q[4]['itb_component'] ?? null ) ? $q[4]['itb_component'] : 'core';
			$sql       = self::normalize( $q[0] );
			$total    += $ms;
			if ( $ms >= self::SLOW_MS ) {
				$slow[] = [
					'sql'       => $sql,
					'ms'        => $ms,
					'component' => $component,
				];
			}
			$key = md5( $sql );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [
					'sql'       => $sql,
					'count'     => 0,
					'ms'        => 0.0,
					'component' => $component,
				];
			}
			++$groups[ $key ]['count'];
			$groups[ $key ]['ms']             += $ms;
			$components[ $component ]['count'] = ( $components[ $component ]['count'] ?? 0 ) + 1;
			$components[ $component ]['ms']    = ( $components[ $component ]['ms'] ?? 0.0 ) + $ms;
		}
		usort( $slow, static fn( array $a, array $b ): int => $b['ms'] <=> $a['ms'] );
		$repeated = array_values( array_filter( $groups, static fn( array $g ): bool => $g['count'] >= 3 ) );
		usort( $repeated, static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] );
		$byComponent = [];
		foreach ( $components as $name => $c ) {
			$byComponent[] = [
				'component' => (string) $name,
				'count'     => $c['count'],
				'ms'        => round( $c['ms'], 1 ),
			];
		}
		usort( $byComponent, static fn( array $a, array $b ): int => $b['ms'] <=> $a['ms'] );
		return [
			'count'      => array_sum( array_column( $byComponent, 'count' ) ),
			'ms'         => round( $total, 1 ),
			'slow'       => array_slice( $slow, 0, 15 ),
			'repeated'   => array_map( static fn( array $g ): array => [ 'ms' => round( $g['ms'], 1 ) ] + $g, array_slice( $repeated, 0, 10 ) ),
			'components' => array_slice( $byComponent, 0, 15 ),
		];
	}

	/**
	 * SQL sin valores: cadenas y números por "?", listas IN (?, ?, …) por IN (…), espacios compactados.
	 */
	public static function normalize( string $sql ): string {
		$sql = (string) preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql );
		$sql = (string) preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/s', '?', $sql );
		$sql = (string) preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $sql );
		$sql = (string) preg_replace( '/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (…)', $sql );
		$sql = trim( (string) preg_replace( '/\s+/', ' ', $sql ) );
		return mb_substr( $sql, 0, 400 );
	}
}
