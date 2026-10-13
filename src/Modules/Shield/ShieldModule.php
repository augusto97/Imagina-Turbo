<?php
/**
 * Escudo de servidor (docs/02 módulo 6). Riesgo seguro, activo por defecto.
 *
 * Lo que corta tráfico basura antes de cargar plugins vive en EarlyGuard (etapa temprana). Aquí, ya
 * con WordPress cargado:
 * - Heartbeat: fuera del frontend; cada N segundos en el admin y en el editor.
 * - Combinaciones de filtros de WooCommerce (?filter_color=…&filter_talla=…&min_price=…): por encima de
 *   N parámetros, X-Robots-Tag noindex, nofollow (los crawlers dejan de recorrer combinaciones infinitas).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Shield;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Runtime;

/**
 * Registro del módulo.
 */
final class ShieldModule implements ModuleInterface {

	public function register( Container $container, RequestContext $request ): void {
		$shield = self::settings();
		if ( RequestType::FrontendHtml === $request->type ) {
			if ( $shield['heartbeat'] ) {
				add_action( 'wp_enqueue_scripts', [ self::class, 'removeFrontendHeartbeat' ], 100 );
			}
			if ( $shield['filter_limit'] > 0 && self::filterParams( $_GET ) > $shield['filter_limit'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se cuentan nombres.
				add_action( 'send_headers', [ self::class, 'noindex' ] );
			}
		}
		if ( RequestType::Admin === $request->type && $shield['heartbeat'] ) {
			add_filter( 'heartbeat_settings', [ self::class, 'heartbeatInterval' ] );
		}
	}

	/**
	 * @return array{heartbeat: bool, heartbeat_admin: int, heartbeat_editor: int, filter_limit: int}
	 */
	public static function settings(): array {
		$s = Runtime::$config?->get( 'shield', [] );
		$s = is_array( $s ) ? $s : [];
		return [
			'heartbeat'        => false !== ( $s['heartbeat'] ?? true ),
			'heartbeat_admin'  => is_int( $s['heartbeat_admin'] ?? null ) ? $s['heartbeat_admin'] : 60,
			'heartbeat_editor' => is_int( $s['heartbeat_editor'] ?? null ) ? $s['heartbeat_editor'] : 15,
			'filter_limit'     => is_int( $s['filter_limit'] ?? null ) ? $s['filter_limit'] : 3,
		];
	}

	/**
	 * Solo para visitantes: con sesión, un plugin del frontend (LMS, foros, chat) puede usarlo, y quitar el
	 * script también quitaría los que dependen de él.
	 */
	public static function removeFrontendHeartbeat(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * @param mixed $settings Ajustes de heartbeat.
	 * @return mixed
	 */
	public static function heartbeatInterval( mixed $settings ): mixed {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		global $pagenow;
		$editor               = in_array( $pagenow, [ 'post.php', 'post-new.php' ], true );
		$s                    = self::settings();
		$settings['interval'] = max( 15, min( 120, $editor ? $s['heartbeat_editor'] : $s['heartbeat_admin'] ) );
		return $settings;
	}

	public static function noindex(): void {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	/**
	 * Parámetros de filtrado de WooCommerce en la URL.
	 *
	 * @param array<mixed> $get $_GET.
	 */
	public static function filterParams( array $get ): int {
		$n = 0;
		foreach ( array_keys( $get ) as $name ) {
			$name = (string) $name;
			if ( str_starts_with( $name, 'filter_' ) || str_starts_with( $name, 'query_type_' ) || in_array( $name, [ 'min_price', 'max_price', 'rating_filter' ], true ) ) {
				++$n;
			}
		}
		return $n;
	}
}
