<?php
/**
 * Integración con Imagina Updater (repo augusto97/imagina-updater).
 *
 * Cómo funciona allí el licenciamiento (verificado en imagina-updater, commit 617d439):
 * - El plugin no integra ningún SDK. Al subir el ZIP marcado como Premium al servidor, la
 *   extensión de licencias inyecta un bloque de protección en el archivo principal, justo después
 *   de la comprobación de ABSPATH. Si la licencia no es válida, ese bloque hace `return` y el resto
 *   del archivo principal no se ejecuta.
 * - La clase inyectada se llama ILP_{uid}, con uid = substr(md5(slug . 'imagina_license_v5'), 0, 8)
 *   y slug = sanitize_key(Text Domain). Guarda la licencia en la opción ilp_license_{uid}.
 * - Las actualizaciones las gestiona el plugin Imagina Updater Client.
 *
 * Esta clase solo lee ese estado para mostrarlo en el admin y, más adelante, para el hub.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Licensing;

/**
 * Estado de licencia y actualizaciones.
 */
final class ImaginaUpdater {

	/** Debe coincidir con el Text Domain del archivo principal. */
	public const SLUG = 'imagina-turbo';

	/**
	 * Id único que usa el código de protección inyectado.
	 */
	public static function uid(): string {
		return substr( md5( self::SLUG . 'imagina_license_v5' ), 0, 8 );
	}

	/**
	 * Nombre de la clase de protección (el archivo principal no declara namespace).
	 */
	public static function protectionClass(): string {
		return 'ILP_' . self::uid();
	}

	/**
	 * Estado para el admin.
	 *
	 * @return array{protected: bool, licensed: bool|null, client_active: bool, license_page: string, masked_key: string}
	 */
	public function status(): array {
		$class     = self::protectionClass();
		$protected = class_exists( $class, false );
		$licensed  = null;
		$callable  = [ $class, 'is_licensed' ];
		if ( $protected && is_callable( $callable ) ) {
			try {
				$licensed = (bool) call_user_func( $callable );
			} catch ( \Throwable ) {
				$licensed = false;
			}
		}

		return [
			'protected'     => $protected,
			'licensed'      => $licensed,
			'client_active' => function_exists( 'imagina_updater_client' ),
			'license_page'  => $this->licensePageUrl(),
			'masked_key'    => self::mask( $this->licenseKey() ),
		];
	}

	/**
	 * Clave de licencia guardada por el código inyectado (para el hub). Vacía si no hay.
	 */
	public function licenseKey(): string {
		$data = get_option( 'ilp_license_' . self::uid(), [] );
		return is_array( $data ) && is_string( $data['license_key'] ?? null ) ? $data['license_key'] : '';
	}

	/**
	 * Pantalla donde se activa la licencia: el panel central del cliente si existe, si no la propia.
	 */
	public function licensePageUrl(): string {
		if ( defined( 'IAUC_CENTRALIZED_LICENSES' ) ) {
			return admin_url( 'options-general.php?page=imagina-updater-client' );
		}
		if ( class_exists( self::protectionClass(), false ) ) {
			return admin_url( 'options-general.php?page=' . self::SLUG . '-license' );
		}
		return '';
	}

	private static function mask( string $key ): string {
		if ( strlen( $key ) < 12 ) {
			return '' === $key ? '' : str_repeat( '•', strlen( $key ) );
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', 8 ) . substr( $key, -4 );
	}
}
