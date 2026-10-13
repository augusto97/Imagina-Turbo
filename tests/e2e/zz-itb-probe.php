<?php
/**
 * Sonda SOLO para la instancia de pruebas (NUNCA en producción): expone en cabeceras lo que decidió la etapa temprana.
 * El nombre empieza por "zz" para cargarse después del loader de Imagina Turbo.
 */
$itb_probe = static function () {
		if ( ! class_exists( '\ImaginaTurbo\Core\Runtime', false ) ) {
			header( 'X-ITB-Probe: no-runtime' );
			return;
		}
		$r = \ImaginaTurbo\Core\Runtime::class;
		header( 'X-ITB-Probe: ' . json_encode( [
			'mu'     => $r::$fromMuLoader,
			'main'   => $r::$mainBooted,
			'kill'   => $r::$killReason,
			'safe'   => $r::$safeMode,
			'config' => null !== $r::$config,
			'type'   => $r::$request?->type->value,
			'ep'     => $r::$request?->endpoint,
		] ) );
};
add_action( 'send_headers', $itb_probe );
add_action( 'login_init', $itb_probe );
add_filter( 'rest_pre_serve_request', static function ( $served ) use ( $itb_probe ) { $itb_probe(); return $served; } );
add_action( 'admin_init', static function () use ( $itb_probe ) { if ( ! headers_sent() ) { $itb_probe(); } } );
// Simula un fatal dentro del directorio del plugin (para probar el modo seguro).
if ( isset( $_GET['itb_probe_fatal'] ) ) {
	add_action( 'init', static function () {
		require WP_PLUGIN_DIR . ( isset( $_GET['otro'] ) ? '/otro/f.php' : '/imagina-turbo/zz-test-fatal.php' );
	} );
}
