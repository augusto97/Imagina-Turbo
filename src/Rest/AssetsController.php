<?php
/**
 * Gestor de assets: GET /assets/status, POST /assets/inspect, POST /assets/suggest,
 * POST /assets/rules, POST /assets/rules/{id}/activate|disable|test, DELETE /assets/rules/{id},
 * POST /assets/preview, POST /assets/scripts (Carga de scripts, 0.14.0).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Safety\AdminBypassCookie;
use ImaginaTurbo\Modules\AssetManager\AssetManagerModule;
use ImaginaTurbo\Modules\AssetManager\AssetRules;
use ImaginaTurbo\Modules\AssetManager\AssetScanner;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeWorker;
use ImaginaTurbo\Modules\CacheCoordinator\Purger;
use ImaginaTurbo\Modules\Database\DatabaseAudit;
use ImaginaTurbo\Modules\ScriptLoading\ScriptLoadingModule;

/**
 * Inventario, sugerencias, reglas y previsualización.
 *
 * @phpstan-import-type AssetRule from AssetRules
 */
final class AssetsController extends Controller {

	private const ID = '(?P<id>[a-f0-9]{12})';

	/** Vida de la cookie de previsualización. */
	private const PREVIEW_TTL = 2 * HOUR_IN_SECONDS;

	private const SUGGEST_TRANSIENT = 'itb_assets_suggest';

	public function registerRoutes(): void {
		$this->route( '/assets/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$this->route(
			'/assets/inspect',
			\WP_REST_Server::CREATABLE,
			[ $this, 'inspect' ],
			[
				'path' => [
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 300,
				],
			]
		);
		$this->route( '/assets/suggest', \WP_REST_Server::CREATABLE, [ $this, 'suggest' ] );
		$this->route( '/assets/scripts', \WP_REST_Server::CREATABLE, [ $this, 'updateScripts' ] );
		$list = [
			'type'     => 'array',
			'maxItems' => 200,
		];
		$this->route(
			'/assets/rules',
			\WP_REST_Server::CREATABLE,
			[ $this, 'create' ],
			[
				'handle'     => [
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 100,
				],
				'type'       => [
					'type'     => 'string',
					'enum'     => [ 'js', 'css' ],
					'required' => true,
				],
				'mode'       => [
					'type'     => 'string',
					'enum'     => [ 'never', 'only' ],
					'required' => true,
				],
				'templates'  => $list,
				'ids'        => $list,
				'post_types' => $list,
				'note'       => [
					'type'      => 'string',
					'maxLength' => 200,
				],
			]
		);
		$confirm = [
			'confirm' => [
				'type'    => 'boolean',
				'default' => false,
			],
		];
		$this->route( '/assets/rules/' . self::ID . '/activate', \WP_REST_Server::CREATABLE, [ $this, 'activate' ], $confirm );
		$this->route( '/assets/rules/' . self::ID . '/disable', \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->setStatus( (string) $r->get_param( 'id' ), 'disabled' ) );
		$this->route( '/assets/rules/' . self::ID . '/test', \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->setStatus( (string) $r->get_param( 'id' ), 'testing' ) );
		$this->route( '/assets/rules/' . self::ID, \WP_REST_Server::DELETABLE, [ $this, 'delete' ] );
		$this->route(
			'/assets/preview',
			\WP_REST_Server::CREATABLE,
			[ $this, 'preview' ],
			[
				'on' => [
					'type'     => 'boolean',
					'required' => true,
				],
			]
		);
	}

