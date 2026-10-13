<?php
/**
 * Lectura tipada de la configuración compilada (wp-content/imagina-turbo/config.php).
 *
 * El archivo es un array PHP que vive en OPcache. Este lector es parte del camino crítico:
 * sin WordPress, sin consultas.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Config;

/**
 * Configuración compilada validada.
 */
final class CompiledConfig {

	/** Versión del formato. Si el archivo trae otra, se ignora y se recompila. */
	public const FORMAT_VERSION = 1;

	/**
	 * @param array<string, mixed> $data Contenido validado.
	 */
	private function __construct( private readonly array $data ) {
	}

	/**
	 * Ruta del archivo compilado para el sitio actual.
	 */
	public static function path( string $dataDir, int $blogId = 1, bool $multisite = false ): string {
		return $multisite ? $dataDir . '/config-' . $blogId . '.php' : $dataDir . '/config.php';
	}

	/**
	 * Carga el archivo; null si no existe, está corrupto o es de otra versión.
	 */
	public static function load( string $file ): ?self {
		if ( ! is_file( $file ) ) {
			return null;
		}
		try {
			$data = include $file;
		} catch ( \Throwable ) {
			return null;
		}
		return is_array( $data ) ? self::fromArray( $data ) : null;
	}

	/**
	 * Valida un array con el formato compilado.
	 *
	 * @param array<mixed> $data Datos crudos.
	 */
	public static function fromArray( array $data ): ?self {
		if ( ( $data['v'] ?? null ) !== self::FORMAT_VERSION || ! is_string( $data['hash'] ?? null ) ) {
			return null;
		}
		foreach ( [ 'modules', 'paths', 'filter', 'bypass_cookies' ] as $key ) {
			if ( isset( $data[ $key ] ) && ! is_array( $data[ $key ] ) ) {
				return null;
			}
		}
		/** @var array<string, mixed> $data */
		return new self( $data );
	}

	public function hash(): string {
		return (string) $this->data['hash'];
	}

	/**
	 * Marca de tiempo de compilación.
	 */
	public function generatedAt(): int {
		return is_int( $this->data['generated'] ?? null ) ? $this->data['generated'] : 0;
	}

	public function pluginVersion(): string {
		return is_string( $this->data['plugin_version'] ?? null ) ? $this->data['plugin_version'] : '';
	}

	/**
	 * El módulo está activo para este sitio (ya aplicadas la guardia de solapamientos y la licencia).
	 */
	public function moduleEnabled( string $id ): bool {
		return true === ( $this->data['modules'][ $id ] ?? false );
	}

	/**
	 * @return array<string, bool>
	 */
	public function modules(): array {
		$modules = $this->data['modules'] ?? [];
		return is_array( $modules ) ? array_filter( $modules, 'is_bool' ) : [];
	}

	/**
	 * Datos que necesita el clasificador.
	 *
	 * @return array<string, mixed>
	 */
	public function classifierConfig(): array {
		return [
			'home_path'          => $this->data['home_path'] ?? '',
			'site_path'          => $this->data['site_path'] ?? ( $this->data['home_path'] ?? '' ),
			'rest_prefix'        => $this->data['rest_prefix'] ?? 'wp-json',
			'lang_prefixes'      => $this->data['lang_prefixes'] ?? [],
			'paths'              => $this->data['paths'] ?? [],
			'static_passthrough' => $this->data['static_passthrough'] ?? [],
		];
	}

	/**
	 * Valor arbitrario de primer nivel.
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return $this->data;
	}
}
