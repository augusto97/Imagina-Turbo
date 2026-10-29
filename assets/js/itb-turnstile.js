/**
 * Imagina Turbo: Cloudflare Turnstile en los formularios (0.21.0).
 *
 * api.js se descarga solo cuando hace falta: al ver el formulario, al enfocarlo o al enviarlo (o al
 * cargar, en el pago). El widget es invisible salvo que Cloudflare pida interacción. Si el formulario se
 * envía sin token, se detiene, se espera al token y se reenvía con el mismo botón. En el pago clásico
 * se reinicia tras un error (el token vale una sola vez) y en el pago por bloques el token va en los
 * datos de extensión del checkout.
 */
( function () {
	var w = window,
		d = document,
		state = 0,
		queue = [],
		all = d.getElementsByClassName( 'itb-ts' );

	function api( src, cb ) {
		if ( state === 2 ) {
			return cb();
		}
		queue.push( cb );
		if ( state ) {
			return;
		}
		state = 1;
		w.itbTsReady = function () {
			state = 2;
			queue.splice( 0 ).forEach( function ( f ) {
				f();
			} );
		};
		var s = d.createElement( 'script' );
		s.src = src + '?render=explicit&onload=itbTsReady';
		s.async = true;
		d.head.appendChild( s );
	}

	function token( el ) {
		var i = el.querySelector( 'input[name="cf-turnstile-response"]' );
		return i ? i.value : '';
	}

	function block( t ) {
		var s = w.wp && w.wp.data && w.wp.data.dispatch( 'wc/store/checkout' );
		if ( s && s.setExtensionData ) {
			s.setExtensionData( 'imagina-turbo', { turnstile: t } );
		}
	}

	function done( el ) {
		var f = el.itbThen;
		el.itbThen = null;
		if ( f ) {
			f();
		}
	}

	// En el pago por bloques el contenedor queda fuera del formulario: solo se ve si hay que interactuar.
	function show( el, on ) {
		if ( el.hasAttribute( 'data-block' ) ) {
			el.style.cssText = on ? 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:99999;background:#fff;padding:8px;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.25)' : '';
		}
	}

	function render( el, then ) {
		if ( then ) {
			el.itbThen = then;
		}
		if ( el.itbId !== undefined ) {
			return;
		}
		el.itbId = null;
		var isBlock = el.hasAttribute( 'data-block' );
		api( el.getAttribute( 'data-u' ), function () {
			el.itbId = w.turnstile.render( el, {
				sitekey: el.getAttribute( 'data-k' ),
				action: el.getAttribute( 'data-action' ),
				appearance: 'interaction-only',
				size: 'flexible',
				callback: function ( t ) {
					if ( isBlock ) {
						block( t );
					}
					done( el );
				},
				'expired-callback': function () {
					if ( isBlock ) {
						block( '' );
					}
				},
				// Sin token, el servidor responde con su mensaje: el formulario nunca queda bloqueado aquí.
				'error-callback': function () {
					done( el );
				},
				'before-interactive-callback': function () {
					show( el, true );
				},
				'after-interactive-callback': function () {
					show( el, false );
				},
			} );
		} );
	}

	function reset( el ) {
		if ( el.itbId && w.turnstile ) {
			w.turnstile.reset( el.itbId );
			if ( el.hasAttribute( 'data-block' ) ) {
				block( '' );
			}
		}
	}

	d.addEventListener(
		'submit',
		function ( e ) {
			var f = e.target,
				el = f.querySelector && f.querySelector( '.itb-ts' );
			if ( ! el || token( el ) ) {
				return;
			}
			// Antes que los manejadores del formulario (el pago clásico de WooCommerce usa jQuery).
			e.preventDefault();
			e.stopImmediatePropagation();
			var b = e.submitter;
			render( el, function () {
				if ( f.requestSubmit ) {
					f.requestSubmit( b && b.form === f ? b : undefined );
				} else {
					f.submit();
				}
			} );
		},
		true
	);

	d.addEventListener(
		'focusin',
		function ( e ) {
			var f = e.target.form || ( e.target.closest && e.target.closest( 'form' ) ),
				el = f && f.querySelector( '.itb-ts' );
			if ( el ) {
				render( el );
			}
		},
		true
	);

	function scan() {
		var io =
			w.IntersectionObserver &&
			new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( en ) {
					if ( en.isIntersecting ) {
						io.unobserve( en.target );
						render( en.target );
					}
				} );
			} );
		Array.prototype.forEach.call( all, function ( el ) {
			if ( el.hasAttribute( 'data-now' ) ) {
				render( el );
			} else if ( io ) {
				io.observe( el );
			}
		} );
		// Pago clásico: el token se gastó en el intento fallido.
		if ( w.jQuery ) {
			w.jQuery( d.body ).on( 'checkout_error', function () {
				Array.prototype.forEach.call( all, reset );
			} );
		}
		// Pago por bloques: lo mismo cuando el checkout pasa a error.
		if ( w.wp && w.wp.data && w.wp.data.subscribe && d.querySelector( '.itb-ts[data-block]' ) ) {
			var had = false;
			w.wp.data.subscribe( function () {
				var s = w.wp.data.select( 'wc/store/checkout' ),
					err = !! ( s && s.hasError && s.hasError() ),
					first = err && ! had;
				// Antes de reiniciar: el reinicio cambia la tienda y vuelve a llamar aquí.
				had = err;
				if ( first ) {
					Array.prototype.forEach.call( d.querySelectorAll( '.itb-ts[data-block]' ), reset );
				}
			} );
		}
	}

	if ( d.readyState === 'loading' ) {
		d.addEventListener( 'DOMContentLoaded', scan );
	} else {
		scan();
	}
} )();
