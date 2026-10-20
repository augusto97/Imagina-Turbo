<?php
/**
 * Habilidades para asistentes de IA (Abilities API de WordPress, 0.15.0).
 *
 * Verificado en wp-includes/abilities-api.php de WordPress 7.1.3 (API desde 6.9):
 * - Las categorías se registran en `wp_abilities_api_categories_init` con wp_register_ability_category()
 *   y las habilidades en `wp_abilities_api_init` con wp_register_ability(); fuera de esos hooks fallan.
 * - Cada habilidad lleva label, description, category, execute_callback, permission_callback y, si
 *   recibe o devuelve datos, input_schema y output_schema (JSON Schema, el subconjunto de la REST API).
 * - meta.annotations (readonly, destructive, idempotent) orienta a los clientes. meta.public expone la
 *   habilidad a los canales (REST wp-abilities/v1 y MCP); meta.mcp.public es el filtro que usa MCP.
 *
 * Idea tomada de Warp Performance (MCP en producción): el asistente lee las métricas reales y el estado
 * de la caché, y solo cambia algo si el administrador lo permite.
 * - Lectura: siempre, con el permiso del admin de Imagina Turbo.
 * - Escritura (purgar la caché, encender o apagar módulos): solo con el ajuste ai_write, apagado por
 *   defecto. Sin él no se registran.
 * - Todo pasa por las mismas rutas REST del admin (rest_do_request): las mismas validaciones, la misma
 *   confirmación de riesgo. Un asistente solo puede encender módulos seguros, porque no envía `confirm`.
 * - Cada escritura queda en el registro de eventos.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Integrations;

use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Rest\RestRegistrar;

/**
 * Registro de la categoría y las habilidades.
 */
final class Abilities {

	public const CATEGORY = 'imagina-turbo';

	public function __construct( private readonly EventLog $events, private readonly bool $write ) {
	}

	/**
	 * Engancha el registro si WordPress tiene la Abilities API (6.9+).
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', [ $this, 'registerCategory' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'registerAbilities' ] );
	}

	public function registerCategory(): void {
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Imagina Turbo', 'imagina-turbo' ),
				'description' => __( 'Rendimiento del sitio: métricas reales de visitantes, caché, diagnóstico y módulos de Imagina Turbo.', 'imagina-turbo' ),
			]
		);
	}

	public function registerAbilities(): void {
		foreach ( self::definitions( $this->write ) as $name => $def ) {
			wp_register_ability(
				$name,
				[
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => self::CATEGORY,
					'input_schema'        => $def['input'],
					'output_schema'       => [
						'type'       => 'object',
						'properties' => [
							'result' => [
								'type'        => [ 'object', 'array' ],
								'description' => __( 'La respuesta de Imagina Turbo.', 'imagina-turbo' ),
							],
						],
					],
					'execute_callback'    => fn( mixed $input = null ): mixed => $this->execute( $name, is_array( $input ) ? $input : [] ),
					'permission_callback' => [ RestRegistrar::class, 'canManage' ],
					'meta'                => [
						'annotations' => [
							'readonly'    => $def['readonly'],
							'destructive' => false,
							'idempotent'  => $def['readonly'],
						],
						'public'      => true,
						'mcp'         => [ 'public' => true ],
					],
				]
			);
		}
	}

	/**
	 * Habilidades disponibles. Las de escritura solo con $write.
	 *
	 * @return array<lowercase-string&non-falsy-string, array{label: string, description: string, readonly: bool, input: array<string, mixed>}>
	 */
	public static function definitions( bool $write ): array {
		$none = [
			'type'                 => 'object',
			'properties'           => (object) [],
			'additionalProperties' => false,
		];
		$out  = [
			'imagina-turbo/get-status'         => [
				'label'       => __( 'Estado de Imagina Turbo', 'imagina-turbo' ),
				'description' => __( 'Versión, entorno (servidor, plugin de caché, Cloudflare), módulos activos y apagados por solapamiento, modo seguro y alertas ordenadas por gravedad.', 'imagina-turbo' ),
				'readonly'    => true,
				'input'       => $none,
			],
			'imagina-turbo/get-vitals'         => [
				'label'       => __( 'Métricas reales de visitantes', 'imagina-turbo' ),
				'description' => __( 'Core Web Vitals de visitantes reales (RUM): LCP, INP, CLS y TTFB en p75 por plantilla y dispositivo, desglose del TTFB (conexión, espera con copia en caché o generada), tipos de navegación, causas de bfcache e imágenes LCP medidas.', 'imagina-turbo' ),
				'readonly'    => true,
				'input'       => [
					'type'                 => 'object',
					'properties'           => [
						'days' => [
							'type'        => 'integer',
							'enum'        => [ 7, 30 ],
							'default'     => 7,
							'description' => __( 'Ventana en días.', 'imagina-turbo' ),
						],
					],
					'additionalProperties' => false,
				],
			],
			'imagina-turbo/get-cache-status'   => [
				'label'       => __( 'Estado de la caché', 'imagina-turbo' ),
				'description' => __( 'Coordinador de caché: adaptador (LiteSpeed Cache o WP Rocket), cabeceras de borde, TTL, stale-while-revalidate, refresco de nonces, recalentado y cola de purgas.', 'imagina-turbo' ),
				'readonly'    => true,
				'input'       => $none,
			],
			'imagina-turbo/get-platform-audit' => [
				'label'       => __( 'Ajustes de la plataforma', 'imagina-turbo' ),
				'description' => __( 'Revisión de Elementor, WooCommerce, WoodMart, caché de objetos e índices de la base de datos, con lo que conviene cambiar y dónde.', 'imagina-turbo' ),
				'readonly'    => true,
				'input'       => $none,
			],
			'imagina-turbo/get-events'         => [
				'label'       => __( 'Registro de eventos', 'imagina-turbo' ),
				'description' => __( 'Últimos cambios y avisos de Imagina Turbo (purgas, módulos, ajustes, Cloudflare, modo seguro).', 'imagina-turbo' ),
				'readonly'    => true,
				'input'       => $none,
			],
		];
		if ( ! $write ) {
			return $out;
		}
		$out['imagina-turbo/purge-cache'] = [
			'label'       => __( 'Purgar la caché', 'imagina-turbo' ),
			'description' => __( 'Purga la caché de páginas: todo el sitio, una URL o un tag. Purgar todo deja el sitio sin copias hasta que se regeneren; úsalo solo si hace falta.', 'imagina-turbo' ),
			'readonly'    => false,
			'input'       => [
				'type'                 => 'object',
				'properties'           => [
					'type'  => [
						'type' => 'string',
						'enum' => [ 'all', 'url', 'tag' ],
					],
					'value' => [
						'type'        => 'string',
						'maxLength'   => 2000,
						'description' => __( 'La URL o el tag (vacío con "all").', 'imagina-turbo' ),
					],
				],
				'required'             => [ 'type' ],
				'additionalProperties' => false,
			],
		];
		$out['imagina-turbo/set-module']  = [
			'label'       => __( 'Encender o apagar un módulo', 'imagina-turbo' ),
			'description' => __( 'Enciende o apaga un módulo de Imagina Turbo por su id (ver get-status). Solo se pueden encender módulos de riesgo seguro; los moderados o invasivos se activan desde el admin.', 'imagina-turbo' ),
			'readonly'    => false,
			'input'       => [
				'type'                 => 'object',
				'properties'           => [
					'module'  => [
						'type'    => 'string',
						'pattern' => '^[a-z_]{2,40}$',
					],
					'enabled' => [ 'type' => 'boolean' ],
				],
				'required'             => [ 'module', 'enabled' ],
				'additionalProperties' => false,
			],
		];
		return $out;
	}

