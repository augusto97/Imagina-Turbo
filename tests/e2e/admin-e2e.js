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

	// Recompilar.
	await page.getByRole( 'button', { name: 'Recompilar configuración' } ).click();
	await page.waitForSelector( 'text=Configuración recompilada.' );

	// Validación del formulario de dominios de staging (Zod).
	await page.fill( 'textarea', 'no válido!!' );
	await page.getByRole( 'button', { name: 'Guardar' } ).click();
	await page.waitForSelector( 'text=Cada línea debe ser un dominio' );
	await page.fill( 'textarea', '.staging' + Date.now() + '.mitienda.com\nSTAGING.Otra.com' );
	await page.getByRole( 'button', { name: 'Guardar' } ).click();
	await page.waitForSelector( 'text=Ajustes guardados.' );
	await page.screenshot( { path: OUT + '/4-avanzado.png', fullPage: true } );

	// Vista móvil.
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.click( 'a[href="#/panel"]' );
	await page.waitForSelector( 'text=Alertas' );
	await page.screenshot( { path: OUT + '/5-movil.png', fullPage: true } );

	// El admin de WordPress no debe verse alterado fuera de nuestra página.
	await page.setViewportSize( { width: 1400, height: 900 } );
	await page.goto( BASE + '/wp-admin/plugins.php' );
	await page.screenshot( { path: OUT + '/6-plugins.png' } );

	console.log( JSON.stringify( { rows, problems }, null, 1 ) );
	await browser.close();
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
