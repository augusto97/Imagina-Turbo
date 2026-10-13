<?php
/**
 * Reglas del firewall en .htaccess (docs/03, sección 6, capa 3), para Apache/Plesk.
 *
 * Subconjunto de patrones inspirados en el firewall 8G de Jeff Starr (Perishable Press,
 * https://perishablepress.com/8g-firewall/), reescritos y reducidos; no se copia su código.
 *
 * - El bloque va AL PRINCIPIO del archivo: si fuera después del de WordPress, la regla [L] de
 *   WordPress (index.php) terminaría el procesamiento antes de llegar a él.
 * - Antes de escribir se respalda el archivo completo (opción no autoload). Después se piden la
 *   portada, una página de producto y /wp-json/: si alguna no responde 200, se restaura el respaldo.
 * - OpenLiteSpeed solo interpreta las reglas de reescritura del .htaccess y puede necesitar reiniciar
 *   el servidor para leerlas: ahí son opcionales y la protección principal es Cloudflare + CrowdSec.
 * - Webhooks de pasarelas (/wc-api/, ?wc-api=) quedan fuera de las reglas de query.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Firewall;

/**
 * Lectura, escritura y autoprueba del bloque.
 */
final class HtaccessWriter {

	public const BEGIN      = '# BEGIN Imagina Turbo Firewall';
	public const END        = '# END Imagina Turbo Firewall';
	public const BACKUP_KEY = 'itb_htaccess_backup';

	/**
	 * @param string                          $file  Ruta del .htaccess.
	 * @param \Closure(string): int           $probe Pide una URL y devuelve el estado HTTP (0 si falla).
	 * @param list<string>                    $urls  URLs de la autoprueba.
	 */
	public function __construct(
		private readonly string $file,
		private readonly \Closure $probe,
		private readonly array $urls,
	) {
	}

