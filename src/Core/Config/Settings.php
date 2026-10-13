<?php
/**
 * Ajustes del plugin (opción itb_settings, autoload = no).
 *
 * Solo se leen en admin, cron, REST y al compilar. El frontend usa la config compilada.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Config;

/**
 * Lectura, validación y escritura de los ajustes.
 */
final class Settings {

	public const OPTION = 'itb_settings';

	public const SCHEMA_VERSION = 1;

	/** @var array<string, mixed>|null */
	private ?array $cache = null;

	/**
	 * Ajustes por defecto. El prefijo de tags se genera una vez por sitio.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'version'         => self::SCHEMA_VERSION,
			'modules'         => [],
			'tag_prefix'      => '',
			'debug'           => false,
			'staging_domains' => [],
		];
	}

	/**
	 * Todos los ajustes, completados con los valores por defecto.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = self::sanitize( is_array( $stored ) ? $stored : [] );
		}
		return $this->cache;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->all()[ $key ] ?? $default;
	}

	/**
	 * Interruptores de módulos guardados (id => bool).
	 *
	 * @return array<string, bool>
	 */
	public function modules(): array {
		$modules = $this->get( 'modules', [] );
		return is_array( $modules ) ? $modules : [];
	}

	/**
	 * Mezcla cambios y guarda. Devuelve los ajustes resultantes.
	 *
	 * @param array<string, mixed> $changes Cambios parciales.
	 * @return array<string, mixed>
	 */
	public function update( array $changes ): array {
		$merged = array_replace( $this->all(), $changes );
		if ( isset( $changes['modules'] ) && is_array( $changes['modules'] ) ) {
			$merged['modules'] = array_replace( $this->modules(), $changes['modules'] );
		}
		$clean = self::sanitize( $merged );
		update_option( self::OPTION, $clean, false );
		$this->cache = $clean;
		return $clean;
	}

	/**
	 * Crea la opción con autoload = no si no existe y asegura el prefijo de tags.
	 */
	public function ensureInitialized(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::sanitize( [] ), '', false );
			$this->cache = null;
		}
		if ( '' === $this->get( 'tag_prefix', '' ) ) {
			$this->update( [ 'tag_prefix' => self::generateTagPrefix() ] );
		}
	}

	/**
	 * Ajustes exportables (sin secretos: el token de Cloudflare vive en otra opción).
	 *
	 * @return array<string, mixed>
	 */
	public function export(): array {
		$data = $this->all();
		unset( $data['tag_prefix'] );
		return [
			'plugin'   => 'imagina-turbo',
			'version'  => self::SCHEMA_VERSION,
			'settings' => $data,
		];
	}

	/**
	 * Importa ajustes exportados. Lanza si el formato no es válido.
	 *
	 * @param mixed $payload Datos importados.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException Si el formato no es válido.
	 */
	public function import( mixed $payload ): array {
		if ( ! is_array( $payload ) || 'imagina-turbo' !== ( $payload['plugin'] ?? null ) || ! is_array( $payload['settings'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Formato de exportación no válido.' );
		}
		if ( ! is_int( $payload['version'] ?? null ) || $payload['version'] > self::SCHEMA_VERSION ) {
			throw new \InvalidArgumentException( 'La exportación es de una versión más nueva del plugin.' );
		}
		$settings = $payload['settings'];
		unset( $settings['tag_prefix'] );
		return $this->update( $settings );
	}

	/**
	 * Valida y normaliza. Las claves desconocidas se descartan.
	 *
	 * @param array<mixed> $input Datos crudos.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$out = self::defaults();

		if ( isset( $input['modules'] ) && is_array( $input['modules'] ) ) {
			foreach ( $input['modules'] as $id => $enabled ) {
				if ( is_string( $id ) && 1 === preg_match( '/^[a-z_]{2,40}$/', $id ) && is_bool( $enabled ) ) {
					$out['modules'][ $id ] = $enabled;
				}
			}
		}

		if ( isset( $input['tag_prefix'] ) && is_string( $input['tag_prefix'] ) && 1 === preg_match( '/^[a-z0-9]{2,6}$/', $input['tag_prefix'] ) ) {
			$out['tag_prefix'] = $input['tag_prefix'];
		}

		$out['debug'] = isset( $input['debug'] ) && true === $input['debug'];

		if ( isset( $input['staging_domains'] ) && is_array( $input['staging_domains'] ) ) {
			$domains = [];
			foreach ( $input['staging_domains'] as $domain ) {
				if ( ! is_string( $domain ) ) {
					continue;
				}
				$domain = strtolower( trim( $domain ) );
				if ( 1 === preg_match( '/^[a-z0-9.-]{2,253}$/', $domain ) ) {
					$domains[ $domain ] = true;
				}
			}
			$out['staging_domains'] = array_slice( array_keys( $domains ), 0, 100 );
		}

		return $out;
	}

	/**
	 * Dos caracteres: el primero letra (los tags no empiezan por dígito), el segundo letra o dígito.
	 */
	public static function generateTagPrefix(): string {
		$letters = 'abcdefghijklmnopqrstuvwxyz';
		$chars   = $letters . '0123456789';
		return $letters[ random_int( 0, 25 ) ] . $chars[ random_int( 0, 35 ) ];
	}
}
