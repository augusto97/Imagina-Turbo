<?php
/**
 * Turnstile en los formularios (0.21.0). Riesgo moderado, apagado por defecto.
 *
 * Cada formulario tiene su interruptor: inicio de sesión, registro, recuperar contraseña y pago. Ninguno
 * se activa hasta que las claves pasan la prueba del admin (un widget real verificado con la clave
 * secreta): con unas claves mal puestas nadie podría entrar.
 *
 * Verificado en WooCommerce 11.2.1 y WordPress 6.8:
 * - wp-login.php: login_form/authenticate, register_form/registration_errors y
 *   lostpassword_form/lostpassword_post. authenticate también corre en el login de WooCommerce (vía
 *   wp_signon), en XML-RPC y en las contraseñas de aplicación: solo se exige dentro de wp-login.php
 *   (login_init), incluida la URL de acceso personalizada que lo carga.
 * - Mi cuenta: woocommerce_login_form/woocommerce_process_login_errors,
 *   woocommerce_register_form/woocommerce_process_registration_errors y
 *   woocommerce_lostpassword_form más lostpassword_post (que WooCommerce también dispara: se verifica
 *   una sola vez por request). No se usa woocommerce_registration_errors: también corre al crear la
 *   cuenta desde el pago.
 * - Pago clásico: el widget va en woocommerce_checkout_after_customer_details, fuera del fragmento que
 *   WooCommerce recarga al cambiar la dirección; se verifica en woocommerce_after_checkout_validation
 *   solo para el envío del formulario (?wc-ajax=checkout o el POST sin JavaScript). Los botones exprés
 *   (Apple Pay, Google Pay, PayPal) crean el pedido por su propio endpoint y no pasan por aquí; las
 *   pasarelas de la lista de excepciones tampoco.
 * - Pago por bloques: el token viaja en los datos de extensión del checkout
 *   (setExtensionData('imagina-turbo', {turnstile})) y se verifica en
 *   woocommerce_store_api_checkout_update_order_from_request solo en el POST que crea el pedido; el error
 *   es una RouteException de la Store API.
 *
 * El widget es invisible salvo que Cloudflare pida interacción (appearance: interaction-only). api.js
 * solo se descarga cuando el formulario se ve, recibe el foco o se envía: un formulario de acceso en una
 * ventana emergente del tema no cuesta nada mientras no se abre. Quien gestiona la tienda
 * (manage_woocommerce) no pasa por la verificación del pago.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Turnstile;

use ImaginaTurbo\Core\Container;
use ImaginaTurbo\Core\ModuleInterface;
use ImaginaTurbo\Core\Request\RequestContext;
use ImaginaTurbo\Core\Request\RequestType;
use ImaginaTurbo\Core\Runtime;
use ImaginaTurbo\Modules\Rum\RumCollector;

/**
 * Registro del módulo.
 */
final class TurnstileModule implements ModuleInterface {

	public const HANDLE = 'itb-turnstile';

	/** Tipos de request sin formularios de visitantes. */
	private const SKIP = [ RequestType::Admin, RequestType::AdminAjax, RequestType::Cron, RequestType::Cli, RequestType::Rest, RequestType::XmlRpc, RequestType::Feed, RequestType::Sitemap, RequestType::Robots, RequestType::StaticMiss ];

	private static ?Container $container = null;

	/** @var array<string, true> Formularios ya verificados en este request (lostpassword_post se dispara dos veces). */
	private static array $checked = [];

