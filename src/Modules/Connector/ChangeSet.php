<?php
/**
 * Lotes de cambios del conector con prueba y vuelta atrás automática (regla 3: nada sensible por fe).
 *
 * 1. Guarda una copia de todos los ajustes (itb_settings incluye módulos, ajustes rápidos, scripts y
 *    navegación: todo lo que el conector puede tocar).
 * 2. Mide las páginas clave del sitio saltando la caché de página (las mismas del verificador).
 * 3. Aplica los pasos con `confirm` por las rutas del admin (las mismas validaciones).
 * 4. Vuelve a medir. Si una página deja de responder bien, muestra un error de PHP, queda casi vacía
 *    o tarda mucho más (medido dos veces, para no confundir un pico con un fallo), restaura la copia.
 * 5. Purga la caché de páginas para que los visitantes vean el resultado, sea cual sea.
 *
 * Un lote a la vez (bloqueo de 3 minutos). Los últimos 20 quedan en itb_connector_changes con la copia
 * para deshacerlos después.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Connector;

use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Modules\CacheCoordinator\PurgeRequest;
use ImaginaTurbo\Modules\CacheCoordinator\Purger;
use ImaginaTurbo\Verifier\VerifierService;

/**
 * Aplicar, comprobar y deshacer.
 *
 * @phpstan-type Measure array{path: string, status: int, ms: int, bytes: int, fatal: bool, headers: array<string, string>}
 */
final class ChangeSet {

	public const OPTION = 'itb_connector_changes';

	private const KEEP = 20;

	private const LOCK = 'itb_connector_lock';

	/** Lotes (y deshacer) por hora: cada uno purga la caché entera. */
	private const PER_HOUR = 6;

	/** Claves de los ajustes que son mapas: se deshacen por entrada. */
	private const MAPS = [ 'modules', 'tweaks' ];

	/** Páginas que se miden en cada lote. */
	private const PAGES = 6;

	/** Segundos como máximo desde que empieza el lote hasta terminar de medir. */
	private const DEADLINE = 120;

	/** Un lote pendiente más viejo que esto murió a mitad: se deshace. */
	private const STALE = 360;

	public const USER_AGENT = 'ImaginaTurbo-Connector/1.0';

	/** Cabeceras que se devuelven al medir. */
	private const HEADERS = [ 'x-litespeed-cache', 'x-litespeed-cache-control', 'x-qc-cache', 'cf-cache-status', 'cache-control', 'age', 'server-timing', 'x-itb-probe', 'x-itb-shield', 'content-type', 'location' ];

	/** @var \Closure(string, bool): (Measure|string)|null Medición simulada (pruebas). */
	private static ?\Closure $measurer = null;

	public function __construct( private readonly Container $container ) {
	}

	/**
	 * Aplica un lote.
	 *
	 * @param list<mixed>                                         $steps Pasos.
	 * @param array{id: int, client_name: string, scope: string} $grant Acceso.
	 * @return array<string, mixed>
	 */
	public function apply( string $summary, array $steps, array $grant ): array {
		$summary = sanitize_text_field( $summary );
		$steps   = self::validSteps( $steps );
		if ( is_string( $steps ) ) {
			return [
				'result' => 'rejected',
				'error'  => $steps,
			];
		}
		$this->recover();
		if ( ! self::lock() ) {
			return [
				'result' => 'rejected',
				'error'  => 'Hay otro lote en curso. Espera a que termine.',
			];
		}
		// Cada lote purga la caché entera: como mucho seis lotes (o deshacer) por hora.
		if ( ! Connector::budget( 'changes', self::PER_HOUR, HOUR_IN_SECONDS ) ) {
			self::unlock();
			return [
				'result' => 'rejected',
				'error'  => 'Ya se aplicaron varios lotes en la última hora. Espera antes de seguir: cada lote purga la caché del sitio.',
			];
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- el lote mide páginas antes y después.
		}
		// Si Claude o Cloudflare cortan la conexión, el lote termina igual: se comprueba o se deshace.
		ignore_user_abort( true );
		try {
			return $this->run( $summary, $steps, $grant );
		} finally {
			self::unlock();
		}
	}

