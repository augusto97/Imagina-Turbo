<?php
/**
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Tests\Unit;

use ImaginaTurbo\Core\Request\RequestClassifier;
use ImaginaTurbo\Core\Request\RequestType;
use PHPUnit\Framework\Attributes\DataProvider;

final class RequestClassifierTest extends TestCase {

	private const CONFIG = [
		'home_path'          => '',
		'rest_prefix'        => 'wp-json',
		'lang_prefixes'      => [ 'en' ],
		'paths'              => [
			'cart'     => [ '/carrito', '/en/cart' ],
			'checkout' => [ '/finalizar-compra' ],
			'account'  => [ '/mi-cuenta' ],
			'login'    => [ '/acceso' ],
		],
		'static_passthrough' => [ '/wp-content/uploads/al-vuelo/' ],
	];

	/**
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>, 4: array<string, bool>, 5: RequestType, 6: string}>
	 */
	public static function cases(): array {
		return [
			'portada'                    => [ 'GET', '/', [], [], [], RequestType::FrontendHtml, '' ],
			'entrada'                    => [ 'GET', '/blog/hola-mundo/', [], [], [], RequestType::FrontendHtml, '' ],
			'HEAD es frontend'           => [ 'HEAD', '/tienda/', [], [], [], RequestType::FrontendHtml, '' ],
			'add-to-cart sigue html'     => [ 'GET', '/tienda/?add-to-cart=12', [ 'add-to-cart' => '12' ], [], [], RequestType::FrontendHtml, '' ],
			'wc-ajax'                    => [ 'POST', '/?wc-ajax=add_to_cart', [ 'wc-ajax' => 'add_to_cart' ], [], [], RequestType::WcAjax, 'add_to_cart' ],
			'wc-ajax con basura'         => [ 'GET', '/?wc-ajax=get<script>', [ 'wc-ajax' => 'Get<Script>' ], [], [], RequestType::WcAjax, 'getscript' ],
			'admin-ajax action por POST' => [ 'POST', '/wp-admin/admin-ajax.php', [], [ 'action' => 'heartbeat' ], [], RequestType::AdminAjax, 'heartbeat' ],
			'admin-ajax action por GET'  => [ 'GET', '/wp-admin/admin-ajax.php?action=x_y', [ 'action' => 'x_y' ], [], [], RequestType::AdminAjax, 'x_y' ],
			'admin-ajax POST gana'       => [ 'POST', '/wp-admin/admin-ajax.php?action=a', [ 'action' => 'a' ], [ 'action' => 'b' ], [], RequestType::AdminAjax, 'b' ],
			'constante DOING_AJAX'       => [ 'POST', '/cualquier', [], [ 'action' => 'z' ], [ 'ajax' => true ], RequestType::AdminAjax, 'z' ],
			'rest'                       => [ 'GET', '/wp-json/wp/v2/posts', [], [], [], RequestType::Rest, 'wp/v2' ],
			'rest raíz'                  => [ 'GET', '/wp-json/', [], [], [], RequestType::Rest, '' ],
			'rest_route'                 => [ 'GET', '/?rest_route=/imagina-search/v1/q', [ 'rest_route' => '/imagina-search/v1/q' ], [], [], RequestType::Rest, 'imagina-search/v1' ],
			'store api'                  => [ 'GET', '/wp-json/wc/store/v1/cart', [], [], [], RequestType::StoreApi, 'wc/store/v1' ],
			'rest con idioma'            => [ 'GET', '/en/wp-json/wp/v2/pages', [], [], [], RequestType::Rest, 'wp/v2' ],
			'wp-jsonx no es rest'        => [ 'GET', '/wp-jsonx/algo', [], [], [], RequestType::FrontendHtml, '' ],
			'cron por ruta'              => [ 'GET', '/wp-cron.php?doing_wp_cron=1', [], [], [], RequestType::Cron, '' ],
			'cron por constante'         => [ 'GET', '/', [], [], [ 'cron' => true ], RequestType::Cron, '' ],
			'cli'                        => [ 'GET', '/', [], [], [ 'cli' => true ], RequestType::Cli, '' ],
			'admin'                      => [ 'GET', '/wp-admin/edit.php', [], [], [], RequestType::Admin, '' ],
			'admin sin barra'            => [ 'GET', '/wp-admin', [], [], [], RequestType::Admin, '' ],
			'admin por constante'        => [ 'GET', '/x', [], [], [ 'admin' => true ], RequestType::Admin, '' ],
			'login'                      => [ 'POST', '/wp-login.php', [], [], [], RequestType::Login, '' ],
			'login personalizado'        => [ 'GET', '/acceso/', [], [], [], RequestType::Login, '' ],
			'login personalizado hijo'   => [ 'GET', '/acceso/otra/', [], [], [], RequestType::FrontendHtml, '' ],
			'xmlrpc'                     => [ 'POST', '/xmlrpc.php', [], [], [], RequestType::XmlRpc, '' ],
			'feed'                       => [ 'GET', '/feed/', [], [], [], RequestType::Feed, '' ],
			'feed de categoría'          => [ 'GET', '/categoria/noticias/feed/rss2/', [], [], [], RequestType::Feed, '' ],
			'feed por query'             => [ 'GET', '/?feed=rss2', [ 'feed' => 'rss2' ], [], [], RequestType::Feed, '' ],
			'feedback no es feed'        => [ 'GET', '/feedback/', [], [], [], RequestType::FrontendHtml, '' ],
			'sitemap core'               => [ 'GET', '/wp-sitemap.xml', [], [], [], RequestType::Sitemap, '' ],
			'sitemap yoast'              => [ 'GET', '/post-sitemap2.xml', [], [], [], RequestType::Sitemap, '' ],
			'sitemap xsl'                => [ 'GET', '/main-sitemap.xsl', [], [], [], RequestType::Sitemap, '' ],
			'robots'                     => [ 'GET', '/robots.txt', [], [], [], RequestType::Robots, '' ],
			'imagen inexistente'         => [ 'GET', '/wp-content/uploads/2024/01/foto.JPG', [], [], [], RequestType::StaticMiss, '' ],
			'css con versión'            => [ 'GET', '/wp-content/themes/x/style.css?ver=1', [], [], [], RequestType::StaticMiss, '' ],
			'favicon no es static miss'  => [ 'GET', '/favicon.ico', [], [], [], RequestType::FrontendHtml, '' ],
			'passthrough de la matriz'   => [ 'GET', '/wp-content/uploads/al-vuelo/a.jpg', [], [], [], RequestType::FrontendHtml, '' ],
			'punto en carpeta'           => [ 'GET', '/v1.2/pagina', [], [], [], RequestType::FrontendHtml, '' ],
			'carrito'                    => [ 'GET', '/carrito/', [], [], [], RequestType::Cart, '' ],
			'carrito traducido'          => [ 'GET', '/en/cart/', [], [], [], RequestType::Cart, '' ],
			'carritos-de-golf no'        => [ 'GET', '/carritos-de-golf/', [], [], [], RequestType::FrontendHtml, '' ],
			'checkout con endpoint'      => [ 'GET', '/finalizar-compra/order-received/55/?key=x', [ 'key' => 'x' ], [], [], RequestType::Checkout, '' ],
			'checkout POST'              => [ 'POST', '/finalizar-compra/', [], [], [], RequestType::Checkout, '' ],
			'cuenta'                     => [ 'GET', '/mi-cuenta/pedidos/', [], [], [], RequestType::Account, '' ],
			'mayúsculas y codificación'  => [ 'GET', '/Mi-Cuenta/%70edidos/', [], [], [], RequestType::Account, '' ],
			'POST al frontend'           => [ 'POST', '/contacto/', [], [], [], RequestType::Other, '' ],
			'comentarios'                => [ 'POST', '/wp-comments-post.php', [], [], [], RequestType::Other, '' ],
			'barras repetidas'           => [ 'GET', '//wp-admin//index.php', [], [], [], RequestType::Admin, '' ],
			'URI absoluta'               => [ 'GET', 'https://ejemplo.com/wp-login.php', [], [], [], RequestType::Login, '' ],
		];
	}

	/**
	 * @param array<string, mixed> $get   Query.
	 * @param array<string, mixed> $post  Cuerpo.
	 * @param array<string, bool>  $flags Constantes.
	 */
	#[DataProvider( 'cases' )]
	public function test_classify( string $method, string $uri, array $get, array $post, array $flags, RequestType $expected, string $endpoint ): void {
		$context = ( new RequestClassifier( self::CONFIG ) )->classify(
			[
				'REQUEST_METHOD' => $method,
				'REQUEST_URI'    => $uri,
			],
			$get,
			$post,
			$flags
		);
		$this->assertSame( $expected, $context->type, $uri );
		$this->assertSame( $endpoint, $context->endpoint, $uri );
	}

	public function test_subdirectory_install(): void {
		$classifier = new RequestClassifier(
			[
				'home_path' => '/blog',
				'site_path' => '/blog/wp',
				'paths'     => [ 'cart' => [ '/carrito' ] ],
			]
		);
		$cases = [
			'/blog/'                      => RequestType::FrontendHtml,
			'/blog/wp/wp-admin/'          => RequestType::Admin,
			'/blog/wp/wp-login.php'       => RequestType::Login,
			'/blog/wp/wp-admin/admin-ajax.php' => RequestType::AdminAjax,
			'/blog/wp-json/wp/v2/posts'   => RequestType::Rest,
			'/blog/carrito/'              => RequestType::Cart,
			'/blog/wp/wp-cron.php'        => RequestType::Cron,
		];
		foreach ( $cases as $uri => $type ) {
			$this->assertSame( $type, $classifier->classify( [ 'REQUEST_URI' => $uri ], [], [] )->type, $uri );
		}
	}

	public function test_custom_rest_prefix(): void {
		$classifier = new RequestClassifier( [ 'rest_prefix' => '/api/' ] );
		$this->assertSame( RequestType::Rest, $classifier->classify( [ 'REQUEST_URI' => '/api/wp/v2/posts' ], [], [] )->type );
		$this->assertSame( RequestType::FrontendHtml, $classifier->classify( [ 'REQUEST_URI' => '/wp-json/wp/v2/posts' ], [], [] )->type );
	}

	public function test_hostile_input_never_breaks(): void {
		$classifier = new RequestClassifier( self::CONFIG );
		$inputs     = [ null, 123, [ 'x' ], '', str_repeat( '/a', 5000 ), "/\0/x", '/%ZZ/', '?only=query', '#frag' ];
		foreach ( $inputs as $uri ) {
			$context = $classifier->classify(
				[
					'REQUEST_URI'    => $uri,
					'REQUEST_METHOD' => [ 'GET' ],
				],
				[
					'wc-ajax'    => [ 'a' ],
					'rest_route' => 5,
				],
				[ 'action' => [ 'x' ] ]
			);
			$this->assertStringStartsWith( '/', $context->path );
			$this->assertLessThanOrEqual( 2049, strlen( $context->path ) );
		}
	}

	public function test_empty_config_uses_defaults(): void {
		$classifier = new RequestClassifier( [] );
		$this->assertSame( RequestType::Rest, $classifier->classify( [ 'REQUEST_URI' => '/wp-json/x/v1/y' ], [], [] )->type );
		$this->assertSame( RequestType::FrontendHtml, $classifier->classify( [ 'REQUEST_URI' => '/carrito/' ], [], [] )->type );
	}

	public function test_rest_namespace(): void {
		$this->assertSame( 'wc/store/v1', RequestClassifier::restNamespace( '/wc/store/v1/cart/items' ) );
		$this->assertSame( 'wp/v2', RequestClassifier::restNamespace( '/wp/v2/users/me' ) );
		$this->assertSame( 'oembed/1.0', RequestClassifier::restNamespace( '/oembed/1.0/embed' ) );
		$this->assertSame( 'plugin', RequestClassifier::restNamespace( '/plugin/sin/version' ) );
		$this->assertSame( 'x/v3.1', RequestClassifier::restNamespace( '/x/v3.1/y' ) );
	}

	public function test_rule_key(): void {
		$context = ( new RequestClassifier( [] ) )->classify( [ 'REQUEST_URI' => '/?wc-ajax=checkout' ], [ 'wc-ajax' => 'checkout' ], [] );
		$this->assertSame( 'WcAjax:checkout', $context->ruleKey() );
		$this->assertTrue( $context->type->isNeverFiltered() === false );
	}

	public function test_never_filtered_types(): void {
		foreach ( [ RequestType::Admin, RequestType::Login, RequestType::Cron, RequestType::Cli, RequestType::Checkout, RequestType::Account, RequestType::Other ] as $type ) {
			$this->assertTrue( $type->isNeverFiltered(), $type->value );
		}
		$this->assertFalse( RequestType::WcAjax->isNeverFiltered() );
	}
}
