<?php
/**
 * Vaciado de la cola (Action Scheduler, tras la ventana de agrupación):
 * 1. Si el plugin de caché dejó purgas pendientes (LSCache en cron), se provoca un request PHP para
 *    que las envíe: el origen siempre se purga antes que el edge.
 * 2. Edge: por tag si el edge los entiende y las páginas los llevan en cada acierto; si no, por URL.
 * 3. Recalentado de lo purgado (o del sitemap completo tras purgar todo).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\CacheCoordinator\Adapters\CacheAdapter;
use ImaginaTurbo\Modules\CacheCoordinator\Edge\EdgePurger;
use ImaginaTurbo\Repository\PurgeQueueRepository;
use ImaginaTurbo\Support\Jobs;

/**
 * Procesa la cola.
 */
final class PurgeWorker {

	public const BATCH = 2000;

	public const STATE_OPTION = 'itb_purge_state';

	public function __construct(
		private readonly PurgeQueueRepository $queue,
		private readonly CacheAdapter $adapter,
		private readonly EdgePurger $edge,
		private readonly TagBuilder $tags,
		private readonly Warmer $warmer,
		private readonly EventLog $events,
		private readonly bool $warm,
	) {
	}

	/**
	 * @return array{tags: int, urls: int, all: bool, edge: string, warm: int}
	 */
	public function run(): array {
		$rows = $this->queue->drain( [ PurgeQueueRepository::KIND_ALL, PurgeQueueRepository::KIND_TAG, PurgeQueueRepository::KIND_URL ], self::BATCH );
		$all  = false;
		$tags = [];
		$urls = [];
		foreach ( $rows as $row ) {
			if ( PurgeQueueRepository::KIND_ALL === $row['kind'] ) {
				$all = true;
			} elseif ( PurgeQueueRepository::KIND_TAG === $row['kind'] ) {
				$tags[] = $row['item'];
			} else {
				$urls[] = $row['item'];
			}
		}
		$summary = [
			'tags' => count( $tags ),
			'urls' => count( $urls ),
			'all'  => $all,
			'edge' => 'none',
			'warm' => 0,
		];
		if ( [] === $rows ) {
			return $summary;
		}

		if ( $this->adapter->hasDeferredPurge() ) {
			self::poke();
		}

		$byUrl = UrlResolver::forTags( $tags );
		if ( $this->edge->available() ) {
			$useTags         = $this->edge->supportsTags() && $this->adapter->headersOnHit();
			$result          = $all
				? $this->edge->purge( [], [], true )
				: $this->edge->purge( $useTags ? $this->tags->tags( $tags ) : [], $useTags ? $urls : array_values( array_unique( array_merge( $urls, $byUrl ) ) ), false );
			$summary['edge'] = $result['ok'] ? ( $all ? 'all' : ( $useTags ? 'tags' : 'urls' ) ) : 'error';
			if ( ! $result['ok'] ) {
				// Vuelve a la cola lo que falte (límites de la API, red caída…). Los tags que vuelven ya
				// llevan prefijo: se guardan sin él, como el resto de la cola.
				if ( $all ) {
					$this->queue->add( PurgeQueueRepository::KIND_ALL, [ 'all' ] );
				} else {
					$left = isset( $result['tags_left'] ) ? array_values( array_filter( array_map( [ $this->tags, 'strip' ], $result['tags_left'] ) ) ) : $tags;
					$this->queue->add( PurgeQueueRepository::KIND_TAG, $left );
					$this->queue->add( PurgeQueueRepository::KIND_URL, $result['urls_left'] ?? $urls );
				}
				$retry = max( 60, min( 3600, (int) ( $result['retry_after'] ?? 300 ) ) );
				Warmer::schedule( Jobs::PURGE_FLUSH, $retry );
				$this->events->warning(
					'cache',
					'edge_purge',
					/* translators: %d: segundos. */
					sprintf( __( 'La purga en el edge no se completó; lo pendiente se reintentará en %d s.', 'imagina-turbo' ), $retry ),
					[ 'error' => $result['error'] ]
				);
			}
		}

		if ( $this->warm ) {
			if ( $all ) {
				Warmer::schedule( Jobs::WARM_SEED, 15 );
			} else {
				$summary['warm'] = $this->warmer->enqueue( array_values( array_unique( array_merge( $urls, $byUrl ) ) ) );
			}
		}

		update_option(
			self::STATE_OPTION,
			array_merge( $summary, [ 'at' => time() ] ),
			false
		);
		$this->events->info(
			'cache',
			'purge',
			$all
				? __( 'Purga completa sincronizada.', 'imagina-turbo' )
				/* translators: 1: número de tags, 2: número de URLs */
				: sprintf( __( 'Purga sincronizada: %1$d tags y %2$d URLs.', 'imagina-turbo' ), count( $tags ), count( $urls ) ),
			$summary
		);

		if ( count( $rows ) >= self::BATCH ) {
			Warmer::schedule( Jobs::PURGE_FLUSH, 1 );
		}
		return $summary;
	}

	/**
	 * Último vaciado.
	 *
	 * @return array<string, mixed>
	 */
	public static function lastState(): array {
		$state = get_option( self::STATE_OPTION, [] );
		return is_array( $state ) ? $state : [];
	}

	/**
	 * Request PHP barato para que el plugin de caché envíe sus purgas pendientes (LSCache las manda
	 * en la cabecera de la siguiente respuesta que genera PHP; admin-ajax nunca sale de caché).
	 */
	public static function poke(): void {
		wp_remote_get(
			admin_url( 'admin-ajax.php' ),
			[
				'timeout'    => 5,
				'user-agent' => Warmer::USER_AGENT,
				'sslverify'  => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
			]
		);
	}
}
