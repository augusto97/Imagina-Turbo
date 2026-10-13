<?php
/**
 * Inventario de assets de una página, en una petición del sitio a sí mismo (loopback) firmada con
 * la cookie itb_assets_inv. Funciona aunque el módulo esté apagado: es lo que se usa para decidir
 * las reglas antes de activarlo.
 *
 * Al final del pie (después de wp_print_footer_scripts) guarda en un transient, con el identificador
 * de la petición (?itb_nc=), lo que la página imprimió: handles, origen, dependencias, si se
 * encolaron directamente o solo como dependencia, y los shortcodes y bloques que se renderizaron.
 * No va en el HTML: así ninguna optimización del plugin de caché (combinar, minificar, retrasar JS)
 * puede alterarlo. La cookie nunca llega a un navegador y la respuesta no se guarda en caché.
 * Mientras se inventaría, las reglas no se aplican (se quiere ver la página completa).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\AssetManager;

use ImaginaTurbo\Core\Plugin;
use ImaginaTurbo\Core\Safety\AdminBypassCookie;

/**
 * Recolección.
 *
 * @phpstan-type Asset array{handle: string, src: string, deps: list<string>, footer: bool, enqueued: bool}
 * @phpstan-type Page array{template: string, id: int, post_type: string, js: list<Asset>, css: list<Asset>, shortcodes: list<string>, blocks: list<string>}
 */
final class AssetInventory {

	public const COOKIE = 'itb_assets_inv';

	/** Vida de la cookie: lo que dura una inspección. */
	public const TTL = 300;

	public const TRANSIENT = 'itb_assets_inv_';

	private static bool $active = false;

	private static string $nonce = '';

	/** @var array<string, true> */
	private static array $shortcodes = [];

	/** @var array<string, true> */
	private static array $blocks = [];

	public static function active(): bool {
		return self::$active;
	}

	/**
	 * Si la cookie es válida, prepara la recolección.
	 */
	public static function register(): void {
		$key = AdminBypassCookie::key( self::COOKIE );
		if ( null === $key || ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		if ( null === AdminBypassCookie::validate( wp_unslash( $_COOKIE[ self::COOKIE ] ), $key, time() ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida con HMAC.
			return;
		}
		$nonce = isset( $_GET['itb_nc'] ) ? sanitize_key( wp_unslash( $_GET['itb_nc'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- la autorización es la cookie firmada.
		if ( 1 !== preg_match( '/^[a-z0-9]{8,24}$/', $nonce ) ) {
			return;
		}
		self::$nonce  = $nonce;
		self::$active = true;
		add_action( 'init', [ Plugin::class, 'noPageCache' ], 0 );
		add_filter( 'do_shortcode_tag', [ self::class, 'shortcode' ], 10, 2 );
		add_filter( 'render_block', [ self::class, 'block' ], 10, 2 );
		add_action( 'wp_footer', [ self::class, 'output' ], PHP_INT_MAX );
	}

	/**
	 * @param mixed $output Salida del shortcode.
	 * @return mixed
	 */
	public static function shortcode( mixed $output, mixed $tag ): mixed {
		if ( is_string( $tag ) && count( self::$shortcodes ) < 200 ) {
			self::$shortcodes[ $tag ] = true;
		}
		return $output;
	}

	/**
	 * @param mixed $content Salida del bloque.
	 * @return mixed
	 */
	public static function block( mixed $content, mixed $block ): mixed {
		if ( is_array( $block ) && is_string( $block['blockName'] ?? null ) && count( self::$blocks ) < 200 ) {
			self::$blocks[ $block['blockName'] ] = true;
		}
		return $content;
	}

	/**
	 * Guarda el inventario al final del pie.
	 */
	public static function output(): void {
		$context = AssetManagerModule::context();
		$data    = [
			'template'   => $context['template'],
			'id'         => $context['id'],
			'post_type'  => $context['post_type'],
			'js'         => self::assets( wp_scripts() ),
			'css'        => self::assets( wp_styles() ),
			'shortcodes' => array_keys( self::$shortcodes ),
			'blocks'     => array_keys( self::$blocks ),
		];
		set_transient( self::TRANSIENT . self::$nonce, $data, self::TTL );
	}

	/**
	 * Lo que se imprimió de un tipo de dependencia.
	 *
	 * @return list<Asset>
	 */
	public static function assets( \WP_Dependencies $deps ): array {
		$out = [];
		foreach ( $deps->done as $handle ) {
			$item = $deps->registered[ $handle ] ?? null;
			if ( ! $item instanceof \_WP_Dependency ) {
				continue;
			}
			$out[] = [
				'handle'   => (string) $handle,
				'src'      => is_string( $item->src ) ? $item->src : '',
				'deps'     => array_values( array_map( 'strval', $item->deps ) ),
				'footer'   => ( $deps instanceof \WP_Scripts && in_array( $handle, $deps->in_footer, true ) ) || 1 === (int) ( $item->extra['group'] ?? 0 ),
				'enqueued' => in_array( $handle, $deps->queue, true ),
			];
			if ( count( $out ) >= 300 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Lee (y borra) el inventario de una petición.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function take( string $nonce ): ?array {
		$data = get_transient( self::TRANSIENT . $nonce );
		delete_transient( self::TRANSIENT . $nonce );
		return is_array( $data ) ? $data : null;
	}
}