	/**
	 * @param list<array{area?: string, changes?: array<string, mixed>, module?: string, enabled?: bool}> $steps Pasos válidos.
	 * @param array{id: int, client_name: string, scope: string}                                          $grant Acceso.
	 * @return array<string, mixed>
	 */
	private function run( string $summary, array $steps, array $grant ): array {
		$settings = $this->container->get( Settings::class );
		$snapshot = $settings->fresh();
		$start    = microtime( true );
		$id       = substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
		// Antes de tocar nada queda registrado como pendiente con los valores de antes: si el proceso
		// muere a mitad, el siguiente lote, el historial o el admin lo deshacen (recover()).
		$this->save(
			[
				'id'      => $id,
				'at'      => time(),
				'summary' => $summary,
				'steps'   => $steps,
				'result'  => 'pending',
				'client'  => $grant['client_name'],
				'user'    => get_current_user_id(),
				'before'  => self::touched( $snapshot, $steps ),
			]
		);
		$pages  = self::pages();
		$before = [];
		foreach ( $pages as $page ) {
			$before[ $page['url'] ] = self::measure( $page['url'], true );
		}

		$applied = [];
		$error   = '';
		foreach ( $steps as $i => $step ) {
			if ( isset( $step['module'] ) ) {
				$result = Tools::rest(
					'PUT',
					'/modules/' . $step['module'],
					[
						'enabled' => (bool) ( $step['enabled'] ?? false ),
						'confirm' => true,
					]
				);
			} else {
				$route  = Tools::writeRoute( $step['area'] ?? '' ) ?? [ 'PUT', '/' ];
				$result = Tools::rest( $route[0], $route[1], (array) ( $step['changes'] ?? [] ) + [ 'confirm' => true ] );
			}
			if ( ! $result['ok'] ) {
				$data  = $result['data'];
				$error = sprintf( 'El paso %d no se aplicó: %s', $i + 1, is_array( $data ) && is_string( $data['error'] ?? null ) ? $data['error'] : 'error' );
				break;
			}
			$applied[] = $step;
		}

		$reasons = [];
		$after   = [];
		if ( '' === $error ) {
			foreach ( $pages as $page ) {
				// Plazo total: un cambio que hace que el sitio no responda no puede dejar el lote colgado.
				if ( microtime( true ) - $start > self::DEADLINE ) {
					$reasons[] = $page['label'] . ' (' . $page['url'] . '): el sitio tardó demasiado en responder';
					break;
				}
				$now = self::measure( $page['url'], true );
				$was = $before[ $page['url'] ];
				$why = self::regression( $was, $now );
				if ( null !== $why && str_contains( $why, 'lenta' ) ) {
					// Un pico de tiempo se mide otra vez antes de dar el lote por malo.
					$again = self::measure( $page['url'], true );
					$why   = self::regression( $was, $again );
					$now   = $again;
				}
				$after[ $page['url'] ] = $now;
				if ( null !== $why ) {
					$reasons[] = $page['label'] . ' (' . $page['url'] . '): ' . $why;
				}
			}
		}

		// Solo lo que este lote cambió, con el valor de antes y el de después.
		$undo     = self::undo( $snapshot, $settings->fresh() );
		$reverted = '' !== $error || [] !== $reasons;
		if ( $reverted ) {
			$this->restore( $undo );
		}
		$this->container->get( Purger::class )->purge( PurgeRequest::all( 'Claude: lote de cambios' ) );

		$this->save(
			[
				'id'      => $id,
				'at'      => time(),
				'summary' => $summary,
				'steps'   => $steps,
				'result'  => $reverted ? 'reverted' : 'applied',
				'reasons' => '' !== $error ? [ $error ] : $reasons,
				'client'  => $grant['client_name'],
				'user'    => get_current_user_id(),
				'undo'    => $undo,
			]
		);
		$this->container->get( EventLog::class )->warning(
			'connector',
			$reverted ? 'change_reverted' : 'change_applied',
			$reverted
				/* translators: %s: resumen del lote */
				? sprintf( __( 'Claude intentó un cambio y se deshizo solo: %s', 'imagina-turbo' ), $summary )
				/* translators: %s: resumen del lote */
				: sprintf( __( 'Claude aplicó un cambio probado en las páginas clave: %s', 'imagina-turbo' ), $summary ),
			[
				'user'    => get_current_user_id(),
				'client'  => $grant['client_name'],
				'change'  => $id,
				'steps'   => $steps,
				'reasons' => '' !== $error ? [ $error ] : $reasons,
			]
		);

		return [
			'result'  => $reverted ? 'reverted' : 'applied',
			'id'      => $id,
			'reasons' => '' !== $error ? [ $error ] : $reasons,
			'pages'   => array_map(
				static fn( array $page ): array => [
					'label'  => $page['label'],
					'path'   => $page['url'],
					'before' => self::short( $before[ $page['url'] ] ?? null ),
					'after'  => self::short( $after[ $page['url'] ] ?? null ),
				],
				$pages
			),
			'note'    => $reverted ? 'Los ajustes volvieron a como estaban.' : 'Para deshacerlo: revert_change con este id.',
		];
	}

