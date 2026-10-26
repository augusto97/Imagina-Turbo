/**
 * Verificador de Imagina Turbo (docs/05): servicio aparte para un VPS (Docker).
 *
 * Rutas (todas firmadas con la clave del sitio salvo /v1/health):
 *   GET  /v1/health           Estado y versión.
 *   POST /v1/runs             {pages?: [{label, url, flow?, expect?}], lighthouse?, visual?, threshold?,
 *                             cookie?: {name, value}} → {id, status}. Sin páginas usa las de sites.json.
 *   GET  /v1/runs?limit=10    Últimas corridas del sitio (manuales y nocturnas).
 *   GET  /v1/runs/{id}        Una corrida con sus resultados.
 *   POST /v1/baseline         {run_id} → toma las capturas de esa corrida como nueva referencia visual.
 *
 * Variables: PORT (8787), VERIFIER_DATA (./data), VERIFIER_SITES ($VERIFIER_DATA/sites.json),
 * NIGHTLY_HOUR (3, hora local de TZ; vacío = sin nocturna).
 */
import { createServer } from 'node:http';
import { copyFile, mkdir } from 'node:fs/promises';
import { join } from 'node:path';
import { NonceCache, verify } from './auth.js';
import { Runner, VERSION, resolvePages, slug } from './runner.js';
import { Store } from './store.js';

const PORT = Number( process.env.PORT ?? 8787 );
const DATA = process.env.VERIFIER_DATA ?? join( process.cwd(), 'data' );
const SITES = process.env.VERIFIER_SITES ?? join( DATA, 'sites.json' );
const NIGHTLY_HOUR = process.env.NIGHTLY_HOUR === '' ? null : Number( process.env.NIGHTLY_HOUR ?? 3 );
const MAX_BODY = 64 * 1024;

const store = new Store( DATA, SITES );
const runner = new Runner( store );
const nonces = new NonceCache();

