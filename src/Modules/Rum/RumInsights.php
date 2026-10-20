<?php
/**
 * Lo que el RUM decide sobre el sitio (tarea diaria): páginas siguientes para el prefetch predictivo
 * (docs/02 módulo 5), imagen LCP por plantilla (módulo 9) y fuentes del primer render (0.15.0).
 *
 * Los beacons no se autentican. Por eso, para cambiar el sitio, un valor tiene que:
 * - repetirse muchas veces y venir de muchos visitantes distintos (mapa de bits de IP-hash);
 * - ser contenido publicado del propio sitio (una entrada, una página, un término, un adjunto de la
 *   biblioteca de medios), resuelto aquí, nunca en el frontend;
 * - pasar las exclusiones (carrito, checkout, cuenta, rutas sin caché).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Rum;

/**
 * Cálculo puro (la resolución contra WordPress llega como closures).
 *
 * @phpstan-type Event array{template: string, device: string, value: string, n: int|string, bits: string}
 * @phpstan-type Lcp array{kind: string, id: int, src: string, n: int, share: float}
 */
final class RumInsights {

	public const OPTION = 'itb_rum_insights';

	public const NEXT_MIN      = 20;
	public const NEXT_DISTINCT = 10;
	public const NEXT_SHARE    = 0.10;
	public const NEXT_MAX      = 4;

	public const LCP_MIN      = 50;
	public const LCP_DISTINCT = 20;
	public const LCP_SHARE    = 0.6;

	public const FONT_MIN      = 30;
	public const FONT_DISTINCT = 15;
	public const FONT_MAX      = 2;

