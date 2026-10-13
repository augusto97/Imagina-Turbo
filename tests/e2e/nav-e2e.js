// Prueba E2E de navegación instantánea y RUM como visitante anónimo (Chromium).
// Requiere la muestra del RUM al 100 % (PUT /navigation/settings {rum_rate: 1}).
const { chromium } = require( 'playwright' );
const BASE = process.env.ITB_BASE_URL || 'http://127.0.0.1:8080';
const OUT = process.argv[ 2 ] || '.';

( async () => {
	// Playwright desactiva el bfcache por defecto: aquí se quiere medir.
	const browser = await chromium.launch( { executablePath: process.env.ITB_CHROMIUM || undefined, ignoreDefaultArgs: [ '--disable-back-forward-cache' ] } );
	const context = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
	const host = new URL( BASE ).host;
	await context.route( ( url ) => url.host !== host, ( route ) => route.abort( 'blockedbyclient' ) );
	const page = await context.newPage();
	const beacons = [];
	const prefetches = [];
	page.on( 'request', ( r ) => {
		if ( r.url().includes( 'itb-rum=1' ) ) beacons.push( JSON.parse( r.postData() || '{}' ) );
		const purpose = r.headers()[ 'sec-purpose' ] || r.headers()[ 'purpose' ] || '';
		if ( purpose.includes( 'prefetch' ) ) prefetches.push( r.url() );
	} );
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );

	await page.goto( BASE + '/' );
	await page.waitForTimeout( 800 );
	const rules = await page.evaluate( () => [ ...document.querySelectorAll( 'script[type="speculationrules"]' ) ].map( ( s ) => s.textContent ) );
	// Pasar el cursor por un enlace interno (anticipación moderada) y abrirlo.
	const link = page.locator( 'a[href*="/product/"], a[href*="/shop/"], a[href*="/tienda/"]' ).first();
	const href = await link.getAttribute( 'href' );
	await link.hover( { force: true } ).catch( () => {} );
	await page.waitForTimeout( 1200 );
	await link.click( { force: true } );
	await page.waitForURL( href );
	await page.waitForTimeout( 800 );
	const nav = await page.evaluate( () => {
		const n = performance.getEntriesByType( 'navigation' )[ 0 ];
		return { type: n.type, deliveryType: n.deliveryType || '', activationStart: n.activationStart || 0 };
	} );
	// Vuelta atrás (bfcache) y adelante.
	await page.goBack( { waitUntil: 'commit' } );
	await page.waitForTimeout( 800 );
	await page.goForward( { waitUntil: 'commit' } );
	await page.waitForTimeout( 800 );
	await page.reload();
	await page.waitForTimeout( 800 );
	// Ocultar la página fuerza el último beacon.
	await page.goto( 'about:blank' );
	await page.waitForTimeout( 800 );
	// Fachada de YouTube (ITB_VIDEO_URL: una entrada con un video incrustado).
	let facade = null;
	if ( process.env.ITB_VIDEO_URL ) {
		await page.goto( process.env.ITB_VIDEO_URL );
		const before = await page.locator( 'iframe' ).count();
		await page.locator( '.itb-facade' ).first().click();
		const src = await page.locator( '.itb-facade iframe' ).getAttribute( 'src' );
		facade = { iframesBefore: before, iframeAfterClick: src };
	}
	console.log( JSON.stringify( { facade, href, rulesCount: rules.length, rulesHaveExclusions: rules.some( ( r ) => r.includes( '/carrito' ) ), prefetches, nav, beacons, errors }, null, 1 ) );
	await browser.close();
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
