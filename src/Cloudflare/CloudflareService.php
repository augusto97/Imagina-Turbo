<?php
/**
 * Fachada de Cloudflare para la REST del admin y las tareas programadas.
 *
 * Nunca corre en el frontend (regla 7: el sitio no depende de Cloudflare para servir una página).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Repository\SnapshotRepository;

/**
 * Conectar, inspeccionar, proponer, aplicar, restaurar y medir.
 */
final class CloudflareService {

	/** Clave del estado con la fecha desde la que cada regla del firewall está en desafío. */
	public const CHALLENGE_KEY = 'itb_cf_challenge';

	/** Permisos que se piden al crear el token (nombres del panel de Cloudflare). */
	public const TOKEN_PERMISSIONS = [
		'Zone → Zone → Read',
		'Zone → Zone Settings → Edit',
		'Zone → Cache Rules → Edit',
		'Zone → Transform Rules → Edit',
		'Zone → Cache Purge → Purge',
		'Zone → Analytics → Read',
		'Zone → Zone WAF → Edit (firewall, fase 4)',
		'Zone → Zaraz → Edit (opcional: Google Analytics y Meta desde Cloudflare)',
		'Zone → Bot Management → Edit (opcional: políticas de bots de IA)',
	];

	/**
	 * @param array{enabled?: bool, rules?: array<string, bool>, ratelimit?: bool, views?: bool, all?: bool} $firewall Ajustes del firewall.
	 */
	public function __construct(
		private readonly TokenVault $vault,
		private readonly State $state,
		private readonly Client $client,
		private readonly SiteContext $site,
		private readonly SnapshotRepository $snapshots,
		private readonly EventLog $events,
		private readonly string $limitsFile,
		private readonly bool $moduleEnabled,
		private readonly array $firewall = [],
	) {
	}

	/**
	 * Hay zona y token legible, y el dominio permite tocar Cloudflare.
	 */
	public function ready(): bool {
		return '' !== $this->state->zoneId() && 'ok' === $this->vault->status()['state'] && '' === $this->guard();
	}

	/**
	 * Prefijo de las reglas propias de este sitio (itb-{prefijo de tags}-).
	 */
	public function refPrefix(): string {
		return $this->site->refPrefix;
	}

