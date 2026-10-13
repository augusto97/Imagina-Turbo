<?php
/**
 * Experimentos de carga condicional (docs/02 módulo 4): el sitio se llama a sí mismo con y sin la regla
 * (cookie firmada) y compara.
 *
 * - Calibración: dos llamadas sin filtrar por petición de prueba eligen la comparación exacta o la
 *   estructural (ResponseComparer).
 * - Pares: sin filtrar y filtrada, alternando el orden. Cuenta como diferencia el estado, el tipo de
 *   contenido, la redirección, las cookies, el cuerpo, un fatal o más avisos de PHP que sin filtrar.
 * - 20 pares iguales seguidos promueven la regla; la primera diferencia la marca como fallida con su
 *   evidencia. Cada paso tiene un presupuesto de tiempo y la pantalla repite hasta terminar.
 * - Tiempo y memoria salen del registro que escribe cada request de experimento (ExperimentProbe).
 *
 * Lo que un experimento NO ve: efectos que no salen en la respuesta (analítica del servidor, sincronización,
 * correos). Por eso la REST solo se filtra en GET salvo que se marque lo contrario.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

use ImaginaTurbo\Core\Events\EventLog;

/**
 * Ejecución por pasos.
 *
 * @phpstan-import-type FilterRule from RuleStore
 * @phpstan-import-type Reply from ResponseComparer
 * @phpstan-type Measure array{ms: float, kb: float, e: int, em: list<string>, f: string, h: int}
 */
final class ExperimentRunner {

	/**
	 * @param \Closure(string, string, string, string): (Reply|string) $http      Pide método, ruta, cuerpo y cookie; respuesta o error.
	 * @param \Closure(): void                                         $recompile Recompila la config.
	 */
	public function __construct(
		private readonly RuleStore $store,
		private readonly \Closure $http,
		private readonly string $dataDir,
		private readonly EventLog $events,
		private readonly \Closure $recompile,
	) {
	}

	/**
	 * Avanza un experimento en curso hasta terminar o agotar el presupuesto.
	 *
	 * @return FilterRule|null
	 */
	public function step( string $id, float $budget = 12.0 ): ?array {
		$rule = $this->store->get( $id );
		if ( null === $rule || 'testing' !== $rule['status'] ) {
			return $rule;
		}
		$rule = $this->run( $rule, RulePolicy::REQUIRED_PAIRS, $budget );
		if ( 'testing' === $rule['status'] && $rule['evidence']['pairs'] >= RulePolicy::REQUIRED_PAIRS ) {
			$rule['status']     = 'active';
			$rule['checked_at'] = time();
			$rule['reason']     = '';
			$this->events->info( 'plugin_filter', 'promoted', __( 'Regla de carga condicional promovida: 20 pares iguales.', 'imagina-turbo' ), self::summary( $rule ) );
			$this->store->save( $rule );
			( $this->recompile )();
			$this->cleanup( $id );
			return $rule;
		}
		$this->store->save( $rule );
		if ( 'failed' === $rule['status'] ) {
			$this->events->warning( 'plugin_filter', 'experiment_failed', __( 'El experimento de carga condicional encontró una diferencia: la regla no se activa.', 'imagina-turbo' ), self::summary( $rule ) );
			( $this->recompile )();
			$this->cleanup( $id );
		}
		return $rule;
	}

	/**
	 * Revisión de una regla activa con pocos pares. Si aparece una diferencia, se desactiva.
	 *
	 * @return FilterRule|null
	 */
	public function recheck( string $id, int $pairs = 5, float $budget = 20.0 ): ?array {
		$rule = $this->store->get( $id );
		if ( null === $rule || 'active' !== $rule['status'] ) {
			return $rule;
		}
		$saved                        = $rule['evidence'];
		$rule['evidence']             = RuleStore::emptyEvidence();
		$rule['evidence']['required'] = $pairs;
		$rule['status']               = 'testing';
		$rule                         = $this->run( $rule, $pairs, $budget );
		$rule['checked_at']           = time();
		if ( 'failed' === $rule['status'] ) {
			$rule['status'] = 'disabled';
			$rule['reason'] = __( 'La revisión periódica encontró una diferencia: ', 'imagina-turbo' ) . $rule['reason'];
			$this->events->warning( 'plugin_filter', 'disabled', __( 'Regla de carga condicional desactivada por la revisión periódica.', 'imagina-turbo' ), self::summary( $rule ) );
			$this->store->save( $rule );
			( $this->recompile )();
		} else {
			$rule['status']   = 'active';
			$rule['evidence'] = $saved;
			$this->store->save( $rule );
		}
		$this->cleanup( $id );
		return $rule;
	}