	public function register( Container $container, RequestContext $request ): void {
		if ( in_array( $request->type, self::SKIP, true ) ) {
			return;
		}
		$c = Turnstile::compiled();
		if ( [] === $c ) {
			return;
		}
		self::$container = $container;
		$forms           = $c['forms'];
		if ( $forms['login'] ) {
			add_action( 'login_form', [ self::class, 'loginWidget' ] );
			add_filter( 'authenticate', [ self::class, 'checkCoreLogin' ], 100, 1 );
			add_action( 'woocommerce_login_form', [ self::class, 'loginWidget' ] );
			add_filter( 'woocommerce_process_login_errors', [ self::class, 'checkWooErrors' ], 10, 1 );
		}
		if ( $forms['register'] ) {
			add_action( 'register_form', [ self::class, 'registerWidget' ] );
			add_filter( 'registration_errors', [ self::class, 'checkCoreRegistration' ], 10, 1 );
			add_action( 'woocommerce_register_form', [ self::class, 'registerWidget' ] );
			add_filter( 'woocommerce_process_registration_errors', [ self::class, 'checkWooRegistration' ], 10, 1 );
		}
		if ( $forms['lostpassword'] ) {
			add_action( 'lostpassword_form', [ self::class, 'lostPasswordWidget' ] );
			add_action( 'woocommerce_lostpassword_form', [ self::class, 'lostPasswordWidget' ] );
			add_action( 'lostpassword_post', [ self::class, 'checkLostPassword' ], 10, 1 );
		}
		if ( $forms['checkout'] ) {
			add_action( 'woocommerce_checkout_after_customer_details', [ self::class, 'checkoutWidget' ] );
			add_action( 'woocommerce_after_checkout_validation', [ self::class, 'checkClassicCheckout' ], 10, 2 );
			add_filter( 'render_block_woocommerce/checkout', [ self::class, 'blockCheckoutWidget' ] );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ self::class, 'checkBlockCheckout' ], 10, 2 );
			if ( did_action( 'woocommerce_blocks_loaded' ) ) {
				self::registerExtensionData();
			} else {
				add_action( 'woocommerce_blocks_loaded', [ self::class, 'registerExtensionData' ] );
			}
		}
	}

	public static function loginWidget(): void {
		self::widget( 'login' );
	}

	public static function registerWidget(): void {
		self::widget( 'register' );
	}

	public static function lostPasswordWidget(): void {
		self::widget( 'lostpassword' );
	}

	public static function checkoutWidget(): void {
		self::widget( 'checkout', true );
	}

	/**
	 * Al final del bloque de pago, fuera del árbol de React (lo que se metiera dentro se perdería al
	 * montarlo). Solo aparece en pantalla si Cloudflare pide interacción.
	 */
	public static function blockCheckoutWidget( mixed $html ): mixed {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		return $html . self::container( 'checkout', true, true );
	}

	/**
	 * Contenedor del widget: la clave, la acción y la URL de api.js van en atributos (sin datos en
	 * línea que un plugin de caché pudiera retrasar).
	 */
	public static function widget( string $action, bool $now = false ): void {
		echo self::container( $action, $now, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en container().
	}

	public static function container( string $action, bool $now, bool $block ): string {
		$c = Turnstile::compiled();
		if ( [] === $c ) {
			return '';
		}
		self::enqueue();
		return sprintf(
			'<div class="itb-ts" data-k="%1$s" data-action="%2$s" data-u="%3$s"%4$s%5$s></div>',
			esc_attr( $c['key'] ),
			esc_attr( $action ),
			esc_url( Turnstile::API_JS ),
			$now ? ' data-now="1"' : '',
			$block ? ' data-block="1"' : ''
		);
	}

	private static function enqueue(): void {
		if ( wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		$file = is_file( Runtime::$pluginDir . '/assets/js/itb-turnstile.min.js' ) ? 'itb-turnstile.min.js' : 'itb-turnstile.js';
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/js/' . $file, Runtime::$pluginDir . '/imagina-turbo.php' ), [], ITB_VERSION, [ 'in_footer' => true ] );
	}

	/**
	 * wp-login.php y la URL de acceso que lo carga. Va al final (después de comprobar la contraseña) y,
	 * si la verificación falla, su error reemplaza al de la contraseña: si no, un bot podría probar
	 * contraseñas y saber cuál es la buena por el cambio de mensaje.
	 *
	 * @param mixed $user Usuario, error o null.
	 * @return mixed
	 */
	public static function checkCoreLogin( mixed $user ): mixed {
		if ( ! did_action( 'login_init' ) || ! isset( $_POST['log'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el login del core no lleva nonce.
			return $user;
		}
		$error = self::check( 'login' );
		return null === $error ? $user : new \WP_Error( 'itb_turnstile', $error );
	}

	/**
	 * @param mixed $errors Errores del registro de wp-login.php.
	 * @return mixed
	 */
	public static function checkCoreRegistration( mixed $errors ): mixed {
		if ( ! did_action( 'login_init' ) || ! $errors instanceof \WP_Error ) {
			return $errors;
		}
		return self::addError( $errors, self::check( 'register' ) );
	}

	/**
	 * @param mixed $errors Errores del login de WooCommerce.
	 * @return mixed
	 */
	public static function checkWooErrors( mixed $errors ): mixed {
		return $errors instanceof \WP_Error ? self::addError( $errors, self::check( 'login' ) ) : $errors;
	}

	/**
	 * @param mixed $errors Errores del registro de WooCommerce.
	 * @return mixed
	 */
	public static function checkWooRegistration( mixed $errors ): mixed {
		return $errors instanceof \WP_Error ? self::addError( $errors, self::check( 'register' ) ) : $errors;
	}

	/**
	 * Recuperar contraseña, del core o de WooCommerce.
	 *
	 * @param mixed $errors Errores.
	 */
	public static function checkLostPassword( mixed $errors ): void {
		if ( $errors instanceof \WP_Error ) {
			self::addError( $errors, self::check( 'lostpassword' ) );
		}
	}

	/**
	 * Pago clásico: solo el envío del formulario. Si ya hay otros errores, el token no se gasta (el
	 * visitante corrige los campos y vuelve a enviar con el mismo).
	 *
	 * @param mixed $data   Datos del pedido.
	 * @param mixed $errors Errores de validación.
	 */
	public static function checkClassicCheckout( mixed $data, mixed $errors ): void {
		if ( ! $errors instanceof \WP_Error || $errors->has_errors() || ! self::classicSubmit() || self::skipCheckout( is_array( $data ) && is_string( $data['payment_method'] ?? null ) ? $data['payment_method'] : '' ) ) {
			return;
		}
		self::addError( $errors, self::check( 'checkout' ) );
	}

	/**
	 * Pago por bloques: solo el POST que crea el pedido (el PUT que actualiza el borrador no).
	 *
	 * @param mixed $order   Pedido.
	 * @param mixed $request Petición de la Store API.
	 */
	public static function checkBlockCheckout( mixed $order, mixed $request ): void {
		if ( ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return;
		}
		$method = $request->get_param( 'payment_method' );
		if ( self::skipCheckout( is_string( $method ) ? $method : '' ) ) {
			return;
		}
		$ext   = $request->get_param( 'extensions' );
		$token = is_array( $ext ) && is_array( $ext[ Turnstile::EXTENSION ] ?? null ) && is_string( $ext[ Turnstile::EXTENSION ]['turnstile'] ?? null ) ? $ext[ Turnstile::EXTENSION ]['turnstile'] : '';
		$error = self::check( 'checkout', $token );
		if ( null !== $error ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'itb_turnstile', esc_html( $error ), 400 );
		}
	}

	/**
	 * El campo del token en los datos de extensión del checkout por bloques.
	 */
	public static function registerExtensionData(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			[
				'endpoint'        => 'checkout',
				'namespace'       => Turnstile::EXTENSION,
				'schema_callback' => static fn(): array => [
					'turnstile' => [
						'description' => 'Cloudflare Turnstile',
						'type'        => 'string',
						'context'     => [],
					],
				],
				'schema_type'     => ARRAY_A,
			]
		);
	}

	/**
	 * Verifica el token del formulario (una vez por request). Null si pasa; si no, el mensaje.
	 */
	public static function check( string $form, ?string $token = null ): ?string {
		if ( isset( self::$checked[ $form ] ) ) {
			return null;
		}
		self::$checked[ $form ] = true;
		if ( null === $token ) {
			$raw   = $_POST[ Turnstile::FIELD ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el token es la verificación; cada formulario valida su propio nonce.
			$token = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : '';
		}
		$container = self::$container;
		if ( null === $container ) {
			return null;
		}
		$result = $container->get( Turnstile::class )->verify( $token, $form, RumCollector::clientIp( $_SERVER ) );
		return $result['ok'] ? null : Turnstile::message( $result['code'] );
	}

	/** Reinicio entre pruebas. */
	public static function reset(): void {
		self::$checked = [];
	}

	private static function addError( \WP_Error $errors, ?string $message ): \WP_Error {
		if ( null !== $message ) {
			$errors->add( 'itb_turnstile', $message );
		}
		return $errors;
	}

	/**
	 * Envío del formulario de pago: ?wc-ajax=checkout, o el POST sin JavaScript (lleva el nonce del
	 * formulario y no es AJAX). Los endpoints de los botones exprés no cuentan.
	 */
	public static function classicSubmit(): bool {
		$ajax = $_GET['wc-ajax'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se lee el endpoint.
		if ( wp_doing_ajax() ) {
			return is_string( $ajax ) && 'checkout' === $ajax;
		}
		return isset( $_POST['woocommerce-process-checkout-nonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce valida el nonce.
	}

	private static function skipCheckout( string $method ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- capacidad de WooCommerce.
			return true;
		}
		$c = Turnstile::compiled();
		return '' !== $method && [] !== $c && in_array( $method, $c['skip'], true );
	}
}
