<?php
/**
 * Kill switch: constante, archivo bandera o cookie firmada de administrador.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Safety;

/**
 * Determina si el plugin debe desactivarse por completo en este request.
 */
final class KillSwitch {

	public const FILE = 'disable';

	/** Igual que AdminBypassCookie::NAME (repetido para no cargar esa clase en cada request). */
	public const COOKIE = 'itb_bypass';

	public const REASON_CONSTANT = 'constant';
	public const REASON_FILE     = 'file';
	public const REASON_COOKIE   = 'cookie';

	/**
	 * Motivo por el que el kill switch está activo, o null.
	 *
	 * @param array<mixed> $cookies Equivalente a $_COOKIE.
	 */
	public static function reason( string $dataDir, array $cookies ): ?string {
		if ( self::constantActive() ) {
			return self::REASON_CONSTANT;
		}
		if ( self::fileActive( $dataDir ) ) {
			return self::REASON_FILE;
		}
		// La clase de la cookie solo se carga si la cookie existe: el camino normal no paga por ella.
		// Ojo: aquí no hay autoloader (mu-plugins), así que no se puede tocar AdminBypassCookie antes del require.
		if ( isset( $cookies[ self::COOKIE ] ) ) {
			if ( ! class_exists( AdminBypassCookie::class, false ) ) {
				require_once __DIR__ . '/AdminBypassCookie.php';
			}
			if ( AdminBypassCookie::isValidRequest( $cookies ) ) {
				return self::REASON_COOKIE;
			}
		}
		return null;
	}

	/**
	 * define('ITB_DISABLE', true) en wp-config.php.
	 */
	public static function constantActive(): bool {
		return defined( 'ITB_DISABLE' ) && (bool) constant( 'ITB_DISABLE' );
	}

	/**
	 * Archivo wp-content/imagina-turbo/disable.
	 */
	public static function fileActive( string $dataDir ): bool {
		return '' !== $dataDir && is_file( $dataDir . '/' . self::FILE );
	}

	/**
	 * Crea o borra el archivo bandera. Devuelve el estado final.
	 */
	public static function setFile( string $dataDir, bool $enabled ): bool {
		$file = $dataDir . '/' . self::FILE;
		if ( $enabled ) {
			if ( ! is_dir( $dataDir ) ) {
				return false;
			}
			if ( ! is_file( $file ) ) {
				file_put_contents( $file, gmdate( 'c' ) . "\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- se usa sin WordPress cargado.
			}
			return is_file( $file );
		}
		if ( is_file( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		return is_file( $file );
	}
}
