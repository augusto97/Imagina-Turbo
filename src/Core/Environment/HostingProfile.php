<?php
/**
 * Perfil de hosting (0.25.0): compartido (cPanel, DirectAdmin, CloudLinux) o VPS/dedicado.
 *
 * En un hosting compartido cambian tres cosas que el plugin daba por hechas:
 * - La carga del servidor es la de todas las cuentas: no sirve para decidir si recalentar.
 * - CloudLinux limita CPU, memoria y procesos por cuenta y responde 508 («Resource Limit Is Reached»).
 * - Las peticiones del sitio a sí mismo (loopback) y WP-Cron pueden estar bloqueados o depender de
 *   las visitas, que con el HTML en Cloudflare casi no llegan a WordPress.
 *
 * La detección es heurística y se puede corregir a mano (ajuste hosting_profile: auto, shared o
 * dedicated). Señales:
 * - cPanel: la instalación cuelga de /home*\/usuario/public_html o hay un ~/.cpanel en la cuenta.
 * - DirectAdmin: /home/usuario/domains/dominio/public_html.
 * - Plesk: /var/www/vhosts/ (no implica compartido: tus servidores Plesk son propios).
 * - CloudLinux: el kernel lleva «lve» en la versión o existe /etc/cloudlinux-release.
 * - Restricciones de PHP: open_basedir, funciones deshabilitadas, memoria y tiempo máximo bajos.
 *
 * Nada de esto corre en el frontend salvo noteCron() en las peticiones a wp-cron.php.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Environment;

use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\CacheCoordinator\Warmer;

/**
 * Detección, cron y prueba de loopback.
 *
 * @phpstan-type Facts array{path: string, uname: string, open_basedir: string, disabled: list<string>, cloudlinux_file: bool, cpanel_dir: bool, memory_limit: string, max_execution_time: int}
 * @phpstan-type Detection array{panel: string, cloudlinux: bool, restrictions: list<string>, signals: list<string>, shared: bool}
 * @phpstan-type Check array{id: string, url: string, status: string, code: int, ms: int, message: string}
 */
final class HostingProfile {

	public const PROFILES = [ 'auto', 'shared', 'dedicated' ];

	/** Última vez que se ejecutó wp-cron.php (por visita o por la tarea del sistema). */
	public const CRON_OPTION = 'itb_cron_seen';

	/** Desde cuándo se vigila el cron (para avisar si nunca llega a correr). */
	public const CRON_WATCH_OPTION = 'itb_cron_watch';

	public const LOOPBACK_OPTION = 'itb_loopback_check';

	/** Con DISABLE_WP_CRON y sin noticias de wp-cron.php en este tiempo, la tarea del sistema no corre. */
	public const CRON_STALE = 1800;

	/** Funciones que el plugin usa y que algunos hostings deshabilitan. */
	private const WATCHED_FUNCTIONS = [ 'sys_getloadavg', 'set_time_limit', 'ignore_user_abort', 'exec', 'shell_exec', 'proc_open', 'popen', 'opcache_get_status', 'opcache_invalidate' ];