	/**
	 * Páginas siguientes más probables por plantilla de origen.
	 *
	 * @param list<Event>             $events   Eventos "next" agrupados (plantilla de origen, ruta destino).
	 * @param \Closure(string): bool  $isTarget La ruta es contenido publicado y no está excluida.
	 * @return array<string, list<string>>
	 */
	public static function predicted( array $events, \Closure $isTarget ): array {
		$totals = [];
		foreach ( $events as $e ) {
			$totals[ $e['template'] ] = ( $totals[ $e['template'] ] ?? 0 ) + (int) $e['n'];
		}
		$byTemplate = [];
		foreach ( $events as $e ) {
			$prev = $byTemplate[ $e['template'] ][ $e['value'] ] ?? null;
			// Si el mismo destino llega en varias filas, el mapa más poblado (lo exacto es BIT_OR en SQL).
			$bits                                        = null !== $prev && RumCollector::popcount( $prev['bits'] ) > RumCollector::popcount( $e['bits'] ) ? $prev['bits'] : $e['bits'];
			$byTemplate[ $e['template'] ][ $e['value'] ] = [
				'n'    => ( $prev['n'] ?? 0 ) + (int) $e['n'],
				'bits' => $bits,
			];
		}
		$out = [];
		foreach ( $byTemplate as $template => $paths ) {
			uasort( $paths, static fn( array $a, array $b ): int => $b['n'] <=> $a['n'] );
			foreach ( $paths as $path => $data ) {
				if ( count( $out[ $template ] ?? [] ) >= self::NEXT_MAX ) {
					break;
				}
				if ( $data['n'] < self::NEXT_MIN || RumCollector::distinct( $data['bits'] ) < self::NEXT_DISTINCT || $data['n'] < self::NEXT_SHARE * $totals[ $template ] ) {
					continue;
				}
				if ( $isTarget( (string) $path ) ) {
					$out[ $template ][] = (string) $path;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Imagen LCP por plantilla (móvil). "#featured" = la imagen destacada de cada página.
	 *
	 * @param list<Event>           $events     Eventos "lcp" agrupados.
	 * @param \Closure(string): int $attachment Id del adjunto para una ruta, o 0.
	 * @return array<string, Lcp>
	 */
	public static function lcp( array $events, \Closure $attachment ): array {
		$totals = [];
		$best   = [];
		foreach ( $events as $e ) {
			if ( 'm' !== $e['device'] ) {
				continue;
			}
			$totals[ $e['template'] ] = ( $totals[ $e['template'] ] ?? 0 ) + (int) $e['n'];
			if ( ! isset( $best[ $e['template'] ] ) || (int) $e['n'] > (int) $best[ $e['template'] ]['n'] ) {
				$best[ $e['template'] ] = $e;
			}
		}
		$out = [];
		foreach ( $best as $template => $e ) {
			$n     = (int) $e['n'];
			$share = $n / max( 1, $totals[ $template ] );
			if ( $n < self::LCP_MIN || RumCollector::distinct( $e['bits'] ) < self::LCP_DISTINCT || $share < self::LCP_SHARE ) {
				continue;
			}
			if ( '#featured' === $e['value'] ) {
				$out[ $template ] = [
					'kind'  => 'featured',
					'id'    => 0,
					'src'   => '',
					'n'     => $n,
					'share' => round( $share, 2 ),
				];
				continue;
			}
			$id = $attachment( $e['value'] );
			if ( $id > 0 ) {
				$out[ $template ] = [
					'kind'  => 'image',
					'id'    => $id,
					'src'   => $e['value'],
					'n'     => $n,
					'share' => round( $share, 2 ),
				];
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Fuentes del propio sitio que el primer render pide en cada plantilla (ambos dispositivos), para
	 * precargarlas. Las que llegan tarde mueven el texto (CLS) y retrasan el primer pintado. Hasta 2 por
	 * plantilla, con al menos la mitad de muestras que la más pedida, y solo archivos de fuente que
	 * existen en el servidor (lo resuelve $isFont).
	 *
	 * @param list<Event>             $events Eventos "font" agrupados.
	 * @param \Closure(string): bool  $isFont La ruta es un archivo de fuente del sitio.
	 * @return array<string, list<string>>
	 */
	public static function fonts( array $events, \Closure $isFont ): array {
		$byTemplate = [];
		foreach ( $events as $e ) {
			$prev                                        = $byTemplate[ $e['template'] ][ $e['value'] ] ?? null;
			$bits                                        = null !== $prev && RumCollector::popcount( $prev['bits'] ) > RumCollector::popcount( $e['bits'] ) ? $prev['bits'] : $e['bits'];
			$byTemplate[ $e['template'] ][ $e['value'] ] = [
				'n'    => ( $prev['n'] ?? 0 ) + (int) $e['n'],
				'bits' => $bits,
			];
		}
		$out = [];
		foreach ( $byTemplate as $template => $paths ) {
			uasort( $paths, static fn( array $a, array $b ): int => $b['n'] <=> $a['n'] );
			$top  = (int) ( reset( $paths )['n'] ?? 0 );
			$keep = [];
			foreach ( $paths as $path => $row ) {
				if ( count( $keep ) >= self::FONT_MAX ) {
					break;
				}
				if ( $row['n'] < self::FONT_MIN || $row['n'] * 2 < $top || RumCollector::distinct( $row['bits'] ) < self::FONT_DISTINCT || ! $isFont( (string) $path ) ) {
					continue;
				}
				$keep[] = (string) $path;
			}
			if ( [] !== $keep ) {
				$out[ (string) $template ] = $keep;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Archivo de fuente del propio sitio: extensión de fuente, sin "..", y existe bajo la raíz de
	 * WordPress (la ruta viene de un beacon sin autenticar).
	 */
	public static function isFontFile( string $path, string $root, string $homePath = '' ): bool {
		if ( 1 !== preg_match( '/\.(woff2?|ttf|otf)$/i', $path ) || str_contains( $path, '..' ) ) {
			return false;
		}
		$relative = '' !== $homePath && str_starts_with( $path, $homePath . '/' ) ? substr( $path, strlen( $homePath ) ) : $path;
		return is_file( rtrim( $root, '/' ) . rawurldecode( $relative ) );
	}

	/**
	 * Quita el sufijo de tamaño de WordPress (foto-300x200.jpg → foto.jpg).
	 */
	public static function originalPath( string $path ): string {
		return (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]{2,5}$)/i', '', $path );
	}

	/**
	 * La ruta empieza por alguna exclusión (prefijos sin comodín final).
	 *
	 * @param list<string> $excluded Prefijos.
	 */
	public static function excluded( string $path, array $excluded ): bool {
		foreach ( $excluded as $prefix ) {
			$prefix = rtrim( (string) strtok( $prefix, '*' ), '/' );
			if ( '' !== $prefix && ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
