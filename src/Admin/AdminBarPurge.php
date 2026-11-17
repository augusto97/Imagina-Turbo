<?php
/**
 * Purga desde la barra de administración de WordPress (0.23.0): «Purgar esta página» en el sitio y
 * «Purgar toda la caché» en cualquier pantalla.
 *
 * - El menú solo existe para quien administra el plugin (mismo permiso que la REST del admin). Para un
 *   visitante no cuesta nada: WordPress no dispara admin_bar_menu sin barra.
 * - Los enlaces van a admin-post.php con nonce; la URL a purgar tiene que ser de este sitio (mismo host
 *   y puerto), igual que en POST /cache/purge.
 * - Purga el plugin de caché del servidor en el acto y el edge por la cola, como la purga manual de la
 *   pantalla Caché, y queda en el registro.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Admin;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeWorker;
use ImaginaTurbo\Modules\CacheCoordinator\Purger;
use ImaginaTurbo\Rest\RestRegistrar;

/**
 * Menú y manejador.
 */
final class AdminBarPurge {

	public const ACTION = 'itb_purge';

	/** Parámetro con el que vuelve al admin para mostrar el aviso. */
	public const DONE = 'itb_purged';

	public function __construct( private readonly Container $container ) {
	}

	/**
	 * Manejador (solo en el admin: admin-post.php) y aviso tras purgar.
	 */
	public function registerAdmin(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	public function menu( \WP_Admin_Bar $bar ): void {
		if ( ! RestRegistrar::canManage() ) {
			return;
		}
		$back = self::currentUrl();
		$bar->add_node(
			[
				'id'    => 'itb',
				'title' => '<span class="ab-icon dashicons dashicons-performance" aria-hidden="true" style="margin-top:2px"></span><span class="ab-label">' . esc_html__( 'Turbo', 'imagina-turbo' ) . '</span>',
				'href'  => admin_url( 'admin.php?page=' . Menu::SLUG ),
			]
		);
		if ( ! is_admin() && '' !== $back ) {
			$bar->add_node(
				[
					'parent' => 'itb',
					'id'     => 'itb-purge-page',
					'title'  => esc_html__( 'Purgar esta página', 'imagina-turbo' ),
					'href'   => self::link( 'url', $back ),
				]
			);
		}
		$bar->add_node(
			[
				'parent' => 'itb',
				'id'     => 'itb-purge-all',
				'title'  => esc_html__( 'Purgar toda la caché', 'imagina-turbo' ),
				'href'   => self::link( 'all', $back ),
				'meta'   => [
					'onclick' => 'return confirm(' . wp_json_encode( __( '¿Purgar toda la caché? Las páginas se vuelven a generar hasta que el recalentado las llene de nuevo.', 'imagina-turbo' ) ) . ');',
				],
			]
		);
		$bar->add_node(
			[
				'parent' => 'itb',
				'id'     => 'itb-open',
				'title'  => esc_html__( 'Abrir Imagina Turbo', 'imagina-turbo' ),
				'href'   => admin_url( 'admin.php?page=' . Menu::SLUG ),
			]
		);
	}

	public function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! RestRegistrar::canManage() ) {
			wp_die( esc_html__( 'No tienes permiso para purgar la caché.', 'imagina-turbo' ), 403 );
		}
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$back = isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( $_GET['back'] ) ) : '';
		$back = self::sameSite( $back ) ? $back : admin_url( 'admin.php?page=' . Menu::SLUG );
		if ( 'all' === $type ) {
			$request = PurgeRequest::all( 'admin_bar' );
		} elseif ( 'url' === $type && self::sameSite( $back ) ) {
			$request = new PurgeRequest( [], [ $back ], false, 'admin_bar' );
		} else {
			wp_die( esc_html__( 'Purga no válida.', 'imagina-turbo' ), 400 );
		}
		$purger = $this->container->get( Purger::class );
		$purger->purge( $request );
		$purger->flushToQueue();
		$summary = $this->container->get( PurgeWorker::class )->run();
		$this->container->get( EventLog::class )->warning(
			'cache_coordinator',
			'manual_purge',
			__( 'Purga desde la barra de administración.', 'imagina-turbo' ),
			[
				'user'  => get_current_user_id(),
				'type'  => $type,
				'value' => 'url' === $type ? $back : '',
				'edge'  => $summary['edge'],
			]
		);
		// En el admin se avisa; en el sitio se vuelve a la página, ya purgada.
		$target = str_starts_with( $back, admin_url() ) ? add_query_arg( self::DONE, $type, $back ) : $back;
		wp_safe_redirect( $target );
		exit;
	}

	public function notice(): void {
		$done = isset( $_GET[ self::DONE ] ) ? sanitize_key( wp_unslash( $_GET[ self::DONE ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo muestra un aviso.
		if ( '' === $done || ! RestRegistrar::canManage() ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( 'all' === $done ? __( 'Imagina Turbo: caché purgada. El recalentado sigue en segundo plano.', 'imagina-turbo' ) : __( 'Imagina Turbo: página purgada.', 'imagina-turbo' ) ) . '</p></div>';
	}

	/**
	 * Enlace firmado a admin-post.php.
	 */
	public static function link( string $type, string $back ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action' => self::ACTION,
					'type'   => $type,
					'back'   => rawurlencode( $back ),
				],
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	/**
	 * URL de la página actual, sin el aviso de una purga anterior.
	 */
	public static function currentUrl(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $host || '' === $uri ) {
			return '';
		}
		return remove_query_arg( self::DONE, ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri );
	}

	/**
	 * Mismo host y mismo puerto que el sitio.
	 */
	public static function sameSite( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$home = home_url();
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) )
			&& wp_parse_url( $url, PHP_URL_PORT ) === wp_parse_url( $home, PHP_URL_PORT );
	}
}