	/**
	 * Bloque de reglas (mod_rewrite).
	 */
	public static function block(): string {
		$lines = [
			self::BEGIN,
			'# Patrones inspirados en el firewall 8G de Jeff Starr (perishablepress.com). No editar: Imagina Turbo reescribe este bloque.',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteCond %{REQUEST_METHOD} ^(TRACE|TRACK|DEBUG)$ [NC]',
			'RewriteRule .* - [F,L]',
			'RewriteCond %{REQUEST_URI} !/wc-api/ [NC]',
			'RewriteCond %{QUERY_STRING} !(^|&)wc-api= [NC]',
			'RewriteCond %{QUERY_STRING} (\.\./|\.\.%2f|%2e%2e) [NC,OR]',
			'RewriteCond %{QUERY_STRING} (base64_(en|de)code|union(\+|%20|\s)+(all(\+|%20|\s)+)?select) [NC,OR]',
			'RewriteCond %{QUERY_STRING} (<|%3c)script [NC,OR]',
			'RewriteCond %{QUERY_STRING} (^|&)(GLOBALS|_REQUEST)(=|\[|%3d|%5b) [OR]',
			'RewriteCond %{QUERY_STRING} (/etc/passwd|eval\(|eval%28) [NC]',
			'RewriteRule .* - [F,L]',
			'RewriteCond %{REQUEST_URI} (/\.git(/|$)|/\.env|/vendor/phpunit/|phpinfo\.php$|\.(sql|bak)$|/wp-config\.) [NC]',
			'RewriteRule .* - [F,L]',
			'RewriteRule ^wp-content/uploads/.*\.(php|phtml|phar)$ - [F,NC,L]',
			'RewriteRule ^(readme\.html|license\.txt)$ - [F,NC,L]',
			'</IfModule>',
			self::END,
		];
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Contenido con el bloque al principio (y sin copias anteriores).
	 */
	public static function withBlock( string $content ): string {
		$rest = ltrim( self::withoutBlock( $content ), "\n" );
		return self::block() . ( '' === $rest ? '' : "\n" . $rest );
	}

	/**
	 * Contenido sin el bloque.
	 */
	public static function withoutBlock( string $content ): string {
		$pattern = '/' . preg_quote( self::BEGIN, '/' ) . '.*?' . preg_quote( self::END, '/' ) . "\\n?\\n?/s";
		return (string) preg_replace( $pattern, '', $content );
	}

	/**
	 * Quita el bloque de un archivo sin autoprueba (al desactivar el plugin: quitar solo reglas
	 * propias no puede romper el sitio). Devuelve true si había bloque y se quitó.
	 */
	public static function stripFile( string $file ): bool {
		if ( ! is_file( $file ) ) {
			return false;
		}
		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo local.
		if ( false === $content || ! self::hasBlock( $content ) ) {
			return false;
		}
		return false !== file_put_contents( $file, self::withoutBlock( $content ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- escritura con bloqueo; WP_Filesystem puede pedir credenciales FTP.
	}

	public static function hasBlock( string $content ): bool {
		return str_contains( $content, self::BEGIN ) && str_contains( $content, self::END );
	}

	/**
	 * @return array{exists: bool, writable: bool, active: bool, backup_at: int}
	 */
	public function status(): array {
		$exists  = is_file( $this->file );
		$content = $exists ? (string) file_get_contents( $this->file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo local.
		$backup  = get_option( self::BACKUP_KEY, null );
		return [
			'exists'    => $exists,
			'writable'  => $exists ? wp_is_writable( $this->file ) : wp_is_writable( dirname( $this->file ) ),
			'active'    => self::hasBlock( $content ),
			'backup_at' => is_array( $backup ) && is_int( $backup['at'] ?? null ) ? $backup['at'] : 0,
		];
	}

	/**
	 * Escribe el bloque, prueba y restaura si algo dejó de responder.
	 *
	 * @return array{ok: bool, error: string, checks: list<array{url: string, status: int}>, restored: bool}
	 */
	public function apply(): array {
		return $this->change( true );
	}

	/**
	 * Quita el bloque (también con autoprueba).
	 *
	 * @return array{ok: bool, error: string, checks: list<array{url: string, status: int}>, restored: bool}
	 */
	public function remove(): array {
		return $this->change( false );
	}

	/**
	 * @return array{ok: bool, error: string, checks: list<array{url: string, status: int}>, restored: bool}
	 */
	private function change( bool $add ): array {
		$exists   = is_file( $this->file );
		$original = $exists ? file_get_contents( $this->file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo local.
		if ( false === $original ) {
			return self::result( false, __( 'No se pudo leer el .htaccess.', 'imagina-turbo' ) );
		}
		$next = $add ? self::withBlock( $original ) : self::withoutBlock( $original );
		if ( $next === $original ) {
			return self::result( true, '' );
		}
		update_option(
			self::BACKUP_KEY,
			[
				'at'      => time(),
				'exists'  => $exists,
				'content' => $original,
			],
			false
		);
		if ( ! $this->write( $next ) ) {
			return self::result( false, __( 'No se pudo escribir el .htaccess (permisos).', 'imagina-turbo' ) );
		}
		$checks = [];
		foreach ( $this->urls as $url ) {
			$status   = ( $this->probe )( $url );
			$checks[] = [
				'url'    => $url,
				'status' => $status,
			];
			if ( 200 !== $status ) {
				$this->restore();
				return [
					'ok'       => false,
					'error'    => sprintf(
						/* translators: 1: URL, 2: estado HTTP. */
						__( '%1$s respondió %2$d después del cambio: se restauró el .htaccess anterior.', 'imagina-turbo' ),
						$url,
						$status
					),
					'checks'   => $checks,
					'restored' => true,
				];
			}
		}
		return [
			'ok'       => true,
			'error'    => '',
			'checks'   => $checks,
			'restored' => false,
		];
	}

	/**
	 * Vuelve al respaldo.
	 */
	public function restore(): bool {
		$backup = get_option( self::BACKUP_KEY, null );
		if ( ! is_array( $backup ) || ! is_string( $backup['content'] ?? null ) ) {
			return false;
		}
		if ( false === ( $backup['exists'] ?? true ) && '' === $backup['content'] ) {
			wp_delete_file( $this->file );
			return ! is_file( $this->file );
		}
		return $this->write( $backup['content'] );
	}

	private function write( string $content ): bool {
		return false !== file_put_contents( $this->file, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- escritura atómica con bloqueo; WP_Filesystem puede pedir credenciales FTP.
	}

	/**
	 * @return array{ok: bool, error: string, checks: list<array{url: string, status: int}>, restored: bool}
	 */
	private static function result( bool $ok, string $error ): array {
		return [
			'ok'       => $ok,
			'error'    => $error,
			'checks'   => [],
			'restored' => false,
		];
	}
}
