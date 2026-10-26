/**
 * Batería funcional de una página con Playwright (docs/05, verificador).
 *
 * Como un visitante en el móvil: abre la página sin sesión, interactúa (mueve el ratón, desplaza y
 * pulsa Tab) para que se carguen los scripts retrasados por el plugin de caché o por Imagina Turbo,
 * baja hasta el final para despertar lo diferido y vuelve a mirar. Falla con:
 * - estado HTTP 400 o más (salvo que la página espere un 404),
 * - errores de JavaScript sin capturar,
 * - peticiones del propio sitio que fallan (JS, CSS, fuentes, AJAX, imágenes con 5xx),
 * - avisos o errores de PHP en el HTML («Fatal error», «There has been a critical error»…),
 * - un flujo que no se cumple (añadir al carrito).
 * Los errores de consola y los fallos de terceros quedan como aviso: dependen de extensiones,
 * bloqueadores y servicios ajenos.
 */
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';
import { access, mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';

const PHP_MARKERS = [
	/<b>(Fatal error|Parse error|Warning|Notice|Deprecated)<\/b>:/i,
	/There has been a critical error on (this|your) website/i,
	/Ha habido un error crítico en esta web/i,
	/Error establishing a database connection/i,
];

const CACHE_HEADERS = [ 'cf-cache-status', 'x-litespeed-cache', 'x-cache', 'x-rocket-nginx-serving-static', 'cache-control', 'age', 'x-itb-fragment' ];

/** Métricas de la página (se registran antes de que cargue). */
const OBSERVERS = `(() => {
	const m = window.__itbv = { lcp: 0, cls: 0 };
	try {
		new PerformanceObserver((l) => { for (const e of l.getEntries()) m.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
		new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) m.cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
	} catch (e) {}
})();`;

export function phpErrors( html ) {
	return PHP_MARKERS.filter( ( re ) => re.test( html ) ).map( ( re ) => html.match( re )[ 0 ].replace( /<[^>]+>/g, '' ).slice( 0, 120 ) );
}

/** Proporción de píxeles distintos entre dos PNG (1 si cambian de tamaño). */
export function visualDiff( a, b ) {
	const imgA = PNG.sync.read( a );
	const imgB = PNG.sync.read( b );
	if ( imgA.width !== imgB.width || imgA.height !== imgB.height ) {
		return 1;
	}
	const diff = pixelmatch( imgA.data, imgB.data, null, imgA.width, imgA.height, { threshold: 0.2 } );
	return Math.round( ( 10000 * diff ) / ( imgA.width * imgA.height ) ) / 10000;
}

const exists = ( path ) => access( path ).then( () => true, () => false );

/**
 * @param {import('playwright').BrowserContext} context Contexto (móvil, sin sesión).
 * @param {{origin: string, label: string, url: string, flow?: string, expect?: number}} page Página.
 * @param {{baseline?: string, shot?: string, threshold?: number}} files Rutas de la referencia y la captura.
 */
export async function checkPage( context, page, files = {} ) {
	const tab = await context.newPage();
	const origin = new URL( page.origin ).host;
	const result = {
		label: page.label,
		url: page.url,
		status: 0,
		pass: false,
		ttfb: null,
		lcp: null,
		cls: null,
		cache: {},
		failures: [],
		warnings: [],
	};
	const sameOrigin = ( url ) => {
		try {
			return new URL( url ).host === origin;
		} catch {
			return false;
		}
	};
	tab.on( 'pageerror', ( e ) => result.failures.push( `JS: ${ String( e.message ).slice( 0, 200 ) }` ) );
	tab.on( 'console', ( m ) => {
		if ( m.type() === 'error' && result.warnings.length < 30 ) {
			result.warnings.push( `Consola: ${ m.text().slice( 0, 200 ) }` );
		}
	} );
	tab.on( 'requestfailed', ( r ) => {
		const reason = r.failure()?.errorText ?? '';
		// Las peticiones que el propio navegador cancela al navegar o desplazar no son fallos.
		if ( reason.includes( 'ERR_ABORTED' ) ) {
			return;
		}
		( sameOrigin( r.url() ) ? result.failures : result.warnings ).push( `Petición fallida (${ reason }): ${ r.url().slice( 0, 160 ) }` );
	} );
	tab.on( 'response', ( r ) => {
		const type = r.request().resourceType();
		if ( r.status() < 400 || r.request().isNavigationRequest() ) {
			return;
		}
		const own = sameOrigin( r.url() );
		// Un 404 de una imagen propia es un aviso; uno de JS, CSS, fuente o AJAX rompe la página.
		const breaking = own && ( r.status() >= 500 || [ 'script', 'stylesheet', 'font', 'xhr', 'fetch' ].includes( type ) );
		( breaking ? result.failures : result.warnings ).push( `${ r.status() } ${ type }: ${ r.url().slice( 0, 160 ) }` );
	} );
	await tab.addInitScript( OBSERVERS );
	try {
		const response = await tab.goto( page.url, { waitUntil: 'load', timeout: 45000 } );
		result.status = response?.status() ?? 0;
		const headers = response?.headers() ?? {};
		for ( const h of CACHE_HEADERS ) {
			if ( headers[ h ] ) {
				result.cache[ h ] = headers[ h ].slice( 0, 100 );
			}
		}
		const expected = page.expect ?? 200;
		if ( result.status >= 400 && result.status !== expected ) {
			result.failures.push( `Estado HTTP ${ result.status }` );
		}
		result.ttfb = await tab.evaluate( () => {
			const n = performance.getEntriesByType( 'navigation' )[ 0 ];
			return n ? Math.round( n.responseStart ) : null;
		} );
		for ( const msg of phpErrors( await tab.content() ) ) {
			result.failures.push( `PHP: ${ msg }` );
		}
		// Captura de la primera pantalla antes de interactuar (lo que ve el visitante al llegar).
		if ( files.shot ) {
			await tab.waitForTimeout( 1000 );
			const png = await tab.screenshot( { fullPage: false } );
			await mkdir( dirname( files.shot ), { recursive: true } );
			await writeFile( files.shot, png );
			if ( files.baseline ) {
				if ( await exists( files.baseline ) ) {
					result.visual = visualDiff( await readFile( files.baseline ), png );
					if ( result.visual > ( files.threshold ?? 0.15 ) ) {
						result.warnings.push( `Cambio visual: ${ Math.round( result.visual * 100 ) } % de la primera pantalla` );
					}
				} else {
					await mkdir( dirname( files.baseline ), { recursive: true } );
					await writeFile( files.baseline, png );
					result.visual = 0;
					result.baseline_created = true;
				}
			}
		}
		// Interacción: carga los scripts retrasados (LSCache, WP Rocket, Imagina Turbo) y lo diferido.
		await tab.mouse.move( 40, 120 );
		await tab.mouse.wheel( 0, 500 );
		await tab.keyboard.press( 'Tab' );
		await tab.waitForTimeout( 2500 );
		await tab.evaluate( async () => {
			for ( let y = 0; y < document.body.scrollHeight && y < 20000; y += 700 ) {
				window.scrollTo( 0, y );
				await new Promise( ( r ) => setTimeout( r, 120 ) );
			}
		} );
		await tab.waitForTimeout( 1500 );
		const metrics = await tab.evaluate( () => window.__itbv ?? null );
		if ( metrics ) {
			result.lcp = Math.round( metrics.lcp ) || null;
			result.cls = Math.round( metrics.cls * 1000 ) / 1000;
		}
		if ( page.flow === 'add_to_cart' ) {
			result.flow = await addToCart( tab, page.origin );
			if ( ! result.flow.pass ) {
				result.failures.push( `Añadir al carrito: ${ result.flow.reason }` );
			}
		}
	} catch ( e ) {
		result.failures.push( `No cargó: ${ String( e.message ).split( '\n' )[ 0 ].slice( 0, 200 ) }` );
	} finally {
		await tab.close();
	}
	result.pass = result.failures.length === 0;
	return result;
}

/**
 * Añade el producto de la ficha al carrito y lo comprueba por la Store API; después lo quita (no
 * crea pedidos ni deja el carrito lleno). Ruta de la API con rest_route: funciona con cualquier
 * prefijo de REST.
 */
async function addToCart( tab, origin ) {
	const api = ( path ) => `${ origin }/?rest_route=/wc/store/v1/cart${ path }`;
	const count = () => tab.evaluate( async ( url ) => {
		const r = await fetch( url, { credentials: 'same-origin' } );
		const j = r.ok ? await r.json() : null;
		return { items: j ? j.items_count : -1, nonce: r.headers.get( 'Nonce' ) || '', keys: j ? j.items.map( ( i ) => i.key ) : [] };
	}, api( '' ) );
	const before = await count();
	if ( before.items < 0 ) {
		return { pass: false, reason: 'la Store API del carrito no responde' };
	}
	const button = tab.locator( 'form.cart .single_add_to_cart_button, form.cart button[type=submit]' ).first();
	if ( ! ( await button.count() ) ) {
		return { pass: false, reason: 'no hay botón de añadir al carrito' };
	}
	// Producto variable: la primera opción de cada atributo.
	for ( const select of await tab.locator( 'form.variations_form select' ).all() ) {
		const value = await select.evaluate( ( s ) => [ ...s.options ].find( ( o ) => o.value !== '' )?.value ?? '' );
		if ( value ) {
			await select.selectOption( value );
		}
	}
	await tab.waitForTimeout( 500 );
	await Promise.all( [
		tab.waitForResponse( ( r ) => /wc-ajax=add_to_cart|add-to-cart=|wc\/store\/v1\/cart\/add-item|wc\/store\/v1\/batch/.test( r.url() ) || r.request().isNavigationRequest(), { timeout: 15000 } ).catch( () => null ),
		button.click( { timeout: 10000 } ),
	] );
	await tab.waitForLoadState( 'load' ).catch( () => null );
	await tab.waitForTimeout( 1500 );
	const after = await count();
	const pass = after.items > before.items;
	// Limpieza: quita lo añadido.
	for ( const key of after.keys.filter( ( k ) => ! before.keys.includes( k ) ) ) {
		await tab.evaluate( async ( [ url, nonce, k ] ) => {
			await fetch( url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Nonce: nonce }, body: JSON.stringify( { key: k } ) } );
		}, [ api( '/remove-item' ), after.nonce, key ] ).catch( () => null );
	}
	const cleaned = ( await count() ).items === before.items;
	return pass ? { pass: true, items: after.items, cleaned } : { pass: false, reason: `el carrito no cambió (${ before.items } → ${ after.items })`, cleaned };
}
