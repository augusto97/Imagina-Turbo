<?php
/**
 * Comparación de dos respuestas del mismo endpoint (sin filtrar y filtrada).
 *
 * Dos modos por petición de prueba, que se eligen calibrando con dos llamadas sin filtrar:
 * - exact: el cuerpo normalizado tiene que ser igual. Se neutralizan valores que cambian entre
 *   llamadas aunque nada cambie (nonces, hashes, marcas de tiempo, fechas).
 * - structural: si dos llamadas sin filtrar ya difieren, se compara la forma (claves y tipos del
 *   JSON, secuencia de etiquetas del HTML).
 *
 * Además: código de estado, tipo de contenido, redirección y nombres de cookies.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

/**
 * Normalización y diferencias.
 *
 * @phpstan-type Reply array{status: int, headers: array<string, string>, body: string}
 */
final class ResponseComparer {

	public const EXACT      = 'exact';
	public const STRUCTURAL = 'structural';

	private const VOLATILE = [
		'/\b[0-9a-f]{32,64}\b/i'    => '#hash',
		'/\b[0-9a-f]{10}\b/i'       => '#nonce',
		'/\b1[5-9]\d{8}(\d{3})?\b/' => '#ts',
		'/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?/' => '#date',
		'/itb_nc=[a-z0-9]+/i'       => 'itb_nc=#',
	];

	/**
	 * Diferencia entre dos respuestas, o null si son equivalentes.
	 *
	 * @param Reply $a Sin filtrar.
	 * @param Reply $b Filtrada.
	 * @return array{field: string, baseline: string, filtered: string}|null
	 */
	public static function diff( array $a, array $b, string $mode ): ?array {
		if ( $a['status'] !== $b['status'] ) {
			return self::d( 'status', (string) $a['status'], (string) $b['status'] );
		}
		foreach ( [ 'content-type', 'location' ] as $header ) {
			$x = self::headerValue( $a, $header );
			$y = self::headerValue( $b, $header );
			if ( 'content-type' === $header ) {
				$x = strtolower( trim( explode( ';', $x )[0] ) );
				$y = strtolower( trim( explode( ';', $y )[0] ) );
			}
			if ( $x !== $y ) {
				return self::d( $header, $x, $y );
			}
		}
		$cookiesA = self::cookieNames( $a );
		$cookiesB = self::cookieNames( $b );
		if ( $cookiesA !== $cookiesB ) {
			return self::d( 'set-cookie', implode( ', ', $cookiesA ), implode( ', ', $cookiesB ) );
		}
		$type = self::headerValue( $a, 'content-type' );
		$x    = self::fingerprint( $a['body'], $type, $mode );
		$y    = self::fingerprint( $b['body'], $type, $mode );
		if ( $x !== $y ) {
			[ $from, $to ] = self::firstDifference( $x, $y );
			return self::d( 'body', $from, $to );
		}
		return null;
	}

	/**
	 * Modo para una petición de prueba: exacto si dos llamadas sin filtrar coinciden.
	 *
	 * @param Reply $first  Primera.
	 * @param Reply $second Segunda.
	 */
	public static function calibrate( array $first, array $second ): string {
		return null === self::diff( $first, $second, self::EXACT ) ? self::EXACT : self::STRUCTURAL;
	}

	/**
	 * Huella comparable del cuerpo.
	 */
	public static function fingerprint( string $body, string $contentType, string $mode ): string {
		$json = str_contains( strtolower( $contentType ), 'json' ) ? json_decode( $body, true ) : null;
		if ( null !== $json || 'null' === trim( $body ) ) {
			return self::STRUCTURAL === $mode ? (string) wp_json_encode( self::shape( $json ) ) : self::volatile( (string) wp_json_encode( $json ) );
		}
		if ( self::STRUCTURAL === $mode ) {
			preg_match_all( '#<\s*([a-z][a-z0-9-]*)#i', $body, $tags );
			return strtolower( implode( ' ', $tags[1] ) );
		}
		return self::volatile( preg_replace( '/\s+/', ' ', $body ) ?? $body );
	}

	/**
	 * Forma de un valor JSON: claves y tipos; las listas, por longitud y la forma de sus elementos.
	 */
	public static function shape( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				$shapes = array_values( array_unique( array_map( static fn( $v ): string => (string) wp_json_encode( self::shape( $v ) ), $value ) ) );
				sort( $shapes );
				return [
					'list' => count( $value ),
					'of'   => $shapes,
				];
			}
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ (string) $k ] = self::shape( $v );
			}
			ksort( $out );
			return $out;
		}
		return get_debug_type( $value );
	}

	public static function volatile( string $text ): string {
		foreach ( self::VOLATILE as $pattern => $replacement ) {
			$text = (string) preg_replace( $pattern, $replacement, $text );
		}
		return $text;
	}

	/**
	 * Fragmentos alrededor de la primera diferencia (para la evidencia).
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function firstDifference( string $a, string $b ): array {
		$max = min( strlen( $a ), strlen( $b ) );
		$i   = 0;
		while ( $i < $max && $a[ $i ] === $b[ $i ] ) {
			++$i;
		}
		$start = max( 0, $i - 40 );
		return [ substr( $a, $start, 120 ), substr( $b, $start, 120 ) ];
	}

	/**
	 * @param Reply $reply Respuesta.
	 */
	private static function headerValue( array $reply, string $name ): string {
		return $reply['headers'][ $name ] ?? '';
	}

	/**
	 * @param Reply $reply Respuesta.
	 * @return list<string>
	 */
	private static function cookieNames( array $reply ): array {
		$names = [];
		foreach ( explode( "\n", $reply['headers']['set-cookie'] ?? '' ) as $cookie ) {
			$name = trim( explode( '=', $cookie, 2 )[0] );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		$names = array_values( array_unique( $names ) );
		sort( $names );
		return $names;
	}

	/**
	 * @return array{field: string, baseline: string, filtered: string}
	 */
	private static function d( string $field, string $baseline, string $filtered ): array {
		return [
			'field'    => $field,
			'baseline' => substr( $baseline, 0, 160 ),
			'filtered' => substr( $filtered, 0, 160 ),
		];
	}
}
