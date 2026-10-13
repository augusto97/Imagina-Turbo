<?php
/**
 * Límites por plan de Cloudflare (data/cloudflare-limits.json, actualizable desde el hub).
 *
 * Son orientativos: lo definitivo lo dice la propia API con ?dry_run=true antes de aplicar. Un plan
 * desconocido se trata como Free (lo más restrictivo).
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Lectura de límites.
 */
final class PlanLimits {

	public const PLANS = [ 'free', 'pro', 'business', 'enterprise' ];

	/** @var array<string, mixed> */
	private array $limits;

	/**
	 * @param array<string, mixed> $data Contenido del archivo (o vacío: valores de Free).
	 */
	public function __construct( private readonly string $plan, array $data ) {
		$plans        = is_array( $data['plans'] ?? null ) ? $data['plans'] : [];
		$selected     = is_array( $plans[ self::normalize( $plan ) ] ?? null ) ? $plans[ self::normalize( $plan ) ] : ( is_array( $plans['free'] ?? null ) ? $plans['free'] : [] );
		$this->limits = $selected;
	}

	public static function fromFile( string $plan, string $file ): self {
		$raw  = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo local del plugin.
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return new self( $plan, is_array( $data ) ? $data : [] );
	}

	/**
	 * Plan a partir de plan.legacy_id de la zona (campo marcado como obsoleto por Cloudflare: si
	 * desaparece o trae un valor desconocido, se asume Free).
	 */
	public static function normalize( string $legacyId ): string {
		$id = strtolower( trim( $legacyId ) );
		return in_array( $id, self::PLANS, true ) ? $id : 'free';
	}

	public function plan(): string {
		return self::normalize( $this->plan );
	}

	public function int( string $key, int $default = 0 ): int {
		return is_int( $this->limits[ $key ] ?? null ) ? $this->limits[ $key ] : $default;
	}

	public function bool( string $key ): bool {
		return true === ( $this->limits[ $key ] ?? false );
	}

	/**
	 * Reglas que admite una fase ('0' si no se conoce).
	 */
	public function rulesFor( string $phase ): int {
		return match ( $phase ) {
			Phases::CACHE     => $this->int( 'cache_rules', 10 ),
			Phases::TRANSFORM => $this->int( 'transform_rules', 10 ),
			Phases::WAF       => $this->int( 'waf_custom_rules', 5 ),
			Phases::RATELIMIT => $this->int( 'ratelimit_rules', 1 ),
			default           => 0,
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	public function ratelimit(): array {
		return is_array( $this->limits['ratelimit'] ?? null ) ? $this->limits['ratelimit'] : [];
	}
}
