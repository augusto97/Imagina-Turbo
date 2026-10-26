/**
 * Imagina Turbo: elementos diferidos (0.17.0).
 *
 * Cada marcador <div data-itb-lz> lleva el HTML original dentro de un <template> (LazyElements.php).
 * - "v": pasa al DOM al acercarse a la pantalla (IntersectionObserver, 600 px antes).
 * - "i": en la primera interacción (cursor, toque, tecla, foco). Lo "v" que está oculto (sin caja,
 *   dentro de un desplegable cerrado) también pasa en ese momento.
 * - Todo pasa antes de imprimir y si la URL apunta a un ancla.
 * Los <style> y <link> de cada plantilla se mueven al lugar del marcador en el acto: Elementor y
 * WoodMart imprimen el CSS de un widget solo la primera vez que aparece, y las siguientes apariciones
 * (fuera de la plantilla) lo necesitan. Al pasar al DOM, si Elementor ya se inició, se le piden los
 * manejadores de cada elemento (runReadyTrigger), como hace él al cargar.
 * Vanilla, sin dependencias.
 */
( function ( d, w ) {
	var io = null;
	var started = false;
	var lsDone = false;
	var events = [ 'pointerover', 'pointerdown', 'touchstart', 'keydown', 'focusin' ];

	function moveStyles( ph ) {
		var t = ph.firstElementChild;
		if ( t && t.content ) {
			[].forEach.call( t.content.querySelectorAll( 'style,link[rel="stylesheet"]' ), function ( s ) {
				ph.parentNode.insertBefore( s, ph );
			} );
		}
	}

	// Scripts que el plugin de caché retrasa: si su carga ya terminó, se ejecutan aquí.
	function runDelayed( list ) {
		list.forEach( function ( old ) {
			var s = d.createElement( 'script' );
			[].forEach.call( old.attributes, function ( a ) {
				if ( a.name !== 'type' && a.name !== 'data-src' ) {
					s.setAttribute( a.name, a.value );
				}
			} );
			if ( old.getAttribute( 'data-src' ) ) {
				s.src = old.getAttribute( 'data-src' );
			} else {
				s.text = old.text;
			}
			old.parentNode.replaceChild( s, old );
		} );
	}

	function show( ph ) {
		var t = ph.firstElementChild;
		var frag;
		var els;
		var inner;
		var delayed;
		var ef = w.elementorFrontend;
		if ( ! ph.parentNode ) {
			return;
		}
		if ( io ) {
			io.unobserve( ph );
		}
		if ( ! t || ! t.content ) {
			ph.parentNode.removeChild( ph );
			return;
		}
		frag = d.importNode( t.content, true );
		els = [].slice.call( frag.querySelectorAll( '.elementor-element' ) );
		inner = [].slice.call( frag.querySelectorAll( '[data-itb-lz]' ) );
		delayed = lsDone ? [].slice.call( frag.querySelectorAll( 'script[type="litespeed/javascript"]' ) ) : [];
		ph.parentNode.replaceChild( frag, ph );
		runDelayed( delayed );
		inner.forEach( watch );
		if ( ef && ef.documentsManager && ef.elementsHandler ) {
			els.forEach( function ( el ) {
				setTimeout( function () {
					ef.elementsHandler.runReadyTrigger( el );
				} );
			} );
		}
	}

	function all() {
		[].slice.call( d.querySelectorAll( '[data-itb-lz]' ) ).forEach( show );
	}

	function watch( ph ) {
		moveStyles( ph );
		if ( ph.getAttribute( 'data-itb-lz' ) === 'v' && io ) {
			io.observe( ph );
		}
		if ( started ) {
			setTimeout( function () {
				pending( ph );
			} );
		}
	}

	function pending( ph ) {
		if ( ph.getAttribute( 'data-itb-lz' ) === 'i' || ! ph.getClientRects().length ) {
			show( ph );
		}
	}

	// Primera interacción: lo de los menús y lo "v" que está oculto (sin caja: el observador nunca lo ve).
	function interacted() {
		started = true;
		events.forEach( function ( e ) {
			d.removeEventListener( e, interacted, true );
		} );
		[].slice.call( d.querySelectorAll( '[data-itb-lz]' ) ).forEach( pending );
	}

	if ( 'IntersectionObserver' in w ) {
		io = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( e ) {
					if ( e.isIntersecting ) {
						show( e.target );
					}
				} );
			},
			{ rootMargin: '600px 0px' }
		);
	}
	[].slice.call( d.querySelectorAll( '[data-itb-lz]' ) ).forEach( watch );
	if ( ! io || location.hash.length > 1 ) {
		all();
	}
	events.forEach( function ( e ) {
		d.addEventListener( e, interacted, { capture: true, passive: true } );
	} );
	w.addEventListener( 'beforeprint', all );
	w.addEventListener( 'hashchange', all );
	w.addEventListener( 'DOMContentLiteSpeedLoaded', function () {
		lsDone = true;
	} );
}( document, window ) );
