<?php
/**
 * Cabecera Server-Timing para el administrador con la cookie de diagnóstico.
 *
 * Una cabecera solo puede llevar lo ocurrido antes de enviarse: en el frontend se emite en
 * send_headers (después de cargar plugins y resolver la consulta, antes de renderizar la plantilla).
 * El desglose completo, con el render, queda en la pantalla Diagnóstico.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Profiler;

/**
 * Emisión de Server-Timing.
 */
final class ServerTiming {

	private const TOP_PLUGINS = 6;

	private bool $sent = false;

	public function __construct( private readonly Collector $collector ) {
	}

	public function register(): void {
		add_action( 'send_headers', [ $this, 'emit' ], 999 );
		add_action( 'admin_init', [ $this, 'emit' ], PHP_INT_MAX );
		add_filter( 'rest_post_dispatch', [ $this, 'addToRestResponse' ], 999 );
	}

	/**
	 * Envía la cabecera si aún se puede.
	 */
	public function emit(): void {
		if ( $this->sent || headers_sent() ) {
			return;
		}
		$this->sent = true;
		header( 'Server-Timing: ' . $this->value(), false );
	}

	/**
	 * En REST las cabeceras las envía el servidor REST a partir de la respuesta.
	 *
	 * @param mixed $response Respuesta REST.
	 * @return mixed
	 */
	public function addToRestResponse( mixed $response ): mixed {
		if ( ! $this->sent && $response instanceof \WP_HTTP_Response ) {
			$this->sent = true;
			$response->header( 'Server-Timing', $this->value(), false );
		}
		return $response;
	}

	/**
	 * Valor de la cabecera (sintaxis: nombre;dur=ms;desc="texto", separados por coma).
	 */
	public function value(): string {
		$parts = [ self::metric( 'itb-total', $this->collector->elapsedMs(), 'Imagina Turbo: hasta las cabeceras' ) ];
		foreach ( $this->collector->phases() as $phase => $ms ) {
			$parts[] = self::metric( 'itb-fase-' . $phase, $ms, 'Fase ' . $phase );
		}
		$i = 0;
		foreach ( $this->collector->components() as $component => $cost ) {
			if ( ++$i > self::TOP_PLUGINS ) {
				break;
			}
			$parts[] = self::metric( 'itb-p' . $i, $cost['load_ms'] + $cost['hook_ms'], $component );
		}
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \wpdb ) {
			$parts[] = 'itb-db;desc="' . (int) $GLOBALS['wpdb']->num_queries . ' consultas"';
		}
		$parts[] = self::metric( 'itb-propio', $this->collector->overheadUs() / 1000, 'Costo del perfilador' );
		return implode( ', ', $parts );
	}

	/**
	 * Una métrica, con el nombre limitado a caracteres de token y la descripción escapada.
	 */
	public static function metric( string $name, float $ms, string $desc ): string {
		$token = (string) preg_replace( '/[^a-z0-9_-]/', '-', strtolower( $name ) );
		// Las cabeceras viajan en Latin-1: solo ASCII imprimible (se quitan tildes y control).
		$desc = str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], (string) preg_replace( '/[^\x20-\x7e]/', '', $desc ) );
		return sprintf( '%s;dur=%s;desc="%s"', $token, number_format( max( 0.0, $ms ), 2, '.', '' ), $desc );
	}
}
