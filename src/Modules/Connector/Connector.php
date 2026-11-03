<?php
/**
 * Conector de Claude (0.22.0): direcciones, requisitos y alcances.
 *
 * Servidor MCP propio del sitio para que el dueño use Claude (claude.ai, escritorio, móvil o Claude
 * Code) para diagnosticar y ajustar Imagina Turbo sin acceso a nada más. Verificado contra la
 * especificación MCP 2025-11-25 (transporte Streamable HTTP y autorización) y los requisitos de
 * Anthropic para conectores (claude.com/docs/connectors/building/authentication, 2026-11-03):
 * - El servidor responde 401 con WWW-Authenticate y resource_metadata; publica los metadatos del
 *   recurso (RFC 9728) y los del servidor de autorización (RFC 8414).
 * - Claude se identifica con un Client ID Metadata Document (CIMD) cuando el servidor anuncia
 *   client_id_metadata_document_supported y "none" en token_endpoint_auth_methods_supported. Solo se
 *   aceptan documentos alojados en dominios de Anthropic: no hay registro dinámico (DCR), que dejaría
 *   a cualquiera registrar un cliente con su propia dirección de vuelta.
 * - PKCE S256 obligatorio, parámetro resource (RFC 8707) ligado al token, refresh tokens rotativos.
 *
 * Las rutas no van bajo /wp-json: los plugins que cierran la REST a visitantes anónimos (y el propio
 * escudo) no deben cortar el conector, que se autentica con su token, no con la sesión de WordPress.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Connector;

/**
 * Datos comunes del conector.
 */
final class Connector {

	/** Ruta del servidor MCP, relativa a home_url. */
	public const MCP_PATH = '/itb-mcp';

	/** Ruta del servidor de autorización (su issuer), relativa a home_url. */
	public const ISSUER_PATH = '/itb-oauth';

	/** Solo lectura: diagnosticar y explicar. */
	public const SCOPE_READ = 'itb.read';

	/** Ajustes: cambia lo seguro directamente y lo moderado con prueba y reversión automática. */
	public const SCOPE_ADJUST = 'itb.adjust';

	public const ACCESS_TTL  = HOUR_IN_SECONDS;
	public const REFRESH_TTL = 30 * DAY_IN_SECONDS;
	public const CODE_TTL    = 600;

	/** Vida máxima de un acceso: después hay que volver a dar permiso, aunque se use a diario. */
	public const MAX_GRANT_AGE = 180 * DAY_IN_SECONDS;

	/** Accesos activos como máximo (cada conexión desde Claude es uno). */
	public const MAX_CONNECTIONS = 20;

	/** Dominios desde los que se aceptan documentos de cliente (CIMD). */
	public const TRUSTED_CLIENT_HOSTS = [ 'claude.ai', 'claude.com' ];

	/** Rango de salida de Anthropic hacia los servidores MCP (documentación de conectores). */
	public const ANTHROPIC_EGRESS = '160.79.104.0/21';

	public static function resource(): string {
		return untrailingslashit( home_url( self::MCP_PATH ) );
	}

	public static function issuer(): string {
		return untrailingslashit( home_url( self::ISSUER_PATH ) );
	}

	/**
	 * Metadatos del recurso: la dirección que va en el 401.
	 */
	public static function resourceMetadataUrl(): string {
		return home_url( '/.well-known/oauth-protected-resource' . self::MCP_PATH );
	}

	/**
	 * Requisitos que faltan para que Claude pueda conectarse (vacío si está listo).
	 *
	 * @return list<string>
	 */
	public static function problems(): array {
		$out = [];
		if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) && ! self::allowHttp() ) {
			$out[] = __( 'El sitio no usa HTTPS: Claude solo se conecta a servidores con HTTPS.', 'imagina-turbo' );
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$out[] = __( 'Los enlaces permanentes están en «Simple»: el conector necesita enlaces amigables (Ajustes → Enlaces permanentes).', 'imagina-turbo' );
		}
		return $out;
	}

	/**
	 * HTTP sin cifrar solo para pruebas locales: la constante en wp-config.php y, además, un sitio en
	 * una dirección local. Olvidarla puesta en producción no deja el conector sin cifrar.
	 */
	public static function allowHttp(): bool {
		if ( ! defined( 'ITB_CONNECTOR_ALLOW_HTTP' ) || true !== ITB_CONNECTOR_ALLOW_HTTP ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return in_array( $host, [ 'localhost', '127.0.0.1', '::1', '[::1]' ], true ) || 1 === preg_match( '/\.(localhost|local|test)$/', $host );
	}

	/**
	 * Límite de uso por clave y ventana (transient; con caché de objetos, en memoria).
	 */
	public static function budget( string $key, int $max, int $window ): bool {
		$slot  = 'itb_cn_' . md5( $key ) . '_' . intdiv( time(), $window );
		$count = (int) get_transient( $slot );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $slot, $count + 1, $window * 2 );
		return true;
	}

	/**
	 * El usuario puede usar el conector (lo mismo que el admin del plugin).
	 */
	public static function userCan( int $userId ): bool {
		return $userId > 0 && ( user_can( $userId, \ImaginaTurbo\Core\Installer::CAPABILITY ) || user_can( $userId, 'manage_options' ) );
	}

	/**
	 * Alcances válidos de una lista separada por espacios (lo desconocido se descarta).
	 *
	 * @return list<string>
	 */
	public static function scopes( string $raw ): array {
		$out = [];
		foreach ( preg_split( '/\s+/', trim( $raw ) ) ?: [] as $scope ) {
			if ( in_array( $scope, [ self::SCOPE_READ, self::SCOPE_ADJUST ], true ) && ! in_array( $scope, $out, true ) ) {
				$out[] = $scope;
			}
		}
		return $out;
	}

	/**
	 * Forma canónica de una URL para comparar el resource (RFC 8707): esquema y host en minúsculas,
	 * sin barra final ni fragmento.
	 */
	public static function canonical( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}
		$out = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$out .= ':' . (int) $parts['port'];
		}
		$out .= rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		if ( isset( $parts['query'] ) ) {
			$out .= '?' . $parts['query'];
		}
		return $out;
	}

	/**
	 * Hash de un token o código: lo único que se guarda.
	 */
	public static function hash( string $secret ): string {
		return hash( 'sha256', $secret );
	}

	/**
	 * Secreto aleatorio en base64url (32 bytes).
	 */
	public static function secret( string $prefix ): string {
		return $prefix . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binario a texto.
	}

	/**
	 * Cabeceras de una respuesta que nunca se guarda en ninguna caché.
	 */
	public static function noStore(): void {
		do_action( 'litespeed_control_set_nocache', 'imagina-turbo: conector' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LSCache.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante estándar de los plugins de caché.
		}
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, private' );
			header( 'Pragma: no-cache' );
			header( 'Cloudflare-CDN-Cache-Control: no-store' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
	}
}