	/**
	 * Deshace un lote aplicado.
	 *
	 * @param array{id: int, client_name: string, scope: string} $grant Acceso.
	 */
	public function revert( string $id, array $grant ): bool {
		$this->recover();
		$list = get_option( self::OPTION, [] );
		$list = is_array( $list ) ? $list : [];
		foreach ( $list as $i => $item ) {
			if ( is_array( $item ) && ( $item['id'] ?? null ) === $id && 'applied' === ( $item['result'] ?? '' ) && is_array( $item['undo'] ?? null ) ) {
				if ( ! Connector::budget( 'changes', self::PER_HOUR, HOUR_IN_SECONDS ) ) {
					return false;
				}
				$this->restore( $item['undo'] );
				$this->container->get( Purger::class )->purge( PurgeRequest::all( 'Claude: deshacer un lote' ) );
				$list[ $i ]['result']    = 'undone';
				$list[ $i ]['undone_at'] = time();
				update_option( self::OPTION, $list, false );
				$this->container->get( EventLog::class )->warning(
					'connector',
					'change_undone',
					/* translators: %s: resumen del lote */
					sprintf( __( 'Claude deshizo un cambio: %s', 'imagina-turbo' ), (string) ( $item['summary'] ?? '' ) ),
					[
						'user'   => get_current_user_id(),
						'client' => $grant['client_name'],
						'change' => $id,
					]
				);
				return true;
			}
		}
		return false;
	}

	/**
	 * Historial sin las copias.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function history(): array {
		$this->recover();
		$list = get_option( self::OPTION, [] );
		$out  = [];
		foreach ( array_reverse( is_array( $list ) ? $list : [] ) as $item ) {
			if ( is_array( $item ) ) {
				unset( $item['undo'], $item['snapshot'], $item['before'] );
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Pasos válidos, o el motivo del rechazo.
	 *
	 * @param list<mixed> $steps Pasos.
	 * @return list<array{area?: string, changes?: array<string, mixed>, keys?: list<string>, module?: string, enabled?: bool}>|string
	 */
	public static function validSteps( array $steps ): array|string {
		if ( [] === $steps || count( $steps ) > 5 ) {
			return 'Entre 1 y 5 pasos por lote.';
		}
		$out = [];
		foreach ( $steps as $i => $step ) {
			if ( ! is_array( $step ) ) {
				return sprintf( 'El paso %d no es válido.', $i + 1 );
			}
			if ( isset( $step['module'] ) ) {
				if ( ! in_array( $step['module'], Tools::CHANGE_SET_MODULES, true ) || ! is_bool( $step['enabled'] ?? null ) ) {
					return sprintf( 'El paso %d: ese módulo no se cambia desde el conector (protecciones, Cloudflare y carga condicional se cambian en el admin).', $i + 1 );
				}
				$out[] = [
					'module'  => (string) $step['module'],
					'enabled' => $step['enabled'],
				];
				continue;
			}
			$area  = is_string( $step['area'] ?? null ) ? $step['area'] : '';
			$valid = null === Tools::writeRoute( $area ) ? 'área no ajustable desde el conector.' : Tools::validate( $area, $step['changes'] ?? null, true );
			if ( is_string( $valid ) ) {
				return sprintf( 'El paso %d: %s', $i + 1, $valid );
			}
			$out[] = [
				'area'    => $area,
				'changes' => $valid['params'],
				'keys'    => $valid['keys'],
			];
		}
		return $out;
	}

