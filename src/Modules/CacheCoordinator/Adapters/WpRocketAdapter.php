<?php
/**
 * WP Rocket 3.x. Funciones y hooks verificados en su código (wp-media/wp-rocket 3.23):
 * rocket_clean_files(), rocket_clean_domain(), after_rocket_clean_post, after_rocket_clean_files,
 * after_rocket_clean_home y rocket_after_clean_domain.
 *
 * WP Rocket no tiene tags: purga por URL. Sus aciertos se sirven como archivo estático (Apache) o
 * con readfile() antes de cargar los plugins (advanced-cache.php), así que nunca llevan Cache-Tag:
 * en el edge se purga por URL. En Apache, la cabecera del edge se agrega por .htaccess
 * (`rocket_htaccess_files_match`) a sus archivos de caché; en nginx no hay forma y el HTML no se
 * deja en el edge.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator\Adapters;

use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;

/**
 * Adaptador de WP Rocket.
 */
final class WpRocketAdapter implements CacheAdapter {

	public const HTACCESS_MARKER = '# Imagina Turbo: cabecera del edge para la caché de WP Rocket';

	private bool $listening = false;

	/**
	 * @param string $serverFamily Servidor web (apache, nginx, openlitespeed, litespeed, unknown).
	 * @param string $edgeHeader   Valor de Cloudflare-CDN-Cache-Control para sus aciertos ('' = no emitir).
	 */
	public function __construct( private readonly string $serverFamily, private readonly string $edgeHeader ) {
	}

	public function id(): string {
		return 'wp-rocket';
	}

	public function addResponseTags( array $tags ): void {
	}

	public function noCache( string $reason ): void {
		// WP Rocket no cachea REST ni AJAX; en páginas, la convención es esta constante.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante estándar que respetan los plugins de caché.
		}
	}

	public function purge( PurgeRequest $request, array $tags ): void {
		if ( $request->all ) {
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			return;
		}
		if ( [] !== $request->urls && function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( $request->urls );
		}
	}

	public function listen( callable $onPurge ): void {
		if ( $this->listening ) {
			return;
		}
		$this->listening = true;
		add_action(
			'after_rocket_clean_post',
			static function ( $post, $urls = [] ) use ( $onPurge ): void {
				$names = $post instanceof \WP_Post ? [ 'p-' . $post->ID ] : [];
				$onPurge( new PurgeRequest( $names, self::urls( $urls ), false, 'wp-rocket' ) );
			},
			10,
			2
		);
		add_action(
			'after_rocket_clean_files',
			static function ( $urls ) use ( $onPurge ): void {
				$onPurge( new PurgeRequest( [], self::urls( $urls ), false, 'wp-rocket' ) );
			}
		);
		add_action(
			'after_rocket_clean_home',
			static function () use ( $onPurge ): void {
				$onPurge( new PurgeRequest( [ 'home' ], [ home_url( '/' ) ], false, 'wp-rocket' ) );
			}
		);
		// Una sola vez al final de "vaciar todo" (after_rocket_clean_domain se dispara por idioma).
		add_action(
			'rocket_after_clean_domain',
			static function () use ( $onPurge ): void {
				$onPurge( PurgeRequest::all( 'wp-rocket' ) );
			}
		);
	}

	public function hasDeferredPurge(): bool {
		return false;
	}

	public function headersOnHit(): bool {
		return false;
	}

	/**
	 * En Apache, sus archivos de caché pueden llevar una cabecera fija del edge (sin Cache-Tag).
	 */
	public function edgeCapable(): bool {
		return 'apache' === $this->serverFamily;
	}

	/**
	 * Agrega la cabecera del edge a los archivos de caché de WP Rocket en su bloque de .htaccess.
	 */
	public function registerHtaccess(): void {
		add_filter(
			'rocket_htaccess_files_match',
			function ( $rules ) {
				return is_string( $rules ) ? $rules . self::htaccessBlock( $this->edgeCapable() ? $this->edgeHeader : '' ) : $rules;
			}
		);
	}

	/**
	 * Bloque de .htaccess ('' si no hay cabecera que emitir). Solo los archivos de caché de WP Rocket
	 * (index[-https][-webp].html[_gzip]).
	 */
	public static function htaccessBlock( string $edgeHeader ): string {
		if ( '' === $edgeHeader || 1 !== preg_match( '/^[a-z0-9=,\- ]+$/', $edgeHeader ) ) {
			return '';
		}
		return PHP_EOL . self::HTACCESS_MARKER . PHP_EOL
			. '<IfModule mod_headers.c>' . PHP_EOL
			. '<FilesMatch "^index(-[a-z0-9]+)*\.html(_gzip)?$">' . PHP_EOL
			. 'Header set Cloudflare-CDN-Cache-Control "' . $edgeHeader . '"' . PHP_EOL
			. '</FilesMatch>' . PHP_EOL
			. '</IfModule>' . PHP_EOL;
	}

	/**
	 * Regenera el .htaccess de WP Rocket (al cambiar nuestros ajustes).
	 */
	public static function flushHtaccess(): void {
		if ( function_exists( 'flush_rocket_htaccess' ) ) {
			flush_rocket_htaccess();
		}
	}

	/**
	 * @param mixed $urls Lista de URLs de WP Rocket.
	 * @return list<string>
	 */
	private static function urls( mixed $urls ): array {
		$out = [];
		foreach ( is_array( $urls ) ? $urls : [] as $url ) {
			if ( is_string( $url ) && str_starts_with( $url, 'http' ) ) {
				$out[] = $url;
			}
		}
		return $out;
	}
}
