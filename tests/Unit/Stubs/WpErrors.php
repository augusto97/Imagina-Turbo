<?php
/**
 * WP_Error, WP_REST_Request y RouteException mínimos para probar Turnstile sin WordPress ni WooCommerce.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WP_Error', false ) ) {
		// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ValidClassName.NotCamelCaps
		class WP_Error {

			/** @var array<string, list<string>> */
			public array $errors = [];

			public function __construct( string $code = '', string $message = '' ) {
				if ( '' !== $code ) {
					$this->add( $code, $message );
				}
			}

			public function add( string $code, string $message ): void {
				$this->errors[ $code ][] = $message;
			}

			public function has_errors(): bool {
				return [] !== $this->errors;
			}

			/** @return list<string> */
			public function get_error_codes(): array {
				return array_keys( $this->errors );
			}

			public function get_error_message( string $code = '' ): string {
				$code = '' === $code ? (string) array_key_first( $this->errors ) : $code;
				return $this->errors[ $code ][0] ?? '';
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Request', false ) ) {
		// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ValidClassName.NotCamelCaps
		class WP_REST_Request {

			/**
			 * @param array<string, mixed> $params Parámetros.
			 */
			public function __construct( private string $method = 'GET', private array $params = [] ) {
			}

			public function get_method(): string {
				return $this->method;
			}

			public function get_param( string $key ): mixed {
				return $this->params[ $key ] ?? null;
			}
		}
	}
}

namespace Automattic\WooCommerce\StoreApi\Exceptions {
	if ( ! class_exists( RouteException::class, false ) ) {
		// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		class RouteException extends \Exception {

			public function __construct( public string $errorCode, string $message, public int $status = 400 ) {
				parent::__construct( $message );
			}
		}
	}
}
