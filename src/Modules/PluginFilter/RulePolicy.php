<?php
/**
 * Qué se puede filtrar, qué plugins nunca se quitan y con qué peticiones se prueba una regla.
 *
 * Además de los tipos que el documento excluye (admin, login, cron, CLI, checkout, cuenta), quedan
 * fuera:
 * - La Store API (namespace wc/store/v1): incluye el checkout de bloques, y la regla es por namespace.
 * - wc-ajax update_order_review y checkout (docs/02 módulo 4).
 * - wp/v2 y la raíz de la REST: los usan el editor y casi todos los plugins.
 * - Requests con sesión iniciada (lo decide el loader).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\PluginFilter;

use ImaginaTurbo\Integrations\ImaginaSearch;

/**
 * Reglas de la política.
 */
final class RulePolicy {

	public const TYPES = [ 'WcAjax', 'AdminAjax', 'Rest', 'StaticMiss' ];

	public const BLOCKED_KEYS = [ 'WcAjax:update_order_review', 'WcAjax:checkout', 'Rest:wp/v2', 'Rest', 'WcAjax', 'AdminAjax' ];

	/** Carpetas de plugins de seguridad, antispam, 2FA, captcha y membresías (0.21.2). */
	public const SECURITY_FOLDERS = [
		'wordfence',
		'better-wp-security',
		'ithemes-security-pro',
		'all-in-one-wp-security-and-firewall',
		'wp-simple-firewall',
		'sucuri-scanner',
		'wp-cerber',
		'limit-login-attempts-reloaded',
		'loginizer',
		'wps-hide-login',
		'wp-2fa',
		'two-factor',
		'two-factor-authentication',
		'miniorange-2-factor-authentication',
		'akismet',
		'antispam-bee',
		'cleantalk-spam-protect',
		'simple-cloudflare-turnstile',
		'advanced-google-recaptcha',
		'really-simple-ssl',
		'memberpress',
		'paid-memberships-pro',
		'restrict-content',
		'restrict-content-pro',
		'woocommerce-memberships',
		'woocommerce-subscriptions',
		'ultimate-member',
		'members',
		'sfwd-lms',
		'lifterlms',
		'tutor',
	];

	/**
	 * Campos de formularios que inician sesión, registran, cambian la contraseña, crean un pedido o
	 * publican un comentario (0.21.2): un POST que los lleva nunca se procesa con plugins quitados.
	 */
	public const SENSITIVE_POST = [ 'log', 'pwd', 'username', 'password', 'user_login', 'user_email', 'pass1', 'login', 'register', 'woocommerce-login-nonce', 'woocommerce-register-nonce', 'woocommerce-lost-password-nonce', 'woocommerce-reset-password-nonce', 'woocommerce-process-checkout-nonce', 'woocommerce_checkout_place_order', 'comment_post_ID' ];

	/** Pares iguales seguidos para promover una regla (docs/02 módulo 4). */
	public const REQUIRED_PAIRS = 20;

	/**
	 * Motivo por el que una clave no admite reglas, o vacío si las admite.
	 */
	public static function blockedReason( string $key ): string {
		if ( 1 !== preg_match( '#^(WcAjax|AdminAjax|Rest|StaticMiss)(:[A-Za-z0-9_\-./]{1,100})?$#', $key ) || str_contains( $key, '..' ) ) {
			return __( 'Solo se filtran wc-ajax, admin-ajax, la REST por namespace y los assets inexistentes.', 'imagina-turbo' );
		}
		if ( in_array( $key, self::BLOCKED_KEYS, true ) ) {
			return 'Rest:wp/v2' === $key || 'Rest' === $key
				? __( 'wp/v2 y la raíz de la REST los usan el editor y casi todos los plugins: no se filtran.', 'imagina-turbo' )
				: __( 'Este endpoint interviene en el checkout o no identifica una acción: no se filtra.', 'imagina-turbo' );
		}
		if ( str_starts_with( $key, 'Rest:imagina-turbo/' ) ) {
			return __( 'Es la API del admin de Imagina Turbo: siempre llega con sesión iniciada y nunca se filtra.', 'imagina-turbo' );
		}
		if ( str_starts_with( $key, 'Rest:wc/store' ) ) {
			return __( 'La Store API incluye el checkout: no se filtra.', 'imagina-turbo' );
		}
		return '';
	}