	/**
	 * Decide con lo que se observó del servidor (función pura).
	 *
	 * @param Facts $facts Hechos.
	 * @return Detection
	 */
	public static function evaluate( array $facts ): array {
		$path    = $facts['path'];
		$signals = [];
		$panel   = '';
		if ( 1 === preg_match( '#^/home\d*/[^/]+/public_html(/|$)#', $path ) || $facts['cpanel_dir'] ) {
			$panel     = 'cpanel';
			$signals[] = __( 'Estructura de cuenta de cPanel', 'imagina-turbo' );
		} elseif ( 1 === preg_match( '#^/home\d*/[^/]+/domains/[^/]+/public_html(/|$)#', $path ) ) {
			$panel     = 'directadmin';
			$signals[] = __( 'Estructura de cuenta de DirectAdmin', 'imagina-turbo' );
		} elseif ( str_starts_with( $path, '/var/www/vhosts/' ) ) {
			$panel     = 'plesk';
			$signals[] = __( 'Estructura de Plesk', 'imagina-turbo' );
		}
		$cloudlinux = str_contains( strtolower( $facts['uname'] ), 'lve' ) || $facts['cloudlinux_file'];
		if ( $cloudlinux ) {
			$signals[] = __( 'CloudLinux (límites de recursos por cuenta)', 'imagina-turbo' );
		}

		$restrictions = [];
		if ( '' !== trim( $facts['open_basedir'] ) ) {
			$restrictions[] = __( 'open_basedir limita los archivos que PHP puede leer', 'imagina-turbo' );
		}
		$disabled = array_values( array_intersect( self::WATCHED_FUNCTIONS, $facts['disabled'] ) );
		if ( [] !== $disabled ) {
			/* translators: %s: funciones. */
			$restrictions[] = sprintf( __( 'Funciones deshabilitadas: %s', 'imagina-turbo' ), implode( ', ', $disabled ) );
		}
		$memory = self::bytes( $facts['memory_limit'] );
		if ( $memory > 0 && $memory < 256 * 1024 * 1024 ) {
			/* translators: %s: límite de memoria de PHP. */
			$restrictions[] = sprintf( __( 'Memoria de PHP baja (%s; se recomiendan 256 MB o más)', 'imagina-turbo' ), $facts['memory_limit'] );
		}
		if ( $facts['max_execution_time'] > 0 && $facts['max_execution_time'] < 60 ) {
			/* translators: %d: segundos. */
			$restrictions[] = sprintf( __( 'Tiempo máximo de PHP corto (%d s)', 'imagina-turbo' ), $facts['max_execution_time'] );
		}

		return [
			'panel'        => $panel,
			'cloudlinux'   => $cloudlinux,
			'restrictions' => $restrictions,
			'signals'      => $signals,
			'shared'       => $cloudlinux || in_array( $panel, [ 'cpanel', 'directadmin' ], true ),
		];
	}

