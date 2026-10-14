<?php
/**
 * Reglas del gestor de assets (opción itb_asset_rules, autoload = no; el frontend las lee compiladas).
 *
 * Una regla quita un handle (script o estilo) "nunca aquí" (never: se quita donde coincide) o "solo
 * aquí" (only: se quita donde NO coincide). Coincide por plantilla (RouteKey::template()), por id de
 * la página o por tipo de contenido. Estados: testing (solo con la cookie de previsualización del
 * admin), active, disabled.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\AssetManager;

/**
 * Lectura, validación y compilación.
 *
 * @phpstan-type AssetRule array{id: string, handle: string, type: string, mode: string, templates: list<string>, ids: list<int>, post_types: list<string>, status: string, note: string, verified_by: int, verified_at: int, created_at: int}
 * @phpstan-type Context array{template: string, id: int, post_type: string}
 */
final class AssetRules {

	public const OPTION = 'itb_asset_rules';

	/**
	 * @return array<string, AssetRule>
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, [] );
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $rule ) {
			$rule = is_array( $rule ) ? self::normalize( $rule ) : null;
			if ( null !== $rule ) {
				$out[ $rule['id'] ] = $rule;
			}
		}
		return $out;
	}

	/**
	 * @param AssetRule $rule Regla.
	 */
	public function save( array $rule ): void {
		$all                = $this->all();
		$all[ $rule['id'] ] = $rule;
		update_option( self::OPTION, $all, false );
	}

	public function delete( string $id ): void {
		$all = $this->all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * @param array<mixed> $rule Datos crudos.
	 * @return AssetRule|null
	 */
	public static function normalize( array $rule ): ?array {
		$handle = is_string( $rule['handle'] ?? null ) ? $rule['handle'] : '';
		$type   = in_array( $rule['type'] ?? '', [ 'js', 'css' ], true ) ? (string) $rule['type'] : '';
		$mode   = in_array( $rule['mode'] ?? '', [ 'never', 'only' ], true ) ? (string) $rule['mode'] : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-.\/]{1,100}$/', $handle ) || '' === $type || '' === $mode ) {
			return null;
		}
		$templates = array_values( array_unique( array_filter( is_array( $rule['templates'] ?? null ) ? $rule['templates'] : [], static fn( $t ): bool => is_string( $t ) && 1 === preg_match( '/^[a-z0-9:_\-]{1,40}$/', $t ) ) ) );
		$ids       = array_values( array_unique( array_filter( array_map( 'intval', is_array( $rule['ids'] ?? null ) ? $rule['ids'] : [] ), static fn( int $i ): bool => $i > 0 ) ) );
		$types     = array_values( array_unique( array_filter( is_array( $rule['post_types'] ?? null ) ? $rule['post_types'] : [], static fn( $t ): bool => is_string( $t ) && 1 === preg_match( '/^[a-z0-9_\-]{1,20}$/', $t ) ) ) );
		// Sin ningún lugar, "solo aquí" quitaría el asset en todo el sitio y "nunca aquí" no haría nada:
		// ninguna de las dos es válida.
		if ( [] === $templates && [] === $ids && [] === $types ) {
			return null;
		}
		$id = substr( hash( 'sha256', $type . '|' . $handle . '|' . $mode . '|' . implode( ',', $templates ) . '|' . implode( ',', $ids ) . '|' . implode( ',', $types ) ), 0, 12 );
		return [
			'id'          => $id,
			'handle'      => $handle,
			'type'        => $type,
			'mode'        => $mode,
			'templates'   => $templates,
			'ids'         => array_slice( $ids, 0, 200 ),
			'post_types'  => $types,
			'status'      => in_array( $rule['status'] ?? '', [ 'testing', 'active', 'disabled' ], true ) ? (string) $rule['status'] : 'testing',
			'note'        => is_string( $rule['note'] ?? null ) ? substr( $rule['note'], 0, 200 ) : '',
			'verified_by' => (int) ( $rule['verified_by'] ?? 0 ),
			'verified_at' => (int) ( $rule['verified_at'] ?? 0 ),
			'created_at'  => is_int( $rule['created_at'] ?? null ) ? $rule['created_at'] : time(),
		];
	}

	/**
	 * Para la config compilada (forma corta).
	 *
	 * @return list<array{h: string, t: string, m: string, tpl: list<string>, ids: list<int>, pt: list<string>, s: string}>
	 */
	public function compile(): array {
		$out = [];
		foreach ( $this->all() as $rule ) {
			if ( 'disabled' === $rule['status'] ) {
				continue;
			}
			$out[] = [
				'h'   => $rule['handle'],
				't'   => $rule['type'],
				'm'   => $rule['mode'],
				'tpl' => $rule['templates'],
				'ids' => $rule['ids'],
				'pt'  => $rule['post_types'],
				's'   => $rule['status'],
			];
		}
		return $out;
	}

	/**
	 * ¿Se quita el asset en este contexto?
	 *
	 * @param array{m: string, tpl: list<string>, ids: list<int>, pt: list<string>} $rule    Regla compilada.
	 * @param Context                                                               $context Página actual.
	 */
	public static function removes( array $rule, array $context ): bool {
		$match = in_array( $context['template'], $rule['tpl'], true )
			|| ( $context['id'] > 0 && in_array( $context['id'], $rule['ids'], true ) )
			|| ( '' !== $context['post_type'] && in_array( $context['post_type'], $rule['pt'], true ) );
		return 'never' === $rule['m'] ? $match : ! $match;
	}
}
