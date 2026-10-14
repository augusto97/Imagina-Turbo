<?php
/**
 * Inspección de assets desde el admin: pide páginas al propio sitio como visitante anónimo con la
 * cookie firmada de inventario y completa lo que devuelven (origen, tamaño, quién lo necesita,
 * reglas que aplican). También busca dónde se usan los formularios, sliders y bloques de la tienda
 * para las sugerencias. Solo en el admin: nunca en el frontend.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\AssetManager;

use ImaginaTurbo\Core\Safety\AdminBypassCookie;
use ImaginaTurbo\Modules\PluginFilter\LoopbackClient;

/**
 * Escáner.
 *
 * @phpstan-import-type Usage from AssetSuggestions
 */
final class AssetScanner {

	/** Páginas de muestra para las sugerencias. */
	public const SAMPLE_MAX = 6;

	/** @var \Closure(string, string, string, string): (array{status: int, headers: array<string, string>, body: string}|string) */
	private \Closure $http;

	/**
	 * @param (\Closure(string, string, string, string): (array{status: int, headers: array<string, string>, body: string}|string))|null $http Cliente (pruebas).
	 */
	public function __construct( private readonly AssetRules $rules, ?\Closure $http = null ) {
		$this->http = $http ?? \Closure::fromCallable( [ LoopbackClient::class, 'request' ] );
	}

