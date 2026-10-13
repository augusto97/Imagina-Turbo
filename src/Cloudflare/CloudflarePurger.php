<?php
/**
 * Purga en el edge de Cloudflare para la cola del coordinador de caché (docs/03, sección 5).
 *
 * POST /zones/{zone_id}/purge_cache con {tags}, {files} o {purge_everything}. Por tandas según el
 * límite de elementos por llamada y como máximo N llamadas por vuelta (el plan Free admite pocas
 * purgas por minuto): lo que no entra vuelve a la cola con la espera que pida la API.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

use ImaginaTurbo\Modules\CacheCoordinator\Edge\EdgePurger;

/**
 * Purgador de Cloudflare.
 */
final class CloudflarePurger implements EdgePurger {

	/**
	 * @param string $zoneId   Zona conectada ('' = no disponible).
	 * @param string $host     Host del sitio: solo se purgan URLs de este host.
	 * @param bool   $blocked  Dominio de staging o zona que no corresponde al sitio: nunca purgar.
	 * @param int    $perCall  Elementos por llamada.
	 * @param int    $maxCalls Llamadas por vuelta.
	 */
	public function __construct(
		private readonly Client $client,
		private readonly string $zoneId,
		private readonly string $host,
		private readonly bool $blocked,
		private readonly int $perCall,
		private readonly int $maxCalls,
	) {
	}

	public function available(): bool {
		return '' !== $this->zoneId && ! $this->blocked;
	}

	public function supportsTags(): bool {
		return true;
	}

	public function purge( array $tags, array $urls, bool $all ): array {
		if ( ! $this->available() ) {
			return [
				'ok'    => false,
				'error' => 'unavailable',
			];
		}
		$path = '/zones/' . rawurlencode( $this->zoneId ) . '/purge_cache';
		if ( $all ) {
			$response = $this->client->post( $path, [ 'purge_everything' => true ] );
			return $response->ok() ? [
				'ok'    => true,
				'error' => '',
			] : [
				'ok'          => false,
				'error'       => $response->message(),
				'retry_after' => $response->retryAfter,
			];
		}

		$urls  = array_values( array_filter( $urls, fn( string $url ): bool => $this->sameHost( $url ) ) );
		$tags  = array_values( array_unique( $tags ) );
		$calls = 0;
		$size  = max( 1, $this->perCall );
		foreach ( [ 'tags', 'files' ] as $key ) {
			$items = 'tags' === $key ? $tags : $urls;
			while ( [] !== $items ) {
				if ( $calls >= max( 1, $this->maxCalls ) ) {
					return $this->left( 'tags' === $key ? $items : [], 'tags' === $key ? $urls : $items, 'rate', 60 );
				}
				$chunk    = array_slice( $items, 0, $size );
				$response = $this->client->post( $path, [ $key => $chunk ] );
				++$calls;
				if ( ! $response->ok() ) {
					return $this->left( 'tags' === $key ? $items : [], 'tags' === $key ? $urls : $items, $response->message(), $response->retryAfter );
				}
				$items = array_slice( $items, $size );
			}
			if ( 'tags' === $key ) {
				$tags = [];
			}
		}
		return [
			'ok'    => true,
			'error' => '',
		];
	}

	/**
	 * @param list<string> $tags Tags pendientes.
	 * @param list<string> $urls URLs pendientes.
	 * @return array{ok: bool, error: string, tags_left: list<string>, urls_left: list<string>, retry_after: int}
	 */
	private function left( array $tags, array $urls, string $error, int $retryAfter ): array {
		return [
			'ok'          => false,
			'error'       => $error,
			'tags_left'   => array_values( $tags ),
			'urls_left'   => array_values( $urls ),
			'retry_after' => $retryAfter,
		];
	}

	private function sameHost( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && strtolower( $host ) === strtolower( $this->host );
	}
}
