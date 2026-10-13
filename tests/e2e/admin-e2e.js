// Prueba E2E del admin de Imagina Turbo en la instancia local.
const { chromium } = require( 'playwright' );
const OUT = process.argv[ 2 ] || '.';
const BASE = process.env.ITB_BASE_URL || 'http://127.0.0.1:8080';
const USER = process.env.ITB_USER || 'admin';
const PASS = process.env.ITB_PASS || '';
const LOGIN = process.env.ITB_LOGIN_PATH || '/wp-login.php';

( async () => {
	const browser = await chromium.launch( { executablePath: process.env.ITB_CHROMIUM || undefined } );
	const page = await browser.newPage( { viewport: { width: 1400, height: 1000 } } );
	const problems = [];
	page.on( 'console', ( m ) => { if ( m.type() === 'error' || m.type() === 'warning' ) problems.push( `${ m.type() }: ${ m.text() }` ); } );
	page.on( 'requestfailed', ( r ) => problems.push( 'failed: ' + r.url().slice( 0, 90 ) ) );
	page.on( 'pageerror', ( e ) => problems.push( 'pageerror: ' + e.message ) );
	page.on( 'response', ( r ) => { if ( r.url().includes( 'imagina-turbo' ) && r.status() >= 400 ) problems.push( `HTTP ${ r.status() } ${ r.url() }` ); } );

	// Login (ITB_LOGIN_PATH si el sitio usa una URL de acceso personalizada).
	await page.goto( BASE + LOGIN );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

	await page.goto( BASE + '/wp-admin/admin.php?page=imagina-turbo#/panel' );
	await page.waitForSelector( 'text=Alertas', { timeout: 15000 } );
	await page.waitForSelector( 'text=Integraciones aplicadas' );
	await page.screenshot( { path: OUT + '/1-panel.png', fullPage: true } );

	// Diagnóstico: muestreo, ranking por plugin, fases, rutas y auditoría.
	await page.click( 'a[href="#/diagnostico"]' );
	await page.waitForSelector( 'text=Muestreo' );
	await page.waitForSelector( 'text=Costo por plugin', { timeout: 15000 } );
	await page.waitForSelector( 'text=Fases del request' );
	await page.waitForSelector( 'text=Rutas y endpoints más pesados' );
	const plugins = await page.getByRole( 'table' ).first().locator( 'tbody tr' ).count();
	await page.getByRole( 'button', { name: 'Revisar ahora' } ).click();
	await page.waitForSelector( 'text=Revisando…', { state: 'detached', timeout: 30000 } );
	await page.screenshot( { path: OUT + '/2-diagnostico.png', fullPage: true } );
	await page.selectOption( 'select[aria-label="Tipo de request"]', 'FrontendHtml' );
	await page.selectOption( 'select[aria-label="Periodo"]', '7d' );
	await page.waitForSelector( 'text=Fuente: agregados horarios.' );
	await page.screenshot( { path: OUT + '/2b-diagnostico-paginas-7d.png', fullPage: true } );

	await page.click( 'a[href="#/registro"]' );
	await page.waitForSelector( 'table tbody tr' );
	const rows = await page.locator( 'table tbody tr' ).count();
	await page.locator( 'table tbody tr' ).first().click();
	await page.screenshot( { path: OUT + '/2-registro.png', fullPage: true } );

	await page.click( 'a[href="#/avanzado"]' );
	await page.waitForSelector( 'text=Kill switch' );
	// Activar kill switch con confirmación y luego desactivarlo.
	await page.getByRole( 'switch', { name: 'Kill switch' } ).click();
	await page.waitForSelector( 'text=¿Activar el kill switch?' );
	await page.screenshot( { path: OUT + '/3-confirmacion.png' } );
	await page.getByRole( 'button', { name: 'Activar', exact: true } ).click();
	await page.waitForSelector( 'text=Kill switch activo' );
	await page.getByRole( 'switch', { name: 'Kill switch' } ).click();
	await page.getByRole( 'button', { name: 'Desactivar', exact: true } ).click();
	await page.waitForFunction( () => ! document.body.innerText.includes( 'Kill switch activo' ) );

	// Cookie de diagnóstico: emitir y retirar.
	const diagCard = page.locator( 'text=Cookie de diagnóstico >> xpath=ancestor::div[contains(@class,"itb:rounded-lg")]' );
	await diagCard.getByRole( 'button', { name: 'Emitir para mí' } ).click();
	await diagCard.getByRole( 'button', { name: 'Retirar' } ).waitFor();
	const diagCookie = ( await page.context().cookies() ).find( ( c ) => c.name === 'itb_diag' );
	const timing = await page.request.get( BASE + '/' ).then( ( r ) => r.headers()[ 'server-timing' ] || '' );
	await diagCard.getByRole( 'button', { name: 'Retirar' } ).click();
	await diagCard.getByRole( 'button', { name: 'Emitir para mí' } ).waitFor();
	const noTiming = await page.request.get( BASE + '/' ).then( ( r ) => r.headers()[ 'server-timing' ] || '' );
	await page.waitForSelector( 'text=Hub de Imagina' );

	// Recompilar.
	await page.getByRole( 'button', { name: 'Recompilar configuración' } ).click();
	await page.waitForSelector( 'text=Configuración recompilada.' );

	// Validación del formulario de dominios de staging (Zod).
	const settingsForm = page.locator( 'form', { hasText: 'Dominios de staging' } );
	await page.fill( 'textarea', 'no válido!!' );
	await settingsForm.getByRole( 'button', { name: 'Guardar' } ).click();
	await page.waitForSelector( 'text=Cada línea debe ser un dominio' );
	await page.fill( 'textarea', '.staging' + Date.now() + '.mitienda.com\nSTAGING.Otra.com' );
	await settingsForm.getByRole( 'button', { name: 'Guardar' } ).click();
	await page.waitForSelector( 'text=Ajustes guardados.' );
	await page.screenshot( { path: OUT + '/4-avanzado.png', fullPage: true } );

	// Vista móvil.
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.click( 'a[href="#/panel"]' );
	await page.waitForSelector( 'text=Alertas' );
	await page.screenshot( { path: OUT + '/5-movil.png', fullPage: true } );
	await page.click( 'a[href="#/diagnostico"]' );
	await page.waitForSelector( 'text=Costo por plugin' );
	const overflow = await page.evaluate( () => document.documentElement.scrollWidth > window.innerWidth + 1 );
	await page.screenshot( { path: OUT + '/5b-movil-diagnostico.png', fullPage: true } );

	// El admin de WordPress no debe verse alterado fuera de nuestra página.
	await page.setViewportSize( { width: 1400, height: 900 } );
	await page.goto( BASE + '/wp-admin/plugins.php' );
	await page.screenshot( { path: OUT + '/6-plugins.png' } );

	console.log( JSON.stringify( { rows, plugins, diagCookie: diagCookie ? { httpOnly: diagCookie.httpOnly, sameSite: diagCookie.sameSite } : null, serverTiming: timing.slice( 0, 120 ), serverTimingAfterRetire: noTiming, mobileOverflow: overflow, problems }, null, 1 ) );
	await browser.close();
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
