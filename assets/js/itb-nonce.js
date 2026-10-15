/**
 * Imagina Turbo: refresco de nonces en páginas cacheadas.
 *
 * Si la copia de la página tiene más de 10 h, pide nonces nuevos (una petición, firmada por el
 * servidor) y reemplaza los viejos en atributos del DOM, en objetos globales y en wp.apiFetch.
 *
 * 0.13.1: los nonces woocommerce* de un invitado con sesión de WooCommerce dependen de su sesión,
 * así que la copia compartida nunca le sirve, tenga la edad que tenga (el login de la cabecera
 * fallaba en silencio). Con la cookie itb_wcs (la pone el servidor junto a la de sesión, que es
 * HttpOnly) se refrescan al cargar; sin ella, justo antes de enviar un formulario que los lleve.
 * Vanilla, sin dependencias.
 */
( function () {
	var MAX_AGE = 36000;
	var node = document.getElementById( 'itb-nonce' );
	var data;
	var fresh;
	if ( ! node || ! window.fetch ) {
		return;
	}
	try {
		data = JSON.parse( node.textContent );
	} catch ( e ) {
		return;
	}
	// Fecha de los nonces actuales; la de la firma (data.t) no cambia.
	fresh = data.t;
	var woo = Object.keys( data.n ).filter( function ( action ) {
		return action.indexOf( 'woocommerce' ) === 0;
	} );
	var wooDone = false;
	var passing = false;

	function replaceIn( value, from, to, depth ) {
		var key;
		if ( depth > 3 || ! value || typeof value !== 'object' ) {
			return;
		}
		for ( key in value ) {
			if ( ! Object.prototype.hasOwnProperty.call( value, key ) ) {
				continue;
			}
			if ( value[ key ] === from ) {
				value[ key ] = to;
			} else if ( value[ key ] && typeof value[ key ] === 'object' && Object.getPrototypeOf( value[ key ] ) === Object.prototype ) {
				replaceIn( value[ key ], from, to, depth + 1 );
			}
		}
	}

	function swap( from, to ) {
		var attrs = document.evaluate( '//@*[contains(., "' + from + '")]', document, null, 7, null );
		var i;
		var attr;
		var names;
		for ( i = 0; i < attrs.snapshotLength; i++ ) {
			attr = attrs.snapshotItem( i );
			attr.value = attr.value.split( from ).join( to );
			if ( attr.name === 'value' && attr.ownerElement && attr.ownerElement.value === from ) {
				attr.ownerElement.value = to;
			}
		}
		names = Object.keys( window );
		for ( i = 0; i < names.length; i++ ) {
			try {
				if ( window[ names[ i ] ] && typeof window[ names[ i ] ] === 'object' && Object.getPrototypeOf( window[ names[ i ] ] ) === Object.prototype ) {
					replaceIn( window[ names[ i ] ], from, to, 0 );
				}
			} catch ( e ) {}
		}
		if ( window.wp && wp.apiFetch && wp.apiFetch.nonceMiddleware && wp.apiFetch.nonceMiddleware.nonce === from ) {
			wp.apiFetch.nonceMiddleware.nonce = to;
		}
	}

	function refresh( force, then ) {
		var actions = Object.keys( data.n );
		var query = new URLSearchParams();
		var done = function () {
			if ( then ) {
				then();
			}
		};
		if ( ! actions.length || ( ! force && Date.now() / 1000 - fresh < MAX_AGE ) ) {
			return done();
		}
		query.set( 't', data.t );
		query.set( 's', data.s );
		actions.forEach( function ( action ) {
			query.append( 'a[]', action );
		} );
		fetch( data.u + ( data.u.indexOf( '?' ) < 0 ? '?' : '&' ) + query.toString(), { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( result ) {
				if ( result && result.n ) {
					actions.forEach( function ( action ) {
						if ( result.n[ action ] && result.n[ action ] !== data.n[ action ] ) {
							swap( data.n[ action ], result.n[ action ] );
							data.n[ action ] = result.n[ action ];
						}
					} );
					fresh = Date.now() / 1000;
					wooDone = true;
				}
				done();
			} )
			[ 'catch' ]( done );
	}

	refresh( woo.length > 0 && /(?:^|;\s*)itb_wcs=/.test( document.cookie ) );
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			refresh();
		}
	} );

	// Sin la marca (sesiones anteriores a 0.13.1): refrescar antes de enviar un formulario con un nonce
	// woocommerce* y reenviarlo con el mismo botón (WooCommerce exige su name, p. ej. «login»).
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var sub = event.submitter;
		var values = woo.map( function ( action ) {
			return data.n[ action ];
		} );
		var found = false;
		var i;
		if ( passing || wooDone || ! woo.length || ! form || ! form.elements ) {
			return;
		}
		for ( i = 0; i < form.elements.length; i++ ) {
			if ( values.indexOf( form.elements[ i ].value ) >= 0 ) {
				found = true;
			}
		}
		if ( ! found ) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		refresh( true, function () {
			var input;
			wooDone = true;
			passing = true;
			try {
				form.requestSubmit( sub && sub.form === form ? sub : undefined );
			} catch ( e ) {
				if ( sub && sub.name ) {
					input = document.createElement( 'input' );
					input.type = 'hidden';
					input.name = sub.name;
					input.value = sub.value;
					form.appendChild( input );
				}
				form.submit();
			}
			passing = false;
		} );
	}, true );
} )();