	/**
	 * @param FilterRule $rule Regla.
	 * @return FilterRule
	 */
	private function run( array $rule, int $required, float $budget ): array {
		$key = ExperimentCookie::key();
		if ( null === $key ) {
			return self::fail( $rule, __( 'Faltan AUTH_KEY y AUTH_SALT válidas en wp-config.php: no se puede firmar la cookie del experimento.', 'imagina-turbo' ) );
		}
		if ( [] === $rule['probes'] ) {
			return self::fail( $rule, __( 'La regla no tiene peticiones de prueba.', 'imagina-turbo' ) );
		}
		$deadline = microtime( true ) + $budget;
		$ev       = &$rule['evidence'];
		foreach ( $rule['probes'] as $i => $probe ) {
			if ( isset( $ev['modes'][ $i ] ) ) {
				continue;
			}
			$first  = $this->call( $rule['id'], $probe, 0, $key );
			$second = $this->call( $rule['id'], $probe, 0, $key );
			if ( is_string( $first['reply'] ) || is_string( $second['reply'] ) ) {
				return self::fail( $rule, __( 'La petición de prueba falló: ', 'imagina-turbo' ) . ( is_string( $first['reply'] ) ? $first['reply'] : (string) $second['reply'] ) );
			}
			if ( null === $first['measure'] || null === $second['measure'] ) {
				return self::fail( $rule, __( 'El sitio no aplicó el experimento (¿la config no se recompiló o el módulo está apagado?).', 'imagina-turbo' ) );
			}
			$ev['modes'][ $i ] = ResponseComparer::calibrate( $first['reply'], $second['reply'] );
		}
		$count = count( $rule['probes'] );
		while ( $ev['pairs'] < $required && microtime( true ) < $deadline ) {
			$i     = $ev['pairs'] % $count;
			$probe = $rule['probes'][ $i ];
			// Orden alterno: ni el sin filtrar ni el filtrado se benefician siempre de la caché caliente.
			if ( 0 === $ev['pairs'] % 2 ) {
				$base = $this->call( $rule['id'], $probe, 0, $key );
				$filt = $this->call( $rule['id'], $probe, 1, $key );
			} else {
				$filt = $this->call( $rule['id'], $probe, 1, $key );
				$base = $this->call( $rule['id'], $probe, 0, $key );
			}
			if ( is_string( $base['reply'] ) || is_string( $filt['reply'] ) ) {
				return self::fail( $rule, __( 'La petición de prueba falló: ', 'imagina-turbo' ) . ( is_string( $base['reply'] ) ? $base['reply'] : (string) $filt['reply'] ), $i );
			}
			if ( null === $base['measure'] || null === $filt['measure'] ) {
				return self::fail( $rule, __( 'El sitio no aplicó el experimento (¿la config no se recompiló o el módulo está apagado?).', 'imagina-turbo' ), $i );
			}
			if ( 0 === $filt['measure']['h'] ) {
				return self::fail( $rule, __( 'La regla no quitó ningún plugin en el request filtrado (plugins inactivos o rewrite_rules vacía).', 'imagina-turbo' ), $i );
			}
			$diff = ResponseComparer::diff( $base['reply'], $filt['reply'], $ev['modes'][ $i ] ?? ResponseComparer::EXACT );
			if ( null === $diff && '' !== $filt['measure']['f'] ) {
				$diff = [
					'field'    => 'php_fatal',
					'baseline' => $base['measure']['f'],
					'filtered' => $filt['measure']['f'],
				];
			}
			if ( null === $diff && $filt['measure']['e'] > $base['measure']['e'] ) {
				$diff = [
					'field'    => 'php_warnings',
					'baseline' => (string) $base['measure']['e'],
					'filtered' => $filt['measure']['e'] . ': ' . implode( ' | ', $filt['measure']['em'] ),
				];
			}
			if ( null !== $diff ) {
				$ev['mismatch'] = [ 'probe' => $i ] + $diff;
				return self::fail( $rule, self::diffReason( $diff['field'] ), $i );
			}
			++$ev['pairs'];
			$ev['hidden'] = $filt['measure']['h'];
			self::accumulate( $ev['baseline'], $base['measure'] );
			self::accumulate( $ev['filtered'], $filt['measure'] );
		}
		unset( $ev );
		return $rule;
	}

