<?php
/**
 * Encola la app del admin a partir del manifiesto de Vite (o del servidor de desarrollo).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Admin;

use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Rest\RestRegistrar;

/**
 * Lee admin/dist/manifest.json y registra JS (como módulo) y CSS.
 */
final class AssetsLoader {

	public const HANDLE = 'itb-admin';

	private const ENTRY = 'src/main.tsx';

	/**
	 * @param string $pluginDir Directorio del plugin.
	 * @param string $pluginUrl URL del directorio del plugin.
	 */
	public function __construct(
		private readonly string $pluginDir,
		private readonly string $pluginUrl,
	) {
	}

	public function isBuilt(): bool {
		return null !== $this->manifest() || null !== $this->devServer();
	}

	public function enqueue(): void {
		$dev = $this->devServer();
		if ( null !== $dev ) {
			wp_enqueue_script( 'itb-vite-client', $dev . '/@vite/client', [], null, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- servidor de desarrollo.
			wp_enqueue_script( self::HANDLE, $dev . '/' . self::ENTRY, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		} else {
			$manifest = $this->manifest();
			$entry    = is_array( $manifest[ self::ENTRY ] ?? null ) ? $manifest[ self::ENTRY ] : null;
			if ( null === $entry || ! is_string( $entry['file'] ?? null ) ) {
				return;
			}
			$base = $this->pluginUrl . '/admin/dist/';
			wp_enqueue_script( self::HANDLE, $base . $entry['file'], [], ITB_VERSION, true );
			foreach ( is_array( $entry['css'] ?? null ) ? $entry['css'] : [] as $index => $css ) {
				if ( is_string( $css ) ) {
					wp_enqueue_style( self::HANDLE . '-' . $index, $base . $css, [], ITB_VERSION );
				}
			}
		}

		add_filter( 'script_loader_tag', [ $this, 'moduleTag' ], 10, 2 );

		$config = [
			'restUrl'   => esc_url_raw( rest_url( RestRegistrar::NAMESPACE . '/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'version'   => ITB_VERSION,
			'adminUrl'  => esc_url_raw( admin_url() ),
			'locale'    => str_replace( '_', '-', get_user_locale() ),
			'userId'    => get_current_user_id(),
			'killed'    => null !== Runtime::$killReason,
			'safeMode'  => Runtime::$safeMode,
			'pluginUrl' => esc_url_raw( $this->pluginUrl ),
		];
		wp_add_inline_script( self::HANDLE, 'window.itbAdmin = ' . wp_json_encode( $config ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'imagina-turbo', $this->pluginDir . '/languages' );
	}

	/**
	 * type="module" para la app y el cliente de Vite.
	 */
	public function moduleTag( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle && 'itb-vite-client' !== $handle ) {
			return $tag;
		}
		if ( str_contains( $tag, 'type="module"' ) ) {
			return $tag;
		}
		// Solo la etiqueta con src (id="{handle}-js"), no los scripts en línea before/after.
		return (string) preg_replace( '/<script(\s[^>]*\bid=(["\'])' . preg_quote( $handle, '/' ) . '-js\2)/', '<script type="module"$1', $tag, 1 );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function manifest(): ?array {
		foreach ( [ '/admin/dist/manifest.json', '/admin/dist/.vite/manifest.json' ] as $relative ) {
			$file = $this->pluginDir . $relative;
			if ( is_file( $file ) ) {
				$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				return is_array( $data ) ? $data : null;
			}
		}
		return null;
	}

	/**
	 * URL del servidor de desarrollo de Vite si está corriendo (solo con SCRIPT_DEBUG).
	 */
	private function devServer(): ?string {
		if ( ! defined( 'SCRIPT_DEBUG' ) || ! SCRIPT_DEBUG ) {
			return null;
		}
		$file = $this->pluginDir . '/admin/dist/vite-dev-server.json';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$url  = is_array( $data ) && is_string( $data['origin'] ?? null ) ? $data['origin'] : null;
		return null !== $url ? untrailingslashit( esc_url_raw( $url ) ) : null;
	}
}
