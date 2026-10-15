/**
 * Imagina Turbo: contador y total del mini-carrito por Store API, sin cart-fragments.
 *
 * - Sin la cookie woocommerce_items_in_cart (carrito vacío) no hay petición: muestra 0.
 * - Con carrito: pinta lo guardado en localStorage y pide GET /wc/store/v1/cart (no requiere nonce).
 * - Deja a WooCommerce Blocks un nonce fresco de la Store API (storeApiNonce, el más reciente gana):
 *   el de una página cacheada puede ser viejo.
 * - En prerender no pide nada hasta que la página se active.
 * - Con el Mini-Cart de bloques de WooCommerce 10.7+ y sin contadores del tema no hace nada: ese
 *   bloque ya pide el carrito y su nonce en cada vista.
 * - Se actualiza al agregar o quitar productos (eventos de jQuery y de Blocks) y al volver a la pestaña.
 * - El desplegable con la lista de productos (HTML del tema) se pide a WooCommerce solo cuando el
 *   visitante con carrito pasa el cursor o toca el mini-carrito, una vez por página.
 */
( function () {
	var c = window.itbCart;
	// b: el Mini-Cart de bloques (WooCommerce 10.7+) ya pide el carrito y no hay contador del tema.
	if ( ! c || ! window.fetch || c.b ) {
		return;
	}
	var KEY = 'itb_cart_' + c.k;
	var last = 0;
	var menu = 0;

	function hasCart() {
		return ( '; ' + document.cookie ).indexOf( '; ' + c.c + '=' ) > -1;
	}

	function store( d ) {
		try {
			localStorage.setItem( KEY, JSON.stringify( d ) );
		} catch ( e ) {}
	}

	function stored() {
		try {
			return JSON.parse( localStorage.getItem( KEY ) || 'null' );
		} catch ( e ) {
			return null;
		}
	}

	// Subtotal de artículos (lo que muestran los encabezados), con o sin impuestos según la tienda.
	function money( t ) {
		var d = t.currency_minor_unit || 0;
		var cents = parseInt( t.total_items, 10 ) + ( c.x ? parseInt( t.total_items_tax, 10 ) || 0 : 0 );
		var parts = ( cents / Math.pow( 10, d ) ).toFixed( d ).split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, t.currency_thousand_separator );
		return t.currency_prefix + parts.join( t.currency_decimal_separator ) + t.currency_suffix;
	}

	function each( list, fn ) {
		for ( var i = 0; i < list.length; i++ ) {
			var els = document.querySelectorAll( list[ i ] );
			for ( var j = 0; j < els.length; j++ ) {
				fn( els[ j ] );
			}
		}
	}

	function paint( d ) {
		each( c.n, function ( el ) {
			[ 'data-cart-total', 'data-count', 'data-counter' ].forEach( function ( a ) {
				if ( el.hasAttribute( a ) ) {
					el.setAttribute( a, d.n );
				}
			} );
			var text = el.textContent;
			if ( /\d/.test( text ) ) {
				// "3 items": con las formas del tema (singular/plural) si las hay; si no, solo el número.
				el.textContent = c.f && /\D/.test( text.trim() ) ? c.f[ d.n === 1 ? 0 : 1 ].replace( '%d', d.n ) : text.replace( /\d+/, d.n );
			}
		} );
		each( c.t, function ( el ) {
			el.textContent = d.t;
		} );
		var $ = window.jQuery;
		if ( $ ) {
			$( document.body ).trigger( 'wc_fragments_refreshed' );
		}
	}

	function freshNonce( r ) {
		var n = r.headers.get( 'Nonce' );
		var ts = parseInt( r.headers.get( 'Nonce-Timestamp' ) || '0', 10 );
		if ( ! n ) {
			return;
		}
		try {
			var cur = JSON.parse( localStorage.getItem( 'storeApiNonce' ) || '{}' ) || {};
			if ( ! cur.timestamp || ts >= cur.timestamp ) {
				localStorage.setItem( 'storeApiNonce', JSON.stringify( { nonce: n, timestamp: ts } ) );
			}
		} catch ( e ) {}
		var api = window.wp && window.wp.apiFetch;
		if ( api && api.setNonce ) {
			api.setNonce( r.headers );
		}
	}

	function dropdown( e ) {
		var t = e.target;
		if ( menu || ! c.w || ! hasCart() || ! t.closest || ! c.m.some( function ( s ) {
			return t.closest( s );
		} ) ) {
			return;
		}
		menu = 1;
		fetch( c.w, { method: 'POST', credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				var f = ( j && j.fragments ) || {};
				Object.keys( f ).forEach( function ( s ) {
					var els = document.querySelectorAll( s );
					for ( var i = 0; i < els.length; i++ ) {
						els[ i ].outerHTML = f[ s ];
					}
				} );
				var $ = window.jQuery;
				if ( $ ) {
					$( document.body ).trigger( 'wc_fragments_loaded' );
				}
			} )
			.catch( function () {
				menu = 0;
			} );
	}

	function load() {
		last = Date.now();
		menu = 0;
		fetch( c.api, { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
			.then( function ( r ) {
				freshNonce( r );
				return r.ok ? r.json() : null;
			} )
			.then( function ( j ) {
				if ( j && j.totals ) {
					var d = { n: j.items_count || 0, t: money( j.totals ) };
					store( d );
					paint( d );
				}
			} )
			.catch( function () {} );
	}

	function run() {
		if ( ! hasCart() ) {
			var empty = { n: 0, t: c.z };
			store( empty );
			paint( empty );
			return;
		}
		var d = stored();
		if ( d ) {
			paint( d );
		}
		load();
	}

	function start() {
		run();
		var $ = window.jQuery;
		if ( $ ) {
			$( document.body ).on( 'added_to_cart removed_from_cart wc_cart_emptied updated_cart_totals', load );
		}
		[ 'mouseover', 'focusin', 'touchstart' ].forEach( function ( ev ) {
			document.addEventListener( ev, dropdown, { passive: true } );
		} );
		document.body.addEventListener( 'wc-blocks_added_to_cart', load );
		document.body.addEventListener( 'wc-blocks_removed_from_cart', load );
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'visible' && hasCart() && Date.now() - last > 10000 ) {
				load();
			}
		} );
		window.addEventListener( 'pageshow', function ( e ) {
			if ( e.persisted ) {
				run();
			}
		} );
	}

	if ( document.prerendering ) {
		document.addEventListener( 'prerenderingchange', start, { once: true } );
	} else if ( document.readyState === 'loading' ) {
		// Sin el nombre literal del evento: LiteSpeed Cache en modo "Delayed" lo reescribe en el JS que
		// minifica y el contador esperaría a la primera interacción.
		document.addEventListener( [ 'DOMContent', 'Loaded' ].join( '' ), start );
	} else {
		start();
	}
} )();
