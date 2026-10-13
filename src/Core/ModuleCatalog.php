<?php
/**
 * Catálogo de módulos del plugin.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core;

/**
 * Lista fija de módulos con sus metadatos. Un módulo sin clase todavía no está implementado:
 * aparece en el admin como "próximamente" y nunca se activa.
 */
final class ModuleCatalog {

	/**
	 * Clases implementadas por id. Es lo único que se consulta en el frontend.
	 * Cada fase agrega aquí sus módulos.
	 *
	 * @return array<string, class-string<ModuleInterface>>
	 */
	public static function classMap(): array {
		return [
			'profiler'          => \ImaginaTurbo\Modules\Profiler\ProfilerModule::class,
			'cache_coordinator' => \ImaginaTurbo\Modules\CacheCoordinator\CacheModule::class,
			'cloudflare'        => \ImaginaTurbo\Modules\Cloudflare\CloudflareModule::class,
			'woo_cart'          => \ImaginaTurbo\Modules\WooCart\WooCartModule::class,
			'shield'            => \ImaginaTurbo\Modules\Shield\ShieldModule::class,
			'tweaks'            => \ImaginaTurbo\Modules\Tweaks\TweaksModule::class,
			'firewall'          => \ImaginaTurbo\Modules\Firewall\FirewallModule::class,
		];
	}

	/** @var array<string, ModuleDefinition>|null */
	private ?array $definitions = null;

	/**
	 * @return array<string, ModuleDefinition>
	 */
	public function all(): array {
		if ( null === $this->definitions ) {
			$this->definitions = [];
			foreach ( $this->build() as $definition ) {
				$this->definitions[ $definition->id ] = $definition;
			}
		}
		return $this->definitions;
	}

	public function get( string $id ): ?ModuleDefinition {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * @return list<ModuleDefinition>
	 */
	private function build(): array {
		$m = static fn( string $id, string $label, string $description, RiskLevel $risk, bool $default, int $phase ): ModuleDefinition => new ModuleDefinition(
			$id,
			$label,
			$description,
			$risk,
			$default && $risk->allowsDefaultOn(),
			$phase,
			self::classMap()[ $id ] ?? null
		);

		return [
			$m( 'profiler', __( 'Perfilador', 'imagina-turbo' ), __( 'Mide el costo de cada plugin y fase del request.', 'imagina-turbo' ), RiskLevel::Safe, true, 1 ),
			$m( 'cache_coordinator', __( 'Coordinador de caché', 'imagina-turbo' ), __( 'Etiqueta páginas, emite cabeceras para el edge y sincroniza purgas.', 'imagina-turbo' ), RiskLevel::Moderate, false, 2 ),
			$m( 'cloudflare', __( 'Cloudflare', 'imagina-turbo' ), __( 'Reglas de caché, purga, ajustes de zona y snapshots por API.', 'imagina-turbo' ), RiskLevel::Moderate, false, 2 ),
			$m( 'woo_cart', __( 'Carrito por Store API', 'imagina-turbo' ), __( 'Hace cacheable el catálogo aunque el visitante tenga carrito.', 'imagina-turbo' ), RiskLevel::Moderate, false, 3 ),
			$m( 'shield', __( 'Escudo de servidor', 'imagina-turbo' ), __( 'Corta tráfico basura antes de cargar plugins (404 de assets, enumeración, XML-RPC).', 'imagina-turbo' ), RiskLevel::Safe, true, 4 ),
			$m( 'tweaks', __( 'Ajustes rápidos', 'imagina-turbo' ), __( 'Reemplazo de los ajustes de Perfmatters, cada uno con su interruptor.', 'imagina-turbo' ), RiskLevel::Safe, true, 4 ),
			$m( 'firewall', __( 'Firewall', 'imagina-turbo' ), __( 'Reglas WAF en Cloudflare inspiradas en 8G, rate limiting y cierre del origen.', 'imagina-turbo' ), RiskLevel::Moderate, false, 4 ),
			$m( 'plugin_filter', __( 'Carga condicional de plugins', 'imagina-turbo' ), __( 'Quita plugins innecesarios por tipo de request o endpoint, solo con reglas verificadas.', 'imagina-turbo' ), RiskLevel::Invasive, false, 5 ),
			$m( 'speculation', __( 'Navegación instantánea', 'imagina-turbo' ), __( 'Speculation Rules (prefetch), bfcache y View Transitions.', 'imagina-turbo' ), RiskLevel::Safe, true, 6 ),
			$m( 'rum', __( 'Métricas reales (RUM)', 'imagina-turbo' ), __( 'LCP, INP, CLS y TTFB de visitantes reales, muestreados.', 'imagina-turbo' ), RiskLevel::Safe, true, 6 ),
			$m( 'frontend_hints', __( 'Pistas de frontend', 'imagina-turbo' ), __( 'Prioriza la imagen LCP y agrega fachadas para videos.', 'imagina-turbo' ), RiskLevel::Safe, true, 6 ),
			$m( 'asset_manager', __( 'Gestor de assets', 'imagina-turbo' ), __( 'Desencola CSS/JS que una ruta no usa.', 'imagina-turbo' ), RiskLevel::Moderate, false, 7 ),
			$m( 'database', __( 'Base de datos', 'imagina-turbo' ), __( 'Auditoría y limpieza con respaldo.', 'imagina-turbo' ), RiskLevel::Moderate, false, 7 ),
		];
	}
}
