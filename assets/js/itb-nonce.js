/**
 * Imagina Turbo: refresco de nonces en páginas cacheadas.
 *
 * Si la copia de la página tiene más de 10 h, pide nonces nuevos (una petición, firmada por el
 * servidor) y reemplaza los viejos en atributos del DOM, en objetos globales y en wp.apiFetch.
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

	function refresh() {
		var actions = Object.keys( data.n );
		var query = new URLSearchParams();
		if ( ! actions.length || Date.now() / 1000 - fresh < MAX_AGE ) {
			return;
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
				if ( ! result || ! result.n ) {
					return;
				}
				actions.forEach( function ( action ) {
					if ( result.n[ action ] && result.n[ action ] !== data.n[ action ] ) {
						swap( data.n[ action ], result.n[ action ] );
						data.n[ action ] = result.n[ action ];
					}
				} );
				fresh = Date.now() / 1000;
			} )
			[ 'catch' ]( function () {} );
	}

	refresh();
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			refresh();
		}
	} );
} )();