	private function rules(): AssetRules {
		return $this->container->get( AssetRules::class );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$enabled = false;
		$forced  = '';
		foreach ( $this->container->get( Diagnostics::class )->modules() as $module ) {
			if ( 'asset_manager' === ( $module['id'] ?? null ) ) {
				$enabled = true === ( $module['enabled'] ?? false );
				$forced  = implode( ', ', is_array( $module['forcedOff'] ?? null ) ? array_filter( $module['forcedOff'], 'is_string' ) : [] );
			}
		}
		$suggest = get_transient( self::SUGGEST_TRANSIENT );
		$key     = AdminBypassCookie::key( AssetManagerModule::PREVIEW_COOKIE );
		$cookie  = isset( $_COOKIE[ AssetManagerModule::PREVIEW_COOKIE ] ) ? wp_unslash( $_COOKIE[ AssetManagerModule::PREVIEW_COOKIE ] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida con HMAC.
		return [
			'enabled'     => $enabled,
			'forced_off'  => $forced,
			'rules'       => array_values( $this->rules()->all() ),
			'preview'     => null !== $key && get_current_user_id() === AdminBypassCookie::validate( $cookie, $key, time() ),
			'suggestions' => is_array( $suggest ) ? $suggest : null,
			'templates'   => $this->templates(),
			'post_types'  => array_values( get_post_types( [ 'public' => true ] ) ),
			'home'        => home_url( '/' ),
			'scripts'     => $this->scriptsStatus(),
		];
	}

	/**
	 * Módulo Carga de scripts (0.14.0): ajustes y si el plugin de caché ya hace lo mismo.
	 *
	 * @return array<string, mixed>
	 */
	private function scriptsStatus(): array {
		$enabled = false;
		$forced  = '';
		foreach ( $this->container->get( Diagnostics::class )->modules() as $module ) {
			if ( 'script_loading' === ( $module['id'] ?? null ) ) {
				$enabled = true === ( $module['enabled'] ?? false );
				$forced  = implode( ', ', is_array( $module['forcedOff'] ?? null ) ? array_filter( $module['forcedOff'], 'is_string' ) : [] );
			}
		}
		$settings = $this->container->get( Settings::class );
		return [
			'enabled'         => $enabled,
			'forced_off'      => $forced,
			'defer'           => (bool) $settings->get( 'script_defer', false ),
			'defer_exclude'   => Settings::scriptHandles( (array) $settings->get( 'script_defer_exclude', [] ) ),
			'delay'           => Settings::delayPatterns( (array) $settings->get( 'script_delay', [] ) ),
			'delay_timeout'   => (int) $settings->get( 'script_delay_timeout', 0 ),
			'delay_scope'     => 'essential' === $settings->get( 'delay_js_scope', 'compat' ) ? 'essential' : 'compat',
			'lsdelay_timeout' => (int) $settings->get( 'lsdelay_timeout', 0 ),
			'cache_defers'    => ScriptLoadingModule::cacheDefers(),
			'cache_delays'    => ScriptLoadingModule::cacheDelays(),
		];
	}

	public function updateScripts( \WP_REST_Request $request ): \WP_REST_Response {
		$changes = [];
		foreach ( [ 'script_defer', 'script_defer_exclude', 'script_delay', 'script_delay_timeout', 'delay_js_scope', 'lsdelay_timeout' ] as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$changes[ $key ] = $request->get_param( $key );
			}
		}
		$this->container->get( Settings::class )->update( $changes );
		$this->container->get( ConfigCompiler::class )->compile();
		// Las páginas guardadas llevan las etiquetas anteriores.
		$this->purge();
		$this->container->get( EventLog::class )->info(
			'script_loading',
			'settings',
			__( 'Ajustes de carga de scripts actualizados.', 'imagina-turbo' ),
			[
				'user'    => get_current_user_id(),
				'changes' => $changes,
			]
		);
		return $this->ok( $this->status() );
	}

