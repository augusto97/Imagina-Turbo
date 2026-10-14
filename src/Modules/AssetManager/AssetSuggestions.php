<?php
/**
 * Sugerencias de reglas del gestor de assets (docs/02 módulo 8). Cálculo puro: el uso en el
 * contenido y el inventario llegan ya resueltos.
 *
 * - Formularios y sliders: sus assets solo donde se usan (shortcode, bloque o widget de Elementor
 *   en el contenido). Si aparecen en un widget, una plantilla del tema o un bloque reutilizable, se
 *   usan en cualquier página y no se sugiere nada. Si no se usan en ningún contenido, se sugiere
 *   revisar el plugin (desactivarlo es mejor que quitar sus assets).
 * - WooCommerce: sus assets solo en las páginas de la tienda y donde el contenido muestre productos.
 *   Moderado: el mini carrito del encabezado puede necesitarlos; requiere verificación.
 *
 * Solo se sugieren handles encolados directamente: uno que otro asset necesita como dependencia
 * se seguiría imprimiendo igual.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\AssetManager;

/**
 * Catálogo y cálculo.
 *
 * @phpstan-type Usage array{global: bool, ids: list<int>}
 * @phpstan-type Suggestion array{plugin: string, label: string, kind: string, risk: string, handle: string, type: string, mode: string, templates: list<string>, ids: list<int>, note: string}
 * @phpstan-type Unused array{plugin: string, label: string, kind: string}
 */
final class AssetSuggestions {

	/** Plantillas de la tienda (RouteKey::template()). */
	public const WOO_TEMPLATES = [ 'archivo:product', 'single:product', 'tax:product_cat', 'tax:product_tag', 'tax:product_brand' ];

	/**
	 * Assets de WooCommerce que se necesitan en cualquier página: la atribución de pedidos registra
	 * la página por la que entró el visitante (casi nunca es de la tienda) y los fragmentos del
	 * carrito mantienen el contador del encabezado.
	 */
	public const WOO_KEEP = [ 'wc-order-attribution', 'sourcebuster-js', 'wc-cart-fragments' ];

	/** Más páginas que esto: el plugin se usa en todo el sitio. */
	public const MAX_IDS = 50;

	/**
	 * Plugins de formularios y sliders: carpeta => etiqueta, tipo y marcas en el contenido.
	 * Las marcas son shortcodes y nombres de bloque de cada plugin; para Elementor se busca el nombre
	 * del plugin en sus datos (sus widgets guardan ahí el formulario o slider elegido).
	 *
	 * @return array<string, array{label: string, kind: string, markers: list<string>, elementor: list<string>}>
	 */
	public static function catalog(): array {
		return [
			'contact-form-7'            => [
				'label'     => 'Contact Form 7',
				'kind'      => 'form',
				'markers'   => [ '[contact-form-7', '[contact-form ', 'wp:contact-form-7/' ],
				'elementor' => [ 'contact-form-7' ],
			],
			'wpforms-lite'              => [
				'label'     => 'WPForms',
				'kind'      => 'form',
				'markers'   => [ '[wpforms', 'wp:wpforms/' ],
				'elementor' => [ 'wpforms' ],
			],
			'wpforms'                   => [
				'label'     => 'WPForms',
				'kind'      => 'form',
				'markers'   => [ '[wpforms', 'wp:wpforms/' ],
				'elementor' => [ 'wpforms' ],
			],
			'fluentform'                => [
				'label'     => 'Fluent Forms',
				'kind'      => 'form',
				'markers'   => [ '[fluentform', 'wp:fluentfom/', 'wp:fluentform/' ],
				'elementor' => [ 'fluent' ],
			],
			'gravityforms'              => [
				'label'     => 'Gravity Forms',
				'kind'      => 'form',
				'markers'   => [ '[gravityform', 'wp:gravityforms/' ],
				'elementor' => [ 'gravity' ],
			],
			'ninja-forms'               => [
				'label'     => 'Ninja Forms',
				'kind'      => 'form',
				'markers'   => [ '[ninja_form', 'wp:ninja-forms/' ],
				'elementor' => [ 'ninja' ],
			],
			'formidable'                => [
				'label'     => 'Formidable Forms',
				'kind'      => 'form',
				'markers'   => [ '[formidable', 'wp:formidable/' ],
				'elementor' => [ 'formidable' ],
			],
			'revslider'                 => [
				'label'     => 'Slider Revolution',
				'kind'      => 'slider',
				'markers'   => [ '[rev_slider', 'wp:themepunch/' ],
				'elementor' => [ 'rev_slider', 'revslider' ],
			],
			'smart-slider-3'            => [
				'label'     => 'Smart Slider 3',
				'kind'      => 'slider',
				'markers'   => [ '[smartslider3', 'wp:nextend/' ],
				'elementor' => [ 'smartslider' ],
			],
			'nextend-smart-slider3-pro' => [
				'label'     => 'Smart Slider 3 Pro',
				'kind'      => 'slider',
				'markers'   => [ '[smartslider3', 'wp:nextend/' ],
				'elementor' => [ 'smartslider' ],
			],
			'ml-slider'                 => [
				'label'     => 'MetaSlider',
				'kind'      => 'slider',
				'markers'   => [ '[metaslider', 'wp:metaslider/' ],
				'elementor' => [ 'metaslider' ],
			],
		];
	}

