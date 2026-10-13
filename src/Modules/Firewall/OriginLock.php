<?php
/**
 * Origen cerrado (docs/03, sección 6, capa 2): ¿llega tráfico externo directo al servidor, sin pasar
 * por Cloudflare? ¿El servidor restaura la IP real del visitante?
 *
 * No añade trabajo al frontend: el perfilador ya guarda en cada muestra por dónde llegó el request
 * (columna via_cf) y aquí solo se clasifica y se resume.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Modules\Firewall;

/**
 * Clasificación y resumen.
 */
final class OriginLock {

	/** Llegó directo desde una IP pública, sin Cloudflare. */
	public const DIRECT = 0;
	/** Por Cloudflare, con la IP real del visitante en REMOTE_ADDR. */
	public const CLOUDFLARE = 1;
	/** Local: CLI, loopback o red privada (cron del sistema, recalentado, proxy local). */
	public const LOCAL = 2;
	/** Por Cloudflare, pero REMOTE_ADDR es la IP de Cloudflare: el servidor no restaura la IP real. */
	public const CLOUDFLARE_PROXY_IP = 3;

	/** Desde cuándo las muestras clasifican el origen (las anteriores a la 0.6 no distinguían local). */
	public const STATE_OPTION = 'itb_origin_state';

	/** Muestras mínimas para opinar. */
	public const MIN_SAMPLES = 20;

	/**
	 * @param array<mixed> $server $_SERVER.
	 */
	public static function classify( array $server ): int {
		$remote = is_string( $server['REMOTE_ADDR'] ?? null ) ? $server['REMOTE_ADDR'] : '';
		if ( isset( $server['HTTP_CF_RAY'] ) ) {
			$visitor = is_string( $server['HTTP_CF_CONNECTING_IP'] ?? null ) ? trim( $server['HTTP_CF_CONNECTING_IP'] ) : '';
			return '' !== $visitor && $visitor === $remote ? self::CLOUDFLARE : self::CLOUDFLARE_PROXY_IP;
		}
		if ( 'cli' === PHP_SAPI || '' === $remote ) {
			return self::LOCAL;
		}
		$public = filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		return false === $public ? self::LOCAL : self::DIRECT;
	}

	public static function viaCloudflare( int $code ): bool {
		return self::CLOUDFLARE === $code || self::CLOUDFLARE_PROXY_IP === $code;
	}

	/**
	 * Resumen a partir de muestras del perfilador (request_type, via_cf).
	 *
	 * @param list<array<string, mixed>> $rows  Muestras.
	 * @param int                        $since Ignorar muestras anteriores (timestamp).
	 * @return array{samples: int, cloudflare: int, direct: int, local: int, proxy_ip: int, state: string, message: string}
	 */
	public static function report( array $rows, int $since = 0 ): array {
		$from = $since > 0 ? gmdate( 'Y-m-d H:i:s', $since ) : '';
		$count = [
			self::DIRECT              => 0,
			self::CLOUDFLARE          => 0,
			self::LOCAL               => 0,
			self::CLOUDFLARE_PROXY_IP => 0,
		];
		foreach ( $rows as $row ) {
			// El cron y el CLI no cuentan: los dispara el propio servidor.
			if ( in_array( $row['request_type'] ?? '', [ 'Cron', 'Cli' ], true ) ) {
				continue;
			}
			if ( '' !== $from && (string) ( $row['created_at'] ?? '' ) < $from ) {
				continue;
			}
			$code = (int) ( $row['via_cf'] ?? self::LOCAL );
			if ( isset( $count[ $code ] ) ) {
				++$count[ $code ];
			}
		}
		$cf      = $count[ self::CLOUDFLARE ] + $count[ self::CLOUDFLARE_PROXY_IP ];
		$samples = $cf + $count[ self::DIRECT ];
		$out     = [
			'samples'    => $samples,
			'cloudflare' => $cf,
			'direct'     => $count[ self::DIRECT ],
			'local'      => $count[ self::LOCAL ],
			'proxy_ip'   => $count[ self::CLOUDFLARE_PROXY_IP ],
			'state'      => 'unknown',
			'message'    => __( 'Aún no hay suficientes muestras del perfilador para saberlo.', 'imagina-turbo' ),
		];
		if ( $samples < self::MIN_SAMPLES ) {
			return $out;
		}
		if ( 0 === $cf ) {
			$out['state']   = 'no_cloudflare';
			$out['message'] = __( 'El tráfico no llega por Cloudflare: el firewall en el edge no protege este sitio.', 'imagina-turbo' );
			return $out;
		}
		if ( $count[ self::DIRECT ] > 0 ) {
			$out['state']   = 'open';
			$out['message'] = sprintf(
				/* translators: %d: porcentaje de requests que llegan directo al servidor. */
				__( 'El %d %% del tráfico externo llega directo al servidor sin pasar por Cloudflare: quien conozca la IP se salta el firewall y la caché del edge. Cierra los puertos 80 y 443 a todo lo que no sean los rangos de Cloudflare.', 'imagina-turbo' ),
				max( 1, (int) round( 100 * $count[ self::DIRECT ] / $samples ) )
			);
			return $out;
		}
		$out['state']   = 'closed';
		$out['message'] = __( 'Todo el tráfico externo de las muestras llegó por Cloudflare.', 'imagina-turbo' );
		return $out;
	}

	/**
	 * ¿El servidor ve la IP real? (CrowdSec, los logs y los límites por IP la necesitan.)
	 *
	 * @param array{cloudflare: int, proxy_ip: int} $report Resumen.
	 */
	public static function realIpMissing( array $report ): bool {
		return $report['cloudflare'] >= self::MIN_SAMPLES && $report['proxy_ip'] * 2 > $report['cloudflare'];
	}
}
