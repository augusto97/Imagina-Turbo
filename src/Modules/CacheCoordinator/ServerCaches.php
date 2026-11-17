<?php
/**
 * Cachés del servidor delante de WordPress (0.24.0): Varnish y la caché de nginx (FastCGI o Redis).
 *
 * No reemplazan al adaptador del plugin de caché (LiteSpeed Cache, WP Rocket): son otra capa, que puede
 * convivir con él (por ejemplo, Breeze o WP Rocket con Varnish en Cloudways). Lo que hacen:
 *
 * - Purgar: en el vaciado de la cola (PurgeWorker), antes del edge y del recalentado, para que ni
 *   Cloudflare ni el recalentado se lleven una copia vieja. Más de MAX_URLS URLs → purgar todo.
 * - No guardar: donde el coordinador ya le dice al plugin de caché que no guarde una respuesta (carrito,
 *   exclusiones, refresco de nonces), también se lo dice al servidor por cabecera.
 * - Escuchar: cuando el plugin que gestiona esa caché la vacía entera por su cuenta, se replica en el
 *   edge. Las purgas que hace el propio Imagina Turbo no se escuchan (sin bucles).
 *
 * Verificado en el código y la documentación vigentes (2026-11-17):
 * - Proxy Cache Purge 5.2.0 (varnish-http-purge): VarnishPurger::purge_url( $url ) es pública y
 *   estática; purgar todo es la URL de la portada con la query `vhp-regex` (PURGE de ".*" con la cabecera
 *   X-Purge-Method: regex). Después de cada petición dispara `after_purge_url( $url, $purgeme, $response,
 *   $headers )`.
 * - Breeze (Cloudways), inc/cache/purge-varnish.php: Breeze_PurgeVarnish::purge_cache( $url ); con la query
 *   `breeze` purga todo (método PURGE, convención de Cloudways); si Varnish está apagado no envía nada.
 * - Nginx Helper 3.0.0: el purgador vive en el global `$nginx_purger` (FastCGI_Purger, PhpRedis_Purger o
 *   Predis_Purger, según su configuración) con purge_url( $url, $feed ) y purge_all(); al vaciar todo dispara
 *   `rt_nginx_helper_after_fastcgi_purge_all` o `rt_nginx_helper_after_redis_purge_all`. Su configuración
 *   está en la opción de red `rt_wp_nginx_helper_options` (enable_purge). Su integración con Cloudflare
 *   (opción `easyengine_cache_manager_cf_settings`) se cuelga de la acción rt_nginx_helper_purge_all: por
 *   eso aquí se llama a purge_all() directamente.
 * - nginx (ngx_http_fastcgi_module): «X-Accel-Expires … The zero value disables caching for a response».
 * - Varnish (builtin.vcl): una respuesta con `Surrogate-Control: no-store` no se guarda. PURGE no es un
 *   método del builtin: responde 501 salvo que el VCL lo acepte (lo detecta la prueba del modo directo).
 *
 * Modo directo de Varnish (sin Proxy Cache Purge ni Breeze): PURGE a la dirección configurada con la
 * cabecera Host del sitio y X-Purge-Method, la convención de Proxy Cache Purge, que es la del VCL más usado
 * con WordPress.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

/**
 * Coordinación con Varnish y nginx.
 *
 * @phpstan-type ServerConfig array{varnish: string, varnish_address: string, nginx: string}
 */
final class ServerCaches {

	public const PCP_FILE = 'varnish-http-purge/varnish-http-purge.php';

	public const BREEZE_FILE = 'breeze/breeze.php';

	public const NGINX_HELPER_FILE = 'nginx-helper/nginx-helper.php';

	/** Más URLs que estas en un vaciado: se purga todo (cada URL es una petición). */
	public const MAX_URLS = 30;

	public const DETECT_OPTION = 'itb_server_cache_detect';

	/** Mientras Imagina Turbo purga, las purgas que eso dispara no se replican (sin bucles). */
	private static bool $purging = false;

	/**
	 * @param ServerConfig $config Lo que resolvió la config compilada.
	 */
	public function __construct( private readonly array $config ) {
	}

	/**
	 * Ajustes guardados, válidos: varnish auto/off/direct, su dirección y nginx auto/off.
	 *
	 * @param array<mixed> $input Datos crudos.
	 * @return array{varnish: string, varnish_address: string, nginx: string}
	 */
	public static function settings( array $input ): array {
		$address = is_string( $input['varnish_address'] ?? null ) ? ( self::address( $input['varnish_address'] ) ?? '' ) : '';
		return [
			'varnish'         => in_array( $input['varnish'] ?? null, [ 'auto', 'off', 'direct' ], true ) ? (string) $input['varnish'] : 'auto',
			'varnish_address' => $address,
			'nginx'           => in_array( $input['nginx'] ?? null, [ 'auto', 'off' ], true ) ? (string) $input['nginx'] : 'auto',
		];
	}

