/**
 * Abre cada pantalla del admin en una carga nueva y falla si alguna lanza un error de JavaScript,
 * da un 5xx o queda vacía. Pensada para correr también en un WordPress limpio (sin plugin de caché ni
 * WooCommerce): ahí apareció el fallo de la 0.10.2 en la pantalla Caché.
 *
 * Uso: ITB_BASE_URL=http://127.0.0.1:8070 ITB_USER=admin ITB_PASS=... [ITB_LOGIN_PATH=/wp-login.php] node tests/e2e/screens-e2e.js
 */
const { chromium } = require( 'playwright' );

const BASE = process.env.ITB_BASE_URL || 'http://127.0.0.1:8080';
const LOGIN = process.env.ITB_LOGIN_PATH || '/wp-login.php';
const ROUTES = [ 'panel', 'diagnostico', 'cache', 'cloudflare', 'woocommerce', 'navegacion', 'carga', 'firewall', 'ajustes', 'assets', 'base-de-datos', 'flota', 'registro', 'avanzado' ];

( async () => {
	const browser = await chromium.launch( process.env.ITB_CHROMIUM ? { executablePath: process.env.ITB_CHROMIUM } : {} );
	const page = await browser.newPage( { viewport: { width: 1400, height: 1000 } } );
	let errors = [];
	page.on( 'pageerror', ( e ) => errors.push( String( e ).slice( 0, 200 ) ) );
	page.on( 'response', ( r ) => {
		if ( r.status() >= 500 && r.url().startsWith( BASE ) ) {
			errors.push( `HTTP ${ r.status() } ${ r.url().replace( BASE, '' ).slice( 0, 100 ) }` );
		}
	} );
	await page.goto( BASE + LOGIN );
	await page.fill( '#user_login', process.env.ITB_USER || 'admin' );
	await page.fill( '#user_pass', process.env.ITB_PASS || '' );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'networkidle' );
	const failed = [];
	for ( const route of ROUTES ) {
		errors = [];
		// El parámetro r fuerza una carga nueva en cada pantalla (el hash solo no recarga).
		await page.goto( `${ BASE }/wp-admin/admin.php?page=imagina-turbo&r=${ route }#/${ route }` );
		await page.waitForTimeout( 3000 );
		const text = ( await page.locator( 'main' ).first().innerText().catch( () => '' ) ).trim();
		const broken = text.includes( 'Esta pantalla tuvo un error' );
		const ok = errors.length === 0 && text.length > 50 && ! broken;
		console.log( `${ ok ? '✓' : '✗' } ${ route.padEnd( 14 ) } ${ ok ? '' : errors.join( ' | ' ) || ( broken ? 'error capturado en la pantalla' : 'pantalla vacía' ) }` );
		if ( ! ok ) {
			failed.push( route );
		}
	}
	await browser.close();
	if ( failed.length > 0 ) {
		console.error( `Pantallas con fallos: ${ failed.join( ', ' ) }` );
		process.exit( 1 );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