	/**
	 * Plantillas posibles en este sitio (los mismos nombres que RouteKey::template()).
	 *
	 * @return list<string>
	 */
	private function templates(): array {
		$out = [ 'portada', 'blog', 'busqueda', '404', 'autor', 'fecha' ];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$out[] = 'single:' . $type->name;
			}
			if ( $type->has_archive ) {
				$out[] = 'archivo:' . $type->name;
			}
		}
		foreach ( get_taxonomies( [ 'public' => true ] ) as $tax ) {
			if ( 'post_format' !== $tax ) {
				$out[] = 'tax:' . $tax;
			}
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			$out[] = 'archivo:product';
		}
		return array_values( array_unique( $out ) );
	}

	public function inspect( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$path = self::path( (string) $request->get_param( 'path' ) );
		if ( null === $path ) {
			return $this->error( 'itb_path', __( 'Escribe una ruta del sitio, por ejemplo /tienda/ o /contacto/.', 'imagina-turbo' ), 400 );
		}
		$result = $this->container->get( AssetScanner::class )->inspect( $path );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * Ruta relativa del sitio (acepta también la URL completa del propio sitio).
	 */
	public static function path( string $input ): ?string {
		$input = trim( $input );
		$home  = untrailingslashit( home_url() );
		if ( str_starts_with( $input, $home ) ) {
			$input = substr( $input, strlen( $home ) );
		}
		if ( '' === $input ) {
			$input = '/';
		}
		if ( ! str_starts_with( $input, '/' ) || str_starts_with( $input, '//' ) || str_contains( $input, '://' ) || str_contains( $input, '#' ) || 1 !== preg_match( '#^[A-Za-z0-9_\-./~%?=&+:,@]*$#', $input ) ) {
			return null;
		}
		$admin = '/' . ltrim( (string) wp_parse_url( admin_url(), PHP_URL_PATH ), '/' );
		$site  = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		if ( str_starts_with( $input, $admin ) || str_starts_with( $input, $site . '/wp-' ) || str_starts_with( $input, '/wp-' ) ) {
			return null;
		}
		return $input;
	}

	public function suggest(): \WP_REST_Response {
		$active = [];
		foreach ( DatabaseAudit::plugins() as $folder => $plugin ) {
			if ( $plugin['active'] ) {
				$active[ $folder ] = $plugin['name'];
			}
		}
		$result = $this->container->get( AssetScanner::class )->suggest( $active ) + [ 'generated_at' => time() ];
		set_transient( self::SUGGEST_TRANSIENT, $result, DAY_IN_SECONDS );
		return $this->ok( $this->status() );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$handle = (string) $request->get_param( 'handle' );
		if ( AssetManagerModule::protectedHandle( $handle ) ) {
			return $this->error( 'itb_asset_protected', __( 'Ese asset no se puede quitar (barra de administración o de Imagina Turbo).', 'imagina-turbo' ), 400 );
		}
		$rule = AssetRules::normalize(
			[
				'handle'      => $handle,
				'type'        => $request->get_param( 'type' ),
				'mode'        => $request->get_param( 'mode' ),
				'templates'   => (array) ( $request->get_param( 'templates' ) ?? [] ),
				'ids'         => (array) ( $request->get_param( 'ids' ) ?? [] ),
				'post_types'  => (array) ( $request->get_param( 'post_types' ) ?? [] ),
				'note'        => (string) ( $request->get_param( 'note' ) ?? '' ),
				'status'      => 'testing',
				'verified_by' => 0,
				'verified_at' => 0,
				'created_at'  => time(),
			]
		);
		if ( null === $rule ) {
			return $this->error( 'itb_asset_rule', __( 'Regla no válida. "Solo aquí" necesita al menos una plantilla, página o tipo de contenido.', 'imagina-turbo' ), 400 );
		}
		if ( isset( $this->rules()->all()[ $rule['id'] ] ) ) {
			return $this->error( 'itb_asset_exists', __( 'Esa regla ya existe.', 'imagina-turbo' ), 409 );
		}
		$this->rules()->save( $rule );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info(
			'asset_manager',
			'rule_created',
			__( 'Regla de assets creada en prueba (solo se aplica con la previsualización).', 'imagina-turbo' ),
			[
				'rule'   => $rule['id'],
				'handle' => $rule['handle'],
				'mode'   => $rule['mode'],
				'user'   => get_current_user_id(),
			]
		);
		return $this->ok( $this->status(), 201 );
	}

	public function activate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$rule = $this->rules()->all()[ (string) $request->get_param( 'id' ) ] ?? null;
		if ( null === $rule ) {
			return $this->error( 'itb_asset_rule', __( 'La regla no existe.', 'imagina-turbo' ), 404 );
		}
		if ( true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma que revisaste las páginas afectadas con la previsualización: la regla se aplicará a todos los visitantes.', 'imagina-turbo' ), 428 );
		}
		$rule['status']      = 'active';
		$rule['verified_by'] = get_current_user_id();
		$rule['verified_at'] = time();
		return $this->store( $rule, 'rule_activated', __( 'Regla de assets activada para todos los visitantes (verificada por el administrador).', 'imagina-turbo' ), true );
	}

	public function setStatus( string $id, string $status ): \WP_REST_Response|\WP_Error {
		$rule = $this->rules()->all()[ $id ] ?? null;
		if ( null === $rule ) {
			return $this->error( 'itb_asset_rule', __( 'La regla no existe.', 'imagina-turbo' ), 404 );
		}
		$wasActive      = 'active' === $rule['status'];
		$rule['status'] = $status;
		return $this->store( $rule, 'disabled' === $status ? 'rule_disabled' : 'rule_testing', 'disabled' === $status ? __( 'Regla de assets desactivada.', 'imagina-turbo' ) : __( 'Regla de assets devuelta a prueba.', 'imagina-turbo' ), $wasActive );
	}

	public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (string) $request->get_param( 'id' );
		$rule = $this->rules()->all()[ $id ] ?? null;
		if ( null === $rule ) {
			return $this->error( 'itb_asset_rule', __( 'La regla no existe.', 'imagina-turbo' ), 404 );
		}
		$this->rules()->delete( $id );
		$this->container->get( ConfigCompiler::class )->compile();
		if ( 'active' === $rule['status'] ) {
			$this->purge();
		}
		$this->container->get( EventLog::class )->info(
			'asset_manager',
			'rule_deleted',
			__( 'Regla de assets eliminada.', 'imagina-turbo' ),
			[
				'rule'   => $id,
				'handle' => $rule['handle'],
				'user'   => get_current_user_id(),
			]
		);
		return $this->ok( $this->status() );
	}

	/**
	 * Activa o quita la cookie de previsualización para este administrador (HttpOnly, 2 h).
	 */
	public function preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$key = AdminBypassCookie::key( AssetManagerModule::PREVIEW_COOKIE );
		if ( null === $key ) {
			return $this->error( 'itb_keys', __( 'Faltan AUTH_KEY y AUTH_SALT en wp-config.php.', 'imagina-turbo' ), 409 );
		}
		$on      = true === $request->get_param( 'on' );
		$expires = $on ? time() + self::PREVIEW_TTL : time() - YEAR_IN_SECONDS;
		$value   = $on ? AdminBypassCookie::create( get_current_user_id(), $expires, $key ) : '';
		if ( ! headers_sent() ) {
			setcookie(
				AssetManagerModule::PREVIEW_COOKIE,
				$value,
				[
					'expires'  => $expires,
					'path'     => defined( 'COOKIEPATH' ) && '' !== constant( 'COOKIEPATH' ) ? (string) constant( 'COOKIEPATH' ) : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) constant( 'COOKIE_DOMAIN' ) : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}
		// Con la caché de usuarios con sesión (LSCache "Cache Logged-in Users", la caché por usuario de
		// WP Rocket) este administrador tiene copias de antes: se descartan para que vea el cambio. Las
		// páginas con la previsualización activa no se guardan.
		do_action( 'litespeed_purge_private_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LSCache.
		if ( function_exists( 'rocket_clean_user' ) ) {
			rocket_clean_user( get_current_user_id() );
		}
		if ( $on ) {
			$_COOKIE[ AssetManagerModule::PREVIEW_COOKIE ] = $value;
		} else {
			unset( $_COOKIE[ AssetManagerModule::PREVIEW_COOKIE ] );
		}
		return $this->ok( $this->status() );
	}

	/**
	 * @param AssetRule $rule Regla.
	 */
	private function store( array $rule, string $type, string $message, bool $purge ): \WP_REST_Response {
		$this->rules()->save( $rule );
		$this->container->get( ConfigCompiler::class )->compile();
		if ( $purge ) {
			$this->purge();
		}
		$this->container->get( EventLog::class )->info(
			'asset_manager',
			$type,
			$message,
			[
				'rule'   => $rule['id'],
				'handle' => $rule['handle'],
				'status' => $rule['status'],
				'user'   => get_current_user_id(),
			]
		);
		return $this->ok( $this->status() );
	}

	/**
	 * Las páginas en caché llevan los assets de antes: se purgan (plugin de caché y edge).
	 */
	private function purge(): void {
		try {
			$purger = $this->container->get( Purger::class );
			$purger->purge( PurgeRequest::all( 'asset_manager' ) );
			$purger->flushToQueue();
			$this->container->get( PurgeWorker::class )->run();
		} catch ( \Throwable $e ) {
			$this->container->get( EventLog::class )->warning( 'asset_manager', 'purge_failed', $e->getMessage() );
		}
	}
}