	/**
	 * Ruta REST del admin que atiende cada habilidad.
	 *
	 * @param array<string, mixed> $input Entrada validada por el esquema.
	 * @return array{0: string, 1: string, 2: array<string, mixed>}|null Método, ruta y parámetros.
	 */
	public static function route( string $name, array $input ): ?array {
		return match ( $name ) {
			'imagina-turbo/get-status'         => [ 'GET', '/status', [] ],
			'imagina-turbo/get-vitals'         => [ 'GET', '/navigation/report', [ 'days' => 30 === (int) ( $input['days'] ?? 7 ) ? 30 : 7 ] ],
			'imagina-turbo/get-cache-status'   => [ 'GET', '/cache/status', [] ],
			'imagina-turbo/get-platform-audit' => [ 'GET', '/diagnostics/platform', [] ],
			'imagina-turbo/get-events'         => [ 'GET', '/events', [] ],
			'imagina-turbo/purge-cache'        => [
				'POST',
				'/cache/purge',
				[
					'type'    => (string) ( $input['type'] ?? '' ),
					'value'   => (string) ( $input['value'] ?? '' ),
					'confirm' => true,
				],
			],
			'imagina-turbo/set-module'         => [ 'PUT', '/modules/' . preg_replace( '/[^a-z_]/', '', (string) ( $input['module'] ?? '' ) ), [ 'enabled' => (bool) ( $input['enabled'] ?? false ) ] ],
			default                            => null,
		};
	}

	/**
	 * @param array<string, mixed> $input Entrada.
	 */
	private function execute( string $name, array $input ): mixed {
		$route = self::route( $name, $input );
		if ( null === $route ) {
			return new \WP_Error( 'itb_unknown_ability', __( 'Habilidad desconocida.', 'imagina-turbo' ) );
		}
		[ $method, $path, $params ] = $route;
		$request                    = new \WP_REST_Request( $method, '/' . RestRegistrar::NAMESPACE . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		if ( 'GET' !== $method ) {
			$this->events->info(
				'ai',
				'ability',
				/* translators: %s: nombre de la habilidad */
				sprintf( __( 'Un asistente de IA ejecutó %s.', 'imagina-turbo' ), $name ),
				[
					'user'   => get_current_user_id(),
					'input'  => $input,
					'status' => $response->get_status(),
				]
			);
		}
		if ( $response->is_error() ) {
			return $response->as_error();
		}
		return [ 'result' => $response->get_data() ];
	}
}