	/**
	 * Plugins que nunca se quitan para esa clave.
	 *
	 * @param list<string> $active Plugins activos.
	 * @return list<string>
	 */
	public static function protectedPlugins( string $key, array $active, string $self ): array {
		$out = [ $self ];
		// Seguridad, antispam, 2FA, captcha y membresías nunca se quitan (0.21.2): sin ellos, un login, un
		// comentario o una ruta protegida se procesaría sin bloqueo, sin segundo factor o sin restricción.
		foreach ( self::SECURITY_FOLDERS as $folder ) {
			$plugin = self::find( $active, $folder );
			if ( null !== $plugin ) {
				$out[] = $plugin;
			}
		}
		$woo = self::find( $active, 'woocommerce' );
		if ( null !== $woo && ( str_starts_with( $key, 'WcAjax' ) || str_starts_with( $key, 'Rest:wc/' ) || str_starts_with( $key, 'Rest:wc-' ) ) ) {
			$out[] = $woo;
		}
		if ( 'Rest:' . ImaginaSearch::REST_NAMESPACE === $key ) {
			foreach ( [ ImaginaSearch::plugin( $active ), $woo ] as $plugin ) {
				if ( null !== $plugin ) {
					$out[] = $plugin;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Archivo del plugin cuya carpeta es $folder.
	 *
	 * @param list<string> $active Plugins activos.
	 */
	public static function find( array $active, string $folder ): ?string {
		foreach ( $active as $plugin ) {
			if ( str_starts_with( $plugin, $folder . '/' ) ) {
				return $plugin;
			}
		}
		return null;
	}

	/**
	 * wc-ajax y admin-ajax llegan casi siempre por POST: la regla aplica a todos los métodos. En la REST
	 * y en los assets, por defecto solo a GET/HEAD (un POST escribe y los plugins quitados no ejecutarían
	 * sus efectos).
	 */
	public static function defaultAllMethods( string $key ): bool {
		return str_starts_with( $key, 'WcAjax' ) || str_starts_with( $key, 'AdminAjax' );
	}

	/**
	 * Peticiones de prueba por defecto (rutas relativas a home; la REST con el prefijo del sitio).
	 *
	 * @return list<array{method: string, path: string, body: string}>
	 */
	public static function defaultProbes( string $key, string $restPrefix ): array {
		[ $type, $endpoint ] = array_pad( explode( ':', $key, 2 ), 2, '' );
		$rest                = '/' . trim( $restPrefix, '/' ) . '/';
		return match ( $type ) {
			'WcAjax'     => [ self::probe( 'POST', '/?wc-ajax=' . rawurlencode( $endpoint ) ) ],
			'AdminAjax'  => [ self::probe( 'POST', '/wp-admin/admin-ajax.php', 'action=' . rawurlencode( $endpoint ) ) ],
			'StaticMiss' => [ self::probe( 'GET', '/wp-content/uploads/itb-no-existe.jpg' ) ],
			default      => ImaginaSearch::REST_NAMESPACE === $endpoint ? ImaginaSearch::probes( $rest ) : [ self::probe( 'GET', $rest . $endpoint ) ],
		};
	}

	/**
	 * @return array{method: string, path: string, body: string}
	 */
	public static function probe( string $method, string $path, string $body = '' ): array {
		return [
			'method' => $method,
			'path'   => $path,
			'body'   => $body,
		];
	}

	/**
	 * Valida y limpia las peticiones de prueba que llegan del admin.
	 *
	 * @param mixed $probes Lista cruda.
	 * @return list<array{method: string, path: string, body: string}>
	 */
	public static function sanitizeProbes( mixed $probes ): array {
		$out = [];
		foreach ( is_array( $probes ) ? $probes : [] as $probe ) {
			if ( ! is_array( $probe ) ) {
				continue;
			}
			$method = strtoupper( is_string( $probe['method'] ?? null ) ? $probe['method'] : 'GET' );
			$path   = is_string( $probe['path'] ?? null ) ? trim( $probe['path'] ) : '';
			$body   = is_string( $probe['body'] ?? null ) ? $probe['body'] : '';
			// Solo rutas del propio sitio: nada de URLs absolutas ni protocolos.
			if ( ! in_array( $method, [ 'GET', 'POST' ], true ) || 1 !== preg_match( '#^/[^\s\\\\]{0,300}$#', $path ) || str_starts_with( $path, '//' ) || strlen( $body ) > 2000 ) {
				continue;
			}
			$out[] = self::probe( $method, $path, 'GET' === $method ? '' : $body );
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return $out;
	}
}
