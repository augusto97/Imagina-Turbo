<?php
/**
 * Registro de eventos: cambios, aplicaciones, reversiones, modo seguro, errores.
 *
 * Nunca lanza excepciones: registrar no debe romper la operación que se registra.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Events;

use ImaginaTurbo\Repository\EventRepository;

/**
 * Servicio de registro.
 */
final class EventLog {

	public const INFO    = 0;
	public const WARNING = 1;
	public const ERROR   = 2;

	/** Retención por defecto (docs/01): 180 días. */
	public const RETENTION_DAYS = 180;

	/** Tamaño máximo del contexto JSON. */
	private const MAX_CONTEXT_BYTES = 65535;

	/** Claves que nunca se guardan en el contexto. */
	private const SENSITIVE_KEYS = [ 'token', 'password', 'secret', 'api_key', 'apikey', 'authorization', 'cookie', 'license_key', 'key' ];

	public function __construct( private readonly EventRepository $repository ) {
	}

	/**
	 * Registra un evento. Devuelve el id o 0 si no se pudo.
	 *
	 * @param array<string, mixed> $context Datos adicionales (se eliminan claves sensibles).
	 */
	public function log( string $module, string $type, int $severity, string $message, array $context = [] ): int {
		try {
			$json = [] === $context ? null : wp_json_encode( self::redact( $context ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( is_string( $json ) && strlen( $json ) > self::MAX_CONTEXT_BYTES ) {
				$json = (string) wp_json_encode( [ 'truncated' => true ] );
			}
			return $this->repository->insert(
				[
					'module'     => substr( sanitize_key( $module ), 0, 40 ),
					'type'       => substr( sanitize_key( $type ), 0, 40 ),
					'severity'   => max( self::INFO, min( self::ERROR, $severity ) ),
					'message'    => mb_substr( wp_strip_all_tags( $message ), 0, 255 ),
					'context'    => is_string( $json ) ? $json : null,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				]
			);
		} catch ( \Throwable ) {
			return 0;
		}
	}

	/**
	 * @param array<string, mixed> $context Contexto.
	 */
	public function info( string $module, string $type, string $message, array $context = [] ): int {
		return $this->log( $module, $type, self::INFO, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context Contexto.
	 */
	public function warning( string $module, string $type, string $message, array $context = [] ): int {
		return $this->log( $module, $type, self::WARNING, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context Contexto.
	 */
	public function error( string $module, string $type, string $message, array $context = [] ): int {
		return $this->log( $module, $type, self::ERROR, $message, $context );
	}

	/**
	 * Borra eventos más antiguos que la retención.
	 */
	public function purge( int $days = self::RETENTION_DAYS ): int {
		try {
			return $this->repository->deleteOlderThan( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
		} catch ( \Throwable ) {
			return 0;
		}
	}

	/**
	 * Quita valores de claves sensibles, de forma recursiva.
	 *
	 * @param array<mixed> $context Contexto.
	 * @return array<mixed>
	 */
	public static function redact( array $context, int $depth = 0 ): array {
		if ( $depth > 5 ) {
			return [ 'truncated' => true ];
		}
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), self::SENSITIVE_KEYS, true ) ) {
				$context[ $key ] = '[oculto]';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value, $depth + 1 );
			} elseif ( is_object( $value ) ) {
				$context[ $key ] = get_class( $value );
			}
		}
		return $context;
	}
}
