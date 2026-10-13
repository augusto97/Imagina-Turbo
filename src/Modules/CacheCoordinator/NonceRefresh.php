<?php
/**
 * Refresco de nonces en páginas cacheadas (docs/02, módulo 2).
 *
 * Un nonce de WordPress vale entre 12 y 24 h. Una página que sale de caché con más edad lleva nonces
 * caducados y sus formularios o llamadas AJAX fallan. Mientras se genera una página cacheable se
 * anotan las acciones de nonce que usa (filtro `nonce_life`, que recibe la acción desde WP 6.1) y se
 * imprimen en el pie, firmadas. `itb-nonce.js` pide valores nuevos solo si la copia tiene más de
 * 10 h y los reemplaza en el DOM y en los objetos globales de la página.
 *
 * La firma impide pedir nonces de acciones que ninguna página usa.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\CacheCoordinator;

/**
 * Recolección e impresión.
 */
final class NonceRefresh {

	public const MAX_ACTIONS = 20;

	/** Edad máxima de una página para pedir nonces nuevos (más que el TTL máximo del edge). */
	public const MAX_PAGE_AGE = 8 * DAY_IN_SECONDS;

	public const ACTION_PATTERN = '/^[A-Za-z0-9_\-.:|\/\[\]]{1,100}$/';

	/** @var array<string, true> */
	private array $actions = [];

	private bool $printing = false;

	private bool $started = false;

	public function __construct( private readonly string $scriptUrl, private readonly string $endpoint ) {
	}

	/**
	 * Empieza a anotar (la respuesta resultó cacheable).
	 */
	public function start(): void {
		if ( $this->started ) {
			return;
		}
		$this->started = true;
		add_filter( 'nonce_life', [ $this, 'collect' ], PHP_INT_MAX, 2 );
		add_action( 'wp_footer', [ $this, 'printData' ], PHP_INT_MAX );
	}

	/**
	 * @param mixed $life   Duración (sin cambios).
	 * @param mixed $action Acción del nonce.
	 * @return mixed
	 */
	public function collect( mixed $life, mixed $action = -1 ): mixed {
		if ( ! $this->printing && is_string( $action ) && count( $this->actions ) < self::MAX_ACTIONS && 1 === preg_match( self::ACTION_PATTERN, $action ) ) {
			$this->actions[ $action ] = true;
		}
		return $life;
	}

	public function printData(): void {
		if ( [] === $this->actions ) {
			return;
		}
		$this->printing = true;
		$actions        = array_keys( $this->actions );
		sort( $actions );
		$now    = time();
		$nonces = [];
		foreach ( $actions as $action ) {
			$nonces[ $action ] = wp_create_nonce( $action );
		}
		$data = [
			't' => $now,
			'n' => $nonces,
			's' => self::sign( $actions, $now ),
			'u' => $this->endpoint,
		];
		echo '<script id="itb-nonce" type="application/json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON con < y & escapados.
		echo '<script src="' . esc_url( $this->scriptUrl ) . '" defer></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- se imprime después de wp_print_footer_scripts, solo en páginas cacheables.
		$this->printing = false;
	}

	/**
	 * Firma de una lista de acciones en un instante.
	 *
	 * @param list<string> $actions Acciones ordenadas.
	 */
	public static function sign( array $actions, int $time ): string {
		return substr( hash_hmac( 'sha256', $time . "\n" . implode( "\n", $actions ), self::key() ), 0, 32 );
	}

	/**
	 * Valida una petición de refresco.
	 *
	 * @param mixed $actions Acciones pedidas.
	 * @return list<string>|null Acciones válidas, o null si la firma o la fecha no cuadran.
	 */
	public static function verify( mixed $actions, int $time, string $signature, ?int $now = null ): ?array {
		$now = $now ?? time();
		if ( ! is_array( $actions ) || [] === $actions || count( $actions ) > self::MAX_ACTIONS || $time > $now + 300 || $time < $now - self::MAX_PAGE_AGE ) {
			return null;
		}
		$clean = [];
		foreach ( $actions as $action ) {
			if ( ! is_string( $action ) || 1 !== preg_match( self::ACTION_PATTERN, $action ) ) {
				return null;
			}
			$clean[ $action ] = true;
		}
		$clean = array_keys( $clean );
		sort( $clean );
		return hash_equals( self::sign( $clean, $time ), $signature ) ? $clean : null;
	}

	private static function key(): string {
		return function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) . 'itb-nonce' : 'itb-nonce';
	}
}
