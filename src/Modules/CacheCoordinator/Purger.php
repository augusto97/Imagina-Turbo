<?php
/**
 * Orquesta las purgas en el orden correcto: primero la caché de origen (en este mismo request, para
 * que LSCache envíe su cabecera de purga), después el edge y el recalentado (en cola, con una ventana
 * de agrupación de 30 s). Al revés, el edge volvería a llenarse con la copia vieja del origen.
 *
 * Los posts se vuelven a resolver al final del request: en el editor de bloques los términos se
 * guardan después de save_post, y una purga resuelta en ese momento no vería los nuevos.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

use ImaginaTurbo\Modules\CacheCoordinator\Adapters\CacheAdapter;
use ImaginaTurbo\Repository\PurgeQueueRepository;
use ImaginaTurbo\Support\Jobs;

/**
 * Purgas del request actual.
 */
final class Purger {

	/** Ventana de agrupación antes de purgar el edge. */
	public const WINDOW = 30;

	/** Mientras purgamos el plugin de caché, sus avisos de purga no se vuelven a encolar (sin bucles). */
	private static bool $selfPurging = false;

	private PurgeRequest $pending;

	/** @var array<int, true> Posts a resolver al final del request. */
	private array $posts = [];

	/** @var array<string, true> Tags ya purgados en el origen en este request. */
	private array $originDone = [];

	/** @var array<string, true> URLs ya purgadas en el origen en este request. */
	private array $originUrls = [];

	/** Este request ya purgó todo el origen. */
	private bool $originAll = false;

	private bool $shutdownRegistered = false;

	/** La cola ya se escribió en shutdown: lo que llegue después se escribe al momento. */
	private bool $queueWritten = false;

	public function __construct(
		private readonly CacheAdapter $adapter,
		private readonly TagBuilder $tags,
		private readonly PurgeQueueRepository $queue,
	) {
		$this->pending = new PurgeRequest();
	}

	/**
	 * Purga por un cambio del sitio: origen ahora, edge en cola.
	 */
	public function purge( PurgeRequest $request ): void {
		if ( $request->isEmpty() ) {
			return;
		}
		$this->purgeOrigin( $request );
		$this->defer( $request );
	}

	/**
	 * Purga por un cambio en un post (lo resuelve ahora para el origen y al final para el edge).
	 */
	public function purgePost( \WP_Post $post, string $reason ): void {
		$this->posts[ $post->ID ] = true;
		$this->purge( new PurgeRequest( TagBuilder::namesForPost( $post ), TagBuilder::urlsForPost( $post ), false, $reason ) );
	}

	/**
	 * Purga que hizo el plugin de caché por su cuenta: solo falta replicarla en el edge.
	 */
	public function mirror( PurgeRequest $request ): void {
		if ( self::$selfPurging || $request->isEmpty() ) {
			return;
		}
		// LSCache vuelve a construir su cabecera al final del request con todas las purgas acumuladas,
		// incluidas las nuestras: lo que ya purgamos no se repite.
		if ( $request->all && $this->originAll ) {
			return;
		}
		$tags    = array_values( array_filter( $request->tags, fn( string $name ): bool => ! isset( $this->originDone[ $name ] ) ) );
		$request = new PurgeRequest( $tags, $request->urls, $request->all, $request->reason );
		if ( ! $request->isEmpty() ) {
			$this->defer( $request );
		}
	}

	public static function selfPurging(): bool {
		return self::$selfPurging;
	}

	/**
	 * Escribe la cola al final del request (una vez) y programa el vaciado.
	 */
	public function flushToQueue(): void {
		foreach ( array_keys( $this->posts ) as $postId ) {
			$post = get_post( $postId );
			if ( $post instanceof \WP_Post ) {
				$this->pending = $this->pending->merge( new PurgeRequest( TagBuilder::namesForPost( $post ), TagBuilder::urlsForPost( $post ) ) );
			}
		}
		$this->posts        = [];
		$this->queueWritten = did_action( 'shutdown' ) > 0;
		$request            = $this->pending;
		if ( $request->isEmpty() ) {
			return;
		}
		$this->pending = new PurgeRequest();

		if ( $request->all ) {
			$this->queue->add( PurgeQueueRepository::KIND_ALL, [ 'all' ] );
		}
		$this->queue->add( PurgeQueueRepository::KIND_TAG, $request->tags );
		$this->queue->add( PurgeQueueRepository::KIND_URL, $request->urls );
		self::scheduleFlush();
	}

	/**
	 * Programa el vaciado de la cola si no hay uno pendiente.
	 */
	public static function scheduleFlush(): void {
		if ( ! Jobs::actionSchedulerReady() ) {
			if ( false === wp_next_scheduled( Jobs::PURGE_FLUSH ) ) {
				wp_schedule_single_event( time() + self::WINDOW, Jobs::PURGE_FLUSH );
			}
			return;
		}
		if ( false === as_has_scheduled_action( Jobs::PURGE_FLUSH, [], Jobs::GROUP ) ) {
			as_schedule_single_action( time() + self::WINDOW, Jobs::PURGE_FLUSH, [], Jobs::GROUP );
		}
	}

	private function purgeOrigin( PurgeRequest $request ): void {
		if ( $this->originAll ) {
			return;
		}
		$this->originAll = $request->all;
		$names           = [];
		foreach ( $request->tags as $name ) {
			if ( ! isset( $this->originDone[ $name ] ) ) {
				$this->originDone[ $name ] = true;
				$names[]                   = $name;
			}
		}
		$urls = [];
		foreach ( $request->urls as $url ) {
			if ( ! isset( $this->originUrls[ $url ] ) ) {
				$this->originUrls[ $url ] = true;
				$urls[]                   = $url;
			}
		}
		$todo = new PurgeRequest( $names, $urls, $request->all, $request->reason );
		if ( $todo->isEmpty() ) {
			return;
		}
		self::$selfPurging = true;
		try {
			$this->adapter->purge( $todo, $this->tags->tags( $todo->tags ) );
		} finally {
			self::$selfPurging = false;
		}
	}

	private function defer( PurgeRequest $request ): void {
		$this->pending = $this->pending->merge( $request );
		if ( $this->queueWritten ) {
			$this->flushToQueue();
			return;
		}
		if ( ! $this->shutdownRegistered ) {
			$this->shutdownRegistered = true;
			add_action( 'shutdown', [ $this, 'flushToQueue' ], 0 );
		}
	}
}
