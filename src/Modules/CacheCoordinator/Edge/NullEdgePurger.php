<?php
/**
 * Sin edge conectado.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator\Edge;

/**
 * No purga nada.
 */
final class NullEdgePurger implements EdgePurger {

	public function available(): bool {
		return false;
	}

	public function supportsTags(): bool {
		return false;
	}

	public function purge( array $tags, array $urls, bool $all ): array {
		return [
			'ok'    => true,
			'error' => '',
		];
	}
}