	public function guard(): string {
		return $this->site->guard( $this->state->all()['zone_name'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$s     = $this->state->all();
		$zone  = '' === $s['zone_id'] ? null : [
			'id'        => $s['zone_id'],
			'name'      => $s['zone_name'],
			'plan'      => $s['plan'],
			'plan_name' => $s['plan_name'],
			'status'    => $s['status'],
		];
		$guard = null === $zone ? '' : $this->guard();
		return [
			'available'         => TokenVault::available(),
			'enabled'           => $this->moduleEnabled,
			'token'             => $this->vault->status(),
			'zone'              => $zone,
			'permissions'       => (object) $s['permissions'],
			'guard'             => [
				'blocked' => '' !== $guard,
				'reason'  => $guard,
			],
			'simulator'         => Client::BASE !== Client::base(),
			'checked_at'        => $s['checked_at'],
			'error'             => $s['error'],
			'token_url'         => self::tokenUrl(),
			'token_permissions' => self::TOKEN_PERMISSIONS,
			'footprint'         => $this->state->footprint(),
		];
	}

	/**
	 * Verifica el token y, si sirve, lo guarda cifrado con la zona encontrada.
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function connect( string $token ): array {
		$token = trim( $token );
		if ( ! TokenVault::available() ) {
			return [
				'ok'    => false,
				'error' => __( 'El servidor no tiene la extensión sodium de PHP.', 'imagina-turbo' ),
			];
		}
		if ( ! TokenVault::valid( $token ) ) {
			return [
				'ok'    => false,
				'error' => __( 'Eso no parece un API Token de Cloudflare. Crea uno nuevo (no uses la Global API Key).', 'imagina-turbo' ),
			];
		}
		$connector = new Connector( $this->client );
		$result    = $connector->connect( $token, $this->site->host );
		if ( ! $result['ok'] || null === $result['zone'] ) {
			$this->events->warning( 'cloudflare', 'connect_failed', __( 'No se pudo conectar Cloudflare.', 'imagina-turbo' ), [ 'error' => $result['error'] ] );
			return [
				'ok'    => false,
				'error' => $result['error'],
			];
		}
		$zone = $result['zone'];
		if ( ! $this->vault->store( $token ) ) {
			return [
				'ok'    => false,
				'error' => __( 'No se pudo guardar el token cifrado.', 'imagina-turbo' ),
			];
		}
		$previous = $this->state->all();
		$this->state->update(
			[
				'zone_id'      => $zone['id'],
				'zone_name'    => $zone['name'],
				'account_id'   => $zone['account_id'],
				'plan'         => $zone['plan'],
				'plan_name'    => $zone['plan_name'],
				'status'       => $zone['status'],
				'connected_at' => time(),
				'checked_at'   => time(),
				'permissions'  => ( new Connector( $this->client ) )->probe( $zone['id'] ),
				// Otra zona: los hashes de lo aplicado no valen.
				'applied'      => $previous['zone_id'] === $zone['id'] ? $previous['applied'] : [],
				'error'        => '',
			]
		);
		$this->events->warning(
			'cloudflare',
			'connect',
			__( 'Cloudflare conectado.', 'imagina-turbo' ),
			[
				'user' => get_current_user_id(),
				'zone' => $zone['name'],
				'plan' => $zone['plan'],
			]
		);
		return [
			'ok'    => true,
			'error' => '',
		];
	}

	public function disconnect(): void {
		$zone = $this->state->all()['zone_name'];
		$this->vault->forget();
		$this->state->forget();
		delete_option( Analytics::OPTION );
		delete_option( Analytics::FIREWALL_OPTION );
		delete_option( self::CHALLENGE_KEY );
		$this->events->warning(
			'cloudflare',
			'disconnect',
			__( 'Cloudflare desconectado.', 'imagina-turbo' ),
			[
				'user' => get_current_user_id(),
				'zone' => $zone,
			]
		);
	}

	public function limits(): PlanLimits {
		return PlanLimits::fromFile( $this->state->plan(), $this->limitsFile );
	}

	public function inspector(): Inspector {
		return new Inspector( $this->client, $this->state->zoneId() );
	}

	/**
	 * Vista de la zona para el admin: ajustes, alertas y conteo de reglas.
	 *
	 * @return array<string, mixed>
	 */
	public function zone(): array {
		$zone   = $this->inspector()->read();
		$limits = $this->limits();
		$this->state->update( [ 'checked_at' => time() ] );

		$settings = [];
		$alerts   = [];
		foreach ( Templates::settings( $this->site->edgeHeaders, $this->site->zonePrefs ) as $def ) {
			$current    = $zone['settings'][ $def['id'] ];
			$settings[] = [
				'id'          => $def['id'],
				'label'       => $def['label'],
				'value'       => $current['readable'] ? $current['value'] : null,
				'readable'    => $current['readable'],
				'recommended' => $def['recommended'],
				'default'     => $def['default'],
				'options'     => $def['options'],
				'managed'     => $def['managed'],
				'risk'        => $def['risk'],
				'read_only'   => $def['read_only'],
				'note'        => $def['note'],
				'error'       => $current['readable'] ? '' : $current['error'],
			];
		}
		$ssl = $zone['settings']['ssl']['value'] ?? null;
		if ( 'flexible' === $ssl ) {
			$alerts[] = [
				'level'   => 'error',
				'message' => __( 'SSL/TLS está en Flexible: el tramo entre Cloudflare y el servidor va sin cifrar. Cámbialo a Full (strict) desde el panel de Cloudflare cuando el servidor tenga certificado (Imagina Turbo no toca SSL).', 'imagina-turbo' ),
			];
		} elseif ( 'off' === $ssl ) {
			$alerts[] = [
				'level'   => 'error',
				'message' => __( 'SSL/TLS está desactivado en la zona.', 'imagina-turbo' ),
			];
		} elseif ( 'full' === $ssl ) {
			$alerts[] = [
				'level'   => 'info',
				'message' => __( 'SSL/TLS está en Full: cifra hasta el servidor pero no valida su certificado. Full (strict) es más seguro.', 'imagina-turbo' ),
			];
		}
		$cache = $this->site->edgeTtl;
		if ( $cache < $limits->int( 'min_edge_ttl' ) ) {
			$alerts[] = [
				'level'   => 'info',
				/* translators: %d: segundos. */
				'message' => sprintf( __( 'El plan de la zona no guarda nada menos de %d segundos en el edge: el tiempo configurado en Caché es menor.', 'imagina-turbo' ), $limits->int( 'min_edge_ttl' ) ),
			];
		}

		$phases = [];
		foreach ( Phases::MANAGED as $phase ) {
			$data = $zone['phases'][ $phase ];
			$own  = 0;
			foreach ( $data['rules'] as $rule ) {
				if ( str_starts_with( (string) ( $rule['ref'] ?? '' ), $this->site->refPrefix ) ) {
					++$own;
				}
			}
			$phases[] = [
				'phase'   => $phase,
				'label'   => Phases::label( $phase ),
				'own'     => $own,
				'foreign' => count( $data['rules'] ) - $own,
				'limit'   => $limits->rulesFor( $phase ),
			];
			if ( ! $data['readable'] && Phases::CACHE === $phase ) {
				$alerts[] = [
					'level'   => 'warning',
					/* translators: %s: error de la API. */
					'message' => sprintf( __( 'No se pudieron leer las reglas de caché: %s', 'imagina-turbo' ), $data['error'] ),
				];
			}
		}
		return [
			'settings'     => $settings,
			'alerts'       => $alerts,
			'phases'       => $phases,
			// Smart Shield (0.21.0): solo lectura. Smart Tiered Cache se gestiona arriba; Argo es de pago y
			// Cache Reserve y Regional Tiered Cache, de Enterprise.
			'smart_shield' => $this->inspector()->smartShield(),
			// Crawler Hints no tiene API pública: se activa en el panel (Caché → Configuración).
			'dashboard'    => [
				'crawler_hints' => 'https://dash.cloudflare.com/?to=/:account/:zone/caching/configuration',
				'ai_crawl'      => 'https://dash.cloudflare.com/?to=/:account/:zone/ai',
			],
		];
	}

	/**
	 * Plan de cambios de la fase 2b (caché, reescritura y ajustes).
	 *
	 * @param array{phases: array<string, array{readable: bool, exists: bool, id: string, rules: list<array<string, mixed>>, error: string}>, settings: array<string, array{readable: bool, value: mixed, editable: bool, error: string}>}|null $zone Lectura (si ya se tiene).
	 * @return array{changes: list<array{id: string, kind: string, title: string, description: string, action: string, risk: string, reason: string, current: mixed, desired: mixed, phase: string, ref: string, setting: string}>, plan: string, notes: list<string>}
	 */
	public function plan( ?array $zone = null ): array {
		$zone      = $zone ?? $this->inspector()->read();
		$limits    = $this->limits();
		$templates = new Templates( $this->site, $limits );
		$rules     = $templates->rules();
		$notes     = [];
		if ( true === ( $this->firewall['enabled'] ?? false ) ) {
			$firewall = new FirewallTemplates(
				$this->site,
				$limits,
				is_array( $this->firewall['rules'] ?? null ) ? $this->firewall['rules'] : [],
				false !== ( $this->firewall['ratelimit'] ?? true ),
				$this->challengeSince( $zone ),
				null,
				false !== ( $this->firewall['views'] ?? true ),
				true === ( $this->firewall['all'] ?? false )
			);
			$rules    = array_merge( $rules, $firewall->rules() );
			if ( 'free' === $limits->plan() ) {
				$notes[] = __( 'Plan Free: el límite de peticiones solo puede mirar la ruta, así que el checkout clásico (?wc-ajax=checkout) no queda cubierto en el edge. Lo protegen la pasarela (Turnstile/reCAPTCHA), CrowdSec y la guardia PHP.', 'imagina-turbo' );
			}
		}
		// Las fases del firewall siempre se revisan: si el módulo se apaga, sus reglas se proponen para eliminar.
		$planner = new Planner( $rules, [ Phases::CACHE, Phases::TRANSFORM, Phases::HEADERS, Phases::WAF, Phases::RATELIMIT ], Templates::settings( $this->site->edgeHeaders, $this->site->zonePrefs ), $this->site->refPrefix, $limits, $this->state );
		if ( ! $this->site->edgeHeaders ) {
			$notes[] = __( 'La caché del HTML en el edge necesita el coordinador de caché en modo «Emitir cabeceras para el edge» (pantalla Caché).', 'imagina-turbo' );
		}
		if ( '' !== $this->guard() ) {
			$notes[] = $this->guard();
		}
		return [
			'changes' => $planner->plan( $zone ),
			'plan'    => $limits->plan(),
			'notes'   => $notes,
		];
	}

	/**
	 * @param list<string> $ids Cambios elegidos.
	 * @return array{applied: list<string>, failed: list<array{id: string, error: string}>, snapshot_id: int, rolled_back: bool}
	 */
	public function apply( array $ids ): array {
		$zone = $this->inspector()->read();
		$plan = $this->plan( $zone );
		return $this->applier()->apply( $plan['changes'], $ids, __( 'Antes de aplicar cambios', 'imagina-turbo' ) );
	}

	/**
	 * @return array{applied: list<string>, failed: list<array{id: string, error: string}>, snapshot_id: int, rolled_back: bool}
	 */
	public function restore( int $snapshotId ): array {
		return $this->applier()->restore( $snapshotId );
	}

	/**
	 * Quita de la zona todo lo del plugin (reglas propias y ajustes cambiados), con snapshot previo.
	 *
	 * @return array{applied: list<string>, failed: list<array{id: string, error: string}>, snapshot_id: int, rolled_back: bool}
	 */
	public function removeAll(): array {
		return $this->applier()->removeAll();
	}

	/**
	 * Lo que el plugin tiene en la zona según su estado (sin llamar a la API).
	 *
	 * @return array{rules: int, settings: int}
	 */
	public function footprint(): array {
		return $this->state->footprint();
	}

	/**
	 * @return list<array{id: int, created_at: string, zone_id: string, reason: string}>
	 */
	public function snapshots(): array {
		return '' === $this->state->zoneId() ? [] : $this->snapshots->latest( $this->state->zoneId() );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function analytics( bool $refreshIfOld = true ): array {
		$stored = Analytics::stored();
		if ( $refreshIfOld && $this->ready() && $stored['fetched_at'] < time() - HOUR_IN_SECONDS ) {
			return ( new Analytics( $this->client, $this->state->zoneId(), $this->site->host ) )->refresh();
		}
		return $stored;
	}

	/**
	 * Tarea horaria: analíticas y deriva (reglas propias cambiadas a mano). No escribe en la zona.
	 */
	public function hourly(): void {
		if ( ! $this->ready() ) {
			return;
		}
		( new Analytics( $this->client, $this->state->zoneId(), $this->site->host ) )->refresh();
		if ( true === ( $this->firewall['enabled'] ?? false ) ) {
			( new Analytics( $this->client, $this->state->zoneId(), $this->site->host ) )->firewall();
		}
		$drifted = [];
		foreach ( $this->plan()['changes'] as $change ) {
			if ( 'drifted' === $change['action'] ) {
				$drifted[] = $change['ref'];
			}
		}
		$known = get_option( 'itb_cf_drift', [] );
		$known = is_array( $known ) ? $known : [];
		$new   = array_values( array_diff( $drifted, $known ) );
		if ( [] !== $new ) {
			$this->events->warning( 'cloudflare', 'drift', __( 'Reglas propias modificadas a mano en Cloudflare. Revísalas en la pantalla Cloudflare.', 'imagina-turbo' ), [ 'rules' => $new ] );
		}
		update_option( 'itb_cf_drift', $drifted, false );
		$this->state->update( [ 'checked_at' => time() ] );
	}

	public function zaraz(): Zaraz {
		return new Zaraz( $this->client, $this->state->zoneId() );
	}

	/**
	 * Zaraz: lo que hay en el sitio, lo que hay en la zona y lo que Turbo cambió.
	 *
	 * @param array{ga4: bool, meta: bool} $takeover Lo que Zaraz reemplaza hoy en el sitio.
	 * @return array<string, mixed>
	 */
	public function zarazStatus( array $takeover ): array {
		$read  = $this->ready() ? $this->zaraz()->read() : null;
		$state = get_option( Zaraz::STATE_OPTION, [] );
		return [
			'ready'    => $this->ready(),
			'plugins'  => \ImaginaTurbo\Integrations\AnalyticsTags::fromPlugins(),
			'page'     => get_option( 'itb_zaraz_scan', null ),
			'zone'     => null === $read ? null : [
				'ok'       => $read['ok'],
				'error'    => $read['error'],
				'workflow' => $read['workflow'],
				'secrets'  => $read['secrets'],
			] + ( $read['ok'] ? Zaraz::summary( $read['config'] ) : [] ),
			'takeover' => $takeover,
			'state'    => is_array( $state ) ? (object) $state : (object) [],
		];
	}

	/**
	 * Agrega GA4 y/o Meta a Zaraz con e-commerce (los ids salen de los plugins del sitio).
	 *
	 * @return array{ok: bool, error: string, published: bool, ga4: string, meta: string}
	 */
	public function zarazApply( bool $ga4, bool $meta, string $metaToken ): array {
		$fail = static fn( string $error ): array => [
			'ok'        => false,
			'error'     => $error,
			'published' => false,
			'ga4'       => '',
			'meta'      => '',
		];
		if ( ! $this->ready() ) {
			return $fail( __( 'Cloudflare no está conectado.', 'imagina-turbo' ) );
		}
		$plugins = \ImaginaTurbo\Integrations\AnalyticsTags::fromPlugins();
		$ga4Id   = '';
		$metaId  = '';
		foreach ( $plugins['ga4'] as $item ) {
			$ga4Id = '' === $ga4Id && $item['takeover'] ? $item['id'] : $ga4Id;
		}
		foreach ( $plugins['meta'] as $item ) {
			$metaId = '' === $metaId && $item['takeover'] ? $item['id'] : $metaId;
		}
		if ( $ga4 && '' === $ga4Id ) {
			return $fail( __( 'No hay un Google Analytics 4 de Site Kit que se pueda pasar a Zaraz.', 'imagina-turbo' ) );
		}
		if ( $meta && ( '' === $metaId || 1 !== preg_match( '/^[A-Za-z0-9_\-]{40,500}$/', $metaToken ) ) ) {
			return $fail( '' === $metaId ? __( 'No hay un píxel de Meta for WooCommerce que se pueda pasar a Zaraz.', 'imagina-turbo' ) : __( 'Zaraz envía los eventos de Meta por la API de conversiones: hace falta su token de acceso (Administrador de eventos → Configuración → API de conversiones).', 'imagina-turbo' ) );
		}
		$zaraz = $this->zaraz();
		$read  = $zaraz->read();
		if ( ! $read['ok'] ) {
			return $fail( $read['error'] );
		}
		$base = $zaraz->baseConfig( $read );
		if ( null === $base ) {
			return $fail( __( 'La zona tiene variables secretas y un borrador de Zaraz sin publicar: publícalo (o descártalo) en Cloudflare antes de que Turbo toque la configuración.', 'imagina-turbo' ) );
		}
		$tools = [];
		if ( $ga4 && null === Zaraz::findTool( $base, 'google-analytics', 'tid', $ga4Id ) ) {
			$tools[ Zaraz::TOOL_GA4 ] = Zaraz::ga4Tool( $ga4Id );
		}
		if ( $meta ) {
			// El token puede haber cambiado: la herramienta propia se reescribe; una agregada a mano se respeta.
			$found = Zaraz::findTool( $base, 'facebook-pixel', 'property', $metaId );
			if ( null === $found || Zaraz::TOOL_META === $found ) {
				$tools[ Zaraz::TOOL_META ] = Zaraz::metaTool( $metaId, $metaToken );
			}
		}
		$state = get_option( Zaraz::STATE_OPTION, [] );
		$state = is_array( $state ) ? $state : [];
		if ( ! isset( $state['ecommerce_before'] ) ) {
			$state['ecommerce_before'] = Zaraz::summary( $base )['ecommerce'];
		}
		$result = $zaraz->write( Zaraz::merge( $base, $tools ), $read['workflow'] );
		if ( ! $result['ok'] ) {
			$this->events->warning( 'cloudflare', 'zaraz', __( 'Cloudflare rechazó la configuración de Zaraz.', 'imagina-turbo' ), [ 'error' => $result['error'] ] );
			return $fail(
				sprintf(
				/* translators: %s: error de la API. */
					__( 'Cloudflare rechazó la configuración de Zaraz: %s. Puedes agregar la herramienta a mano en Cloudflare → Zaraz con el mismo id y volver a intentarlo: Turbo la reconoce.', 'imagina-turbo' ),
					$result['error']
				)
			);
		}
		update_option(
			Zaraz::STATE_OPTION,
			[
				'applied_at'       => time(),
				'ecommerce_before' => (bool) $state['ecommerce_before'],
				'ga4'              => $ga4 ? $ga4Id : '',
				'meta'             => $meta ? $metaId : '',
				'tools'            => array_keys( $tools ),
				'published'        => $result['published'],
			],
			false
		);
		$this->events->warning(
			'cloudflare',
			'zaraz',
			__( 'Zaraz configurado: Google Analytics y/o Meta se cargan desde Cloudflare.', 'imagina-turbo' ),
			[
				'ga4'       => $ga4 ? $ga4Id : '',
				'meta'      => $meta ? $metaId : '',
				'published' => $result['published'],
			]
		);
		return [
			'ok'        => true,
			'error'     => $result['error'],
			'published' => $result['published'],
			'ga4'       => $ga4 ? $ga4Id : '',
			'meta'      => $meta ? $metaId : '',
		];
	}

	/**
	 * Quita las herramientas propias de Zaraz y devuelve el interruptor de e-commerce.
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function zarazRevert(): array {
		$state = get_option( Zaraz::STATE_OPTION, [] );
		$state = is_array( $state ) ? $state : [];
		if ( ! $this->ready() ) {
			return [
				'ok'    => false,
				'error' => __( 'Cloudflare no está conectado: quita las herramientas a mano en Cloudflare → Zaraz.', 'imagina-turbo' ),
			];
		}
		$zaraz = $this->zaraz();
		$read  = $zaraz->read();
		$base  = $read['ok'] ? $zaraz->baseConfig( $read ) : null;
		if ( null === $base ) {
			return [
				'ok'    => false,
				'error' => '' !== $read['error'] ? $read['error'] : __( 'No se pudo leer la configuración de Zaraz sin perder secretos o el borrador.', 'imagina-turbo' ),
			];
		}
		$result = $zaraz->write( Zaraz::strip( $base, ! empty( $state['ecommerce_before'] ) ), $read['workflow'] );
		if ( $result['ok'] ) {
			delete_option( Zaraz::STATE_OPTION );
			$this->events->info( 'cloudflare', 'zaraz', __( 'Herramientas de Imagina Turbo quitadas de Zaraz.', 'imagina-turbo' ) );
		}
		return [
			'ok'    => $result['ok'],
			'error' => $result['error'],
		];
	}

	/**
	 * ¿Siguen en Zaraz las herramientas que reemplazan a las del sitio? Si alguien las quitó o apagó
	 * Zaraz, las etiquetas del sitio tienen que volver (tarea horaria). null = no se pudo leer.
	 *
	 * @param array{ga4: bool, meta: bool} $takeover Lo que Zaraz reemplaza hoy.
	 */
	public function zarazHealthy( array $takeover ): ?bool {
		$state = get_option( Zaraz::STATE_OPTION, [] );
		$state = is_array( $state ) ? $state : [];
		$read  = $this->ready() ? $this->zaraz()->read() : null;
		if ( null === $read || ! $read['ok'] ) {
			return null;
		}
		$tools = Zaraz::summary( $read['config'] )['tools'];
		$has   = static function ( string $component, string $id ) use ( $tools ): bool {
			foreach ( $tools as $tool ) {
				if ( $tool['enabled'] && str_contains( $tool['component'], $component ) && strtoupper( $tool['id_value'] ) === strtoupper( $id ) ) {
					return true;
				}
			}
			return false;
		};
		$ok    = ( ! $takeover['ga4'] || $has( 'google-analytics', (string) ( $state['ga4'] ?? '' ) ) ) && ( ! $takeover['meta'] || $has( 'facebook-pixel', (string) ( $state['meta'] ?? '' ) ) );
		if ( ! $ok || false === Zaraz::summary( $read['config'] )['auto_inject'] ) {
			return false;
		}
		// Zona en pausa (Cloudflare deja de hacer de proxy): Zaraz ya no se inyecta.
		$zone = $this->client->get( '/zones/' . rawurlencode( $this->state->zoneId() ) );
		if ( ! $zone->ok() ) {
			return null;
		}
		$raw = $zone->resultArray();
		return true !== ( $raw['paused'] ?? false ) && 'active' === ( $raw['status'] ?? 'active' );
	}

	/**
	 * Desde cuándo cada regla propia del firewall está en desafío: se registra la primera vez que se
	 * ve en la zona con managed_challenge y se olvida si deja de estarlo.
	 *
	 * @param array{phases: array<string, array{readable: bool, exists: bool, id: string, rules: list<array<string, mixed>>, error: string}>, settings: array<string, mixed>} $zone Lectura.
	 * @return array<string, int>
	 */
	private function challengeSince( array $zone ): array {
		$stored = get_option( self::CHALLENGE_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$out    = [];
		foreach ( $zone['phases'][ Phases::WAF ]['rules'] ?? [] as $rule ) {
			$ref = is_string( $rule['ref'] ?? null ) ? $rule['ref'] : '';
			if ( '' === $ref || ! str_starts_with( $ref, $this->site->refPrefix . 'fw-' ) ) {
				continue;
			}
			if ( 'managed_challenge' === ( $rule['action'] ?? '' ) ) {
				$out[ $ref ] = is_int( $stored[ $ref ] ?? null ) ? $stored[ $ref ] : time();
			} elseif ( 'block' === ( $rule['action'] ?? '' ) ) {
				// Ya en bloqueo: se conserva la fecha para que el plan siga proponiendo block.
				$out[ $ref ] = is_int( $stored[ $ref ] ?? null ) ? $stored[ $ref ] : time() - FirewallTemplates::TEST_DAYS * DAY_IN_SECONDS;
			}
		}
		if ( $out !== $stored ) {
			update_option( self::CHALLENGE_KEY, $out, false );
		}
		return $out;
	}

	/**
	 * Eventos del firewall de las últimas 24 h (reglas propias y del cliente), agrupados.
	 *
	 * @return array<string, mixed>
	 */
	public function firewallEvents( bool $refreshIfOld = true ): array {
		$stored = get_option( Analytics::FIREWALL_OPTION, null );
		if ( is_array( $stored ) && is_int( $stored['fetched_at'] ?? null ) && ( ! $refreshIfOld || $stored['fetched_at'] >= time() - HOUR_IN_SECONDS || ! $this->ready() ) ) {
			return $stored;
		}
		if ( ! $this->ready() ) {
			return [
				'available' => false,
				'error'     => '',
				'events'    => [],
				'total'     => 0,
			];
		}
		return ( new Analytics( $this->client, $this->state->zoneId(), $this->site->host ) )->firewall();
	}

	public function purger(): CloudflarePurger {
		$limits = $this->limits();
		return new CloudflarePurger(
			$this->client,
			$this->moduleEnabled && 'ok' === $this->vault->status()['state'] ? $this->state->zoneId() : '',
			$this->site->host,
			'' !== $this->guard(),
			$limits->int( 'purge_items_per_call', 100 ),
			$limits->int( 'purge_calls_per_run', 5 )
		);
	}

	private function applier(): Applier {
		return new Applier( $this->client, $this->state->zoneId(), $this->site->refPrefix, $this->inspector(), $this->snapshots, $this->state, $this->events );
	}

	/**
	 * Plantilla de creación de token del panel de Cloudflare con los permisos documentados
	 * (zona, ajustes, analíticas, purga). Reglas de caché, reescritura y WAF no tienen clave de
	 * plantilla documentada: se agregan a mano (la pantalla lo indica).
	 */
	public static function tokenUrl(): string {
		$keys = [
			[
				'key'  => 'zone',
				'type' => 'read',
			],
			[
				'key'  => 'zone_settings',
				'type' => 'edit',
			],
			[
				'key'  => 'analytics',
				'type' => 'read',
			],
			[
				'key'  => 'cache',
				'type' => 'purge',
			],
		];
		return 'https://dash.cloudflare.com/profile/api-tokens?' . http_build_query(
			[
				'permissionGroupKeys' => (string) wp_json_encode( $keys ),
				'accountId'           => '*',
				'zoneId'              => 'all',
				'name'                => 'Imagina Turbo',
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}
}
