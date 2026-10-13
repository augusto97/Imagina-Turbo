<?php
/**
 * Contrato con el plugin de caché de página del sitio (caché de origen).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator\Adapters;

use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;

/**
 * Operaciones del adaptador.
 */
interface CacheAdapter {

	/** 'litespeed', 'wp-rocket' o 'none'. */
	public function id(): string;

	/**
	 * Agrega los tags propios a la respuesta actual (si el plugin los entiende).
	 *
	 * @param list<string> $tags Tags con prefijo.
	 */
	public function addResponseTags( array $tags ): void;

	/**
	 * La respuesta actual no se guarda en la caché del plugin (endpoints dinámicos propios).
	 */
	public function noCache( string $reason ): void;

	/**
	 * Purga la caché de origen ahora, en este request.
	 *
	 * @param list<string> $tags Tags con prefijo.
	 */
	public function purge( PurgeRequest $request, array $tags ): void;

	/**
	 * Escucha las purgas que hace el plugin por su cuenta para replicarlas en el edge.
	 *
	 * @param callable(PurgeRequest): void $onPurge Recibe la purga traducida a tags propios o URLs.
	 */
	public function listen( callable $onPurge ): void;

	/**
	 * El plugin dejó purgas pendientes que solo envía en el próximo request PHP (LSCache en cron).
	 */
	public function hasDeferredPurge(): bool;

	/**
	 * Las respuestas servidas desde su caché conservan nuestras cabeceras (Cache-Tag, CDN-Cache-Control).
	 */
	public function headersOnHit(): bool;
}