const send = ( res, status, data ) => {
	res.writeHead( status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' } );
	res.end( JSON.stringify( data ) );
};

const readBody = ( req ) => new Promise( ( resolve, reject ) => {
	let size = 0;
	const chunks = [];
	req.on( 'data', ( c ) => {
		size += c.length;
		if ( size > MAX_BODY ) {
			reject( new Error( 'too_large' ) );
			req.destroy();
			return;
		}
		chunks.push( c );
	} );
	req.on( 'end', () => resolve( Buffer.concat( chunks ) ) );
	req.on( 'error', reject );
} );

/** Resumen de una corrida para listados. */
const brief = ( r ) => ( {
	id: r.id,
	trigger: r.trigger,
	status: r.status,
	created_at: r.created_at,
	finished_at: r.finished_at ?? null,
	pass: r.pass,
	alerts: r.alerts ?? [],
	pages: ( r.results ?? [] ).map( ( p ) => ( { label: p.label, pass: p.pass, status: p.status, lcp: p.lcp, cls: p.cls, score: p.lighthouse?.score ?? null, failures: p.failures.length, warnings: p.warnings.length } ) ),
} );

async function handle( req, res ) {
	const url = new URL( req.url, 'http://localhost' );
	if ( req.method === 'GET' && url.pathname === '/v1/health' ) {
		return send( res, 200, { ok: true, version: VERSION, queue: runner.pending, sites: Object.keys( store.sites ).length } );
	}
	if ( ! url.pathname.startsWith( '/v1/' ) ) {
		return send( res, 404, { error: 'not_found' } );
	}
	let body;
	try {
		body = await readBody( req );
	} catch {
		return send( res, 413, { error: 'too_large' } );
	}
	// La firma cubre la ruta con su query (como la envía el plugin).
	const auth = verify( { method: req.method, path: url.pathname + url.search, headers: req.headers, body, findSite: ( id ) => store.site( id ), nonces } );
	if ( auth.error ) {
		return send( res, 401, { error: auth.error } );
	}
	const site = store.site( auth.site );
	let input = {};
	if ( body.length ) {
		try {
			input = JSON.parse( body.toString( 'utf8' ) );
		} catch {
			return send( res, 400, { error: 'bad_json' } );
		}
	}

	if ( req.method === 'POST' && url.pathname === '/v1/runs' ) {
		const { pages, error } = resolvePages( site.url, Array.isArray( input.pages ) && input.pages.length ? input.pages : site.pages );
		if ( error ) {
			return send( res, 400, { error: 'bad_pages', message: error } );
		}
		const options = { lighthouse: input.lighthouse === true, visual: input.visual !== false };
		if ( typeof input.threshold === 'number' && input.threshold > 0 && input.threshold <= 1 ) {
			options.threshold = input.threshold;
		}
		// Solo cookies del propio plugin (experimentos firmados por el sitio): nunca una sesión.
		if ( input.cookie && /^itb_[a-z_]{1,30}$/.test( String( input.cookie.name ) ) && typeof input.cookie.value === 'string' && input.cookie.value.length <= 500 ) {
			options.cookie = { name: input.cookie.name, value: input.cookie.value };
		}
		if ( runner.pending >= 20 ) {
			return send( res, 429, { error: 'busy' } );
		}
		const run = await runner.enqueue( auth.site, pages, options );
		return send( res, 202, { id: run.id, status: run.status } );
	}
	if ( req.method === 'GET' && url.pathname === '/v1/runs' ) {
		const limit = Math.max( 1, Math.min( 30, Number( url.searchParams.get( 'limit' ) ) || 10 ) );
		return send( res, 200, { runs: ( await store.recent( auth.site, limit ) ).map( brief ) } );
	}
	const match = url.pathname.match( /^\/v1\/runs\/(\d{8}-[a-f0-9]{10})$/ );
	if ( req.method === 'GET' && match ) {
		const run = await store.get( auth.site, match[ 1 ] );
		return run ? send( res, 200, run ) : send( res, 404, { error: 'not_found' } );
	}
	if ( req.method === 'POST' && url.pathname === '/v1/baseline' ) {
		const run = await store.get( auth.site, String( input.run_id ?? '' ) );
		if ( ! run || run.status !== 'done' || ! run.options.visual ) {
			return send( res, 404, { error: 'not_found' } );
		}
		let updated = 0;
		for ( const [ i, page ] of run.pages.entries() ) {
			await mkdir( store.dir( 'baselines', auth.site ), { recursive: true } );
			updated += await copyFile( store.dir( 'shots', auth.site, run.id, `${ i }.png` ), store.dir( 'baselines', auth.site, `${ slug( page.label ) }.png` ) ).then( () => 1, () => 0 );
		}
		return send( res, 200, { updated } );
	}
	return send( res, 404, { error: 'not_found' } );
}

/** Nocturna: una vez al día a NIGHTLY_HOUR (hora de TZ) para los sitios con nightly. */
let lastNightly = '';
async function tick() {
	if ( null === NIGHTLY_HOUR ) {
		return;
	}
	const now = new Date();
	const day = now.toLocaleDateString( 'sv' );
	if ( now.getHours() !== NIGHTLY_HOUR || day === lastNightly ) {
		return;
	}
	lastNightly = day;
	await store.loadSites().catch( ( e ) => console.error( '[verifier] sites.json:', e.message ) );
	for ( const [ id, site ] of Object.entries( store.sites ) ) {
		const { pages, error } = resolvePages( site.url, site.pages );
		if ( site.nightly && ! error ) {
			await runner.enqueue( id, pages, { lighthouse: true, visual: true }, 'nightly' );
		}
	}
	await runner.cleanup();
}

await store.loadSites();
createServer( ( req, res ) => {
	handle( req, res ).catch( ( e ) => {
		console.error( '[verifier]', e );
		send( res, 500, { error: 'internal' } );
	} );
} ).listen( PORT, () => console.log( `[verifier] ${ VERSION } en el puerto ${ PORT }, ${ Object.keys( store.sites ).length } sitios` ) );
setInterval( () => void tick(), 5 * 60 * 1000 ).unref();
// Recargar sites.json sin reiniciar: kill -HUP.
process.on( 'SIGHUP', () => void store.loadSites().then( ( s ) => console.log( `[verifier] sites.json recargado: ${ Object.keys( s ).length } sitios` ) ) );
