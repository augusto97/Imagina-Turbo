/**
 * Imagina Turbo · prefetch para navegadores sin Speculation Rules (Safari, Firefox).
 *
 * Al tocar o pulsar un enlace interno (o tras 80 ms con el cursor encima) se agrega un
 * <link rel="prefetch">. Los enlaces a las páginas que el RUM predice como siguientes se precargan
 * cuando entran en pantalla. Respeta Save-Data y conexiones 2G. Nunca precarga enlaces con query,
 * descargas, nofollow, del admin ni de las rutas excluidas (carrito, checkout, cuenta…).
 */
( function () {
	var c = window.itbPf || {};
	var ex = c.x || [];
	var done = {};
	var cn = navigator.connection;
	if ( ( window.HTMLScriptElement && HTMLScriptElement.supports && HTMLScriptElement.supports( 'speculationrules' ) ) || ( cn && ( cn.saveData || /2g/.test( cn.effectiveType || '' ) ) ) ) {
		return;
	}

	function target( a ) {
		var u;
		if ( ! a || ! a.href || a.target === '_blank' || a.hasAttribute( 'download' ) || /(^|\s)nofollow(\s|$)/.test( a.rel || '' ) ) {
			return '';
		}
		try {
			u = new URL( a.href, location.href );
		} catch ( e ) {
			return '';
		}
		var p = u.pathname;
		if ( u.origin !== location.origin || u.search || p === location.pathname || /\/wp-(admin|login|content|includes)|\.php$/.test( p ) || /\.\w{2,5}$/.test( p ) ) {
			return '';
		}
		for ( var i = 0; i < ex.length; i++ ) {
			if ( p.indexOf( ex[ i ] ) === 0 ) {
				return '';
			}
		}
		return u.origin + p;
	}

	function prefetch( href ) {
		if ( ! href || done[ href ] ) {
			return;
		}
		done[ href ] = 1;
		var l = document.createElement( 'link' );
		l.rel = 'prefetch';
		l.href = href;
		document.head.appendChild( l );
	}

	function on( e ) {
		var a = e.target && e.target.closest && e.target.closest( 'a[href]' );
		prefetch( target( a ) );
	}

	var timer;
	addEventListener( 'touchstart', on, { passive: true, capture: true } );
	addEventListener( 'mousedown', on, { capture: true } );
	addEventListener( 'mouseover', function ( e ) {
		clearTimeout( timer );
		timer = setTimeout( function () {
			on( e );
		}, 80 );
	}, { passive: true } );

	if ( c.p && c.p.length && window.IntersectionObserver ) {
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( en ) {
				if ( en.isIntersecting ) {
					prefetch( target( en.target ) );
					io.unobserve( en.target );
				}
			} );
		} );
		document.querySelectorAll( 'a[href]' ).forEach( function ( a ) {
			var h = target( a );
			if ( h && c.p.indexOf( new URL( h ).pathname ) > -1 ) {
				io.observe( a );
			}
		} );
	}
} )();
