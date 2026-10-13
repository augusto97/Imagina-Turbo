<?php
/**
 * Respuesta normalizada de la API de Cloudflare: { success, errors[], messages[], result, result_info }.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Cloudflare;

/**
 * Resultado de una llamada.
 */
final class Response {

	/**
	 * @param list<array{code: int|string, message: string}> $errors     Errores de la API.
	 * @param array<string, mixed>                            $resultInfo Paginación.
	 */
	public function __construct(
		public readonly int $status,
		public readonly bool $success,
		public readonly mixed $result,
		public readonly array $errors = [],
		public readonly array $resultInfo = [],
		public readonly int $retryAfter = 0,
	) {
	}

	public static function failure( int $status, string $code, string $message ): self {
		return new self(
			$status,
			false,
			null,
			[
				[
					'code'    => $code,
					'message' => $message,
				],
			]
		);
	}

	public static function fromHttp( int $status, string $body, int $retryAfter = 0 ): self {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new self(
				$status,
				false,
				null,
				[
					[
						'code'    => 'itb_invalid_json',
						'message' => 'HTTP ' . $status,
					],
				],
				[],
				$retryAfter
			);
		}
		$errors = [];
		foreach ( is_array( $data['errors'] ?? null ) ? $data['errors'] : [] as $error ) {
			if ( is_array( $error ) ) {
				$errors[] = [
					'code'    => is_int( $error['code'] ?? null ) || is_string( $error['code'] ?? null ) ? $error['code'] : 0,
					'message' => substr( is_string( $error['message'] ?? null ) ? $error['message'] : '', 0, 300 ),
				];
			}
		}
		// GraphQL responde 200 con errors[] y sin "success".
		$success = array_key_exists( 'success', $data ) ? true === $data['success'] : [] === $errors;
		return new self(
			$status,
			$success && $status >= 200 && $status < 300,
			$data['result'] ?? ( $data['data'] ?? null ),
			$errors,
			is_array( $data['result_info'] ?? null ) ? $data['result_info'] : [],
			$retryAfter
		);
	}

	public function ok(): bool {
		return $this->success;
	}

	/**
	 * 429, 5xx o error de red: vale la pena reintentar.
	 */
	public function retryable(): bool {
		return ( 0 === $this->status && $this->hasCode( 'itb_http' ) ) || 429 === $this->status || $this->status >= 500;
	}

	public function hasCode( int|string $code ): bool {
		foreach ( $this->errors as $error ) {
			if ( (string) $error['code'] === (string) $code ) {
				return true;
			}
		}
		return false;
	}

	public function message(): string {
		if ( [] === $this->errors ) {
			return $this->ok() ? '' : 'HTTP ' . $this->status;
		}
		return implode( '; ', array_map( static fn( array $e ): string => ( '' !== (string) $e['code'] ? $e['code'] . ': ' : '' ) . $e['message'], array_slice( $this->errors, 0, 3 ) ) );
	}

	/**
	 * Resultado como arreglo (vacío si no lo es).
	 *
	 * @return array<mixed>
	 */
	public function resultArray(): array {
		return is_array( $this->result ) ? $this->result : [];
	}
}
