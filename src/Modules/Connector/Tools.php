<?php
/**
 * Herramientas del conector de Claude.
 *
 * Todas pasan por las rutas REST del admin que ya existen (rest_do_request, como el usuario del token):
 * las mismas validaciones, el mismo registro y la misma confirmación de riesgo. Sin `confirm`, lo que
 * no es seguro responde 428 y la herramienta le dice a Claude que use apply_change_set, que sí lo
 * confirma pero envuelto en una prueba de las páginas clave con vuelta atrás automática.
 *
 * Lo que nunca está aquí: Cloudflare (token, zona, reglas), firewall, escudo, Turnstile, carga
 * condicional de plugins, flota, hub, verificador, kill switch, importar ajustes ni los permisos de
 * los asistentes. Cada una de esas cosas protege el sitio o abre puertas: se cambia en el admin.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Connector;

use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Integrations\Abilities;
use ImaginaTurbo\Rest\RestRegistrar;

/**
 * Definición y ejecución.
 *
 * @phpstan-type Tool array{name: string, title: string, description: string, scope: string, input: array<string, mixed>, readonly: bool, destructive?: bool}
 */
final class Tools {

	/** Áreas de lectura: ruta GET de cada una. */
	public const READ_AREAS = [
		'navigation'    => '/navigation/status',
		'tweaks'        => '/tweaks/status',
		'woo'           => '/woo/status',
		'assets'        => '/assets/status',
		'database'      => '/db/status',
		'shield'        => '/shield/status',
		'firewall'      => '/firewall/status',
		'plugin_filter' => '/plugin-filter/status',
		'cloudflare'    => '/cloudflare/status',
		'turnstile'     => '/turnstile',
		'modules'       => '/modules',
		'compat'        => '/compat/report',
	];

	/** Áreas que se pueden ajustar: método y ruta. */
	public const WRITE_AREAS = [
		'navigation' => [ 'PUT', '/navigation/settings' ],
		'tweaks'     => [ 'PUT', '/tweaks/settings' ],
		'cache'      => [ 'PUT', '/cache/settings' ],
		'woo'        => [ 'PUT', '/woo/settings' ],
		'database'   => [ 'PUT', '/db/settings' ],
		'scripts'    => [ 'POST', '/assets/scripts' ],
		'profiler'   => [ 'PUT', '/profiler/settings' ],
	];

	/** Módulos que un lote puede encender o apagar (los seguros que no protegen y los moderados de rendimiento). */
	public const CHANGE_SET_MODULES = [ 'profiler', 'tweaks', 'speculation', 'rum', 'frontend_hints', 'cache_coordinator', 'woo_cart', 'asset_manager', 'database', 'script_loading' ];

	/** Texto máximo de una respuesta (Claude recibe JSON; lo demás se recorta con aviso). */
	private const MAX_TEXT = 60000;

	/** Argumentos de una llamada, en JSON. */
	private const MAX_INPUT = 16384;

	/** Límites de las herramientas que hacen trabajo de verdad: [veces, segundos]. */
	private const LIMITS = [
		'diagnose_site'          => [ 10, 300 ],
		'measure_page'           => [ 20, 300 ],
		'run_cacheability_audit' => [ 2, 600 ],
		'update_settings'        => [ 30, 3600 ],
		'set_module'             => [ 20, 3600 ],
		'purge_cache'            => [ 20, 3600 ],
	];

	public function __construct( private readonly EventLog $events, private readonly ChangeSet $changes ) {
	}

