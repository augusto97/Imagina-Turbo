<?php
/**
 * Lote de telemetría para el hub: solo agregados técnicos.
 *
 * Nunca incluye datos de clientes finales, correos, pedidos, IPs, contenido ni el contexto de los
 * eventos (que puede llevar rutas o datos del sitio): solo tipo, módulo, nivel y mensaje.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Hub;

use ImaginaTurbo\Core\Config\Settings;
use ImaginaTurbo\Core\Environment\Detector;
use ImaginaTurbo\Core\ModuleCatalog;
use ImaginaTurbo\Core\ModuleRegistry;
use ImaginaTurbo\Repository\EventRepository;
use ImaginaTurbo\Repository\MetricsRepository;

/**
 * Construcción del lote.
 */
final class TelemetryBuilder {

	public const SCHEMA = 1;

	private const MAX_EVENTS = 200;

	public function __construct(
		private readonly Detector $detector,
		private readonly MetricsRepository $metrics,
		private readonly EventRepository $events,
		private readonly Settings $settings,
		private readonly ModuleCatalog $catalog,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build( string $siteId, int $from, int $to ): array {
		$env = $this->detector->detect();

		$modules = [];
		foreach ( ModuleRegistry::states( $this->catalog, $this->settings->modules(), [] ) as $id => $state ) {
			$modules[ $id ] = $state['enabled'];
		}

		$events = $this->events->query(
			[
				'severity' => 1,
				'since'    => gmdate( 'Y-m-d H:i:s', $from ),
				'until'    => gmdate( 'Y-m-d H:i:s', $to ),
			],
			1,
			self::MAX_EVENTS
		);

		return [
			'schema'         => self::SCHEMA,
			'site_id'        => $siteId,
			'plugin_version' => ITB_VERSION,
			'generated_at'   => gmdate( 'c' ),
			'window'         => [
				'from' => gmdate( 'c', $from ),
				'to'   => gmdate( 'c', $to ),
			],
			'environment'    => [
				'wordpress'    => $env['wordpress'] ?? '',
				'php'          => PHP_VERSION,
				'server'       => is_array( $env['server'] ?? null ) ? ( $env['server']['family'] ?? '' ) : '',
				'cache_plugin' => $env['cache_plugin'] ?? null,
				'woocommerce'  => is_array( $env['woocommerce'] ?? null ) ? ( $env['woocommerce']['version'] ?? null ) : null,
				'multilingual' => $env['multilingual'] ?? null,
				'object_cache' => (bool) ( $env['object_cache'] ?? false ),
				'opcache'      => (bool) ( $env['opcache'] ?? false ),
			],
			'inventory'      => $this->inventory(),
			'modules'        => $modules,
			'metrics'        => $this->metrics->between( gmdate( 'Y-m-d H:00:00', $from ), gmdate( 'Y-m-d H:00:00', $to ) ),
			'events'         => array_map(
				static fn( array $e ): array => [
					'created_at' => $e['created_at'],
					'module'     => $e['module'],
					'type'       => $e['type'],
					'severity'   => $e['severity'],
					'message'    => $e['message'],
				],
				$events['items']
			),
		];
	}

	/**
	 * Plugins activos (slug + versión) y tema.
	 *
	 * @return array{plugins: list<array{slug: string, version: string}>, theme: array{stylesheet: string, template: string, version: string}}
	 */
	private function inventory(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all     = get_plugins();
		$plugins = [];
		foreach ( $this->detector->activePlugins() as $file ) {
			$slug      = str_contains( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
			$plugins[] = [
				'slug'    => $slug,
				'version' => isset( $all[ $file ]['Version'] ) && is_string( $all[ $file ]['Version'] ) ? $all[ $file ]['Version'] : '',
			];
		}
		$theme = wp_get_theme();
		return [
			'plugins' => $plugins,
			'theme'   => [
				'stylesheet' => get_stylesheet(),
				'template'   => get_template(),
				'version'    => (string) $theme->get( 'Version' ),
			],
		];
	}
}