	/**
	 * Qué se coordina, según los ajustes y los plugins activos. Automático: el plugin que gestiona esa caché
	 * (Proxy Cache Purge antes que Breeze; Nginx Helper). Directo: solo con una dirección válida.
	 *
	 * @param array<mixed> $settings Ajuste server_cache.
	 * @param list<string> $active   Plugins activos.
	 * @return ServerConfig
	 */
	public static function resolve( array $settings, array $active ): array {
		$s       = self::settings( $settings );
		$has     = static fn( string $file ): bool => in_array( $file, $active, true );
		$varnish = '';
		if ( 'direct' === $s['varnish'] && '' !== $s['varnish_address'] ) {
			$varnish = 'direct';
		} elseif ( 'auto' === $s['varnish'] ) {
			$varnish = $has( self::PCP_FILE ) ? 'proxy-cache-purge' : ( $has( self::BREEZE_FILE ) ? 'breeze' : '' );
		}
		return [
			'varnish'         => $varnish,
			'varnish_address' => 'direct' === $varnish ? $s['varnish_address'] : '',
			'nginx'           => 'auto' === $s['nginx'] && $has( self::NGINX_HELPER_FILE ) ? 'nginx-helper' : '',
		];
	}

	/**
	 * Desde la config compilada.
	 */
	public static function compiled(): self {
		$server = CacheModule::settings()['server_cache'];
		return new self( $server );
	}

	public function active(): bool {
		return '' !== $this->config['varnish'] || '' !== $this->config['nginx'];
	}

	/**
	 * @return ServerConfig
	 */
	public function config(): array {
		return $this->config;
	}

	/**
	 * Purga lo que se coordina: todo o esas URLs (más de MAX_URLS → todo). Devuelve por caché 'ok', 'all'
	 * o el error; vacío si no se coordina.
	 *
	 * @param list<string> $urls URLs del sitio.
	 * @return array{varnish: string, nginx: string}
	 */
	public function purge( bool $all, array $urls ): array {
		$result = [
			'varnish' => '',
			'nginx'   => '',
		];
		$urls   = array_values( array_unique( array_filter( $urls, static fn( string $u ): bool => '' !== $u ) ) );
		if ( ! $this->active() || ( ! $all && [] === $urls ) ) {
			return $result;
		}
		$all           = $all || count( $urls ) > self::MAX_URLS;
		self::$purging = true;
		try {
			if ( '' !== $this->config['varnish'] ) {
				$result['varnish'] = $this->purgeVarnish( $all, $urls );
			}
			if ( '' !== $this->config['nginx'] ) {
				$result['nginx'] = $this->purgeNginx( $all, $urls );
			}
		} finally {
			self::$purging = false;
		}
		return $result;
	}

