<?php
/**
 * Firewall: GET /firewall/status, PUT /firewall/settings, GET /firewall/events,
 * POST /firewall/htaccess.
 *
 * Las reglas del edge se aplican con el ciclo de la pantalla Cloudflare (POST /cloudflare/apply): aquí
 * solo se eligen y se muestran sus cambios pendientes.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Cloudflare\CloudflareService;
use ImaginaTurbo\Cloudflare\EdgeOptions;
use ImaginaTurbo\Cloudflare\FirewallTemplates;
use ImaginaTurbo\Cloudflare\Phases;
use ImaginaTurbo\Cloudflare\PlanLimits;
use ImaginaTurbo\Core\Config\ConfigCompiler;
use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Environment\Detector;
use ImaginaTurbo\Core\Events\EventLog;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\CacheCoordinator\Warmer;
use ImaginaTurbo\Modules\Firewall\HtaccessWriter;
use ImaginaTurbo\Modules\Firewall\OriginLock;
use ImaginaTurbo\Modules\Rum\RumCollector;
use ImaginaTurbo\Repository\SampleRepository;

/**
 * Ajustes, eventos, origen y .htaccess.
 */
final class FirewallController extends Controller {

	public const RULES = [ 'exploits', 'surface', 'bots', 'facets', 'countries' ];

	/** Rangos oficiales de Cloudflare (para cerrar el origen). */
	public const CF_IPS_URL = 'https://www.cloudflare.com/ips/';

