// Prueba E2E del admin de Imagina Turbo en la instancia local.
const { chromium, request: pwRequest } = require( 'playwright' );
const OUT = process.argv[ 2 ] || '.';
const BASE = process.env.ITB_BASE_URL || 'http://127.0.0.1:8080';
const USER = process.env.ITB_USER || 'admin';
const PASS = process.env.ITB_PASS || '';
const LOGIN = process.env.ITB_LOGIN_PATH || '/wp-login.php';

( async () => {
	const browser = await chromium.launch( { executablePath: process.env.ITB_CHROMIUM || undefined } );
	const page = await browser.newPage( { viewport: { width: 1400, height: 1000 } } );
	const problems = [];
	// Los 4xx/5xx de la API se registran (o se esperan) en el listener de respuestas.
	page.on( 'console', ( m ) => { if ( ( m.type() === 'error' || m.type() === 'warning' ) && ! m.text().startsWith( 'Failed to load resource: the server responded' ) && ! m.text().includes( 'ERR_BLOCKED_BY_CLIENT' ) ) problems.push( `${ m.type() }: ${ m.text() }` ); } );
	// Errores que la prueba provoca a propósito (validación, confirmación requerida).
	const expected = [];
	// ERR_ABORTED: peticiones en curso que el navegador cancela al cambiar de página (no son fallos).
	page.on( 'requestfailed', ( r ) => { if ( ! /^net::ERR_(ABORTED|BLOCKED_BY_CLIENT)/.test( r.failure()?.errorText || '' ) ) problems.push( 'failed: ' + r.url().slice( 0, 90 ) + ' ' + ( r.failure()?.errorText || '' ) ); } );
	page.on( 'pageerror', ( e ) => problems.push( 'pageerror: ' + e.message ) );
	page.on( 'response', ( r ) => {
		if ( ! r.url().includes( 'imagina-turbo' ) || r.status() < 400 ) return;
		const i = expected.findIndex( ( e ) => r.url().includes( e.path ) && r.status() === e.status );
		if ( i >= 0 ) expected.splice( i, 1 );
		else problems.push( `HTTP ${ r.status() } ${ r.url() }` );
	} );

	// Sin red externa (gravatar, fuentes): en entornos aislados esas peticiones cuelgan el evento load.
	const host = new URL( BASE ).host;
	await page.route( ( url ) => url.host !== host, ( route ) => route.abort( 'blockedbyclient' ) );

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

	// Caché: se parte del módulo apagado para recorrer la activación con confirmación.
	const rest = ( path, method, body ) => page.evaluate( async ( [ p, m, b ] ) => {
		const r = await fetch( window.itbAdmin.restUrl + p, { method: m, headers: { 'X-WP-Nonce': window.itbAdmin.nonce, 'Content-Type': 'application/json' }, body: b ? JSON.stringify( b ) : undefined } );
		await r.text();
		return r.status;
	}, [ path, method, body ] );
	const disabled = await rest( 'modules/cache_coordinator', 'PUT', { enabled: false } );
	await page.click( 'a[href="#/cache"]' );
	await page.waitForSelector( 'text=Caché de origen' );
	await page.getByRole( 'button', { name: 'Activar', exact: true } ).click();
	await page.waitForSelector( 'text=¿Activar el coordinador de caché?' );
	await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Activar', exact: true } ).click();
	await page.waitForSelector( 'text=El coordinador de caché está apagado', { state: 'detached' } );
	// Volver a «solo sincronizar» y luego emitir cabeceras: exige confirmación.
	const settingsCard = page.locator( 'text=Modo >> xpath=ancestor::div[contains(@class,"itb:rounded-lg")]' );
	if ( await page.getByLabel( 'Solo sincronizar purgas' ).isChecked() === false ) {
		await page.getByLabel( 'Solo sincronizar purgas' ).check();
		await settingsCard.getByRole( 'button', { name: 'Guardar' } ).click();
		await page.waitForFunction( () => [ ...document.querySelectorAll( 'button' ) ].some( ( b ) => b.textContent === 'Guardar' && b.disabled ) );
	}
	// Cabeceras vistas por un visitante anónimo (contexto sin cookies).
	const anon = await pwRequest.newContext();
	const edgeHeader = async ( path ) => ( await anon.get( BASE + path ) ).headers()[ 'cloudflare-cdn-cache-control' ] || null;
	const missBefore = await edgeHeader( '/?itb_e2e=' + Date.now() );
	await page.getByLabel( 'Emitir cabeceras para el edge' ).check();
	await settingsCard.getByRole( 'button', { name: 'Guardar' } ).click();
	await page.waitForSelector( 'text=¿Emitir cabeceras para el edge?' );
	await page.screenshot( { path: OUT + '/7-cache-confirmacion.png' } );
	await page.getByRole( 'button', { name: 'Emitir cabeceras', exact: true } ).click();
	await page.waitForSelector( 'text=¿Emitir cabeceras para el edge?', { state: 'detached' } );
	await page.waitForFunction( () => [ ...document.querySelectorAll( 'button' ) ].some( ( b ) => b.textContent === 'Guardar' && b.disabled ) );
	const edgeAfter = await edgeHeader( '/?itb_e2e=' + Date.now() );
	const edgeCart = await edgeHeader( '/carrito/' );

	// Probar una URL: la portada se puede guardar, el carrito no.
	const inspectCard = page.locator( 'text=Probar una URL >> xpath=ancestor::div[contains(@class,"itb:rounded-lg")]' );
	await inspectCard.getByLabel( 'URL' ).fill( BASE + '/?itb_e2e_home=' + Date.now() );
	await inspectCard.getByRole( 'button', { name: 'Probar' } ).click();
	await inspectCard.locator( 'text=El edge la puede guardar' ).waitFor( { timeout: 20000 } );
	await inspectCard.getByLabel( 'URL' ).fill( BASE + '/carrito/' );
	await inspectCard.getByRole( 'button', { name: 'Probar' } ).click();
	await inspectCard.locator( 'text=El edge no la guarda' ).waitFor( { timeout: 20000 } );
	expected.push( { path: 'cache/inspect', status: 400 } );
	const inspectOutside = await rest( 'cache/inspect', 'POST', { url: 'https://example.com/' } );

	// Purga manual por tag, tag inválido y purga completa con confirmación.
	const purgeCard = page.locator( 'text=Purga manual >> xpath=ancestor::div[contains(@class,"itb:rounded-lg")]' );
	await purgeCard.getByLabel( 'Tipo de purga' ).selectOption( 'tag' );
	await purgeCard.getByLabel( 'Qué purgar' ).fill( 'no válido' );
	expected.push( { path: 'cache/purge', status: 400 } );
	await purgeCard.getByRole( 'button', { name: 'Purgar' } ).click();
	await purgeCard.locator( 'text=Tag no válido' ).waitFor();
	await purgeCard.getByLabel( 'Qué purgar' ).fill( 'home' );
	await purgeCard.getByRole( 'button', { name: 'Purgar' } ).click();
	await purgeCard.locator( 'text=Purgado.' ).waitFor( { timeout: 30000 } );
	await purgeCard.getByLabel( 'Tipo de purga' ).selectOption( 'all' );
	await purgeCard.getByRole( 'button', { name: 'Purgar' } ).click();
	await page.waitForSelector( 'text=¿Purgar toda la caché?' );
	await page.getByRole( 'button', { name: 'Purgar todo', exact: true } ).click();
	await page.waitForSelector( 'text=¿Purgar toda la caché?', { state: 'detached', timeout: 30000 } );
	expected.push( { path: 'cache/purge', status: 428 } );
	const purgeAllUnconfirmed = await rest( 'cache/purge', 'POST', { type: 'all' } );
	await page.screenshot( { path: OUT + '/7-cache.png', fullPage: true } );
	const cache = { disabled, missBefore, edgeAfter, edgeCart, inspectOutside, purgeAllUnconfirmed };
	await anon.dispose();

	// Cloudflare contra el simulador (ITB_CF_MOCK=http://127.0.0.1:8098 y ITB_CF_API_BASE en wp-config.php).
	let cloudflare = null;
	const MOCK = process.env.ITB_CF_MOCK || '';
	if ( MOCK ) {
		const TOKEN = 'cfut_PRUEBA0123456789abcdefghijklmnopqrstuvwxyz';
		const mock = await pwRequest.newContext();
		await mock.post( MOCK + '/__mock/reset', { data: { token: TOKEN, zone_name: new URL( BASE ).hostname, plan: 'free' } } );
		const before = ( await ( await mock.get( MOCK + '/__mock/state' ) ).json() ).result;
		await rest( 'cloudflare/connect', 'DELETE' );
		await rest( 'modules/cloudflare', 'PUT', { enabled: true, confirm: true } );
		await page.click( 'a[href="#/cloudflare"]' );
		await page.waitForSelector( 'text=Conexión' );
		await page.getByLabel( 'API Token' ).fill( 'cfut_tokenfalso0123456789abcdefghijklmnop' );
		await page.getByRole( 'button', { name: 'Verificar y guardar' } ).click();
		expected.push( { path: 'cloudflare/connect', status: 400 } );
		await page.waitForSelector( 'text=Cloudflare rechazó el token' );
		await page.getByLabel( 'API Token' ).fill( TOKEN );
		await page.getByRole( 'button', { name: 'Verificar y guardar' } ).click();
		await page.waitForSelector( 'text=Cambios propuestos', { timeout: 20000 } );
		await page.waitForSelector( 'text=SSL/TLS está en Flexible' );
		await page.waitForSelector( 'text=Caché de assets estáticos' );
		// Por defecto solo vienen marcados los cambios seguros.
		const preselected = await page.locator( 'li input[type="checkbox"]:checked' ).count();
		await page.getByRole( 'checkbox', { name: 'Caché del HTML anónimo' } ).check();
		await page.getByRole( 'button', { name: /Aplicar seleccionados/ } ).click();
		await page.waitForSelector( 'text=¿Aplicar los cambios en Cloudflare?' );
		await page.screenshot( { path: OUT + '/8-cloudflare-confirmacion.png' } );
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Aplicar', exact: true } ).click();
		await page.waitForSelector( 'text=Cambios aplicados. Snapshot previo', { timeout: 30000 } );
		await page.waitForSelector( 'text=La zona ya está como se recomienda.', { state: 'detached', timeout: 1000 } ).catch( () => {} );
		const applied = ( await ( await mock.get( MOCK + '/__mock/state' ) ).json() ).result;
		await page.waitForSelector( 'text=88 %', { timeout: 20000 } );
		await page.screenshot( { path: OUT + '/8-cloudflare.png', fullPage: true } );
		// Restaurar el snapshot del cambio: la zona vuelve a estar como al principio.
		const snapshots = page.locator( 'text=Snapshots >> xpath=ancestor::div[contains(@class,"itb:rounded-lg")]' );
		// El más reciente (la lista va de nuevo a viejo): el del cambio recién aplicado.
		await snapshots.getByRole( 'button', { name: 'Restaurar' } ).first().click();
		await page.waitForSelector( 'text=¿Restaurar este snapshot?' );
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Restaurar', exact: true } ).click();
		await page.waitForSelector( 'text=Snapshot restaurado.', { timeout: 30000 } );
		const restored = ( await ( await mock.get( MOCK + '/__mock/state' ) ).json() ).result;
		const norm = ( st ) => JSON.stringify( { settings: st.settings, rules: Object.fromEntries( Object.entries( st.rulesets ).map( ( [ k, v ] ) => [ k, v.rules.map( ( { id, version, last_updated, ...r } ) => r ) ] ).filter( ( [ , v ] ) => v.length ) ) } );
		await page.setViewportSize( { width: 390, height: 844 } );
		const overflowCf = await page.evaluate( () => document.documentElement.scrollWidth > window.innerWidth + 1 );
		await page.screenshot( { path: OUT + '/8b-movil-cloudflare.png', fullPage: true } );
		await page.setViewportSize( { width: 1400, height: 1000 } );
		cloudflare = {
			preselected,
			ownRules: ( applied.rulesets.http_request_cache_settings?.rules || [] ).map( ( r ) => r.ref ),
			settingsApplied: applied.settings,
			restoredEqualsBefore: norm( restored ) === norm( before ),
			...( norm( restored ) === norm( before ) ? {} : { before: norm( before ), restored: norm( restored ) } ),
			mobileOverflow: overflowCf,
		};
		await rest( 'modules/cloudflare', 'PUT', { enabled: false } );
		await mock.dispose();
	}

	// WooCommerce: estado y prueba de la portada sin y con carrito.
	await page.click( 'a[href="#/woocommerce"]' );
	await page.waitForSelector( 'text=Mini-carrito del tema' );
	await page.getByRole( 'button', { name: 'Probar la portada' } ).click();
	await page.getByRole( 'button', { name: 'Probar la portada' } ).waitFor( { timeout: 30000 } );
	const wooCart = page.locator( 'p', { hasText: /^Con carrito$/ } ).locator( 'xpath=..' );
	await wooCart.waitFor( { timeout: 30000 } );
	const woo = {
		cartNotStored: await wooCart.locator( 'text=El edge no la guarda' ).count() > 0,
		noFragments: await wooCart.locator( 'text=Sin cart-fragments' ).count() > 0,
	};
	await page.screenshot( { path: OUT + '/9-woocommerce.png', fullPage: true } );

	// Ajustes rápidos: escudo (prueba como visitante) y un ajuste moderado que pide confirmación.
	await page.click( 'a[href="#/ajustes"]' );
	await page.waitForSelector( 'text=Escudo del servidor' );
	await page.getByRole( 'button', { name: 'Probar como visitante' } ).click();
	await page.waitForSelector( 'text=Escudo: static_miss', { timeout: 30000 } );
	const shieldHits = await page.locator( 'text=/^Escudo: /' ).count();
	await page.getByRole( 'switch', { name: 'Desactivar feeds RSS' } ).click();
	await page.waitForSelector( 'text=¿Desactivar feeds RSS?' );
	await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Cancelar' } ).click();
	const feedsStillOff = ( await page.getByRole( 'switch', { name: 'Desactivar feeds RSS' } ).getAttribute( 'aria-checked' ) ) === 'false';
	await page.screenshot( { path: OUT + '/10-ajustes.png', fullPage: true } );

	// Firewall: reglas propuestas para la zona (con el simulador) y .htaccess.
	await page.click( 'a[href="#/firewall"]' );
	await page.waitForSelector( 'text=Origen cerrado' );
	let firewall = { htaccessCard: await page.getByText( 'Reglas en el servidor (.htaccess)' ).waitFor( { timeout: 10000 } ).then( () => true, () => false ) };
	if ( MOCK ) {
		const mock = await pwRequest.newContext();
		await mock.post( MOCK + '/__mock/reset', { data: { token: 'cfut_PRUEBA0123456789abcdefghijklmnopqrstuvwxyz', zone_name: new URL( BASE ).hostname, plan: 'free' } } );
		await rest( 'modules/cloudflare', 'PUT', { enabled: true, confirm: true } );
		await rest( 'modules/firewall', 'PUT', { enabled: true, confirm: true } );
		await page.getByRole( 'button', { name: 'Actualizar' } ).click();
		await page.waitForSelector( 'text=Firewall: ataques en la URL >> nth=0', { timeout: 30000 } );
		await page.getByRole( 'checkbox', { name: 'Firewall: ataques en la URL' } ).check();
		await page.getByRole( 'button', { name: /Aplicar seleccionados/ } ).click();
		await page.waitForSelector( 'text=¿Aplicar las reglas del firewall?' );
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Aplicar', exact: true } ).click();
		await page.waitForSelector( 'text=Cambios aplicados. Snapshot previo', { timeout: 30000 } );
		const state = ( await ( await mock.get( MOCK + '/__mock/state' ) ).json() ).result;
		await page.getByRole( 'button', { name: 'Consultar' } ).click();
		await page.locator( 'code', { hasText: /^\/xmlrpc\.php$/ } ).waitFor( { timeout: 20000 } );
		await page.screenshot( { path: OUT + '/11-firewall.png', fullPage: true } );
		await page.setViewportSize( { width: 390, height: 844 } );
		firewall = {
			...firewall,
			waf: ( state.rulesets.http_request_firewall_custom?.rules || [] ).map( ( r ) => `${ r.ref }:${ r.action }` ),
			mobileOverflow: await page.evaluate( () => document.documentElement.scrollWidth > window.innerWidth + 1 ),
		};
		await page.screenshot( { path: OUT + '/11b-movil-firewall.png', fullPage: true } );
		await page.setViewportSize( { width: 1400, height: 1000 } );
		await rest( 'modules/firewall', 'PUT', { enabled: false } );
		await rest( 'modules/cloudflare', 'PUT', { enabled: false } );
		await mock.dispose();
	}

	// Vista móvil.
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.click( 'a[href="#/panel"]' );
	await page.waitForSelector( 'text=Alertas' );
	await page.screenshot( { path: OUT + '/5-movil.png', fullPage: true } );
	await page.click( 'a[href="#/diagnostico"]' );
	await page.waitForSelector( 'text=Costo por plugin' );
	const overflow = await page.evaluate( () => document.documentElement.scrollWidth > window.innerWidth + 1 );
	await page.screenshot( { path: OUT + '/5b-movil-diagnostico.png', fullPage: true } );
	await page.click( 'a[href="#/cache"]' );
	await page.waitForSelector( 'text=Caché de origen' );
	const overflowCache = await page.evaluate( () => document.documentElement.scrollWidth > window.innerWidth + 1 );
	await page.screenshot( { path: OUT + '/5c-movil-cache.png', fullPage: true } );

	// El admin de WordPress no debe verse alterado fuera de nuestra página.
	await page.setViewportSize( { width: 1400, height: 900 } );
	await page.goto( BASE + '/wp-admin/plugins.php' );
	await page.screenshot( { path: OUT + '/6-plugins.png' } );

	console.log( JSON.stringify( { rows, plugins, diagCookie: diagCookie ? { httpOnly: diagCookie.httpOnly, sameSite: diagCookie.sameSite } : null, serverTiming: timing.slice( 0, 120 ), serverTimingAfterRetire: noTiming, mobileOverflow: overflow, mobileOverflowCache: overflowCache, cache, cloudflare, woo, shield: { hits: shieldHits, moderateNeedsConfirm: feedsStillOff }, firewall, problems }, null, 1 ) );
	await browser.close();
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
