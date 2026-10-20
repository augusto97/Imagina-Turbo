/**
 * Imagina Turbo: compatibilidad con «Load JS Delayed» de LiteSpeed Cache (0.10.7, ampliado en 0.14.5).
 *
 * LSCache carga el JS retrasado en la primera interacción, en orden, y al terminar dispara
 * DOMContentLiteSpeedLoaded (assets/js/js_delay.js, 7.9.1). Para entonces la página ya pasó por
 * DOMContentLoaded y load, y jQuery (que no se retrasa) ya está «listo». Sin ayuda:
 * - cada jQuery(fn) de un script retrasado corre en el acto, antes de los scripts que vienen detrás
 *   (WoodMart: productsLoadMore llamaba a clickOnScrollButton antes de que existiera);
 * - los manejadores de load y DOMContentLoaded que agregan nunca se ejecutan (el menú móvil de
 *   WoodMart se inicia en load).
 * Desde la primera interacción hasta DOMContentLiteSpeedLoaded se retienen jQuery(fn)/.ready(fn) y los
 * load/DOMContentLoaded de window y document, y luego se ejecutan en el orden de una carga normal:
 * DOMContentLoaded, ready, load. Es lo que hace WP Rocket con su retraso de JS.
 *
 * El primer clic en un control que depende de JS (botón, campo, enlace sin destino) también se guarda
 * y se repite al final, salvo que el control ya tenga un manejador de jQuery (con YITH excluido del
 * retraso, «Añadir al presupuesto» esperaba 5–8 s sin necesidad) o sea un enlace sin destino que algún
 * manejador atiende (preventDefault). Al terminar se reenvían resize y scroll, y con itbLsDelay.t > 0 la
 * carga empieza sola tras ese plazo (0.14.7). Vanilla, sin dependencias.
 */