	/**
	 * Herramientas disponibles para un acceso.
	 *
	 * @return list<Tool>
	 */
	public static function definitions( bool $adjust ): array {
		$none    = [
			'type'                 => 'object',
			'properties'           => (object) [],
			'additionalProperties' => false,
		];
		$period  = [
			'type'                 => 'object',
			'properties'           => [
				'period' => [
					'type'        => 'string',
					'enum'        => [ '1h', '24h', '48h', '7d', '30d' ],
					'default'     => '24h',
					'description' => 'Ventana de tiempo.',
				],
			],
			'additionalProperties' => false,
		];
		$area    = [
			'type'        => 'string',
			'enum'        => array_keys( self::WRITE_AREAS ),
			'description' => 'navigation: spec_mode (prefetch|prerender), spec_eagerness (conservative|moderate|eager), spec_predictive, spec_fallback, view_transitions, hints_lcp, hints_fonts, hints_facades, lazy_elements (bool), rum_rate (0-1), fonts_manual, lazy_render, lazy_documents. tweaks: tweaks {id: bool}, revisions, autosave. cache: mode (sync|headers), edge_ttl, swr, sie, nonce_refresh, warm, fragments, edge_variants. woo: count_selectors, total_selectors, recently_viewed. database: db_keep_revisions, db_schedule, db_schedule_days, db_as_retention. scripts: script_defer, script_defer_exclude, script_delay, script_delay_timeout, delay_js_scope, lsdelay_timeout. profiler: sample_rate.',
		];
		$changes = [
			'type'                 => 'object',
			'description'          => 'Claves y valores del área (los mismos nombres que devuelve get_area para esa área).',
			'additionalProperties' => true,
		];
		$tools   = [
			self::tool( 'diagnose_site', 'Diagnóstico completo', 'Empieza aquí. Estado y entorno (servidor, plugin de caché, Cloudflare), alertas ordenadas por gravedad, auditoría de la plataforma (Elementor, WooCommerce, caché de objetos, índices), estado de la caché, métricas reales de 7 días, costo de cada plugin en 24 h y peticiones por visita.', $none ),
			self::tool( 'get_status', 'Estado general', 'Versión, entorno detectado, módulos activos y apagados por solapamiento, modo seguro y alertas ordenadas por gravedad.', $none ),
			self::tool(
				'get_vitals',
				'Métricas reales (RUM)',
				'Core Web Vitals de visitantes reales: LCP, INP, CLS y TTFB en p75 por plantilla y dispositivo, desglose del TTFB, tipos de navegación, bfcache e imágenes LCP.',
				[
					'type'                 => 'object',
					'properties'           => [
						'days' => [
							'type'    => 'integer',
							'enum'    => [ 7, 30 ],
							'default' => 7,
						],
					],
					'additionalProperties' => false,
				]
			),
			self::tool( 'get_plugin_costs', 'Costo por plugin', 'Tiempo y memoria de cada plugin y fase del request, según el perfilador.', $period ),
			self::tool( 'get_slow_routes', 'Rutas lentas', 'Rutas y tipos de request más costosos.', $period ),
			self::tool( 'get_per_visit_requests', 'Peticiones por visita', 'AJAX, REST y Store API de visitantes sin sesión: cuántas hace cada página vista y cuáles conviene cortar.', $none ),
			self::tool( 'get_cache_status', 'Estado de la caché', 'Plugin de caché coordinado, cabeceras de borde, TTL, stale-while-revalidate, refresco de nonces, recalentado, caché de fragmentos y cola de purgas.', $none ),
			self::tool( 'get_platform_audit', 'Auditoría de la plataforma', 'Ajustes de Elementor, WooCommerce, WoodMart, caché de objetos e índices con lo que conviene cambiar y dónde (en el admin de cada plugin).', $none ),
			self::tool( 'get_image_audit', 'Auditoría de imágenes', 'Imágenes pesadas, sin formato moderno o sin dimensiones.', $none ),
			self::tool( 'get_cacheability_audit', 'Cacheabilidad (última)', 'Resultado de la última auditoría de cacheabilidad: qué páginas se guardan en caché y qué lo impide (cookies, cabeceras).', $none ),
			self::tool( 'run_cacheability_audit', 'Cacheabilidad (ejecutar)', 'Vuelve a pedir las páginas clave dos veces y comprueba si la caché las sirve. Tarda unos segundos. No cambia ajustes.', $none, false ),
			self::tool(
				'get_area',
				'Ajustes de un área',
				'Estado y ajustes actuales de un área de Imagina Turbo (los nombres de las claves sirven para update_settings).',
				[
					'type'                 => 'object',
					'properties'           => [
						'area' => [
							'type' => 'string',
							'enum' => array_keys( self::READ_AREAS ),
						],
					],
					'required'             => [ 'area' ],
					'additionalProperties' => false,
				]
			),
			self::tool( 'get_events', 'Registro de eventos', 'Últimos cambios y avisos (purgas, módulos, ajustes, Cloudflare, modo seguro, cambios de Claude).', $none ),
			self::tool(
				'measure_page',
				'Medir una página',
				'Pide una página del propio sitio como visitante anónimo y devuelve el código, el tiempo hasta el primer byte, el total, el tamaño y las cabeceras de caché (LiteSpeed, Cloudflare, Imagina Turbo). Con fresh=true salta la caché de página para medir PHP.',
				[
					'type'                 => 'object',
					'properties'           => [
						'path'  => [
							'type'        => 'string',
							'description' => 'Ruta del sitio, por ejemplo / o /tienda/.',
							'maxLength'   => 300,
						],
						'fresh' => [
							'type'    => 'boolean',
							'default' => false,
						],
					],
					'required'             => [ 'path' ],
					'additionalProperties' => false,
				]
			),
			self::tool( 'list_changes', 'Cambios hechos por Claude', 'Lotes aplicados desde el conector con su resultado (aplicado o revertido) y el id para deshacerlos.', $none ),
		];
		if ( ! $adjust ) {
			return $tools;
		}
		$tools[] = self::tool(
			'purge_cache',
			'Purgar la caché',
			'Purga la caché de páginas: una URL, un tag o todo. Purgar todo deja el sitio sin copias hasta que se regeneren: solo si hace falta y como mucho una vez cada 10 minutos.',
			[
				'type'                 => 'object',
				'properties'           => [
					'type'  => [
						'type' => 'string',
						'enum' => [ 'all', 'url', 'tag' ],
					],
					'value' => [
						'type'      => 'string',
						'maxLength' => 2000,
					],
				],
				'required'             => [ 'type' ],
				'additionalProperties' => false,
			],
			false,
			Connector::SCOPE_ADJUST,
			true
		);
		$tools[] = self::tool(
			'set_module',
			'Encender o apagar un módulo seguro',
			'Módulos seguros que no protegen el sitio: ' . implode( ', ', Abilities::AI_MODULES ) . '. Los moderados van con apply_change_set.',
			[
				'type'                 => 'object',
				'properties'           => [
					'module'  => [
						'type' => 'string',
						'enum' => Abilities::AI_MODULES,
					],
					'enabled' => [ 'type' => 'boolean' ],
				],
				'required'             => [ 'module', 'enabled' ],
				'additionalProperties' => false,
			],
			false,
			Connector::SCOPE_ADJUST
		);
		$tools[] = self::tool(
			'update_settings',
			'Cambiar ajustes seguros',
			'Cambia ajustes de un área sin confirmación de riesgo. Si el cambio no es seguro (prerender, elementos diferidos, cabeceras para el borde, caché de fragmentos sin verificar, ajustes rápidos no seguros), responde que hace falta apply_change_set.',
			[
				'type'                 => 'object',
				'properties'           => [
					'area'    => $area,
					'changes' => $changes,
				],
				'required'             => [ 'area', 'changes' ],
				'additionalProperties' => false,
			],
			false,
			Connector::SCOPE_ADJUST
		);
		$tools[] = self::tool(
			'apply_change_set',
			'Aplicar cambios con prueba',
			'Para lo de riesgo moderado. Guarda los ajustes actuales, mide las páginas clave (portada, producto, tienda, categoría, carrito, pago, cuenta), aplica los pasos, vuelve a medir y, si una página deja de responder bien, muestra un error de PHP, queda en blanco o se vuelve mucho más lenta, deshace todo solo. Explícale antes al usuario qué vas a cambiar y por qué. Tarda hasta un minuto.',
			[
				'type'                 => 'object',
				'properties'           => [
					'summary' => [
						'type'        => 'string',
						'maxLength'   => 300,
						'description' => 'Qué se cambia y por qué, en una frase (queda en el registro).',
					],
					'steps'   => [
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 5,
						'items'    => [
							'type'                 => 'object',
							'properties'           => [
								'area'    => $area,
								'changes' => $changes,
								'module'  => [
									'type' => 'string',
									'enum' => self::CHANGE_SET_MODULES,
								],
								'enabled' => [ 'type' => 'boolean' ],
							],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ 'summary', 'steps' ],
				'additionalProperties' => false,
			],
			false,
			Connector::SCOPE_ADJUST
		);
		$tools[] = self::tool(
			'revert_change',
			'Deshacer un lote',
			'Devuelve los ajustes al estado anterior a un lote de apply_change_set (id de list_changes).',
			[
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'    => 'string',
						'pattern' => '^[a-f0-9]{12}$',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			false,
			Connector::SCOPE_ADJUST
		);
		return $tools;
	}

	/**
	 * @param array<string, mixed> $input Esquema de entrada.
	 * @return Tool
	 */
	private static function tool( string $name, string $title, string $description, array $input, bool $readonly = true, string $scope = Connector::SCOPE_READ, bool $destructive = false ): array {
		return [
			'name'        => $name,
			'title'       => $title,
			'description' => $description,
			'scope'       => $scope,
			'input'       => $input,
			'readonly'    => $readonly,
			'destructive' => $destructive,
		];
	}

	/**
	 * Ejecuta una herramienta. Devuelve los datos o un mensaje de error para Claude.
	 *
	 * @param array<string, mixed>                       $args  Argumentos.
	 * @param array{id: int, client_name: string, scope: string} $grant Acceso.
	 * @return array{ok: bool, data: mixed}
	 */
	public function call( string $name, array $args, array $grant ): array {
		$adjust = in_array( Connector::SCOPE_ADJUST, Connector::scopes( $grant['scope'] ), true );
		$known  = array_column( self::definitions( true ), 'scope', 'name' );
		if ( ! isset( $known[ $name ] ) ) {
			return self::fail( 'Herramienta desconocida.' );
		}
		if ( Connector::SCOPE_ADJUST === $known[ $name ] && ! $adjust ) {
			return self::fail( 'Esta conexión es de solo lectura. Para hacer ajustes, el dueño del sitio tiene que volver a conectar a Claude eligiendo «Diagnosticar y ajustar».' );
		}
		// Las herramientas caras tienen su propio límite (por acceso): ninguna sirve para cargar el servidor.
		if ( isset( self::LIMITS[ $name ] ) ) {
			[ $max, $window ] = self::LIMITS[ $name ];
			if ( ! Connector::budget( $name . '|' . $grant['id'], $max, $window ) ) {
				return self::fail( sprintf( 'Límite de uso de esta herramienta: %1$d veces cada %2$d minutos. Espera un poco.', $max, max( 1, intdiv( $window, 60 ) ) ) );
			}
		}
		if ( strlen( (string) wp_json_encode( $args ) ) > self::MAX_INPUT ) {
			return self::fail( 'Entrada demasiado grande.' );
		}
		$period = in_array( $args['period'] ?? '', [ '1h', '24h', '48h', '7d', '30d' ], true ) ? (string) $args['period'] : '24h';
		switch ( $name ) {
			case 'diagnose_site':
				return self::wrap( $this->diagnose() );
			case 'get_status':
				return self::rest( 'GET', '/status' );
			case 'get_vitals':
				return self::rest( 'GET', '/navigation/report', [ 'days' => 30 === (int) ( $args['days'] ?? 7 ) ? 30 : 7 ] );
			case 'get_plugin_costs':
				return self::rest( 'GET', '/profiler/plugins', [ 'period' => $period ] );
			case 'get_slow_routes':
				return self::rest( 'GET', '/profiler/routes', [ 'period' => $period ] );
			case 'get_per_visit_requests':
				return self::rest( 'GET', '/profiler/per-visit' );
			case 'get_cache_status':
				return self::rest( 'GET', '/cache/status' );
			case 'get_platform_audit':
				return self::rest( 'GET', '/diagnostics/platform' );
			case 'get_image_audit':
				return self::rest( 'GET', '/diagnostics/images' );
			case 'get_cacheability_audit':
				return self::rest( 'GET', '/diagnostics/audit' );
			case 'run_cacheability_audit':
				return self::rest( 'POST', '/diagnostics/audit' );
			case 'get_area':
				$area = is_string( $args['area'] ?? null ) ? $args['area'] : '';
				return isset( self::READ_AREAS[ $area ] ) ? self::rest( 'GET', self::READ_AREAS[ $area ] ) : self::fail( 'Área desconocida.' );
			case 'get_events':
				return self::rest( 'GET', '/events' );
			case 'measure_page':
				$measure = ChangeSet::measure( is_string( $args['path'] ?? null ) ? $args['path'] : '', true === ( $args['fresh'] ?? false ) );
				return is_string( $measure ) ? self::fail( $measure ) : self::wrap( $measure );
			case 'list_changes':
				return self::wrap( $this->changes->history() );
			case 'purge_cache':
				return $this->purge( $args, $grant );
			case 'set_module':
				$module = is_string( $args['module'] ?? null ) ? $args['module'] : '';
				if ( ! in_array( $module, Abilities::AI_MODULES, true ) ) {
					return self::fail( 'Solo módulos seguros que no protegen el sitio: ' . implode( ', ', Abilities::AI_MODULES ) . '.' );
				}
				return $this->logged( $grant, $name, $args, self::rest( 'PUT', '/modules/' . $module, [ 'enabled' => true === ( $args['enabled'] ?? false ) ] ) );
			case 'update_settings':
				$route = self::writeRoute( $args['area'] ?? null );
				if ( null === $route ) {
					return self::fail( 'Área no ajustable desde el conector.' );
				}
				$result = self::rest( $route[0], $route[1], self::changes( $args['changes'] ?? null ) );
				if ( ! $result['ok'] && 428 === ( $result['status'] ?? 0 ) ) {
					return self::fail( 'Este cambio no es de riesgo seguro: aplícalo con apply_change_set (lo prueba en las páginas clave y lo deshace solo si algo falla). Motivo: ' . self::message( $result['data'] ) );
				}
				return $this->logged( $grant, $name, $args, $result );
			case 'apply_change_set':
				return self::wrap( $this->changes->apply( is_string( $args['summary'] ?? null ) ? $args['summary'] : '', is_array( $args['steps'] ?? null ) ? array_values( $args['steps'] ) : [], $grant ) );
			case 'revert_change':
				$id = is_string( $args['id'] ?? null ) ? $args['id'] : '';
				return $this->changes->revert( $id, $grant ) ? self::wrap( [ 'reverted' => $id ] ) : self::fail( 'No hay un lote aplicado con ese id (o ya se deshizo, o se alcanzó el límite de lotes por hora).' );
		}
		return self::fail( 'Herramienta desconocida.' );
	}

	/**
	 * Diagnóstico de una vez: lo que se mira primero, cada parte con su propio límite de tamaño.
	 *
	 * @return array<string, mixed>
	 */
	private function diagnose(): array {
		$parts = [
			'status'           => [ 'GET', '/status', [] ],
			'platform_audit'   => [ 'GET', '/diagnostics/platform', [] ],
			'cache'            => [ 'GET', '/cache/status', [] ],
			'vitals_7d'        => [ 'GET', '/navigation/report', [ 'days' => 7 ] ],
			'plugin_costs_24h' => [ 'GET', '/profiler/plugins', [ 'period' => '24h' ] ],
			'per_visit'        => [ 'GET', '/profiler/per-visit', [] ],
		];
		$out   = [];
		foreach ( $parts as $key => [ $method, $path, $params ] ) {
			$result      = self::rest( $method, $path, $params );
			$json        = (string) wp_json_encode( $result['data'] );
			$out[ $key ] = strlen( $json ) > 15000 ? [ 'omitted' => 'Respuesta grande: pídela con su herramienta propia.' ] : $result['data'];
		}
		return $out;
	}

	/**
	 * Purga (todo, como mucho una vez cada 10 minutos desde el conector).
	 *
	 * @param array<string, mixed>                                $args  Argumentos.
	 * @param array{id: int, client_name: string, scope: string} $grant Acceso.
	 * @return array{ok: bool, data: mixed}
	 */
	private function purge( array $args, array $grant ): array {
		$type = is_string( $args['type'] ?? null ) ? $args['type'] : '';
		if ( 'all' === $type ) {
			if ( false !== get_transient( 'itb_connector_purge_all' ) ) {
				return self::fail( 'Ya se purgó todo hace menos de 10 minutos. Purga una URL o un tag, o espera.' );
			}
			set_transient( 'itb_connector_purge_all', time(), Abilities::PURGE_ALL_GAP );
		}
		return $this->logged(
			$grant,
			'purge_cache',
			$args,
			self::rest(
				'POST',
				'/cache/purge',
				[
					'type'    => $type,
					'value'   => is_string( $args['value'] ?? null ) ? $args['value'] : '',
					'confirm' => true,
				]
			)
		);
	}

	/**
	 * Registra una escritura con quién la hizo.
	 *
	 * @param array{id: int, client_name: string, scope: string} $grant  Acceso.
	 * @param array<string, mixed>                                $args   Argumentos.
	 * @param array{ok: bool, data: mixed, status?: int}           $result Resultado.
	 * @return array{ok: bool, data: mixed}
	 */
	private function logged( array $grant, string $tool, array $args, array $result ): array {
		$this->events->log(
			'connector',
			'tool',
			$result['ok'] ? EventLog::INFO : EventLog::WARNING,
			/* translators: %s: herramienta */
			sprintf( __( 'Claude usó %s.', 'imagina-turbo' ), $tool ),
			[
				'user'   => get_current_user_id(),
				'client' => $grant['client_name'],
				'grant'  => $grant['id'],
				'input'  => $args,
				'ok'     => $result['ok'],
			]
		);
		return [
			'ok'   => $result['ok'],
			'data' => $result['data'],
		];
	}

	/**
	 * @return array{0: string, 1: string}|null
	 */
	public static function writeRoute( mixed $area ): ?array {
		return is_string( $area ) && isset( self::WRITE_AREAS[ $area ] ) ? self::WRITE_AREAS[ $area ] : null;
	}

	/**
	 * Cambios sin `confirm` (lo confirma solo apply_change_set).
	 *
	 * @return array<string, mixed>
	 */
	public static function changes( mixed $changes ): array {
		$out = [];
		foreach ( is_array( $changes ) ? $changes : [] as $key => $value ) {
			// Solo nombres de ajuste: ni confirm ni los parámetros especiales de la REST (_method, _fields…).
			if ( is_string( $key ) && 1 === preg_match( '/^[a-z][a-z_]{1,39}$/', $key ) && 'confirm' !== $key && 'context' !== $key && 'id' !== $key && count( $out ) < 30 ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Llama a una ruta del admin como el usuario actual.
	 *
	 * @param array<string, mixed> $params Parámetros.
	 * @return array{ok: bool, data: mixed, status: int}
	 */
	public static function rest( string $method, string $path, array $params = [] ): array {
		$request = new \WP_REST_Request( $method, '/' . RestRegistrar::NAMESPACE . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		$status   = $response->get_status();
		if ( $response->is_error() ) {
			return [
				'ok'     => false,
				'data'   => [ 'error' => self::message( $response->as_error() ) ],
				'status' => $status,
			];
		}
		return [
			'ok'     => true,
			'data'   => $response->get_data(),
			'status' => $status,
		];
	}

	/**
	 * Texto para Claude (JSON, recortado si es enorme).
	 */
	public static function text( mixed $data ): string {
		$json = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return strlen( $json ) > self::MAX_TEXT ? substr( $json, 0, self::MAX_TEXT ) . "\n[Respuesta recortada: es muy grande.]" : $json;
	}

	private static function message( mixed $error ): string {
		if ( $error instanceof \WP_Error ) {
			return $error->get_error_message();
		}
		if ( is_array( $error ) && is_string( $error['error'] ?? null ) ) {
			return $error['error'];
		}
		return is_string( $error ) ? $error : '';
	}

	/**
	 * @return array{ok: bool, data: mixed}
	 */
	private static function wrap( mixed $data ): array {
		return [
			'ok'   => true,
			'data' => $data,
		];
	}

	/**
	 * @return array{ok: bool, data: mixed}
	 */
	private static function fail( string $message ): array {
		return [
			'ok'   => false,
			'data' => [ 'error' => $message ],
		];
	}
}
