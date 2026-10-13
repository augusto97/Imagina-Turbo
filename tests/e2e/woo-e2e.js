// Prueba E2E del carrito por Store API (fase 3) con un visitante anónimo en Chromium.
// Uso: ITB_BASE_URL=http://127.0.0.1:8080 node tests/e2e/woo-e2e.js <carpeta_capturas>
// Requisitos: WooCommerce con un producto simple comprable, pago contra entrega activo y el módulo
// woo_cart encendido (y el coordinador de caché en modo cabeceras para comprobar el edge).
const { chromium } = require( 'playwright' );
const OUT = process.argv[ 2 ] || '.';
const BASE = process.env.ITB_BASE_URL || 'http://127.0.0.1:8080';
const COUNT = process.env.ITB_COUNT_SELECTOR || 'a.cart-contents .count';
const MENU = process.env.ITB_MENU_SELECTOR || '.site-header-cart';
const POSTCODE = process.env.ITB_POSTCODE || '90210';

( async () => {
	const browser = await chromium.launch( { executablePath: process.env.ITB_CHROMIUM || undefined } );
	const context = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
	const page = await context.newPage();
	const problems = [];
	const requests = [];
	page.on( 'pageerror', ( e ) => problems.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => { if ( m.type() === 'error' ) problems.push( 'console: ' + m.text() ); } );
	page.on( 'request', ( r ) => requests.push( r.url() ) );
	const fragmentRequests = () => requests.filter( ( u ) => u.includes( 'get_refreshed_fragments' ) ).length;
	const storeApiRequests = () => requests.filter( ( u ) => u.includes( '/wc/store/v1/cart' ) ).length;

	// 1. Sin carrito: ninguna petición al cargar.
	const r1 = await page.goto( BASE + '/' );
	await page.waitForLoadState( 'networkidle' );
	const emptyCount = ( await page.textContent( COUNT ) || '' ).trim();
	const result = {
		emptyCount,
		emptyEdge: r1.headers()[ 'cloudflare-cdn-cache-control' ] || null,
		emptyRequests: { fragments: fragmentRequests(), store: storeApiRequests() },
		originalFragmentsScript: await page.evaluate( () => [ ...document.scripts ].some( ( s ) => /cart-fragments(\.min)?\.js/.test( s.src ) ) ),
	};

	// 2. Agregar desde la tienda por AJAX: el contador cambia sin recargar.
	await page.goto( BASE + '/tienda/' );
	await page.locator( 'a.add_to_cart_button' ).filter( { visible: true } ).first().click();
	await page.waitForFunction( ( sel ) => /\b1\b/.test( document.querySelector( sel )?.textContent || '' ), COUNT, { timeout: 15000 } );
	result.afterAdd = ( await page.textContent( COUNT ) || '' ).trim();
	result.afterAddTotal = ( await page.textContent( 'a.cart-contents > .woocommerce-Price-amount' ).catch( () => '' ) || '' ).trim();

	// 3. Otra página con carrito: contador correcto poco después de cargar, sin cart-fragments.
	requests.length = 0;
	const r3 = await page.goto( BASE + '/' );
	const t0 = await page.evaluate( () => performance.now() );
	await page.waitForFunction( ( sel ) => /\b1\b/.test( document.querySelector( sel )?.textContent || '' ), COUNT, { timeout: 5000 } );
	result.counterMs = Math.round( ( await page.evaluate( () => performance.now() ) ) - t0 );
	result.counterSinceDomContentLoaded = await page.evaluate( () => {
		const nav = performance.getEntriesByType( 'navigation' )[ 0 ];
		return Math.round( performance.now() - nav.domContentLoadedEventEnd );
	} );
	await page.waitForLoadState( 'networkidle' );
	result.cartEdge = r3.headers()[ 'cloudflare-cdn-cache-control' ] || null;
	result.cartRequests = { fragments: fragmentRequests(), store: storeApiRequests() };
	result.storeApiNonce = await page.evaluate( () => !! JSON.parse( localStorage.getItem( 'storeApiNonce' ) || '{}' ).nonce );

	// 4. El desplegable se pide solo al pasar el cursor.
	await page.hover( MENU );
	await page.waitForFunction( () => document.querySelector( '.widget_shopping_cart_content' )?.textContent.includes( 'Camiseta' ), null, { timeout: 15000 } ).catch( () => {} );
	result.dropdownLoaded = await page.evaluate( () => !! document.querySelector( '.widget_shopping_cart_content' )?.textContent.includes( 'Camiseta' ) );
	result.fragmentsAfterHover = fragmentRequests();
	await page.screenshot( { path: OUT + '/woo-1-dropdown.png' } );

	// 4b. Quitar desde el desplegable: el contador vuelve a 0 sin recargar; luego se vuelve a agregar.
	if ( result.dropdownLoaded ) {
		await page.hover( MENU );
		await page.click( '.widget_shopping_cart_content a.remove_from_cart_button' );
		await page.waitForFunction( ( sel ) => /\b0\b/.test( document.querySelector( sel )?.textContent || '' ), COUNT, { timeout: 15000 } ).catch( () => {} );
		result.afterRemove = ( await page.textContent( COUNT ) || '' ).trim();
	}

	// 4c. Producto con el widget de vistos: sin Set-Cookie; la cookie la escribe el navegador.
	const product = await page.goto( BASE + '/product/camiseta/' );
	result.productSetCookie = ( product.headers()[ 'set-cookie' ] || '' ).includes( 'woocommerce_recently_viewed' );
	result.recentlyViewedCookie = ( await context.cookies() ).find( ( c ) => c.name === 'woocommerce_recently_viewed' )?.value || null;
	await page.click( 'button.single_add_to_cart_button' );
	await page.waitForLoadState( 'networkidle' );

	// 5. Checkout de bloques con pago contra entrega.
	await page.goto( BASE + '/finalizar-compra/' );
	await page.waitForSelector( '.wc-block-checkout', { timeout: 20000 } );
	const fill = async ( id, value ) => {
		const el = page.locator( '#' + id );
		if ( await el.count() ) {
			await el.fill( value );
		}
	};
	await fill( 'email', 'cliente@example.com' );
	await fill( 'billing-first_name', 'Ana' );
	await fill( 'billing-last_name', 'Gómez' );
	await fill( 'shipping-first_name', 'Ana' );
	await fill( 'shipping-last_name', 'Gómez' );
	await fill( 'shipping-address_1', 'Calle 1 # 2-3' );
	await fill( 'billing-address_1', 'Calle 1 # 2-3' );
	await fill( 'shipping-city', 'Manizales' );
	await fill( 'billing-city', 'Manizales' );
	await fill( 'shipping-postcode', POSTCODE );
	await fill( 'billing-postcode', POSTCODE );
	await fill( 'shipping-phone', '3001234567' );
	await fill( 'billing-phone', '3001234567' );
	await page.screenshot( { path: OUT + '/woo-2-checkout.png', fullPage: true } );
	await page.click( '.wc-block-components-checkout-place-order-button' );
	await page.waitForURL( /order-received|pedido-recibido/, { timeout: 30000 } ).catch( () => {} );
	result.orderReceived = /order-received|pedido-recibido/.test( page.url() );
	await page.screenshot( { path: OUT + '/woo-3-pedido.png', fullPage: true } );
	if ( ! result.orderReceived ) {
		result.checkoutErrors = await page.locator( '.wc-block-components-notice-banner, .wc-block-components-validation-error' ).allTextContents();
	}

	result.problems = problems;
	console.log( JSON.stringify( result, null, 1 ) );
	await browser.close();
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
