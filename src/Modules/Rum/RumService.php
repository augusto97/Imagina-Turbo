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
	 * @return array{predicted: array<string, list<string>>, lcp: array<string, Lcp>, fonts: array<string, list<string>>, generated: int}
	 */
	public static function stored(): array {
		$data = get_option( RumInsights::OPTION, [] );
		$data = is_array( $data ) ? $data : [];
		/** @var array{predicted: array<string, list<string>>, lcp: array<string, Lcp>, fonts: array<string, list<string>>, generated: int} */
		return [
			'predicted' => is_array( $data['predicted'] ?? null ) ? $data['predicted'] : [],
			'lcp'       => is_array( $data['lcp'] ?? null ) ? $data['lcp'] : [],
			'fonts'     => is_array( $data['fonts'] ?? null ) ? $data['fonts'] : [],
			'generated' => is_int( $data['generated'] ?? null ) ? $data['generated'] : 0,
		];
	}

	/**
	 * Recalcula y, si cambió algo, recompila.
	 *
	 * @return array{predicted: array<string, list<string>>, lcp: array<string, Lcp>, fonts: array<string, list<string>>, generated: int}
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
		$homePath  = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$fonts     = RumInsights::fonts(
			$this->repository->events( 'font', self::WINDOW_DAYS, 500, false ),
			static fn( string $path ): bool => RumInsights::isFontFile( $path, ABSPATH, $homePath )
		);
		$previous  = self::stored();
		$data      = [
			'predicted' => $predicted,
			'lcp'       => $lcp,
			'fonts'     => $fonts,
			'generated' => time(),
		];
		update_option( RumInsights::OPTION, $data, false );
		if ( $previous['predicted'] !== $predicted || $previous['lcp'] !== $lcp || $previous['fonts'] !== $fonts ) {
			( $this->recompile )();
			$this->events->info(
				'rum',
				'insights',
				__( 'El RUM actualizó las páginas que se precargan, las imágenes LCP y las fuentes.', 'imagina-turbo' ),
				[
					'predicted' => array_map( 'count', $predicted ),
					'lcp'       => array_keys( $lcp ),
					'fonts'     => $fonts,
				]
			);
		}
		return $data;
	}

	/**
	 * Presupuestos (0.19.0): compara el p75 de 7 días, del sitio y de cada plantilla, y avisa en el log
	 * solo de lo que cambia (lo que pasa a estar por encima y lo que vuelve a estar dentro).
	 *
	 * @param array<string, float|int> $budgets Presupuestos normalizados (Budgets::normalize).
	 * @return array{checked_at: int, budgets: array<string, float|int>, site: list<array<string, mixed>>, breaches: list<array{template: string, device: string, metric: string, value: float|int, budget: float|int}>}
	 */
	public function budgets( array $budgets ): array {
		$hist     = $this->repository->histogram( 7 );
		$site     = RumReport::vitals( $hist, true );
		$breaches = Budgets::evaluate( array_merge( $site, RumReport::vitals( $hist ) ), $budgets );
		$previous = Budgets::stored();
		$state    = [
			'checked_at' => time(),
			'budgets'    => $budgets,
			'site'       => $site,
			'breaches'   => $breaches,
		];
		update_option( Budgets::OPTION, $state, false );
		// Con presupuestos cambiados, lo de antes no se compara (avisaría de todo de nuevo o de nada).
		$diff = $previous['budgets'] === $budgets ? Budgets::diff( $previous['breaches'], $breaches ) : [
			'new'      => $breaches,
			'resolved' => [],
		];
		if ( [] !== $diff['new'] ) {
			$this->events->warning(
				'rum',
				'budget_over',
				/* translators: %s: lista de métricas, plantillas y valores. */
				sprintf( __( 'Presupuesto de rendimiento superado (p75 de 7 días): %s.', 'imagina-turbo' ), Budgets::describe( $diff['new'] ) ),
				[ 'breaches' => array_slice( $diff['new'], 0, 30 ) ]
			);
		}
		if ( [] !== $diff['resolved'] ) {
			$this->events->info(
				'rum',
				'budget_ok',
				/* translators: %s: lista de métricas, plantillas y valores. */
				sprintf( __( 'Vuelven a estar dentro del presupuesto: %s.', 'imagina-turbo' ), Budgets::describe( $diff['resolved'] ) ),
				[ 'resolved' => array_slice( $diff['resolved'], 0, 30 ) ]
			);
		}
		return $state;
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
			'site'       => RumReport::vitals( $hist, true ),
			'ttfb'       => RumReport::ttfbBreakdown( $hist ),
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
