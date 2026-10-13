<?php
/**
 * Menú del admin: una sola página que monta la app React.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Admin;

use ImaginaTurbo\Core\Installer;

/**
 * Página admin.php?page=imagina-turbo.
 */
final class Menu {

	public const SLUG = 'imagina-turbo';

	private string $hook = '';

	public function __construct( private readonly AssetsLoader $assets ) {
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addPage' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function addPage(): void {
		$capability = current_user_can( Installer::CAPABILITY ) ? Installer::CAPABILITY : 'manage_options';
		$hook       = add_menu_page(
			__( 'Imagina Turbo', 'imagina-turbo' ),
			__( 'Imagina Turbo', 'imagina-turbo' ),
			$capability,
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-performance',
			81
		);
		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Solo en nuestra página.
	 */
	public function enqueue( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		$this->assets->enqueue();
	}

	public function render(): void {
		echo '<div class="wrap itb-wrap">';
		echo '<div id="itb-admin-root" class="itb-root" aria-live="polite">';
		if ( ! $this->assets->isBuilt() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'La interfaz del admin no está compilada (falta admin/dist). Ejecuta "npm run build" en la carpeta admin o instala el ZIP de distribución.', 'imagina-turbo' );
			echo '</p></div>';
		} else {
			echo '<p class="itb-loading">' . esc_html__( 'Cargando…', 'imagina-turbo' ) . '</p>';
		}
		echo '</div>';
		echo '<noscript><div class="notice notice-error"><p>' . esc_html__( 'Esta pantalla necesita JavaScript.', 'imagina-turbo' ) . '</p></div></noscript>';
		echo '</div>';
	}
}