	/**
	 * Una llamada con la cookie del experimento.
	 *
	 * @param array{method: string, path: string, body: string} $probe Petición.
	 * @return array{reply: Reply|string, measure: Measure|null}
	 */
	private function call( string $id, array $probe, int $mode, string $key ): array {
		$nonce  = strtolower( substr( bin2hex( random_bytes( 8 ) ), 0, 16 ) );
		$cookie = ExperimentCookie::create( $id, $mode, $nonce, time() + ExperimentCookie::MAX_TTL, $key );
		$path   = $probe['path'] . ( str_contains( $probe['path'], '?' ) ? '&' : '?' ) . 'itb_nc=' . $nonce;
		$reply  = ( $this->http )( $probe['method'], $path, $probe['body'], ExperimentCookie::NAME . '=' . rawurlencode( $cookie ) );
		return [
			'reply'   => $reply,
			'measure' => is_string( $reply ) ? null : $this->measure( $id, $nonce ),
		];
	}

	/**
	 * Línea del registro del experimento para una llamada. El shutdown del request puede terminar un
	 * instante después de que llegue la respuesta: se reintenta un momento.
	 *
	 * @return Measure|null
	 */
	private function measure( string $id, string $nonce ): ?array {
		$file = $this->dataDir . '/experiments/' . $id . '.log';
		for ( $try = 0; $try < 20; $try++ ) {
			if ( is_file( $file ) ) {
				clearstatcache( true, $file );
				$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
				foreach ( array_reverse( is_array( $lines ) ? $lines : [] ) as $line ) {
					$row = json_decode( $line, true );
					if ( is_array( $row ) && ( $row['n'] ?? '' ) === $nonce ) {
						return [
							'ms' => (float) ( $row['ms'] ?? 0 ),
							'kb' => (float) ( $row['kb'] ?? 0 ),
							'e'  => (int) ( $row['e'] ?? 0 ),
							'em' => array_values( array_filter( is_array( $row['em'] ?? null ) ? $row['em'] : [], 'is_string' ) ),
							'f'  => is_string( $row['f'] ?? null ) ? $row['f'] : '',
							'h'  => (int) ( $row['h'] ?? 0 ),
						];
					}
				}
			}
			usleep( 50000 );
		}
		return null;
	}

	/**
	 * @param array{n: int, ms: float, kb: float, errors: int} $side    Acumulado.
	 * @param Measure                                          $measure Medición.
	 */
	private static function accumulate( array &$side, array $measure ): void {
		$n               = $side['n'] + 1;
		$side['ms']      = round( ( $side['ms'] * $side['n'] + $measure['ms'] ) / $n, 1 );
		$side['kb']      = round( ( $side['kb'] * $side['n'] + $measure['kb'] ) / $n, 1 );
		$side['errors'] += $measure['e'];
		$side['n']       = $n;
	}

	/**
	 * @param FilterRule $rule Regla.
	 * @return FilterRule
	 */
	private static function fail( array $rule, string $reason, ?int $probe = null ): array {
		$rule['status'] = 'failed';
		$rule['reason'] = $reason;
		if ( null === $rule['evidence']['mismatch'] && null !== $probe ) {
			$rule['evidence']['mismatch'] = [
				'probe'    => $probe,
				'field'    => 'request',
				'baseline' => '',
				'filtered' => '',
			];
		}
		return $rule;
	}

	private static function diffReason( string $field ): string {
		return match ( $field ) {
			'status'       => __( 'El código de estado cambia sin esos plugins.', 'imagina-turbo' ),
			'body'         => __( 'La respuesta cambia sin esos plugins.', 'imagina-turbo' ),
			'set-cookie'   => __( 'Las cookies de la respuesta cambian sin esos plugins.', 'imagina-turbo' ),
			'php_fatal'    => __( 'Sin esos plugins hay un error fatal de PHP.', 'imagina-turbo' ),
			'php_warnings' => __( 'Sin esos plugins aparecen avisos de PHP.', 'imagina-turbo' ),
			default        => __( 'Las cabeceras de la respuesta cambian sin esos plugins.', 'imagina-turbo' ),
		};
	}

	/**
	 * @param FilterRule $rule Regla.
	 * @return array<string, mixed>
	 */
	public static function summary( array $rule ): array {
		return [
			'endpoint' => $rule['key'],
			'plugins'  => $rule['plugins'],
			'pairs'    => $rule['evidence']['pairs'],
			'baseline' => $rule['evidence']['baseline'],
			'filtered' => $rule['evidence']['filtered'],
			'mismatch' => $rule['evidence']['mismatch'],
			'reason'   => $rule['reason'],
		];
	}

	private function cleanup( string $id ): void {
		$file = $this->dataDir . '/experiments/' . $id . '.log';
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
}
