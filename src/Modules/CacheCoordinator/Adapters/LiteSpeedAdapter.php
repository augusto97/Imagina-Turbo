<?php
/**
 * LiteSpeed Cache (LSCWP 7.x). Hooks verificados en su código (src/api.cls.php, src/purge.cls.php).
 *
 * - Tags: `litespeed_tag_add` agrega los nuestros a X-LiteSpeed-Tag; así purgar por nuestros tags
 *   purga también la caché del servidor.
 * - Purga: `litespeed_purge` (tags), `litespeed_purge_url`, `litespeed_purge_all`. LSCWP la envía en
 *   la cabecera X-LiteSpeed-Purge de la respuesta; en cron la guarda y la envía en el siguiente
 *   request PHP (opción litespeed.purge.queue).
 * - Escucha: el filtro `litespeed_purge_tags` recibe todas las purgas públicas (con el id de blog
 *   delante: "_Po.12"; "*" es purgar todo).
 * - OpenLiteSpeed guarda y repite nuestras cabeceras en cada acierto (código de su módulo de caché).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator\Adapters;

use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;
use ImaginaTurbo\Modules\CacheCoordinator\TagBuilder;

/**
 * Adaptador de LSCache.
 */
final class LiteSpeedAdapter implements CacheAdapter {

	/** Tags de LSCache → nombres propios. Los de URL son un hash irreversible: se ignoran. */
	private const MAP = [
		'Po.' => 'p-',
		'T.'  => 't-',
		'A.'  => 'a-',
		'PT.' => 'pt-',
	];

	private bool $listening = false;

	public function __construct( private readonly TagBuilder $tags ) {
	}

	public function id(): string {
		return 'litespeed';
	}

	public function addResponseTags( array $tags ): void {
		if ( [] !== $tags ) {
			do_action( 'litespeed_tag_add', $tags ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LSCache.
		}
	}

	public function noCache( string $reason ): void {
		// LSCache cachea la API REST por defecto: sin esto, el refresco de nonces devolvería valores viejos.
		do_action( 'litespeed_control_set_nocache', 'imagina-turbo: ' . $reason ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LSCache.
	}

	public function purge( PurgeRequest $request, array $tags ): void {
		if ( $request->all ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LSCache.
			return;
		}
		if ( [] !== $tags ) {
			do_action( 'litespeed_purge', $tags ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LSCache.
		}
		foreach ( $request->urls as $url ) {
			do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LSCache.
		}
	}

	public function listen( callable $onPurge ): void {
		if ( $this->listening ) {
			return;
		}
		$this->listening = true;
		add_filter(
			'litespeed_purge_tags',
			function ( $purgeTags, $isPrivate = false ) use ( $onPurge ) {
				if ( ! $isPrivate && is_array( $purgeTags ) ) {
					$request = self::translate( $purgeTags, $this->tags );
					if ( ! $request->isEmpty() ) {
						$onPurge( $request );
					}
				}
				return $purgeTags;
			},
			10,
			2
		);
	}

	public function hasDeferredPurge(): bool {
		foreach ( [ 'litespeed.purge.queue', 'litespeed.purge.queue2' ] as $option ) {
			$value = get_option( $option, '' );
			if ( is_string( $value ) && '' !== $value && '-1' !== $value ) {
				return true;
			}
		}
		return false;
	}

	public function headersOnHit(): bool {
		return true;
	}

	/**
	 * Traduce una purga de LSCache a tags propios.
	 *
	 * @param array<mixed> $purgeTags Tags de LSCache (con el id de blog delante).
	 */
	public static function translate( array $purgeTags, TagBuilder $builder ): PurgeRequest {
		$names = [];
		foreach ( $purgeTags as $raw ) {
			if ( ! is_string( $raw ) ) {
				continue;
			}
			if ( '*' === $raw ) {
				return PurgeRequest::all( 'litespeed' );
			}
			$tag = (string) preg_replace( '/^\d*_/', '', $raw );
			$own = $builder->strip( $tag );
			if ( null !== $own ) {
				$names[] = $own;
				continue;
			}
			if ( 'H' === $tag || 'F' === $tag ) {
				$names[] = 'home';
			} elseif ( 'FD' === $tag ) {
				$names[] = 'feed';
			} else {
				foreach ( self::MAP as $from => $to ) {
					if ( str_starts_with( $tag, $from ) ) {
						$name = $to . substr( $tag, strlen( $from ) );
						if ( '' !== $builder->tag( $name ) ) {
							$names[] = $name;
						}
						break;
					}
				}
			}
		}
		return new PurgeRequest( array_values( array_unique( $names ) ), [], false, 'litespeed' );
	}
}