	public function registerRoutes(): void {
		$this->route( '/firewall/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->status() ) );
		$rules = [];
		foreach ( self::RULES as $id ) {
			$rules[ $id ] = [ 'type' => 'boolean' ];
		}
		$this->route(
			'/firewall/settings',
			'PUT',
			[ $this, 'update' ],
			[
				'rules'     => [
					'type'                 => 'object',
					'properties'           => $rules,
					'additionalProperties' => false,
				],
				'ratelimit' => [ 'type' => 'boolean' ],
			]
		);
		$this->route( '/firewall/events', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->service()->firewallEvents() ) );
		$this->route(
			'/firewall/htaccess',
			\WP_REST_Server::CREATABLE,
			[ $this, 'htaccess' ],
			[
				'action'  => [
					'type'     => 'string',
					'enum'     => [ 'apply', 'remove' ],
					'required' => true,
				],
				'confirm' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$settings = $this->container->get( Settings::class );
		$stored   = $settings->get( 'firewall_rules', [] );
		$stored   = is_array( $stored ) ? $stored : [];
		$rules    = [];
		foreach ( self::RULES as $id ) {
			$rules[ $id ] = (bool) ( $stored[ $id ] ?? ! in_array( $id, FirewallTemplates::OFF_BY_DEFAULT, true ) );
		}
		$service = $this->service();
		$cf      = $service->status();
		$changes = [];
		$notes   = [];
		$error   = '';
		if ( null !== $cf['zone'] && 'ok' === ( $cf['token']['state'] ?? '' ) ) {
			try {
				$plan = $service->plan();
				foreach ( $plan['changes'] as $change ) {
					if ( in_array( $change['phase'], [ Phases::WAF, Phases::RATELIMIT ], true ) ) {
						$changes[] = $change;
					}
				}
				$notes = $plan['notes'];
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
			}
		}
		$state  = get_option( OriginLock::STATE_OPTION, [] );
		$origin = OriginLock::report( $this->container->get( SampleRepository::class )->recent( 7 * 24 ), is_array( $state ) && is_int( $state['since'] ?? null ) ? $state['since'] : 0 );
		$server = Detector::server( $_SERVER );
		$file   = $this->htaccessFile();
		return [
			'enabled'    => null !== Runtime::$config && Runtime::$config->moduleEnabled( 'firewall' ),
			'settings'   => [
				'rules'     => $rules,
				'ratelimit' => (bool) $settings->get( 'firewall_ratelimit', true ),
				'custom'    => EdgeOptions::firewall( (array) $settings->get( 'firewall_custom', [] ) ),
			],
			// Lo que la pantalla necesita para los ajustes manuales (0.23.0).
			'options'    => $this->options( is_string( $cf['zone']['plan'] ?? null ) ? $cf['zone']['plan'] : '' ),
			'cloudflare' => [
				'connected'      => null !== $cf['zone'],
				'module_enabled' => $cf['enabled'],
				'plan'           => $cf['zone']['plan'] ?? '',
				// Edición si se pudo comprobar (dry_run); si no, lectura. Rate limiting usa el mismo permiso.
				'waf_permission' => $cf['permissions']->waf_edit ?? $cf['permissions']->waf_read ?? null,
			],
			'changes'    => $changes,
			'notes'      => $notes,
			'error'      => $error,
			'events'     => $service->firewallEvents( false ),
			'origin'     => $origin + [
				'real_ip_missing' => OriginLock::realIpMissing( $origin ),
				'ranges_url'      => self::CF_IPS_URL,
			],
			'htaccess'   => [
				'server'     => $server['family'],
				'applicable' => in_array( $server['family'], [ 'apache', 'litespeed', 'openlitespeed' ], true ),
				'ols'        => 'openlitespeed' === $server['family'],
				'block'      => HtaccessWriter::block(),
			] + $this->writer( $file )->status(),
		];
	}

	/**
	 * Escáneres de la lista, opciones del límite de peticiones en el plan y quién está guardando.
	 *
	 * @return array<string, mixed>
	 */
	private function options( string $plan ): array {
		$limits           = PlanLimits::fromFile( '' === $plan ? 'free' : $plan, Runtime::$pluginDir . '/data/cloudflare-limits.json' );
		$rl               = $limits->ratelimit();
		$ints             = static fn( mixed $v ): array => is_array( $v ) ? array_values( array_map( 'intval', $v ) ) : [];
		[ $ip, $country ] = self::visitor();
		return [
			'bad_bots'     => FirewallTemplates::BAD_BOTS,
			'plan'         => $limits->plan(),
			'periods'      => $ints( $rl['periods'] ?? null ),
			'timeouts'     => $ints( $rl['mitigation_timeouts'] ?? null ),
			'ratelimit_ip' => in_array( 'ip', is_array( $rl['fields'] ?? null ) ? $rl['fields'] : [], true ),
			'limits'       => [
				'ips'       => EdgeOptions::MAX_IPS,
				'paths'     => EdgeOptions::MAX_PATHS,
				'bots'      => EdgeOptions::MAX_BOTS,
				'countries' => EdgeOptions::MAX_COUNTRIES,
				'rl_paths'  => EdgeOptions::MAX_RL_PATHS,
				'requests'  => [ EdgeOptions::RL_REQUESTS_MIN, EdgeOptions::RL_REQUESTS_MAX ],
			],
			'client'       => [
				'ip'      => $ip,
				'country' => $country,
			],
		];
	}

	/**
	 * IP y país de quien usa el admin. El país lo pone Cloudflare (CF-IPCountry) y solo se cree si el
	 * request viene de sus rangos.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function visitor(): array {
		$ip      = RumCollector::clientIp( $_SERVER );
		$remote  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$country = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) && RumCollector::fromCloudflare( $remote ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ) : '';
		return [ $ip, EdgeOptions::country( $country ) ?? '' ];
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$settings = $this->container->get( Settings::class );
		$changes  = [];
		$rules    = $request->get_param( 'rules' );
		$current  = $settings->get( 'firewall_rules', [] );
		$current  = is_array( $current ) ? $current : [];
		if ( is_array( $rules ) ) {
			foreach ( self::RULES as $id ) {
				if ( is_bool( $rules[ $id ] ?? null ) ) {
					$current[ $id ] = $rules[ $id ];
				}
			}
			$changes['firewall_rules'] = $current;
		}
		if ( is_bool( $request->get_param( 'ratelimit' ) ) ) {
			$changes['firewall_ratelimit'] = $request->get_param( 'ratelimit' );
		}
		$custom = $request->get_param( 'custom' );
		if ( is_array( $custom ) ) {
			// Nada se descarta en silencio: si una entrada no vale, no se guarda nada y se dice cuál.
			$problems = EdgeOptions::problems( $custom, 'firewall' );
			if ( [] !== $problems ) {
				return $this->error( 'itb_firewall_custom', implode( ' · ', $problems ), 400 );
			}
			$changes['firewall_custom'] = EdgeOptions::firewall( $custom );
		}
		// Encender la regla de países o cambiar sus opciones nunca deja fuera a quien guarda.
		$opts             = $changes['firewall_custom'] ?? EdgeOptions::firewall( (array) $settings->get( 'firewall_custom', [] ) );
		[ $ip, $country ] = self::visitor();
		if ( true === ( $current['countries'] ?? false ) && EdgeOptions::locksOut( $opts, $country, $ip ) ) {
			return $this->error(
				'itb_firewall_lockout',
				/* translators: %s: código de país. */
				sprintf( __( 'Con esta regla de países te bloquearías a ti mismo: estás en %s. Agrega tu país a la lista, tu IP a las excepciones o usa «Desafío».', 'imagina-turbo' ), $country ),
				409
			);
		}
		$settings->update( $changes );
		$this->container->get( ConfigCompiler::class )->compile();
		$this->container->get( EventLog::class )->info(
			'firewall',
			'settings',
			__( 'Reglas del firewall elegidas. Aplica los cambios en Cloudflare para que tengan efecto.', 'imagina-turbo' ),
			[
				'user'    => get_current_user_id(),
				'changes' => $changes,
			]
		);
		return $this->ok( $this->status() );
	}

	public function htaccess( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$network = $this->networkOnly();
		if ( null !== $network ) {
			return $network;
		}
		if ( true !== $request->get_param( 'confirm' ) ) {
			return $this->error( 'itb_confirm', __( 'Confirma el cambio en el .htaccess.', 'imagina-turbo' ), 428 );
		}
		$server = Detector::server( $_SERVER );
		if ( ! in_array( $server['family'], [ 'apache', 'litespeed', 'openlitespeed' ], true ) ) {
			return $this->error( 'itb_htaccess_server', __( 'Este servidor no usa .htaccess.', 'imagina-turbo' ), 409 );
		}
		$add     = 'apply' === $request->get_param( 'action' );
		$writer  = $this->writer( $this->htaccessFile() );
		$result  = $add ? $writer->apply() : $writer->remove();
		$log     = $this->container->get( EventLog::class );
		$context = [
			'user'   => get_current_user_id(),
			'checks' => $result['checks'],
		];
		if ( $result['ok'] ) {
			$log->info( 'firewall', $add ? 'htaccess_applied' : 'htaccess_removed', $add ? __( 'Reglas del firewall escritas en el .htaccess.', 'imagina-turbo' ) : __( 'Reglas del firewall quitadas del .htaccess.', 'imagina-turbo' ), $context );
		} else {
			$log->warning( 'firewall', 'htaccess_failed', $result['error'], $context + [ 'restored' => $result['restored'] ] );
		}
		return $this->ok( $result + [ 'status' => $this->status() ] );
	}

	private function htaccessFile(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	private function writer( string $file ): HtaccessWriter {
		return new HtaccessWriter( $file, \Closure::fromCallable( [ self::class, 'probe' ] ), self::checkUrls() );
	}

	/**
	 * Portada, un producto (si hay WooCommerce) y la REST API.
	 *
	 * @return list<string>
	 */
	public static function checkUrls(): array {
		$urls = [ home_url( '/' ) ];
		if ( function_exists( 'wc_get_products' ) ) {
			$ids = wc_get_products(
				[
					'limit'  => 1,
					'status' => 'publish',
					'return' => 'ids',
				]
			);
			if ( is_array( $ids ) && isset( $ids[0] ) && is_int( $ids[0] ) ) {
				$link = get_permalink( $ids[0] );
				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
		}
		$urls[] = rest_url();
		return $urls;
	}

	/**
	 * Estado HTTP de una URL como visitante anónimo, sin caché (parámetro único).
	 */
	public static function probe( string $url ): int {
		$response = wp_remote_get(
			add_query_arg( 'itb_fw_check', (string) wp_rand(), $url ),
			[
				'timeout'     => 15,
				'redirection' => 3,
				'user-agent'  => Warmer::USER_AGENT,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core para loopback.
			]
		);
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	private function service(): CloudflareService {
		return $this->container->get( CloudflareService::class );
	}
}