	/**
	 * Inventario completo de una ruta del sitio.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function inspect( string $path ): array|\WP_Error {
		$key = AdminBypassCookie::key( AssetInventory::COOKIE );
		if ( null === $key ) {
			return new \WP_Error( 'itb_keys', __( 'Faltan AUTH_KEY y AUTH_SALT en wp-config.php.', 'imagina-turbo' ), [ 'status' => 409 ] );
		}
		$nonce  = strtolower( wp_generate_password( 16, false ) );
		$cookie = AdminBypassCookie::create( max( 1, get_current_user_id() ), time() + AssetInventory::TTL, $key );
		$reply  = ( $this->http )( 'GET', $path . ( str_contains( $path, '?' ) ? '&' : '?' ) . 'itb_nc=' . $nonce, '', AssetInventory::COOKIE . '=' . rawurlencode( $cookie ) );
		if ( is_string( $reply ) ) {
			return new \WP_Error( 'itb_loopback', $reply, [ 'status' => 502 ] );
		}
		$data = AssetInventory::take( $nonce );
		if ( null === $data ) {
			return new \WP_Error(
				'itb_inventory',
				sprintf(
					/* translators: %d: código HTTP */
					__( 'La página respondió %d sin inventario (¿no es una página HTML del sitio o la sirvió una caché?).', 'imagina-turbo' ),
					$reply['status']
				),
				[ 'status' => 502 ]
			);
		}
		return $this->enrich( $data, $path, $reply['status'] );
	}

	/**
	 * Agrega origen, tamaño, dependientes y reglas que aplican.
	 *
	 * @param array<string, mixed> $data Inventario crudo.
	 * @return array<string, mixed>
	 */
	public function enrich( array $data, string $path, int $status ): array {
		$context = [
			'template'  => is_string( $data['template'] ?? null ) ? $data['template'] : '',
			'id'        => (int) ( $data['id'] ?? 0 ),
			'post_type' => is_string( $data['post_type'] ?? null ) ? $data['post_type'] : '',
		];
		$rules   = $this->rules->all();
		$out     = [];
		foreach ( [ 'js', 'css' ] as $type ) {
			$items = is_array( $data[ $type ] ?? null ) ? array_values( array_filter( $data[ $type ], 'is_array' ) ) : [];
			$needs = [];
			foreach ( $items as $item ) {
				foreach ( is_array( $item['deps'] ?? null ) ? $item['deps'] : [] as $dep ) {
					$needs[ (string) $dep ][] = (string) ( $item['handle'] ?? '' );
				}
			}
			$list = [];
			foreach ( $items as $item ) {
				$handle  = (string) ( $item['handle'] ?? '' );
				$src     = is_string( $item['src'] ?? null ) ? $item['src'] : '';
				$origin  = self::origin( $src );
				$applied = [];
				foreach ( $rules as $rule ) {
					if ( $rule['type'] === $type && $rule['handle'] === $handle && 'disabled' !== $rule['status'] && AssetRules::removes( self::short( $rule ), $context ) ) {
						$applied[] = [
							'id'     => $rule['id'],
							'status' => $rule['status'],
						];
					}
				}
				$list[] = [
					'handle'    => $handle,
					'src'       => $src,
					'origin'    => $origin['kind'],
					'owner'     => $origin['owner'],
					'size'      => self::size( $src ),
					'deps'      => array_values( array_map( 'strval', is_array( $item['deps'] ?? null ) ? $item['deps'] : [] ) ),
					'footer'    => true === ( $item['footer'] ?? false ),
					'enqueued'  => true === ( $item['enqueued'] ?? false ),
					'needed_by' => $needs[ $handle ] ?? [],
					'protected' => AssetManagerModule::protectedHandle( $handle ),
					'rules'     => $applied,
				];
			}
			$out[ $type ] = $list;
		}
		return [
			'path'       => $path,
			'status'     => $status,
			'context'    => $context,
			'js'         => $out['js'],
			'css'        => $out['css'],
			'shortcodes' => array_values( array_filter( is_array( $data['shortcodes'] ?? null ) ? $data['shortcodes'] : [], 'is_string' ) ),
			'blocks'     => array_values( array_filter( is_array( $data['blocks'] ?? null ) ? $data['blocks'] : [], 'is_string' ) ),
		];
	}

	/**
	 * Sugerencias: inventaría unas páginas de muestra y busca el uso en el contenido.
	 *
	 * @param array<string, string> $active Carpeta => nombre de los plugins activos.
	 * @return array<string, mixed>
	 */
	public function suggest( array $active ): array {
		$pages  = [];
		$errors = [];
		foreach ( $this->samplePaths() as $path ) {
			$page = $this->inspect( $path );
			if ( is_wp_error( $page ) ) {
				$errors[] = $path . ': ' . $page->get_error_message();
				continue;
			}
			$pages[] = $page;
		}

		// Assets por carpeta de plugin, con las plantillas donde aparecieron.
		$assets = [];
		foreach ( $pages as $page ) {
			foreach ( [ 'js', 'css' ] as $type ) {
				foreach ( $page[ $type ] as $asset ) {
					if ( 'plugin' !== $asset['origin'] ) {
						continue;
					}
					$k = $type . '|' . $asset['handle'];
					if ( ! isset( $assets[ $asset['owner'] ][ $k ] ) ) {
						$assets[ $asset['owner'] ][ $k ] = $asset + [
							'type'      => $type,
							'templates' => [],
						];
					}
					$entry                = &$assets[ $asset['owner'] ][ $k ];
					$entry['templates'][] = $page['context']['template'];
					// En alguna página otro asset lo necesita: no se puede quitar.
					$entry['needed_by'] = array_values( array_unique( array_merge( $entry['needed_by'], $asset['needed_by'] ) ) );
					$entry['enqueued']  = $entry['enqueued'] && $asset['enqueued'];
					unset( $entry );
				}
			}
		}
		$assets = array_map( 'array_values', $assets );

		$usage = [];
		foreach ( AssetSuggestions::catalog() as $folder => $info ) {
			if ( isset( $active[ $folder ] ) && [] !== ( $assets[ $folder ] ?? [] ) ) {
				$usage[ $folder ] = $this->usage( $info['markers'], $info['elementor'], $pages );
			}
		}
		if ( isset( $active['woocommerce'] ) ) {
			$usage['woocommerce'] = $this->usage( AssetSuggestions::WOO_MARKERS, AssetSuggestions::WOO_ELEMENTOR, [] );
		}
		$wooIds = [];
		foreach ( [ 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ] as $option ) {
			$id = (int) get_option( $option, 0 );
			if ( $id > 0 ) {
				$wooIds[] = $id;
			}
		}
		$result = AssetSuggestions::build( $assets, $usage, $active, $wooIds );
		// Fuera las que ya existen como regla.
		$existing        = $this->rules->all();
		$result['rules'] = array_values(
			array_filter(
				$result['rules'],
				static function ( array $s ) use ( $existing ): bool {
					$rule = AssetRules::normalize( $s );
					return null !== $rule && ! isset( $existing[ $rule['id'] ] );
				}
			)
		);
		return $result + [
			'scanned' => array_map(
				static fn( array $p ): array => [
					'path'     => $p['path'],
					'template' => $p['context']['template'],
				],
				$pages
			),
			'errors'  => $errors,
		];
	}

	/**
	 * Portada y una página de cada tipo importante.
	 *
	 * @return list<string>
	 */
	public function samplePaths(): array {
		$urls = [ home_url( '/' ) ];
		foreach ( [ 'post', 'page', 'product' ] as $type ) {
			if ( ! post_type_exists( $type ) ) {
				continue;
			}
			$exclude = 'page' === $type ? array_filter( [ (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ] ) : [];
			$posts   = get_posts(
				[
					'post_type'        => $type,
					'post_status'      => 'publish',
					'numberposts'      => 1,
					'exclude'          => $exclude, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- una consulta en el admin.
					'suppress_filters' => false,
					'fields'           => 'ids',
				]
			);
			if ( [] !== $posts ) {
				$link = get_permalink( (int) $posts[0] );
				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop = get_permalink( (int) wc_get_page_id( 'shop' ) );
			if ( is_string( $shop ) ) {
				$urls[] = $shop;
			}
		}
		$home  = untrailingslashit( home_url() );
		$paths = [];
		foreach ( $urls as $url ) {
			if ( str_starts_with( $url, $home ) ) {
				$paths[] = '/' . ltrim( substr( $url, strlen( $home ) ), '/' );
			}
		}
		return array_slice( array_values( array_unique( $paths ) ), 0, self::SAMPLE_MAX );
	}

	/**
	 * Dónde se usa un plugin: contenido publicado, datos de Elementor y widgets. Si en alguna página
	 * de muestra se renderizó su shortcode o bloque sin que esa página lo tenga en el contenido, lo
	 * pone el tema o una plantilla: se usa en cualquier página.
	 *
	 * @param list<string>               $markers   Shortcodes y bloques.
	 * @param list<string>               $elementor Nombres a buscar en los datos de Elementor.
	 * @param list<array<string, mixed>> $pages     Páginas inventariadas.
	 * @return Usage
	 */
	public function usage( array $markers, array $elementor, array $pages ): array {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return [
				'global' => true,
				'ids'    => [],
			];
		}
		$posts = [];
		foreach ( $markers as $marker ) {
			$sql  = $wpdb->prepare( "SELECT ID AS id, post_type FROM %i WHERE post_status IN ('publish','private','future') AND post_type NOT IN ('revision','nav_menu_item') AND post_content LIKE %s LIMIT 60", $wpdb->posts, '%' . $wpdb->esc_like( $marker ) . '%' );
			$rows = null === $sql ? [] : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- búsqueda puntual en el admin, consulta preparada arriba.
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$posts[] = [
					'id'        => (int) $row['id'],
					'post_type' => (string) $row['post_type'],
				];
			}
		}
		foreach ( $elementor as $name ) {
			$sql  = $wpdb->prepare( "SELECT p.ID AS id, p.post_type FROM %i m INNER JOIN %i p ON p.ID = m.post_id WHERE m.meta_key = '_elementor_data' AND p.post_status IN ('publish','private','future') AND m.meta_value LIKE %s LIMIT 60", $wpdb->postmeta, $wpdb->posts, '%' . $wpdb->esc_like( $name ) . '%' );
			$rows = null === $sql ? [] : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- búsqueda puntual en el admin, consulta preparada arriba.
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$posts[] = [
					'id'        => (int) $row['id'],
					'post_type' => (string) $row['post_type'],
				];
			}
		}
		$widgets = false;
		foreach ( [ 'widget_block', 'widget_text', 'widget_custom_html' ] as $option ) {
			$value = maybe_serialize( get_option( $option, [] ) );
			foreach ( $markers as $marker ) {
				if ( is_string( $value ) && str_contains( $value, $marker ) ) {
					$widgets = true;
				}
			}
		}
		$usage = AssetSuggestions::usage( $posts, $widgets );
		foreach ( $pages as $page ) {
			$id       = (int) ( $page['context']['id'] ?? 0 );
			$rendered = array_merge(
				array_map( static fn( string $s ): string => '[' . $s, is_array( $page['shortcodes'] ?? null ) ? $page['shortcodes'] : [] ),
				array_map( static fn( string $b ): string => 'wp:' . $b, is_array( $page['blocks'] ?? null ) ? $page['blocks'] : [] )
			);
			foreach ( $markers as $marker ) {
				foreach ( $rendered as $item ) {
					if ( ( str_starts_with( $item, $marker ) || str_starts_with( $marker, $item ) ) && ! in_array( $id, $usage['ids'], true ) ) {
						$usage['global'] = true;
					}
				}
			}
		}
		return $usage;
	}

	/**
	 * Quién pone el asset: plugin (carpeta), tema, núcleo o un dominio externo.
	 *
	 * @return array{kind: string, owner: string}
	 */
	public static function origin( string $src ): array {
		if ( '' === $src ) {
			return [
				'kind'  => 'inline',
				'owner' => '',
			];
		}
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$host = (string) wp_parse_url( $src, PHP_URL_HOST );
		if ( '' !== $host && strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return [
				'kind'  => 'external',
				'owner' => strtolower( $host ),
			];
		}
		if ( 1 === preg_match( '#/plugins/([^/]+)/#', $path, $m ) ) {
			return [
				'kind'  => 'plugin',
				'owner' => $m[1],
			];
		}
		if ( 1 === preg_match( '#/themes/([^/]+)/#', $path, $m ) ) {
			return [
				'kind'  => 'theme',
				'owner' => $m[1],
			];
		}
		if ( str_contains( $path, '/wp-includes/' ) || str_contains( $path, '/wp-admin/' ) ) {
			return [
				'kind'  => 'core',
				'owner' => 'wordpress',
			];
		}
		return [
			'kind'  => 'other',
			'owner' => '',
		];
	}

	/**
	 * Tamaño del archivo local (bytes), o 0 si no es del sitio.
	 */
	public static function size( string $src ): int {
		if ( '' === $src ) {
			return 0;
		}
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$host = (string) wp_parse_url( $src, PHP_URL_HOST );
		if ( '' !== $host && strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return 0;
		}
		$content = (string) wp_parse_url( content_url(), PHP_URL_PATH );
		$site    = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		if ( '' !== $content && str_starts_with( $path, $content . '/' ) ) {
			$file = WP_CONTENT_DIR . substr( $path, strlen( $content ) );
		} elseif ( str_starts_with( $path, $site . '/wp-includes/' ) || str_starts_with( $path, '/wp-includes/' ) ) {
			$file = ABSPATH . 'wp-includes/' . substr( $path, (int) strpos( $path, '/wp-includes/' ) + 13 );
		} else {
			return 0;
		}
		if ( str_contains( $file, '..' ) || ! is_file( $file ) ) {
			return 0;
		}
		$size = filesize( $file );
		return false === $size ? 0 : $size;
	}

	/**
	 * @param array{mode: string, templates: list<string>, ids: list<int>, post_types: list<string>} $rule Regla completa.
	 * @return array{m: string, tpl: list<string>, ids: list<int>, pt: list<string>}
	 */
	public static function short( array $rule ): array {
		return [
			'm'   => $rule['mode'],
			'tpl' => $rule['templates'],
			'ids' => $rule['ids'],
			'pt'  => $rule['post_types'],
		];
	}
}