	/**
	 * Lo que se ve del servidor real (admin y compilación).
	 *
	 * @return Facts
	 */
	public static function facts(): array {
		$path     = defined( 'ABSPATH' ) ? (string) ABSPATH : '';
		$basedir  = (string) ini_get( 'open_basedir' );
		$disabled = array_values( array_filter( array_map( 'trim', explode( ',', strtolower( (string) ini_get( 'disable_functions' ) ) ) ) ) );
		$home     = 1 === preg_match( '#^(/home\d*/[^/]+)/#', $path, $m ) ? $m[1] : '';
		return [
			'path'               => $path,
			'uname'              => function_exists( 'php_uname' ) && ! in_array( 'php_uname', $disabled, true ) ? (string) php_uname( 'r' ) : '',
			'open_basedir'       => $basedir,
			'disabled'           => $disabled,
			// Con open_basedir, mirar fuera de lo permitido avisa: solo se mira si está permitido.
			'cloudlinux_file'    => self::allowed( '/etc/cloudlinux-release', $basedir ) && @is_file( '/etc/cloudlinux-release' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- archivo del sistema, puede no ser accesible.
			'cpanel_dir'         => '' !== $home && self::allowed( $home . '/.cpanel', $basedir ) && @is_dir( $home . '/.cpanel' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- idem.
			'memory_limit'       => (string) ini_get( 'memory_limit' ),
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
		];
	}

	/**
	 * @return Detection
	 */
	public static function detect(): array {
		return self::evaluate( self::facts() );
	}

	/**
	 * Perfil que se aplica: el elegido, o el detectado en automático.
	 */
	public static function effective( string $setting, bool $detectedShared ): string {
		return match ( $setting ) {
			'shared'    => 'shared',
			'dedicated' => 'dedicated',
			default     => $detectedShared ? 'shared' : 'dedicated',
		};
	}

	/**
	 * Para la config compilada.
	 *
	 * @return array{shared: bool, panel: string}
	 */
	public static function compile( string $setting ): array {
		$detected = self::detect();
		return [
			'shared' => 'shared' === self::effective( $setting, $detected['shared'] ),
			'panel'  => $detected['panel'],
		];
	}

	/**
	 * Perfil compartido según la config compilada (recalentado, en cron).
	 */
	public static function compiledShared(): bool {
		$hosting = Runtime::$config?->get( 'hosting', [] );
		return is_array( $hosting ) && true === ( $hosting['shared'] ?? false );
	}

	/**
	 * En cada wp-cron.php se anota la hora (como mucho cada 5 minutos: una lectura y, a veces, una escritura).
	 */
	public static function noteCron(): void {
		$last = (int) get_option( self::CRON_OPTION, 0 );
		if ( time() - $last >= 300 ) {
			update_option( self::CRON_OPTION, time(), false );
		}
	}

	/**
	 * Comandos para la tarea del sistema: por HTTP y con PHP (sin pasar por la red ni por Cloudflare).
	 *
	 * @return array{http: string, php: string}
	 */
	public static function cronCommands( string $siteUrl, string $abspath ): array {
		$cron = rtrim( $siteUrl, '/' ) . '/wp-cron.php?doing_wp_cron';
		return [
			'http' => '*/5 * * * * curl -fsS ' . $cron . ' >/dev/null 2>&1',
			'php'  => '*/5 * * * * cd ' . rtrim( $abspath, '/' ) . ' && php -q wp-cron.php >/dev/null 2>&1',
		];
	}

	/**
	 * Dónde se crea la tarea en cada panel.
	 */
	public static function cronSteps( string $panel ): string {
		return match ( $panel ) {
			'cpanel'      => __( 'cPanel → Avanzado → Trabajos de cron (Cron Jobs) → «Una vez cada cinco minutos» → pega el comando → Agregar.', 'imagina-turbo' ),
			'directadmin' => __( 'DirectAdmin → Funciones avanzadas → Trabajos de cron → cada 5 minutos (*/5) → pega el comando.', 'imagina-turbo' ),
			'plesk'       => __( 'Plesk → el dominio → Tareas programadas → Añadir tarea → «Recuperar una URL» con la dirección de wp-cron.php, cada 5 minutos.', 'imagina-turbo' ),
			default       => __( 'En el panel del hosting, sección de tareas programadas o cron: una tarea cada 5 minutos con el comando.', 'imagina-turbo' ),
		};
	}

	/**
	 * Alertas del cron (función pura).
	 *
	 * @return list<array{level: string, code: string, message: string}>
	 */
	public static function cronAlerts( bool $edgeHtml, bool $cronDisabled, int $lastCron, int $watchSince, int $now, string $panel ): array {
		if ( ! $cronDisabled && $edgeHtml ) {
			return [
				[
					'level'   => 'warning',
					'code'    => 'cron_visits',
					'message' => __( 'Cloudflare sirve el HTML, así que casi ninguna visita llega a WordPress y WP-Cron, que corre con las visitas, se atrasa: purgas, recalentado y tareas programadas llegan tarde. Crea una tarea del sistema cada 5 minutos (comando en Ajustes rápidos → Cron del sistema) y después define DISABLE_WP_CRON en wp-config.php.', 'imagina-turbo' ) . ' ' . self::cronSteps( $panel ),
				],
			];
		}
		$silentSince = $lastCron > 0 ? $lastCron : $watchSince;
		if ( $cronDisabled && $silentSince > 0 && $now - $silentSince > self::CRON_STALE ) {
			$minutes = (int) floor( ( $now - $silentSince ) / 60 );
			return [
				[
					'level'   => 'error',
					'code'    => 'cron_stalled',
					'message' => sprintf(
						/* translators: %d: minutos. */
						__( 'WP-Cron está desactivado (DISABLE_WP_CRON) y wp-cron.php no se ejecuta desde hace %d minutos: la tarea del sistema no está corriendo. Purgas, recalentado y tareas programadas están detenidos. Revisa la tarea en el panel del hosting (comandos en Ajustes rápidos → Cron del sistema).', 'imagina-turbo' ),
						$minutes
					) . ' ' . self::cronSteps( $panel ),
				],
			];
		}
		return [];
	}

	/**
	 * Clasifica la respuesta de una petición del sitio a sí mismo (función pura).
	 *
	 * @param array<string, string> $headers Cabeceras en minúsculas.
	 * @return array{status: string, message: string}
	 */
	public static function classify( string $id, int $code, array $headers, string $body, string $error ): array {
		$cloudflare = isset( $headers['cf-ray'] ) || str_contains( strtolower( $headers['server'] ?? '' ), 'cloudflare' );
		// admin-ajax sin acción responde 400 con «0»; la REST puede negarse a anónimos con su propio JSON: WordPress respondió.
		$wordpress = ( 'ajax' === $id && 400 === $code && '0' === trim( $body ) ) || ( 'rest' === $id && in_array( $code, [ 401, 403 ], true ) && str_contains( $body, '"code"' ) );
		return match ( true ) {
			0 === $code => [
				'status'  => 'connection',
				/* translators: %s: error de la conexión. */
				'message' => sprintf( __( 'El servidor no consigue conectarse a su propio dominio (%s).', 'imagina-turbo' ), '' !== $error ? $error : __( 'sin respuesta', 'imagina-turbo' ) ),
			],
			( $code >= 200 && $code < 300 ) || $wordpress => [
				'status'  => 'ok',
				'message' => __( 'Responde.', 'imagina-turbo' ),
			],
			$code >= 300 && $code < 400 => [
				'status'  => 'redirect',
				/* translators: %s: destino de la redirección. */
				'message' => sprintf( __( 'Redirige a %s.', 'imagina-turbo' ), '' !== ( $headers['location'] ?? '' ) ? $headers['location'] : '—' ),
			],
			401 === $code => [
				'status'  => 'auth',
				'message' => __( 'Pide usuario y contraseña (protección de directorio del hosting).', 'imagina-turbo' ),
			],
			508 === $code => [
				'status'  => 'limit',
				'message' => __( 'El hosting está al límite de recursos de la cuenta (508, CloudLinux).', 'imagina-turbo' ),
			],
			// Un desafío de Cloudflare lleva cf-mitigated: challenge (documentado). Un 403 que pasó por Cloudflare
			// puede venir del hosting o de una regla de la zona: no se culpa a ninguno sin esa marca.
			'challenge' === strtolower( $headers['cf-mitigated'] ?? '' ) => [
				'status'  => 'cloudflare',
				'message' => __( 'Cloudflare desafía al propio servidor (Bot Fight Mode o una regla de la zona).', 'imagina-turbo' ),
			],
			403 === $code => [
				'status'  => 'forbidden',
				'message' => $cloudflare
					? __( 'Respuesta 403: lo bloquea el firewall del hosting (ModSecurity u otro) o una regla de Cloudflare.', 'imagina-turbo' )
					: __( 'Respuesta 403: lo bloquea el firewall del hosting (ModSecurity u otro).', 'imagina-turbo' ),
			],
			429 === $code || 503 === $code => [
				'status'  => 'busy',
				/* translators: %d: código HTTP. */
				'message' => sprintf( __( 'El servidor rechaza peticiones por exceso de uso (%d).', 'imagina-turbo' ), $code ),
			],
			default => [
				'status'  => 'error',
				/* translators: %d: código HTTP. */
				'message' => sprintf( __( 'Error del servidor (%d).', 'imagina-turbo' ), $code ),
			],
		};
	}

	/**
	 * Funciones que dependen de cada prueba.
	 *
	 * @return array<string, string>
	 */
	public static function affects(): array {
		return [
			'home' => __( 'Recalentado, auditoría de caché, verificación de fragmentos, prueba de las reglas del .htaccess, inspector de assets, detección de la caché del servidor y medición de páginas del conector.', 'imagina-turbo' ),
			'rest' => __( 'Experimentos de carga condicional en rutas REST.', 'imagina-turbo' ),
			'ajax' => __( 'Experimentos de carga condicional en admin-ajax y wc-ajax.', 'imagina-turbo' ),
			'cron' => __( 'WP-Cron no puede lanzarse solo con las visitas: purgas, recalentado y tareas programadas dependen de una tarea del sistema con PHP.', 'imagina-turbo' ),
		];
	}

	/**
	 * Qué pedir o hacer según el fallo.
	 */
	public static function advice( string $status, string $serverIp ): string {
		$ip = '' !== $serverIp ? $serverIp : __( 'la del servidor', 'imagina-turbo' );
		return match ( $status ) {
			/* translators: %s: IP. */
			'connection' => sprintf( __( 'Pide al hosting que el servidor pueda conectarse a su propio dominio (loopback; IP %s). Mientras tanto, usa la tarea del sistema con PHP para WP-Cron.', 'imagina-turbo' ), $ip ),
			/* translators: %s: IP. */
			'forbidden'  => sprintf( __( 'Pide al hosting que excluya la IP del servidor (%s) de ModSecurity o de su firewall para este dominio. Si el sitio está en Cloudflare, revisa también el registro de eventos de seguridad de la zona.', 'imagina-turbo' ), $ip ),
			/* translators: %s: IP. */
			'cloudflare' => sprintf( __( 'En Cloudflare, revisa Bot Fight Mode y las reglas de la zona para que dejen pasar al servidor (IP %s). Las excepciones del Firewall de Imagina Turbo solo cubren sus propias reglas.', 'imagina-turbo' ), $ip ),
			'auth'       => __( 'Quita la protección con contraseña del directorio o pide al hosting que la excluya para el propio servidor.', 'imagina-turbo' ),
			'limit', 'busy' => __( 'El plan está al límite de recursos. Las tareas de Imagina Turbo se pausan solas cuando pasa; si se repite, el plan se queda corto para el tráfico del sitio.', 'imagina-turbo' ),
			'redirect'   => __( 'Revisa que la dirección del sitio (Ajustes → Generales) coincida con la que usa el dominio (http/https, con o sin www).', 'imagina-turbo' ),
			default      => '',
		};
	}

	/**
	 * Prueba las peticiones del sitio a sí mismo como visitante y guarda el resultado.
	 *
	 * @return array{at: int, ok: bool, server_ip: string, checks: list<Check>}
	 */
	public static function loopback(): array {
		$checks = [
			'home' => [ 'GET', home_url( '/?itb_loopback=' . wp_rand( 1000, 999999 ) ), [] ],
			'rest' => [ 'GET', rest_url(), [] ],
			'ajax' => [ 'POST', admin_url( 'admin-ajax.php' ), [ 'action' => 'itb_loopback_test' ] ],
			'cron' => [ 'GET', site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ), [] ],
		];
		$out    = [];
		foreach ( $checks as $id => [ $method, $url, $body ] ) {
			$start    = microtime( true );
			$response = wp_remote_request(
				$url,
				[
					'method'      => $method,
					'timeout'     => 10,
					'redirection' => 0,
					'user-agent'  => Warmer::USER_AGENT,
					'body'        => $body,
					'cookies'     => [],
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
				]
			);
			$headers  = [];
			$code     = 0;
			$text     = '';
			$error    = '';
			if ( is_wp_error( $response ) ) {
				$error = $response->get_error_message();
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$text = substr( (string) wp_remote_retrieve_body( $response ), 0, 2000 );
				foreach ( [ 'server', 'cf-ray', 'cf-mitigated', 'location' ] as $name ) {
					$value = wp_remote_retrieve_header( $response, $name );
					$value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
					if ( '' !== $value ) {
						$headers[ $name ] = substr( sanitize_text_field( $value ), 0, 200 );
					}
				}
			}
			$result = self::classify( $id, $code, $headers, $text, substr( sanitize_text_field( $error ), 0, 200 ) );
			$out[]  = [
				'id'      => $id,
				'url'     => strtok( $url, '?' ) ?: $url,
				'status'  => $result['status'],
				'code'    => $code,
				'ms'      => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'message' => $result['message'],
			];
		}
		$serverIp = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
		$report   = [
			'at'        => time(),
			'ok'        => [] === array_filter( $out, static fn( array $c ): bool => 'ok' !== $c['status'] ),
			'server_ip' => false !== filter_var( $serverIp, FILTER_VALIDATE_IP ) ? $serverIp : '',
			'checks'    => $out,
		];
		update_option( self::LOOPBACK_OPTION, $report, false );
		return $report;
	}

	/**
	 * Último resultado de la prueba.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function lastLoopback(): ?array {
		$value = get_option( self::LOOPBACK_OPTION, null );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Alertas del perfil: cron, prueba de loopback fallida (últimos 7 días) y recalentado frenado por el
	 * hosting (últimas 24 h).
	 *
	 * @param bool                $edgeHtml El HTML se guarda en Cloudflare (coordinador en modo cabeceras).
	 * @param array<string, mixed> $warmer  Estado del recalentado.
	 * @return list<array{level: string, code: string, message: string}>
	 */
	public static function alerts( bool $edgeHtml, array $warmer ): array {
		$watch = (int) get_option( self::CRON_WATCH_OPTION, 0 );
		if ( 0 === $watch ) {
			$watch = time();
			update_option( self::CRON_WATCH_OPTION, $watch, false );
		}
		$panel  = self::detect()['panel'];
		$alerts = self::cronAlerts( $edgeHtml, defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, (int) get_option( self::CRON_OPTION, 0 ), $watch, time(), $panel );

		$loopback = self::lastLoopback();
		if ( null !== $loopback && false === ( $loopback['ok'] ?? true ) && (int) ( $loopback['at'] ?? 0 ) > time() - 7 * DAY_IN_SECONDS ) {
			$failed = [];
			foreach ( is_array( $loopback['checks'] ?? null ) ? $loopback['checks'] : [] as $check ) {
				if ( is_array( $check ) && 'ok' !== ( $check['status'] ?? 'ok' ) ) {
					$failed[] = (string) ( $check['id'] ?? '' );
				}
			}
			$alerts[] = [
				'level'   => 'warning',
				'code'    => 'loopback',
				/* translators: %s: pruebas fallidas. */
				'message' => sprintf( __( 'El sitio no puede llamarse a sí mismo (%s): algunas funciones quedan limitadas. Detalle y qué pedir al hosting en Diagnóstico → Hosting.', 'imagina-turbo' ), implode( ', ', $failed ) ),
			];
		}

		$limitedAt = (int) ( $warmer['limited_at'] ?? 0 );
		if ( $limitedAt > time() - DAY_IN_SECONDS ) {
			$alerts[] = [
				'level'   => 'info',
				'code'    => 'warm_limited',
				/* translators: %d: código HTTP. */
				'message' => sprintf( __( 'El hosting frenó el recalentado (HTTP %d): se pausó y se reanuda solo. Si se repite, el plan está al límite de recursos.', 'imagina-turbo' ), (int) ( $warmer['limited_code'] ?? 0 ) ),
			];
		}
		return $alerts;
	}

	/**
	 * La ruta está dentro de open_basedir (o no hay open_basedir).
	 */
	private static function allowed( string $path, string $basedir ): bool {
		if ( '' === trim( $basedir ) ) {
			return true;
		}
		foreach ( explode( PATH_SEPARATOR, $basedir ) as $dir ) {
			$dir = trim( $dir );
			if ( '' !== $dir && str_starts_with( $path, rtrim( $dir, '/' ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * «256M» → bytes (-1 = sin límite → 0).
	 */
	private static function bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value || '-1' === $value ) {
			return 0;
		}
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (int) $value;
		return match ( $unit ) {
			'g' => $number * 1024 * 1024 * 1024,
			'm' => $number * 1024 * 1024,
			'k' => $number * 1024,
			default => $number,
		};
	}
}
