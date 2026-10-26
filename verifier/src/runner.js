/**
 * Corridas: cola de una en una (un VPS pequeño no aguanta dos Chromium con Lighthouse), batería por
 * página, Lighthouse opcional y comparación de la corrida nocturna con la anterior.
 */
import { devices, chromium } from 'playwright';
import { readdir, rm, stat } from 'node:fs/promises';
import { checkPage } from './battery.js';
import { runLighthouse } from './lighthouse.js';
import { Store } from './store.js';

export const VERSION = '0.20.0';
export const MAX_PAGES = 15;
/** Puntos de Lighthouse que tiene que bajar una página para avisar. */
export const SCORE_DROP = 10;
/** Identificador en el agente de usuario: el RUM de Imagina Turbo no cuenta estas visitas. */
export const UA_TAG = 'ImaginaTurboVerifier';

/**
 * Páginas de la corrida: rutas o URLs del mismo host que el sitio (nunca otro dominio).
 *
 * @return {{pages: Array<object>, error?: string}}
 */
export function resolvePages( siteUrl, pages ) {
	const base = new URL( siteUrl );
	const out = [];
	for ( const p of Array.isArray( pages ) ? pages.slice( 0, MAX_PAGES ) : [] ) {
		let url;
		try {
			url = new URL( String( p.url ?? '' ), base );
		} catch {
			return { pages: [], error: `URL inválida: ${ String( p.url ).slice( 0, 100 ) }` };
		}
		if ( url.host !== base.host || ! [ 'http:', 'https:' ].includes( url.protocol ) ) {
			return { pages: [], error: `Solo páginas de ${ base.host }: ${ url.href.slice( 0, 100 ) }` };
		}
		out.push( {
			origin: base.origin,
			label: String( p.label ?? url.pathname ).slice( 0, 60 ),
			url: url.href,
			flow: p.flow === 'add_to_cart' ? 'add_to_cart' : undefined,
			expect: Number.isInteger( p.expect ) ? p.expect : undefined,
		} );
	}
	return out.length ? { pages: out } : { pages: [], error: 'Sin páginas' };
}

export const slug = ( s ) => s.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' ).slice( 0, 60 ) || 'pagina';

/** Avisos de la nocturna: lo que falla y las caídas de Lighthouse frente a la anterior. */
export function compare( run, previous ) {
	const alerts = [];
	for ( const r of run.results ) {
		if ( ! r.pass ) {
			alerts.push( `${ r.label }: ${ r.failures[ 0 ] ?? 'falla' }` );
		}
		const before = previous?.results?.find( ( p ) => p.label === r.label )?.lighthouse?.score;
		const now = r.lighthouse?.score;
		if ( typeof before === 'number' && typeof now === 'number' && before - now >= SCORE_DROP ) {
			alerts.push( `${ r.label }: Lighthouse ${ before } → ${ now }` );
		}
	}
	return alerts;
}

export class Runner {
	/** @param {Store} store */
	constructor( store ) {
		this.store = store;
		this.chain = Promise.resolve();
		this.pending = 0;
	}

	/** Encola una corrida y devuelve su registro inicial. */
	async enqueue( site, pages, options = {}, trigger = 'manual' ) {
		const run = {
			id: Store.newId(),
			site,
			trigger,
			status: 'queued',
			created_at: Math.floor( Date.now() / 1000 ),
			options: { lighthouse: options.lighthouse === true, visual: options.visual !== false },
			pages,
			results: [],
			pass: null,
			alerts: [],
			verifier: VERSION,
		};
		await this.store.save( site, run );
		this.pending++;
		this.chain = this.chain.then( () => this.execute( run, options ) ).catch( ( e ) => console.error( '[verifier]', e ) ).finally( () => this.pending-- );
		return run;
	}

	async execute( run, options ) {
		run.status = 'running';
		run.started_at = Math.floor( Date.now() / 1000 );
		await this.store.save( run.site, run );
		let browser;
		try {
			browser = await chromium.launch( { args: [ '--no-sandbox', '--disable-dev-shm-usage' ] } );
			const device = devices[ 'Pixel 7' ];
			const context = await browser.newContext( { ...device, userAgent: `${ device.userAgent } ${ UA_TAG }/${ VERSION }`, locale: 'es-CO', timezoneId: 'America/Bogota' } );
			if ( options.cookie ) {
				const host = new URL( run.pages[ 0 ].origin ).hostname;
				await context.addCookies( [ { name: options.cookie.name, value: options.cookie.value, domain: host, path: '/' } ] );
			}
			for ( const [ i, page ] of run.pages.entries() ) {
				const files = run.options.visual ? {
					shot: this.store.dir( 'shots', run.site, run.id, `${ i }.png` ),
					baseline: this.store.dir( 'baselines', run.site, `${ slug( page.label ) }.png` ),
					threshold: options.threshold,
				} : {};
				const result = await checkPage( context, page, files );
				run.results.push( result );
				await this.store.save( run.site, run );
			}
			await context.close();
			await browser.close();
			browser = null;
			if ( run.options.lighthouse ) {
				for ( const result of run.results ) {
					if ( result.status > 0 && result.status < 400 ) {
						result.lighthouse = await runLighthouse( result.url );
						await this.store.save( run.site, run );
					}
				}
			}
			run.pass = run.results.every( ( r ) => r.pass );
			run.status = 'done';
		} catch ( e ) {
			run.status = 'error';
			run.error = String( e.message ).split( '\n' )[ 0 ].slice( 0, 300 );
		} finally {
			await browser?.close().catch( () => null );
		}
		run.finished_at = Math.floor( Date.now() / 1000 );
		if ( run.trigger === 'nightly' && run.status === 'done' ) {
			const previous = ( await this.store.recent( run.site, 10 ) ).find( ( r ) => r.trigger === 'nightly' && r.status === 'done' && r.id !== run.id );
			run.alerts = compare( run, previous );
		}
		await this.store.save( run.site, run );
		console.log( `[verifier] ${ run.site } ${ run.id } ${ run.trigger }: ${ run.status }${ run.pass === false ? ' (falla)' : '' }` );
		return run;
	}

	/** Borra capturas de más de 14 días y corridas de más de 60. */
	async cleanup( now = Date.now() ) {
		for ( const [ dir, days ] of [ [ 'shots', 14 ], [ 'runs', 60 ] ] ) {
			for ( const site of await readdir( this.store.dir( dir ) ).catch( () => [] ) ) {
				for ( const entry of await readdir( this.store.dir( dir, site ) ).catch( () => [] ) ) {
					const path = this.store.dir( dir, site, entry );
					const info = await stat( path ).catch( () => null );
					if ( info && now - info.mtimeMs > days * 86400000 ) {
						await rm( path, { recursive: true, force: true } );
					}
				}
			}
		}
	}
}