	/**
	 * @param list<string> $urls URLs.
	 */
	private function purgeVarnish( bool $all, array $urls ): string {
		$home = rtrim( (string) home_url(), '/' );
		try {
			switch ( $this->config['varnish'] ) {
				case 'proxy-cache-purge':
					// Sin barra inicial: así lo resuelven class_exists e is_callable.
					$purge = [ 'VarnishPurger', 'purge_url' ];
					if ( ! class_exists( 'VarnishPurger' ) || ! is_callable( $purge ) ) {
						return __( 'Proxy Cache Purge no está cargado.', 'imagina-turbo' );
					}
					foreach ( $all ? [ $home . '/?vhp-regex' ] : $urls as $url ) {
						call_user_func( $purge, $url );
					}
					break;
				case 'breeze':
					$class = 'Breeze_PurgeVarnish';
					if ( ! class_exists( $class ) ) {
						return __( 'Breeze no está cargado.', 'imagina-turbo' );
					}
					$purge = [ new $class(), 'purge_cache' ];
					if ( ! is_callable( $purge ) ) {
						return __( 'Breeze no está cargado.', 'imagina-turbo' );
					}
					foreach ( $all ? [ $home . '/?breeze' ] : $urls as $url ) {
						call_user_func( $purge, $url );
					}
					break;
				case 'direct':
					$errors = [];
					foreach ( $all ? [ '' ] : $urls as $url ) {
						$code = self::directPurge( $this->config['varnish_address'], $all ? null : $url );
						if ( $code < 200 || $code >= 300 ) {
							$errors[ $code ] = true;
						}
					}
					if ( [] !== $errors ) {
						/* translators: %s: códigos HTTP. */
						return sprintf( __( 'Varnish respondió %s a la purga.', 'imagina-turbo' ), implode( ', ', array_map( static fn( int $c ): string => 0 === $c ? __( 'sin conexión', 'imagina-turbo' ) : (string) $c, array_keys( $errors ) ) ) );
					}
					break;
			}
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
		return $all ? 'all' : 'ok';
	}

	/**
	 * @param list<string> $urls URLs.
	 */
	private function purgeNginx( bool $all, array $urls ): string {
		global $nginx_purger;
		if ( ! is_object( $nginx_purger ) || ! method_exists( $nginx_purger, 'purge_url' ) || ! method_exists( $nginx_purger, 'purge_all' ) ) {
			return __( 'Nginx Helper no está cargado.', 'imagina-turbo' );
		}
		if ( ! self::nginxHelperPurges() ) {
			return __( 'La purga está apagada en Nginx Helper.', 'imagina-turbo' );
		}
		try {
			if ( $all ) {
				$nginx_purger->purge_all();
				return 'all';
			}
			foreach ( $urls as $url ) {
				$nginx_purger->purge_url( $url, false );
			}
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
		return 'ok';
	}

	/**
	 * PURGE a Varnish (una URL o, con null, todo el sitio). Devuelve el código HTTP o 0 sin conexión.
	 */
	public static function directPurge( string $address, ?string $url ): int {
		$address = self::address( $address );
		if ( null === $address ) {
			return 0;
		}
		$home   = (string) home_url( '/' );
		$parsed = wp_parse_url( $url ?? $home );
		$host   = is_array( $parsed ) && is_string( $parsed['host'] ?? null ) ? $parsed['host'] . ( isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '' ) : '';
		if ( '' === $host || ( null !== $url && ! self::sameSite( $url, $home ) ) ) {
			return 0;
		}
		$path  = is_string( $parsed['path'] ?? null ) && '' !== $parsed['path'] ? $parsed['path'] : '/';
		$query = null !== $url && is_string( $parsed['query'] ?? null ) && '' !== $parsed['query'] ? '?' . $parsed['query'] : '';
		if ( null === $url ) {
			$path = rtrim( $path, '/' ) . '/.*';
		}
		$response = wp_remote_request(
			'http://' . $address . $path . $query,
			[
				'method'      => 'PURGE',
				'timeout'     => 3,
				'redirection' => 0,
				'headers'     => [
					'Host'           => $host,
					'X-Purge-Method' => null === $url ? 'regex' : 'default',
				],
			]
		);
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Prueba la dirección de Varnish con un PURGE de la portada (solo la portada se borra de la caché).
	 *
	 * @return array{ok: bool, status: int, message: string}
	 */
	public static function test( string $address ): array {
		if ( null === self::address( $address ) ) {
			return [
				'ok'      => false,
				'status'  => 0,
				'message' => __( 'Dirección no válida: una IP, opcionalmente con puerto (127.0.0.1:6081 o [::1]:6081).', 'imagina-turbo' ),
			];
		}
		$code = self::directPurge( $address, (string) home_url( '/' ) );
		$ok   = $code >= 200 && $code < 300;
		return [
			'ok'      => $ok,
			'status'  => $code,
			'message' => match ( true ) {
				$ok => __( 'Varnish aceptó la purga.', 'imagina-turbo' ),
				0 === $code => __( 'No hubo respuesta en esa dirección. Revisa la IP y el puerto en los que escucha Varnish.', 'imagina-turbo' ),
				405 === $code || 501 === $code || 403 === $code => __( 'Varnish respondió, pero su VCL no acepta PURGE desde este servidor. Hay que permitirlo (acl de purga) o instalar el plugin que use el hosting.', 'imagina-turbo' ),
				default => sprintf( /* translators: %d: código HTTP. */ __( 'Respuesta inesperada: %d.', 'imagina-turbo' ), $code ),
			},
		];
	}

	/**
	 * Dirección de Varnish: IP (v4 o v6) con puerto opcional; nada de nombres, esquemas ni rutas.
	 */
	public static function address( string $value ): ?string {
		$value = trim( $value );
		if ( 1 === preg_match( '/^\[([0-9a-fA-F:.]+)\](?::(\d{1,5}))?$/', $value, $m ) ) {
			$ip   = $m[1];
			$port = $m[2] ?? '';
			$v6   = true;
		} elseif ( 1 === preg_match( '/^([0-9.]+)(?::(\d{1,5}))?$/', $value, $m ) ) {
			$ip   = $m[1];
			$port = $m[2] ?? '';
			$v6   = false;
		} else {
			return null;
		}
		$flag = $v6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, $flag ) || ( '' !== $port && ( (int) $port < 1 || (int) $port > 65535 ) ) ) {
			return null;
		}
		$host = $v6 ? '[' . strtolower( (string) inet_ntop( (string) inet_pton( $ip ) ) ) . ']' : $ip;
		return '' === $port ? $host : $host . ':' . (int) $port;
	}

	/**
	 * Cuando el plugin que gestiona la caché del servidor la vacía entera por su cuenta, se replica en el
	 * edge. Las purgas de una URL no hace falta: Imagina Turbo ya purga el edge por los mismos cambios.
	 *
	 * @param callable(PurgeRequest): void $onPurge Recibe la purga.
	 */
	public function listen( callable $onPurge ): void {
		if ( 'proxy-cache-purge' === $this->config['varnish'] ) {
			add_action(
				'after_purge_url',
				static function ( $url, $purgeme = '', $response = null, $headers = [] ) use ( $onPurge ): void {
					if ( ! self::$purging && is_array( $headers ) && 'regex' === ( $headers['X-Purge-Method'] ?? '' ) ) {
						$onPurge( PurgeRequest::all( 'varnish' ) );
					}
				},
				10,
				4
			);
		}
		if ( 'nginx-helper' === $this->config['nginx'] ) {
			foreach ( [ 'rt_nginx_helper_after_fastcgi_purge_all', 'rt_nginx_helper_after_redis_purge_all' ] as $hook ) {
				add_action(
					$hook,
					static function () use ( $onPurge ): void {
						if ( ! self::$purging ) {
							$onPurge( PurgeRequest::all( 'nginx' ) );
						}
					}
				);
			}
		}
	}

	/**
	 * La respuesta actual no se guarda en la caché del servidor (las mismas que no guarda el plugin de
	 * caché). Sin efecto si no se coordina ninguna o ya salieron las cabeceras.
	 */
	public static function noCache(): void {
		if ( headers_sent() ) {
			return;
		}
		$server = CacheModule::settings()['server_cache'];
		if ( '' !== $server['nginx'] ) {
			header( 'X-Accel-Expires: 0', true );
		}
		if ( '' !== $server['varnish'] ) {
			header( 'Surrogate-Control: no-store', true );
		}
	}

	/**
	 * Nginx Helper tiene la purga activada (si no, su dueño decidió no purgar desde WordPress).
	 */
	public static function nginxHelperPurges(): bool {
		$options = function_exists( 'get_site_option' ) ? get_site_option( 'rt_wp_nginx_helper_options', [] ) : [];
		return is_array( $options ) && ! empty( $options['enable_purge'] );
	}

	/**
	 * Nginx Helper tiene su propia conexión con Cloudflare: purgaría la zona por su lado.
	 */
	public static function nginxHelperCloudflare(): bool {
		$cf = function_exists( 'get_site_option' ) ? get_site_option( 'easyengine_cache_manager_cf_settings', [] ) : [];
		return is_array( $cf ) && ! empty( $cf['api_token'] ) && ! empty( $cf['zone_id'] );
	}

	/**
	 * Cabeceras de caché de una petición a la portada como visitante: qué caché responde delante de PHP.
	 *
	 * @return array{at: int, varnish: bool, nginx: bool, headers: array<string, string>, error: string}
	 */
	public static function detect(): array {
		$response = wp_remote_get(
			(string) home_url( '/' ),
			[
				'timeout'     => 10,
				'redirection' => 2,
				'user-agent'  => Warmer::USER_AGENT,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
			]
		);
		$out      = [
			'at'      => time(),
			'varnish' => false,
			'nginx'   => false,
			'headers' => [],
			'error'   => is_wp_error( $response ) ? $response->get_error_message() : '',
		];
		if ( is_wp_error( $response ) ) {
			return $out;
		}
		$headers = [];
		foreach ( [ 'x-varnish', 'via', 'x-cache', 'x-cache-status', 'x-fastcgi-cache', 'x-nginx-cache', 'x-proxy-cache', 'x-srcache-fetch-status', 'x-cacheable', 'x-litespeed-cache', 'server', 'age' ] as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );
			$value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			if ( '' !== $value ) {
				$headers[ $name ] = substr( sanitize_text_field( $value ), 0, 120 );
			}
		}
		$out['headers'] = $headers;
		$out['varnish'] = isset( $headers['x-varnish'] ) || str_contains( strtolower( $headers['via'] ?? '' ), 'varnish' );
		$out['nginx']   = isset( $headers['x-fastcgi-cache'] ) || isset( $headers['x-nginx-cache'] ) || isset( $headers['x-srcache-fetch-status'] ) || ( isset( $headers['x-cache-status'] ) && str_contains( strtolower( $headers['server'] ?? '' ), 'nginx' ) ) || ( isset( $headers['x-proxy-cache'] ) && ! $out['varnish'] );
		update_option( self::DETECT_OPTION, $out, false );
		return $out;
	}

	/**
	 * Último resultado de la detección.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function lastDetect(): ?array {
		$value = get_option( self::DETECT_OPTION, null );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Mismo host y puerto que la portada.
	 */
	private static function sameSite( string $url, string $home ): bool {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) )
			&& wp_parse_url( $url, PHP_URL_PORT ) === wp_parse_url( $home, PHP_URL_PORT );
	}
}
