<?php
/**
 * Tarea diaria del RUM e informe para el admin.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Rum;

use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Repository\RumRepository;

/**
 * Insights (prefetch predictivo y LCP) y limpieza.
 *
 * @phpstan-import-type Lcp from RumInsights
 */
final class RumService {

	/** Ventana para decidir: 14 días. */
	public const WINDOW_DAYS = 14;

	/**
	 * @param list<string>     $excluded  Prefijos de rutas excluidas (carrito, checkout, cuenta, sin caché).
	 * @param \Closure(): void $recompile Recompila la config.
	 */
	public function __construct(
		private readonly RumRepository $repository,
		private readonly EventLog $events,
		private readonly array $excluded,
		private readonly \Closure $recompile,
	) {
	}

	/**
	 * @return array{predicted: array<string, list<string>>, lcp: array<string, Lcp>, generated: int}
	 */
	public static function stored(): array {
		$data = get_option( RumInsights::OPTION, [] );
		$data = is_array( $data ) ? $data : [];
		/** @var array{predicted: array<string, list<string>>, lcp: array<string, Lcp>, generated: int} */
		return [
			'predicted' => is_array( $data['predicted'] ?? null ) ? $data['predicted'] : [],
			'lcp'       => is_array( $data['lcp'] ?? null ) ? $data['lcp'] : [],
			'generated' => is_int( $data['generated'] ?? null ) ? $data['generated'] : 0,
		];
	}

	/**
	 * Recalcula y, si cambió algo, recompila.
	 *
	 * @return array{predicted: array<string, list<string>>, lcp: array<string, Lcp>, generated: int}
	 */
	public function daily(): array {
		$this->repository->deleteOlderThan( RumRepository::RETENTION_DAYS );
		$excluded  = $this->excluded;
		$predicted = RumInsights::predicted(
			$this->repository->events( 'next', self::WINDOW_DAYS, 500, false ),
			static fn( string $path ): bool => ! RumInsights::excluded( $path, $excluded ) && self::isContent( $path )
		);
		$lcp       = RumInsights::lcp(
			$this->repository->events( 'lcp', self::WINDOW_DAYS ),
			static fn( string $path ): int => attachment_url_to_postid( home_url( RumInsights::originalPath( $path ) ) )
		);
		$previous  = self::stored();
		$data      = [
			'predicted' => $predicted,
			'lcp'       => $lcp,
			'generated' => time(),
		];
		update_option( RumInsights::OPTION, $data, false );
		if ( $previous['predicted'] !== $predicted || $previous['lcp'] !== $lcp ) {
			( $this->recompile )();
			$this->events->info(
				'rum',
				'insights',
				__( 'El RUM actualizó las páginas que se precargan y las imágenes LCP.', 'imagina-turbo' ),
				[
					'predicted' => array_map( 'count', $predicted ),
					'lcp'       => array_keys( $lcp ),
				]
			);
		}
		return $data;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function report( int $days ): array {
		$hist = $this->repository->histogram( $days );
		return [
			'days'       => $days,
			'navigation' => RumReport::navigation( $hist ),
			'vitals'     => RumReport::vitals( $hist ),
			'bfcache'    => array_slice( self::merge( $this->repository->events( 'nrr', $days, 200 ) ), 0, 15 ),
			'next'       => array_slice( $this->repository->events( 'next', $days, 50 ), 0, 30 ),
			'lcp'        => array_slice( $this->repository->events( 'lcp', $days, 50 ), 0, 30 ),
		];
	}

	/**
	 * Causas de bfcache sumadas entre plantillas.
	 *
	 * @param list<array{template: string, device: string, value: string, n: int|string, bits: string}> $rows Filas.
	 * @return list<array{reason: string, n: int}>
	 */
	private static function merge( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$out[ $row['value'] ] = ( $out[ $row['value'] ] ?? 0 ) + (int) $row['n'];
		}
		arsort( $out );
		$list = [];
		foreach ( $out as $reason => $n ) {
			$list[] = [
				'reason' => (string) $reason,
				'n'      => $n,
			];
		}
		return $list;
	}

	/**
	 * La ruta es contenido publicado: la portada, una entrada o página, el archivo de un tipo de contenido
	 * o un término público.
	 */
	public static function isContent( string $path ): bool {
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( $path === $home ) {
			return true;
		}
		$url = home_url( $path );
		$id  = url_to_postid( $url );
		if ( $id > 0 ) {
			return 'publish' === get_post_status( $id ) && ! post_password_required( $id );
		}
		// Archivos de tipos de contenido (la tienda de WooCommerce es el archivo de "product").
		foreach ( get_post_types(
			[
				'public'      => true,
				'has_archive' => true,
			]
		) as $type ) {
			$link = get_post_type_archive_link( $type );
			if ( is_string( $link ) && untrailingslashit( (string) wp_parse_url( $link, PHP_URL_PATH ) ) === untrailingslashit( $path ) ) {
				return true;
			}
		}
		$slug = basename( untrailingslashit( $path ) );
		if ( '' === $slug ) {
			return false;
		}
		$terms = get_terms(
			[
				'taxonomy'   => get_taxonomies( [ 'public' => true ] ),
				'slug'       => $slug,
				'hide_empty' => false,
				'number'     => 5,
			]
		);
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$link = get_term_link( $term );
			if ( is_string( $link ) && untrailingslashit( (string) wp_parse_url( $link, PHP_URL_PATH ) ) === untrailingslashit( $path ) ) {
				return true;
			}
		}
		return false;
	}
}
