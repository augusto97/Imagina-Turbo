/**
 * Imagina Turbo: carga de los scripts retrasados (módulo Carga de scripts, 0.14.0).
 *
 * Los scripts que coinciden con los patrones salen como <script type="text/plain" data-itb-delay>.
 * En la primera interacción (o tras itbDelay.t segundos, si es > 0) se reemplazan en orden por
 * scripts reales: un externo sin async ni defer se espera antes de seguir, para que el código en
 * línea que viene detrás (gtag(), fbq()) lo encuentre. En prerender, el plazo empieza al abrir la
 * página. Vanilla, sin dependencias.
 */
( function () {
	var c = window.itbDelay || {};
	var events = [ 'pointerdown', 'keydown', 'touchstart', 'scroll', 'wheel', 'mousemove' ];
	var started = false;

	function run( list ) {
		var old = list.shift();
		var s;
		var src;
		var wait;
		var i;
		var a;
		if ( ! old ) {
			return;
		}
		s = document.createElement( 'script' );
		for ( i = 0; i < old.attributes.length; i++ ) {
			a = old.attributes[ i ];
			if ( a.name !== 'type' && a.name.indexOf( 'data-itb-' ) !== 0 ) {
				s.setAttribute( a.name, a.value );
			}
		}
		if ( old.getAttribute( 'data-itb-type' ) ) {
			s.type = old.getAttribute( 'data-itb-type' );
		}
		src = old.getAttribute( 'data-itb-src' );
		wait = src && ! old.hasAttribute( 'async' ) && ! old.hasAttribute( 'defer' );
		if ( src ) {
			s.async = ! wait;
			s.src = src;
		} else {
			s.text = old.text;
		}
		if ( wait ) {
			s.onload = s.onerror = function () {
				run( list );
			};
		}
		old.parentNode.replaceChild( s, old );
		if ( ! wait ) {
			run( list );
		}
	}

	function start() {
		if ( started ) {
			return;
		}
		started = true;
		events.forEach( function ( e ) {
			window.removeEventListener( e, start, { passive: true } );
		} );
		run( [].slice.call( document.querySelectorAll( 'script[data-itb-delay]' ) ) );
	}

	function timer() {
		if ( c.t > 0 ) {
			setTimeout( start, c.t * 1000 );
		}
	}

	events.forEach( function ( e ) {
		window.addEventListener( e, start, { passive: true } );
	} );
	if ( document.prerendering ) {
		document.addEventListener( 'prerenderingchange', timer, { once: true } );
	} else {
		timer();
	}
} )();
