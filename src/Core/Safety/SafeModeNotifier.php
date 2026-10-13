<?php
/**
 * Registra en el log y avisa por correo cuando el loader activó el modo seguro.
 *
 * El loader solo escribe la bandera (en el shutdown de un fatal no se puede confiar en la base de
 * datos ni en el correo). Este servicio corre en el siguiente request de admin o cron.
 *
 * @package ImaginaTurbo
 */

declare(strict_types=1);

namespace ImaginaTurbo\Core\Safety;

use ImaginaTurbo\Core\Events\EventLog;

/**
 * Aviso único por cada entrada en modo seguro.
 */
final class SafeModeNotifier {

	public function __construct(
		private readonly SafeMode $safeMode,
		private readonly EventLog $events,
	) {
	}

	/**
	 * Si hay modo seguro sin notificar: registra el evento, envía el correo y marca la bandera.
	 */
	public function maybeNotify(): void {
		$details = $this->safeMode->details();
		if ( null === $details || true === ( $details['notified'] ?? false ) ) {
			return;
		}
		// Se marca primero: si el correo falla, no se reintenta en cada request.
		if ( ! $this->safeMode->markNotified() ) {
			return;
		}

		$context = is_array( $details['context'] ?? null ) ? $details['context'] : [];
		$this->events->error(
			'core',
			'safe_mode',
			__( 'Modo seguro activado automáticamente por errores fatales repetidos.', 'imagina-turbo' ),
			$context
		);

		$admin = get_option( 'admin_email' );
		if ( is_string( $admin ) && is_email( $admin ) ) {
			$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
			wp_mail(
				$admin,
				/* translators: %s: nombre del sitio */
				sprintf( __( '[%s] Imagina Turbo entró en modo seguro', 'imagina-turbo' ), $site ),
				sprintf(
					/* translators: 1: mensaje del error, 2: archivo, 3: URL del admin */
					__( "Imagina Turbo detectó errores fatales repetidos y entró en modo seguro: todos sus módulos quedaron apagados y el sitio sigue funcionando sin ellos.\n\nÚltimo error: %1\$s\nArchivo: %2\$s\n\nRevisa el registro y sal del modo seguro cuando esté resuelto:\n%3\$s", 'imagina-turbo' ),
					is_string( $context['message'] ?? null ) ? $context['message'] : '-',
					is_string( $context['file'] ?? null ) ? $context['file'] : '-',
					admin_url( 'admin.php?page=imagina-turbo#/avanzado' )
				)
			);
		}
	}
}
