<?php
/**
 * Sin plugin de caché de página: solo el edge.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator\Adapters;

use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;

/**
 * Adaptador vacío.
 */
final class NullAdapter implements CacheAdapter {

	public function id(): string {
		return 'none';
	}

	public function addResponseTags( array $tags ): void {
	}

	public function noCache( string $reason ): void {
	}

	public function purge( PurgeRequest $request, array $tags ): void {
	}

	public function listen( callable $onPurge ): void {
	}

	public function hasDeferredPurge(): bool {
		return false;
	}

	public function headersOnHit(): bool {
		return true;
	}
}
