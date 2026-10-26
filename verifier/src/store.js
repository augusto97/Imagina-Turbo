/**
 * Sitios (sites.json) y corridas (un JSON por corrida) en el directorio de datos.
 *
 * sites.json: {"sites": {"<id>": {"key": "<64 hex>", "url": "https://tienda.com", "pages": [{"label",
 * "url", "flow"?}], "nightly": true}}}. La clave la genera el plugin (Avanzado → Verificador) y se copia
 * aquí; el verificador nunca guarda usuarios ni contraseñas de los sitios.
 */
import { randomBytes } from 'node:crypto';
import { mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

export class Store {
	constructor( dataDir, sitesFile ) {
		this.dataDir = dataDir;
		this.sitesFile = sitesFile;
		this.sites = {};
	}

	async loadSites() {
		const raw = JSON.parse( await readFile( this.sitesFile, 'utf8' ) );
		const sites = {};
		for ( const [ id, s ] of Object.entries( raw.sites ?? {} ) ) {
			if ( ! /^[A-Za-z0-9_.-]{1,100}$/.test( id ) || typeof s.key !== 'string' || s.key.length < 32 || ! /^https?:\/\//.test( s.url ?? '' ) ) {
				console.warn( `[verifier] sitio ignorado en sites.json: ${ id }` );
				continue;
			}
			sites[ id ] = {
				key: s.key,
				url: s.url.replace( /\/+$/, '' ),
				pages: Array.isArray( s.pages ) ? s.pages : [],
				nightly: s.nightly !== false,
			};
		}
		this.sites = sites;
		return sites;
	}

	site( id ) {
		return Object.hasOwn( this.sites, id ) ? this.sites[ id ] : undefined;
	}

	dir( ...parts ) {
		return join( this.dataDir, ...parts );
	}

	static newId() {
		return `${ new Date().toISOString().slice( 0, 10 ).replace( /-/g, '' ) }-${ randomBytes( 5 ).toString( 'hex' ) }`;
	}

	async save( site, run ) {
		await mkdir( this.dir( 'runs', site ), { recursive: true } );
		await writeFile( this.dir( 'runs', site, `${ run.id }.json` ), JSON.stringify( run, null, 1 ) );
	}

	async get( site, id ) {
		if ( ! /^\d{8}-[a-f0-9]{10}$/.test( id ) ) {
			return null;
		}
		try {
			return JSON.parse( await readFile( this.dir( 'runs', site, `${ id }.json` ), 'utf8' ) );
		} catch {
			return null;
		}
	}

	/** Corridas más recientes primero (el id empieza por la fecha; dentro del día, por hora de creación). */
	async recent( site, limit = 10 ) {
		let files = [];
		try {
			files = ( await readdir( this.dir( 'runs', site ) ) ).filter( ( f ) => f.endsWith( '.json' ) );
		} catch {
			return [];
		}
		const runs = [];
		for ( const f of files ) {
			const run = await this.get( site, f.slice( 0, -5 ) );
			if ( run ) {
				runs.push( run );
			}
		}
		runs.sort( ( a, b ) => ( b.created_at ?? 0 ) - ( a.created_at ?? 0 ) );
		return runs.slice( 0, limit );
	}
}
