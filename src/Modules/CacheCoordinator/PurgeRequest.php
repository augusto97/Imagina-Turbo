<?php
/**
 * Qué purgar: tags propios (sin prefijo), URLs o todo.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

/**
 * Petición de purga inmutable.
 */
final class PurgeRequest {

	/**
	 * @param list<string> $tags   Nombres de tags sin prefijo (p-12, t-5, home…).
	 * @param list<string> $urls   URLs absolutas.
	 * @param bool         $all    Purgar todo.
	 * @param string       $reason Motivo (para el registro).
	 */
	public function __construct(
		public readonly array $tags = [],
		public readonly array $urls = [],
		public readonly bool $all = false,
		public readonly string $reason = '',
	) {
	}

	public static function all( string $reason ): self {
		return new self( [], [], true, $reason );
	}

	public function isEmpty(): bool {
		return ! $this->all && [] === $this->tags && [] === $this->urls;
	}

	public function merge( self $other ): self {
		return new self(
			array_values( array_unique( array_merge( $this->tags, $other->tags ) ) ),
			array_values( array_unique( array_merge( $this->urls, $other->urls ) ) ),
			$this->all || $other->all,
			'' !== $this->reason ? $this->reason : $other->reason
		);
	}
}