	/**
	 * Motivo por el que la página empeoró, o null.
	 *
	 * @param Measure|string $before Antes.
	 * @param Measure|string $after  Después.
	 */
	public static function regression( array|string $before, array|string $after ): ?string {
		if ( ! is_array( $before ) ) {
			return null; // Sin medida de antes no hay con qué comparar.
		}
		if ( ! is_array( $after ) ) {
			return 'no respondió (' . $after . ')';
		}
		if ( $after['fatal'] && ! $before['fatal'] ) {
			return 'muestra un error de PHP';
		}
		$okBefore = $before['status'] < 400 || 404 === $before['status'];
		if ( $okBefore && $after['status'] !== $before['status'] && ( $after['status'] >= 500 || ( $before['status'] < 400 && $after['status'] >= 400 ) ) ) {
			return sprintf( 'pasó de %d a %d', $before['status'], $after['status'] );
		}
		if ( $before['bytes'] > 2000 && $after['bytes'] < $before['bytes'] / 2 ) {
			return sprintf( 'quedó casi vacía (%d → %d bytes)', $before['bytes'], $after['bytes'] );
		}
		if ( $after['ms'] > max( 2 * $before['ms'], $before['ms'] + 1500 ) ) {
			return sprintf( 'mucho más lenta (%d → %d ms)', $before['ms'], $after['ms'] );
		}
		return null;
	}

