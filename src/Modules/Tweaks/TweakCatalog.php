<?php
/**
 * Ajustes rápidos (docs/02 módulo 7): reemplazo de los de Perfmatters, cada uno con su interruptor,
 * descripción en español y nivel de riesgo. Solo los seguros vienen activos (regla 3).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Tweaks;

/**
 * Lista de ajustes.
 */
final class TweakCatalog {

	/**
	 * @return array<string, array{label: string, description: string, risk: string, default: bool}>
	 */
	public static function all(): array {
		$t = static fn( string $label, string $description, string $risk, bool $default ): array => [
			'label'       => $label,
			'description' => $description,
			'risk'        => $risk,
			'default'     => $default && 'safe' === $risk,
		];
		return [
			'emojis'         => $t( __( 'Desactivar emojis', 'imagina-turbo' ), __( 'Quita el script y los estilos de emojis de WordPress (los navegadores ya los muestran).', 'imagina-turbo' ), 'safe', true ),
			'head_cleanup'   => $t( __( 'Limpiar el <head>', 'imagina-turbo' ), __( 'Quita RSD, wlwmanifest, shortlink y la versión de WordPress (generator).', 'imagina-turbo' ), 'safe', true ),
			'rest_links'     => $t( __( 'Quitar enlaces de la REST API del <head>', 'imagina-turbo' ), __( 'Quita <link rel="https://api.w.org/"> y la cabecera Link. La API sigue funcionando.', 'imagina-turbo' ), 'safe', true ),
			'self_pingbacks' => $t( __( 'Desactivar auto-pingbacks', 'imagina-turbo' ), __( 'Un enlace a una entrada propia no genera un pingback a uno mismo.', 'imagina-turbo' ), 'safe', true ),
			'dashicons'      => $t( __( 'Dashicons solo con sesión', 'imagina-turbo' ), __( 'La hoja de íconos del admin no se carga a visitantes (salvo que algo del sitio la pida explícitamente en una página con la barra de admin).', 'imagina-turbo' ), 'safe', true ),
			'password_meter' => $t( __( 'Medidor de contraseña solo donde se usa', 'imagina-turbo' ), __( 'El medidor (zxcvbn, unos 400 KB) solo en cuenta, recuperar contraseña y checkout. WooCommerce también lo carga en el carrito; otros plugins, en cualquier página.', 'imagina-turbo' ), 'safe', true ),
			'embeds'         => $t( __( 'Desactivar embeds de WordPress', 'imagina-turbo' ), __( 'Quita wp-embed y el descubrimiento oEmbed: otros sitios ya no muestran tarjetas de tus entradas. YouTube y similares siguen funcionando.', 'imagina-turbo' ), 'safe', false ),
			'revisions'      => $t( __( 'Limitar revisiones', 'imagina-turbo' ), __( 'Conserva solo las últimas N revisiones de cada entrada.', 'imagina-turbo' ), 'safe', false ),
			'autosave'       => $t( __( 'Intervalo de autoguardado', 'imagina-turbo' ), __( 'El editor guarda borradores cada N segundos en lugar de 60.', 'imagina-turbo' ), 'safe', false ),
			'feeds'          => $t( __( 'Desactivar feeds RSS', 'imagina-turbo' ), __( 'Los feeds redirigen a la portada. Solo si nadie los usa (lectores, Mailchimp, Google Merchant).', 'imagina-turbo' ), 'moderate', false ),
			'comments'       => $t( __( 'Desactivar comentarios', 'imagina-turbo' ), __( 'Cierra comentarios y pingbacks en todo el sitio y oculta el menú. Las reseñas de WooCommerce no se tocan.', 'imagina-turbo' ), 'moderate', false ),
			'wc_admin'       => $t( __( 'Quitar marketing y análisis de WooCommerce Admin', 'imagina-turbo' ), __( 'Oculta el Marketing Hub, el panel de análisis y los avisos de WooCommerce Admin. Los informes de pedidos siguen.', 'imagina-turbo' ), 'moderate', false ),
			'google_fonts'   => $t( __( 'Quitar Google Fonts del tema', 'imagina-turbo' ), __( 'Desencola las hojas de fonts.googleapis.com. La tipografía cambia si el tema no tiene una alternativa local.', 'imagina-turbo' ), 'moderate', false ),
			'jquery_migrate' => $t( __( 'Quitar jQuery Migrate', 'imagina-turbo' ), __( 'Solo con verificación: plugins y temas antiguos dejan de funcionar sin él.', 'imagina-turbo' ), 'invasive', false ),
		];
	}

	/**
	 * Estado efectivo: lo guardado o el valor por defecto.
	 *
	 * @param array<mixed> $stored Ajustes guardados (id => bool).
	 * @return array<string, bool>
	 */
	public static function resolve( array $stored ): array {
		$out = [];
		foreach ( self::all() as $id => $tweak ) {
			$out[ $id ] = is_bool( $stored[ $id ] ?? null ) ? $stored[ $id ] : $tweak['default'];
		}
		return $out;
	}
}