	/** Marcas de WooCommerce en el contenido (shortcodes y bloques que muestran productos o el carrito). */
	/**
	 * Widgets de Elementor que pintan productos con botón de carrito (WooCommerce de Elementor Pro y
	 * WoodMart). Sin ellos, una portada de WoodMart con carruseles de productos parecía no usar la
	 * tienda y se sugería quitar wc-add-to-cart (0.10.5).
	 */
	public const WOO_ELEMENTOR = [ '"widgetType":"woocommerce-product', '"widgetType":"wc-', '"widgetType":"wd_product', '"widgetType":"wd_products' ];

	public const WOO_MARKERS = [ '[products', '[product_', '[add_to_cart', '[woocommerce_', '[recent_products', '[featured_products', '[sale_products', '[best_selling_products', '[top_rated_products', 'wp:woocommerce/' ];

	/** Tipos de contenido que se muestran en cualquier página (plantillas, partes, bloques reutilizables). */
	public const GLOBAL_TYPES = [ 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'elementor_library', 'et_pb_layout', 'fl-builder-template', 'ct_template' ];

	/**
	 * @param array<string, list<array<string, mixed>>> $assets  Assets vistos por carpeta de plugin (handle, type, enqueued, needed_by, templates).
	 * @param array<string, Usage>                       $usage   Uso en el contenido por carpeta de plugin (y "woocommerce").
	 * @param array<string, string>                      $active  Carpeta => nombre de los plugins activos.
	 * @param list<int>                                  $wooIds  Carrito, checkout y cuenta.
	 * @return array{rules: list<Suggestion>, unused: list<Unused>}
	 */
	public static function build( array $assets, array $usage, array $active, array $wooIds ): array {
		$rules  = [];
		$unused = [];
		foreach ( self::catalog() as $folder => $info ) {
			if ( ! isset( $active[ $folder ] ) || [] === ( $assets[ $folder ] ?? [] ) ) {
				continue;
			}
			$use = $usage[ $folder ] ?? [
				'global' => false,
				'ids'    => [],
			];
			if ( $use['global'] || count( $use['ids'] ) > self::MAX_IDS ) {
				continue;
			}
			if ( [] === $use['ids'] ) {
				$unused[] = [
					'plugin' => $folder,
					'label'  => $info['label'],
					'kind'   => $info['kind'],
				];
				continue;
			}
			foreach ( self::removable( $assets[ $folder ] ) as $asset ) {
				$rules[] = [
					'plugin'    => $folder,
					'label'     => $info['label'],
					'kind'      => $info['kind'],
					'risk'      => 'moderate',
					'handle'    => (string) $asset['handle'],
					'type'      => (string) $asset['type'],
					'mode'      => 'only',
					'templates' => [],
					'ids'       => $use['ids'],
					'note'      => sprintf(
						/* translators: 1: plugin, 2: número de páginas */
						__( '%1$s solo se usa en %2$d páginas.', 'imagina-turbo' ),
						$info['label'],
						count( $use['ids'] )
					),
				];
			}
		}

		$woo = $usage['woocommerce'] ?? [
			'global' => false,
			'ids'    => [],
		];
		if ( isset( $active['woocommerce'] ) && ! $woo['global'] && count( $woo['ids'] ) <= self::MAX_IDS ) {
			$ids = array_values( array_unique( array_merge( $wooIds, $woo['ids'] ) ) );
			foreach ( self::removable( $assets['woocommerce'] ?? [] ) as $asset ) {
				// Solo lo que se cargó fuera de la tienda.
				$outside = array_diff( is_array( $asset['templates'] ?? null ) ? $asset['templates'] : [], self::WOO_TEMPLATES );
				if ( [] === $outside || in_array( $asset['handle'] ?? '', self::WOO_KEEP, true ) ) {
					continue;
				}
				$rules[] = [
					'plugin'    => 'woocommerce',
					'label'     => 'WooCommerce',
					'kind'      => 'woo',
					'risk'      => 'moderate',
					'handle'    => (string) $asset['handle'],
					'type'      => (string) $asset['type'],
					'mode'      => 'only',
					'templates' => self::WOO_TEMPLATES,
					'ids'       => $ids,
					'note'      => __( 'Assets de la tienda fuera de la tienda. Comprueba el mini carrito del encabezado al previsualizar.', 'imagina-turbo' ),
				];
			}
		}
		return [
			'rules'  => $rules,
			'unused' => $unused,
		];
	}

	/**
	 * Handles que una regla puede quitar de verdad: encolados directamente y que ningún otro asset
	 * de la página necesita.
	 *
	 * @param list<array<string, mixed>> $assets Assets de un plugin.
	 * @return list<array<string, mixed>>
	 */
	public static function removable( array $assets ): array {
		return array_values(
			array_filter(
				$assets,
				static fn( array $a ): bool => true === ( $a['enqueued'] ?? false ) && [] === ( $a['needed_by'] ?? [] ) && ! AssetManagerModule::protectedHandle( (string) ( $a['handle'] ?? '' ) )
			)
		);
	}

	/**
	 * Uso a partir de los contenidos encontrados.
	 *
	 * @param list<array{id: int, post_type: string}> $posts   Contenidos que contienen alguna marca.
	 * @param bool                                    $widgets Alguna marca aparece en un widget.
	 * @return Usage
	 */
	public static function usage( array $posts, bool $widgets ): array {
		$global = $widgets;
		$ids    = [];
		foreach ( $posts as $post ) {
			if ( in_array( $post['post_type'], self::GLOBAL_TYPES, true ) ) {
				$global = true;
			} else {
				$ids[] = (int) $post['id'];
			}
		}
		sort( $ids );
		return [
			'global' => $global,
			'ids'    => array_values( array_unique( $ids ) ),
		];
	}
}
