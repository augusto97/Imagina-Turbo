<?php
/**
 * GET /events: registro paginado con filtros. GET /events/export: hasta 5.000 filas.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Repository\EventRepository;

/**
 * Registro de eventos.
 */
final class EventsController extends Controller {

	private const EXPORT_LIMIT = 5000;

	public function registerRoutes(): void {
		$filters = [
			'module'   => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			],
			'type'     => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => '',
			],
			'severity' => [
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 2,
			],
			'search'   => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'maxLength'         => 100,
			],
		];

		$this->route(
			'/events',
			\WP_REST_Server::READABLE,
			[ $this, 'list' ],
			array_merge(
				$filters,
				[
					'page'     => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 50,
					],
				]
			)
		);
		$this->route( '/events/export', \WP_REST_Server::READABLE, [ $this, 'export' ], $filters );
	}

	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$repository = $this->container->get( EventRepository::class );
		$result     = $repository->query( self::filters( $request ), (int) $request->get_param( 'page' ), (int) $request->get_param( 'per_page' ) );
		return $this->ok(
			[
				'items'    => $result['items'],
				'total'    => $result['total'],
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
				'modules'  => $repository->modules(),
			]
		);
	}

	public function export( \WP_REST_Request $request ): \WP_REST_Response {
		$repository = $this->container->get( EventRepository::class );
		$items      = [];
		$page       = 1;
		do {
			$batch    = $repository->query( self::filters( $request ), $page, 200 );
			$items    = array_merge( $items, $batch['items'] );
			$fullPage = 200 === count( $batch['items'] );
			$total    = count( $items );
			++$page;
		} while ( $fullPage && $total < self::EXPORT_LIMIT );

		return $this->ok( array_slice( $items, 0, self::EXPORT_LIMIT ) );
	}

	/**
	 * @return array{module?: string, type?: string, severity?: int, search?: string}
	 */
	private static function filters( \WP_REST_Request $request ): array {
		$filters = [];
		foreach ( [ 'module', 'type', 'search' ] as $key ) {
			$value = $request->get_param( $key );
			if ( is_string( $value ) && '' !== $value ) {
				$filters[ $key ] = $value;
			}
		}
		$severity = $request->get_param( 'severity' );
		if ( is_numeric( $severity ) ) {
			$filters['severity'] = (int) $severity;
		}
		return $filters;
	}
}