	/**
	 * Mide una página del sitio como visitante anónimo.
	 *
	 * @return Measure|string Medida o motivo del error.
	 */
	public static function measure( string $path, bool $fresh ): array|string {
		$path = trim( $path );
		if ( '' === $path || '/' !== $path[0] || str_starts_with( $path, '//' ) || str_contains( $path, '\\' ) || strlen( $path ) > 300 || 1 === preg_match( '/[\x00-\x20\x7F]/', $path ) ) {
			return 'Ruta no válida: tiene que empezar por / y ser del propio sitio.';
		}
		if ( null !== self::$measurer ) {
			return ( self::$measurer )( $path, $fresh );
		}
		$url = home_url( $path );
		if ( $fresh ) {
			$url = add_query_arg( 'itb_nc', bin2hex( random_bytes( 4 ) ), $url );
		}
		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			[
				'timeout'             => 10,
				'redirection'         => 0,
				'user-agent'          => self::USER_AGENT,
				'limit_response_size' => 4194304,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
			]
		);
		$ms       = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$body    = (string) wp_remote_retrieve_body( $response );
		$headers = [];
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$name = strtolower( (string) $name );
			if ( in_array( $name, self::HEADERS, true ) || str_starts_with( $name, 'x-itb-' ) ) {
				$headers[ $name ] = substr( is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value, 0, 300 );
			}
		}
		return [
			'path'    => $path,
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'ms'      => $ms,
			'bytes'   => strlen( $body ),
			'fatal'   => self::fatal( $body ),
			'headers' => $headers,
		];
	}

	/**
	 * La página muestra un error fatal de PHP o la pantalla de error crítico de WordPress.
	 */
	public static function fatal( string $body ): bool {
		return str_contains( $body, 'There has been a critical error' ) || str_contains( $body, 'Ha habido un error crítico' ) || 1 === preg_match( '/<b>(Fatal|Parse) error<\/b>|PHP (Fatal|Parse) error:/', $body );
	}

	/**
	 * Medición simulada (pruebas).
	 *
	 * @param \Closure(string, bool): (Measure|string)|null $measurer Medición.
	 */
	public static function useMeasurer( ?\Closure $measurer ): void {
		self::$measurer = $measurer;
	}

	/**
	 * Páginas clave (sin la que debe dar 404, que no cambia con los ajustes).
	 *
	 * @return list<array{label: string, url: string}>
	 */
	private static function pages(): array {
		$out = [];
		foreach ( VerifierService::defaultPages() as $page ) {
			if ( 404 !== ( $page['expect'] ?? 200 ) ) {
				$out[] = [
					'label' => $page['label'],
					'url'   => $page['url'],
				];
			}
		}
		return array_slice( $out, 0, self::PAGES );
	}

	/**
	 * Lo que cambió entre dos estados de los ajustes: por clave, [antes, después]. En los mapas
	 * (módulos y ajustes rápidos) solo las entradas que cambiaron.
	 *
	 * @param array<string, mixed> $before Antes.
	 * @param array<string, mixed> $after  Después.
	 * @return array<string, mixed>
	 */
	public static function undo( array $before, array $after ): array {
		$out = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			$was = $before[ $key ] ?? null;
			$now = $after[ $key ] ?? null;
			if ( $was === $now ) {
				continue;
			}
			if ( in_array( $key, self::MAPS, true ) && ( is_array( $was ) || null === $was ) && ( is_array( $now ) || null === $now ) ) {
				$entries = [];
				foreach ( array_unique( array_merge( array_keys( (array) $was ), array_keys( (array) $now ) ) ) as $entry ) {
					if ( ( $was[ $entry ] ?? null ) !== ( $now[ $entry ] ?? null ) ) {
						$entries[ $entry ] = [ $was[ $entry ] ?? null, $now[ $entry ] ?? null ];
					}
				}
				$out[ $key ] = [ 'map' => $entries ];
				continue;
			}
			$out[ $key ] = [ 'value' => [ $was, $now ] ];
		}
		return $out;
	}

	/**
	 * Cambios que deshacen un lote sobre los ajustes actuales. Una clave que alguien cambió después
	 * (ya no tiene el valor que dejó el lote) no se toca: deshacer nunca pisa lo que hizo el dueño.
	 *
	 * @param array<string, mixed> $undo    Resultado de undo().
	 * @param array<string, mixed> $current Ajustes actuales.
	 * @return array<string, mixed>
	 */
	public static function restoration( array $undo, array $current ): array {
		$changes = [];
		foreach ( $undo as $key => $diff ) {
			if ( ! is_string( $key ) || ! is_array( $diff ) ) {
				continue;
			}
			if ( isset( $diff['map'] ) && is_array( $diff['map'] ) ) {
				$map     = is_array( $current[ $key ] ?? null ) ? $current[ $key ] : [];
				$touched = false;
				foreach ( $diff['map'] as $entry => $pair ) {
					if ( is_array( $pair ) && ( $map[ $entry ] ?? null ) === ( $pair[1] ?? null ) ) {
						if ( null === ( $pair[0] ?? null ) ) {
							unset( $map[ $entry ] );
						} else {
							$map[ $entry ] = $pair[0];
						}
						$touched = true;
					}
				}
				if ( $touched ) {
					$changes[ $key ] = $map;
				}
				continue;
			}
			$pair = $diff['value'] ?? null;
			if ( is_array( $pair ) && ( $current[ $key ] ?? null ) === ( $pair[1] ?? null ) ) {
				$changes[ $key ] = $pair[0] ?? null;
			}
		}
		return $changes;
	}

	/**
	 * @param array<string, mixed> $undo Resultado de undo().
	 */
	private function restore( array $undo ): void {
		$settings = $this->container->get( Settings::class );
		$changes  = self::restoration( $undo, $settings->all() );
		if ( [] !== $changes ) {
			$settings->update( $changes );
		}
		$this->container->get( ConfigCompiler::class )->compile();
	}

	/**
	 * Guarda un lote (reemplaza el del mismo id).
	 *
	 * @param array<string, mixed> $entry Lote.
	 */
	private function save( array $entry ): void {
		$list  = get_option( self::OPTION, [] );
		$list  = is_array( $list ) ? array_values( $list ) : [];
		$found = false;
		foreach ( $list as $i => $item ) {
			if ( is_array( $item ) && ( $item['id'] ?? null ) === $entry['id'] ) {
				$list[ $i ] = $entry;
				$found      = true;
			}
		}
		if ( ! $found ) {
			$list[] = $entry;
		}
		update_option( self::OPTION, array_slice( $list, -self::KEEP ), false );
	}

	/**
	 * Valores de antes de las claves que tocan los pasos (para deshacer un lote que murió a mitad).
	 *
	 * @param array<string, mixed>                                                      $settings Ajustes.
	 * @param list<array{keys?: list<string>, module?: string, enabled?: bool}>          $steps    Pasos.
	 * @return array<string, mixed>
	 */
	public static function touched( array $settings, array $steps ): array {
		$out = [];
		foreach ( $steps as $step ) {
			if ( isset( $step['module'] ) ) {
				$out['modules'][ $step['module'] ] = $settings['modules'][ $step['module'] ] ?? null;
				continue;
			}
			foreach ( $step['keys'] ?? [] as $key ) {
				$out[ $key ] = $settings[ $key ] ?? null;
			}
		}
		return $out;
	}

	/**
	 * Deshace los lotes pendientes que murieron a mitad (más viejos que STALE).
	 */
	public function recover(): void {
		$list = get_option( self::OPTION, [] );
		if ( ! is_array( $list ) ) {
			return;
		}
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || 'pending' !== ( $item['result'] ?? '' ) || (int) ( $item['at'] ?? 0 ) > time() - self::STALE ) {
				continue;
			}
			$before  = is_array( $item['before'] ?? null ) ? $item['before'] : [];
			$changes = array_filter( $before, static fn( $v ): bool => null !== $v );
			if ( isset( $changes['modules'] ) && is_array( $changes['modules'] ) ) {
				$changes['modules'] = array_filter( $changes['modules'], 'is_bool' );
			}
			if ( [] !== $changes ) {
				$this->container->get( Settings::class )->update( $changes );
				$this->container->get( ConfigCompiler::class )->compile();
				$this->container->get( Purger::class )->purge( PurgeRequest::all( 'Claude: lote interrumpido' ) );
			}
			$item['result']  = 'interrupted';
			$item['reasons'] = [ 'El lote se interrumpió a mitad: se volvió a los valores de antes.' ];
			unset( $item['before'] );
			$this->save( $item );
			$this->container->get( EventLog::class )->warning(
				'connector',
				'change_interrupted',
				/* translators: %s: resumen del lote */
				sprintf( __( 'Un cambio de Claude se interrumpió a mitad y se deshizo: %s', 'imagina-turbo' ), (string) ( $item['summary'] ?? '' ) ),
				[ 'change' => $item['id'] ?? '' ]
			);
		}
	}

	/**
	 * Bloqueo atómico entre requests (INSERT IGNORE: solo uno gana). Un bloqueo de más de 6 minutos
	 * es de un lote que murió y se libera.
	 */
	private static function lock(): bool {
		global $wpdb;
		$table = $wpdb->options;
		for ( $try = 0; $try < 2; $try++ ) {
			$inserted = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla de opciones del core; bloqueo atómico.
			if ( 1 === $inserted ) {
				return true;
			}
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- idem.
			if ( $since > time() - self::STALE ) {
				return false;
			}
			self::unlock();
		}
		return false;
	}

	private static function unlock(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- idem.
		wp_cache_delete( self::LOCK, 'options' );
	}

	/**
	 * @param Measure|string|null $m Medida.
	 * @return array<string, mixed>|string|null
	 */
	private static function short( array|string|null $m ): array|string|null {
		return is_array( $m ) ? [
			'status' => $m['status'],
			'ms'     => $m['ms'],
			'bytes'  => $m['bytes'],
			'fatal'  => $m['fatal'],
		] : $m;
	}
}
