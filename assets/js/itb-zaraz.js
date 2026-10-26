/**
 * Imagina Turbo: eventos de WooCommerce para Zaraz (0.18.0).
 *
 * Zaraz los manda a Google Analytics 4 y a Meta desde Cloudflare (zaraz.ecommerce, sin mapear
 * disparadores). Los de la página (producto visto, carrito, pago iniciado, pedido completado) llegan
 * en window.itbZaraz.events desde PHP; "Product Added" se arma aquí, con el evento added_to_cart de
 * WooCommerce (AJAX) o el envío del formulario de la ficha. Zaraz se carga aparte (Cloudflare lo
 * inyecta): lo que llega antes se guarda y se envía cuando aparece. Un pedido se envía una sola vez
 * por navegador (recargar la página de gracias no lo repite). Vanilla, sin dependencias.
 */
( function ( w, d ) {
	var data = w.itbZaraz || {};
	var queue = [];
	var tries = 0;
	var lastForm = 0;
	var bound = false;
	var timer;

	function ready() {
		return w.zaraz && typeof w.zaraz.ecommerce === 'function';
	}

	function send( name, params ) {
		if ( ! ready() ) {
			queue.push( [ name, params ] );
			return;
		}
		try {
			w.zaraz.ecommerce( name, params );
		} catch ( e ) {}
	}

	function flush() {
		if ( ready() ) {
			clearInterval( timer );
			queue.splice( 0 ).forEach( function ( item ) {
				send( item[ 0 ], item[ 1 ] );
			} );
		} else if ( ++tries > 60 ) {
			clearInterval( timer );
		}
	}

	function once( key ) {
		try {
			if ( w.localStorage.getItem( key ) ) {
				return false;
			}
			w.localStorage.setItem( key, '1' );
		} catch ( e ) {}
		return true;
	}

	( data.events || [] ).forEach( function ( ev ) {
		if ( ev[ 0 ] !== 'Order Completed' || once( 'itb_zo_' + ev[ 1 ].order_id ) ) {
			send( ev[ 0 ], ev[ 1 ] );
		}
	} );

	// Ficha de producto: el formulario (con o sin AJAX).
	d.addEventListener(
		'submit',
		function ( e ) {
			var f = e.target;
			var qty;
			if ( data.product && f && f.matches && f.matches( 'form.cart' ) ) {
				qty = f.querySelector( '[name="quantity"]' );
				lastForm = Date.now();
				send( 'Product Added', Object.assign( {}, data.product, { quantity: ( qty && +qty.value ) || 1 } ) );
			}
		},
		true
	);

	// Botones de los listados (añadir al carrito por AJAX de WooCommerce).
	function onAdded( e, fragments, hash, btn ) {
		var b = btn && btn[ 0 ];
		if ( ! b || Date.now() - lastForm < 3000 ) {
			return;
		}
		send( 'Product Added', {
			product_id: b.getAttribute( 'data-product_id' ) || '',
			sku: b.getAttribute( 'data-product_sku' ) || '',
			quantity: +b.getAttribute( 'data-quantity' ) || 1,
			currency: data.currency,
		} );
	}

	// jQuery puede llegar después (diferido o retrasado por el plugin de caché).
	function bind() {
		if ( ! bound && w.jQuery && d.body ) {
			bound = true;
			w.jQuery( d.body ).on( 'added_to_cart', onAdded );
		}
	}

	bind();
	d.addEventListener( 'DOMContentLoaded', bind );
	w.addEventListener( 'load', bind );
	w.addEventListener( 'DOMContentLiteSpeedLoaded', bind );
	timer = setInterval( flush, 300 );
	flush();
}( window, document ) );