( function ( d, w ) {
	var events = [ 'mouseover', 'click', 'keydown', 'wheel', 'touchmove', 'touchstart', 'pointerup', 'pointerdown' ];
	var opts = { passive: true, capture: true };
	var started = false;
	var held = false;
	var ready = null;
	var readyQueue = [];
	var domQueue = [];
	var loadQueue = [];
	var jqLoad = 0;
	var addW = w.addEventListener;
	var addD = d.addEventListener;
	var clicked = null;
	var candidate = null;

	function pending() {
		return !! d.querySelector( 'script[type="litespeed/javascript"]' );
	}

	function catcher( original, target ) {
		return function ( type, fn, o ) {
			var self = this || target;
			if ( held && fn && ( type === 'DOMContentLoaded' || ( type === 'load' && self === w ) ) ) {
				( type === 'load' ? loadQueue : domQueue ).push( [ self, fn ] );
				return;
			}
			return original.call( self, type, fn, o );
		};
	}

	// Solo cuando LSCache arranca de verdad: su cargador (en el pie) ya está y, si la página aún se está
	// leyendo, desde DOMContentLoaded, como hace él. Chrome envía mouseover al pintar bajo el cursor
	// mientras la página carga: retener ahí dejaba sin ejecutar los DOMContentLoaded de los scripts que
	// no se retrasan (el contador del carrito) hasta una interacción que LSCache no había visto.
	function hold() {
		if ( started || ! pending() || typeof w.litespeed_load_delayed_js_force !== 'function' ) {
			return;
		}
		started = true;
		if ( d.readyState === 'loading' ) {
			addD.call( d, 'DOMContentLoaded', activate );
		} else {
			activate();
		}
	}

	function activate() {
		var $ = w.jQuery;
		var list;
		held = true;
		w.addEventListener = catcher( addW, w );
		d.addEventListener = catcher( addD, d );
		if ( $ && $.fn && $.fn.ready ) {
			ready = $.fn.ready;
			$.fn.ready = function ( fn ) {
				readyQueue.push( [ this, fn ] );
				return this;
			};
			list = $._data && ( $._data( w, 'events' ) || {} ).load;
			jqLoad = list ? list.length : 0;
		}
	}

	function call( item, type ) {
		var fn = item[ 1 ];
		try {
			if ( typeof fn === 'function' ) {
				fn.call( item[ 0 ], new Event( type ) );
			} else if ( fn && typeof fn.handleEvent === 'function' ) {
				fn.handleEvent( new Event( type ) );
			}
		} catch ( e ) {
			setTimeout( rethrow( e ) );
		}
	}

	function rethrow( e ) {
		return function () {
			throw e;
		};
	}

	// Un control que ya tiene manejador de jQuery (directo o delegado con un selector que lo incluye).
	function bound( t ) {
		var $ = w.jQuery;
		var n;
		var list;
		var i;
		var sel;
		var match;
		if ( t.onclick ) {
			return true;
		}
		if ( ! $ || ! $._data ) {
			return false;
		}
		for ( n = t; n && n.nodeType < 10; n = n.parentNode ) {
			list = ( $._data( n, 'events' ) || {} ).click || [];
			for ( i = 0; i < list.length; i++ ) {
				sel = list[ i ].selector;
				if ( ! sel ) {
					if ( n === t ) {
						return true;
					}
					continue;
				}
				try {
					match = t.closest( sel );
					if ( match && n.contains( match ) ) {
						return true;
					}
				} catch ( e ) {}
			}
		}
		return false;
	}

	function onClick( e ) {
		var t;
		var href;
		if ( clicked || ! pending() ) {
			return;
		}
		t = e.target && e.target.closest ? e.target.closest( 'button,input,select,label,[role=button],a' ) : null;
		if ( ! t ) {
			return;
		}
		if ( t.tagName === 'A' ) {
			href = t.getAttribute( 'href' );
			if ( href && href !== '#' && href.indexOf( 'javascript:' ) !== 0 ) {
				return;
			}
		}
		if ( bound( t ) ) {
			return;
		}
		// Un enlace sin destino que algún manejador atiende llama a preventDefault (si no, saltaría al
		// inicio). Pasa normal y se decide al final del recorrido: así responde el menú móvil de
		// WoodMart, que se enlaza sin jQuery. Los demás controles se retienen como antes.
		if ( t.tagName === 'A' ) {
			candidate = [ e, t ];
			return;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		clicked = t;
	}

	function afterClick( e ) {
		var c = candidate;
		candidate = null;
		if ( ! c || c[ 0 ] !== e || e.defaultPrevented || clicked ) {
			return;
		}
		e.preventDefault();
		clicked = c[ 1 ];
	}

	function done() {
		var $ = w.jQuery;
		var i;
		events.forEach( function ( e ) {
			w.removeEventListener( e, hold, opts );
		} );
		d.removeEventListener( 'click', onClick, true );
		w.removeEventListener( 'click', afterClick );
		if ( held ) {
			held = false;
			w.addEventListener = addW;
			d.addEventListener = addD;
			for ( i = 0; i < domQueue.length; i++ ) {
				call( domQueue[ i ], 'DOMContentLoaded' );
			}
			if ( ready ) {
				$.fn.ready = ready;
				for ( i = 0; i < readyQueue.length; i++ ) {
					ready.call( readyQueue[ i ][ 0 ], readyQueue[ i ][ 1 ] );
				}
			}
		}
		// Después de los ready (jQuery los ejecuta en un setTimeout): load y el clic guardado.
		setTimeout( function () {
			var data = $ && $._data ? $._data( w ) || {} : {};
			var list = ( data.events || {} ).load || [];
			for ( i = 0; i < loadQueue.length; i++ ) {
				// El manejador interno de jQuery: sus load se ejecutan abajo, sin repetir los anteriores.
				if ( loadQueue[ i ][ 1 ] !== data.handle ) {
					call( loadQueue[ i ], 'load' );
				}
			}
			for ( i = jqLoad; i < list.length; i++ ) {
				try {
					list[ i ].handler.call( w, $.Event( 'load' ) );
				} catch ( e ) {
					setTimeout( rethrow( e ) );
				}
			}
			// Los sliders, contadores y animaciones que se iniciaron al cargar miden la ventana y esperan un
			// scroll o resize que ya pasó (0.14.7).
			w.dispatchEvent( new Event( 'resize' ) );
			w.dispatchEvent( new Event( 'scroll' ) );
			if ( clicked ) {
				setTimeout( function () {
					var t = clicked;
					clicked = null;
					t.click();
				} );
			}
		} );
	}

	// Plazo opcional (itbLsDelay.t segundos desde load): carga el JS retrasado aunque nadie interactúe,
	// para que los carruseles y la galería no esperen. Cuesta nota de laboratorio si cae dentro de la
	// medición, por eso viene apagado (0.14.7).
	function deadline() {
		var t = ( w.itbLsDelay || {} ).t;
		if ( ! ( t > 0 ) ) {
			return;
		}
		setTimeout( function () {
			if ( pending() && typeof w.litespeed_load_delayed_js_force === 'function' ) {
				hold();
				w.litespeed_load_delayed_js_force();
			}
		}, t * 1000 );
	}

	function armDeadline() {
		if ( d.prerendering ) {
			addD.call( d, 'prerenderingchange', armDeadline, { once: true } );
		} else if ( d.readyState === 'complete' ) {
			deadline();
		} else {
			addW.call( w, 'load', deadline, { once: true } );
		}
	}

	events.forEach( function ( e ) {
		w.addEventListener( e, hold, opts );
	} );
	d.addEventListener( 'click', onClick, true );
	w.addEventListener( 'click', afterClick );
	w.addEventListener( 'DOMContentLiteSpeedLoaded', done );
	armDeadline();
}( document, window ) );
