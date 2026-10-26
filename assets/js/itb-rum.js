/**
 * Imagina Turbo · RUM (docs/02 módulo 11). Solo se descarga en la muestra (lo decide el script en línea).
 *
 * Mide LCP (y su imagen), INP aproximado, CLS, TTFB (con conexión, espera y edad de la copia), prerender (activationStart), prefetch
 * (deliveryType), recargas y bfcache (con notRestoredReasons cuando existe), y la transición desde la
 * plantilla anterior, las fuentes del propio sitio del primer render y el peso de la carga (peticiones, KB de JS, CSS y
 * total). Envía un beacon al ocultarse la página. Sin datos personales.
 */
( function () {
	var c = window.itbRum;
	if ( ! c || ! window.PerformanceObserver || ! window.JSON ) {
		return;
	}
	var d = window.matchMedia && matchMedia( '(max-width: 767px)' ).matches ? 'm' : 'd';
	var r = { t: c.t, d: d };
	var sent = 0;
	var lcp;
	var inp = 0;
	var cls = 0;
	var win = 0;
	var first = 0;
	var last = 0;
	var nav = performance.getEntriesByType && performance.getEntriesByType( 'navigation' )[ 0 ];
	var act = ( nav && nav.activationStart ) || 0;

	function watch( type, fn, extra ) {
		try {
			var o = { type: type, buffered: true };
			for ( var k in extra ) {
				o[ k ] = extra[ k ];
			}
			new PerformanceObserver( function ( list ) {
				list.getEntries().forEach( fn );
			} ).observe( o );
		} catch ( e ) {}
	}

	if ( nav ) {
		r.b = Math.max( 0, Math.round( nav.responseStart - act ) );
		var type = nav.type;
		r.n = act > 0 ? 'p' : nav.deliveryType === 'navigational-prefetch' ? 'f' : type === 'reload' ? 'r' : type === 'back_forward' ? 'b' : 'n';
		// Desglose del TTFB (0.14.0): conexión (redirecciones, DNS, TCP, TLS) y espera del servidor, solo
		// en navegaciones que fueron a la red; edad de la copia según la hora de generación del HTML.
		if ( r.n === 'n' || r.n === 'r' ) {
			r.k = Math.max( 0, Math.round( nav.requestStart ) );
			r.w = Math.max( 0, Math.round( nav.responseStart - nav.requestStart ) );
			if ( c.g ) {
				r.a = Math.max( 0, Math.round( performance.timeOrigin / 1000 - c.g ) );
			}
		}
		var nrr = nav.notRestoredReasons;
		if ( type === 'back_forward' && nrr ) {
			var x = [];
			( function walk( n ) {
				if ( ! n ) {
					return;
				}
				( n.reasons || [] ).forEach( function ( q ) {
					x.push( String( q.reason || q ).slice( 0, 40 ) );
				} );
				( n.children || [] ).forEach( walk );
			} )( nrr );
			if ( x.length ) {
				r.x = x.slice( 0, 5 );
			}
		}
	}

	watch( 'largest-contentful-paint', function ( e ) {
		lcp = e;
		r.l = Math.max( 0, Math.round( e.startTime - act ) );
	} );
	watch( 'layout-shift', function ( e ) {
		if ( e.hadRecentInput ) {
			return;
		}
		if ( win && e.startTime - last < 1000 && e.startTime - first < 5000 ) {
			win += e.value;
		} else {
			win = e.value;
			first = e.startTime;
		}
		last = e.startTime;
		cls = Math.max( cls, win );
	} );
	watch( 'event', function ( e ) {
		if ( e.interactionId && e.duration > inp ) {
			inp = e.duration;
		}
	}, { durationThreshold: 40 } );
	watch( 'first-input', function ( e ) {
		inp = Math.max( inp, e.duration );
	} );

	if ( c.pf ) {
		r.pf = c.pf;
		r.pu = location.pathname;
	}

	function image() {
		var u = lcp && ( lcp.url || ( lcp.element && lcp.element.currentSrc ) );
		var a;
		if ( ! u ) {
			return;
		}
		try {
			a = new URL( u, location.href );
		} catch ( e ) {
			return;
		}
		if ( a.origin !== location.origin ) {
			return;
		}
		// La imagen destacada de la página, con cualquier tamaño (nombre-300x200.jpg).
		var base = a.pathname.split( '/' ).pop().replace( /-\d+x\d+(?=\.\w+$)/, '' );
		if ( c.f && c.f.indexOf( base ) > -1 ) {
			r.lr = 'f';
		} else {
			r.lu = a.pathname;
		}
	}

	// Fuentes del propio sitio pedidas antes del LCP (0.15.0): son las del primer render. El informe
	// diario precarga las que se repiten en una plantilla.
	function fonts() {
		var until = lcp ? lcp.startTime : 3000;
		var out = [];
		( performance.getEntriesByType ? performance.getEntriesByType( 'resource' ) : [] ).forEach( function ( e ) {
			var a;
			if ( out.length > 1 || e.startTime > until || ! /\.(woff2?|ttf|otf)(\?|$)/i.test( e.name ) ) {
				return;
			}
			try {
				a = new URL( e.name );
			} catch ( x ) {
				return;
			}
			if ( a.origin === location.origin && out.indexOf( a.pathname ) < 0 ) {
				out.push( a.pathname );
			}
		} );
		if ( out.length ) {
			r.fo = out;
		}
	}

	// Peso de la carga (0.19.0, presupuestos): peticiones y KB de JS, CSS y total hasta el evento load,
	// sin contar caché (tamaño del cuerpo). De otros dominios, los KB solo si lo permiten
	// (Timing-Allow-Origin); la petición sí se cuenta.
	function weight() {
		var end = nav && nav.loadEventEnd;
		var q = 1;
		var j = 0;
		var s = 0;
		var t;
		if ( ! end ) {
			return;
		}
		t = nav.encodedBodySize || 0;
		performance.getEntriesByType( 'resource' ).forEach( function ( e ) {
			var b = e.encodedBodySize || e.transferSize || 0;
			if ( e.startTime > end ) {
				return;
			}
			q++;
			t += b;
			if ( e.initiatorType === 'script' || /\.m?js(\?|$)/i.test( e.name ) ) {
				j += b;
			} else if ( /\.css(\?|$)/i.test( e.name ) ) {
				s += b;
			}
		} );
		r.q = q;
		r.j = Math.round( j / 1024 );
		r.s = Math.round( s / 1024 );
		r.kb = Math.round( t / 1024 );
	}

	function post( data ) {
		var body = JSON.stringify( data );
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( c.e, body );
		} else if ( window.fetch ) {
			fetch( c.e, { method: 'POST', body: body, keepalive: true } );
		}
	}

	function send() {
		if ( sent ) {
			return;
		}
		sent = 1;
		image();
		fonts();
		weight();
		if ( inp ) {
			r.i = Math.round( inp );
		}
		r.c = Math.round( cls * 1000 );
		post( r );
	}

	addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			send();
		}
	} );
	addEventListener( 'pagehide', send );
	// Vuelta desde el bfcache: la página no se volvió a cargar.
	addEventListener( 'pageshow', function ( e ) {
		if ( e.persisted ) {
			post( { t: c.t, d: d, n: 'B' } );
		}
	} );
} )();
