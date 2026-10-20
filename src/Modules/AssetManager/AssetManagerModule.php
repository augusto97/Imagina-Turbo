<?php
/**
 * Gestor de assets por ruta (docs/02 módulo 8). Riesgo moderado, apagado por defecto.
 *
 * Quita con wp_dequeue_script / wp_dequeue_style los handles que una regla marca para la página
 * actual, sin tocar el HTML: en wp_enqueue_scripts (prioridad 9999), en wp_print_styles y
 * wp_print_scripts (justo antes de imprimir la cabecera) y en wp_print_footer_scripts (lo que se
 * encola tarde, por ejemplo desde un shortcode). Desencolar solo quita la petición de nivel
 * superior: si otro asset que se queda lo necesita como dependencia, WordPress lo sigue imprimiendo,
 * así que una regla nunca deja a otro script sin su dependencia.
 *
 * Las reglas "testing" solo se aplican al administrador que lleva la cookie firmada de
 * previsualización (itb_assets_prev) y tiene la sesión iniciada; esa página no se cachea.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\AssetManager;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Plugin;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Core\Safety\AdminBypassCookie;
use ImaginaTurbo\Modules\Profiler\RouteKey;

/**
 * Registro y aplicación de las reglas.
 *
 * @phpstan-import-type Context from AssetRules
 */
final class AssetManagerModule implements ModuleInterface {

	public const PREVIEW_COOKIE = 'itb_assets_prev';

	/** Handles que ninguna regla puede quitar (la barra de administración y los propios). */
	public const PROTECTED_PREFIXES = [ 'admin-bar', 'itb-' ];

	/** @var list<array{h: string, t: string, m: string, tpl: list<string>, ids: list<int>, pt: list<string>, s: string}>|null */
	private static ?array $applicable = null;

	/** @var array{js: list<string>, css: list<string>} Lo que se quitó en este request. */
	public static array $removed = [
		'js'  => [],
		'css' => [],
	];

	public function register( Container $container, RequestContext $request ): void {
		if ( RequestType::FrontendHtml !== $request->type ) {
			return;
		}
		LateStyles::register();
		if ( [] === self::rules() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', [ self::class, 'apply' ], 9999 );
		add_action( 'wp_print_styles', [ self::class, 'apply' ], 1 );
		add_action( 'wp_print_scripts', [ self::class, 'apply' ], 1 );
		add_action( 'wp_print_footer_scripts', [ self::class, 'apply' ], 5 );
	}

	/**
	 * @return list<array{h: string, t: string, m: string, tpl: list<string>, ids: list<int>, pt: list<string>, s: string}>
	 */
	public static function rules(): array {
		$rules = Runtime::$config?->get( 'assets', [] );
		$out   = [];
		foreach ( is_array( $rules ) ? $rules : [] as $rule ) {
			if ( is_array( $rule ) && is_string( $rule['h'] ?? null ) && in_array( $rule['t'] ?? '', [ 'js', 'css' ], true ) && in_array( $rule['m'] ?? '', [ 'never', 'only' ], true ) ) {
				$out[] = [
					'h'   => $rule['h'],
					't'   => (string) $rule['t'],
					'm'   => (string) $rule['m'],
					'tpl' => array_values( array_filter( is_array( $rule['tpl'] ?? null ) ? $rule['tpl'] : [], 'is_string' ) ),
					'ids' => array_values( array_map( 'intval', is_array( $rule['ids'] ?? null ) ? $rule['ids'] : [] ) ),
					'pt'  => array_values( array_filter( is_array( $rule['pt'] ?? null ) ? $rule['pt'] : [], 'is_string' ) ),
					's'   => 'testing' === ( $rule['s'] ?? '' ) ? 'testing' : 'active',
				];
			}
		}
		return $out;
	}

	/**
	 * Quita los handles de las reglas que aplican a esta página.
	 */
	public static function apply(): void {
		if ( ! did_action( 'wp' ) || AssetInventory::active() ) {
			return;
		}
		if ( null === self::$applicable ) {
			$preview          = self::previewing();
			$context          = self::context();
			self::$applicable = [];
			foreach ( self::rules() as $rule ) {
				if ( ( 'active' === $rule['s'] || $preview ) && ! self::protectedHandle( $rule['h'] ) && AssetRules::removes( $rule, $context ) ) {
					self::$applicable[] = $rule;
				}
			}
			if ( $preview ) {
				// La previsualización aplica reglas sin verificar: esa página no se guarda en caché.
				Plugin::noPageCache();
			}
		}
		foreach ( self::$applicable as $rule ) {
			if ( 'js' === $rule['t'] && wp_script_is( $rule['h'], 'enqueued' ) ) {
				wp_dequeue_script( $rule['h'] );
				self::$removed['js'][] = $rule['h'];
			} elseif ( 'css' === $rule['t'] && wp_style_is( $rule['h'], 'enqueued' ) ) {
				wp_dequeue_style( $rule['h'] );
				self::$removed['css'][] = $rule['h'];
			}
		}
	}

	/**
	 * Página actual: plantilla, id (solo contenido singular) y tipo de contenido.
	 *
	 * @return Context
	 */
	public static function context(): array {
		$singular = is_singular();
		$type     = $singular ? get_post_type() : false;
		return [
			'template'  => RouteKey::template(),
			'id'        => $singular ? (int) get_queried_object_id() : 0,
			'post_type' => is_string( $type ) ? $type : '',
		];
	}

	public static function protectedHandle( string $handle ): bool {
		foreach ( self::PROTECTED_PREFIXES as $prefix ) {
			if ( str_starts_with( $handle, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * El administrador con sesión iniciada lleva una cookie de previsualización válida y suya.
	 */
	private static function previewing(): bool {
		if ( ! isset( $_COOKIE[ self::PREVIEW_COOKIE ] ) || ! is_user_logged_in() ) {
			return false;
		}
		$key = AdminBypassCookie::key( self::PREVIEW_COOKIE );
		if ( null === $key ) {
			return false;
		}
		$user = AdminBypassCookie::validate( wp_unslash( $_COOKIE[ self::PREVIEW_COOKIE ] ), $key, time() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida con HMAC.
		return null !== $user && get_current_user_id() === $user && current_user_can( 'manage_options' );
	}
}
