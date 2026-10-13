<?php
/**
 * Purga en el edge (Cloudflare llega en la fase 2b; hasta entonces, NullEdgePurger).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator\Edge;

/**
 * Contrato de purga en el edge.
 */
interface EdgePurger {

	public function available(): bool;

	/** Purga por tag (si no, se resuelven URLs). */
	public function supportsTags(): bool;

	/**
	 * @param list<string> $tags Tags con prefijo.
	 * @param list<string> $urls URLs absolutas.
	 * @return array{ok: bool, error: string}
	 */
	public function purge( array $tags, array $urls, bool $all ): array;
}
