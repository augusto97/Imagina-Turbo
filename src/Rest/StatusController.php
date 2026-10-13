<?php
/**
 * GET /status, /modules, /compat/report, /license.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Rest;

use ImaginaTurbo\Core\Diagnostics;
use ImaginaTurbo\Licensing\ImaginaUpdater;

/**
 * Lecturas de estado.
 */
final class StatusController extends Controller {

	public function registerRoutes(): void {
		$this->route( '/status', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->container->get( Diagnostics::class )->status() ) );
		$this->route( '/modules', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->container->get( Diagnostics::class )->modules() ) );
		$this->route( '/compat/report', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->container->get( Diagnostics::class )->compatReport() ) );
		$this->route( '/license', \WP_REST_Server::READABLE, fn(): \WP_REST_Response => $this->ok( $this->container->get( ImaginaUpdater::class )->status() ) );
	}
}
