/**
 * Lighthouse (solo rendimiento, móvil con la limitación simulada por defecto) con el Chromium de
 * Playwright. Devuelve la puntuación, las métricas y las cinco oportunidades con más ahorro.
 */
import * as chromeLauncher from 'chrome-launcher';
import lighthouse from 'lighthouse';
import { chromium } from 'playwright';

const METRICS = {
	fcp: 'first-contentful-paint',
	lcp: 'largest-contentful-paint',
	tbt: 'total-blocking-time',
	cls: 'cumulative-layout-shift',
	si: 'speed-index',
	ttfb: 'server-response-time',
};

/** Resumen de un informe de Lighthouse (lhr). */
export function summarize( lhr ) {
	const audits = lhr.audits ?? {};
	const metrics = {};
	for ( const [ key, id ] of Object.entries( METRICS ) ) {
		const v = audits[ id ]?.numericValue;
		metrics[ key ] = typeof v === 'number' ? ( key === 'cls' ? Math.round( v * 1000 ) / 1000 : Math.round( v ) ) : null;
	}
	const opportunities = Object.values( audits )
		.filter( ( a ) => a.details?.type === 'opportunity' && ( a.details.overallSavingsMs ?? 0 ) >= 100 )
		.sort( ( a, b ) => b.details.overallSavingsMs - a.details.overallSavingsMs )
		.slice( 0, 5 )
		.map( ( a ) => ( { id: a.id, title: a.title, savings_ms: Math.round( a.details.overallSavingsMs ) } ) );
	const score = lhr.categories?.performance?.score;
	return {
		score: typeof score === 'number' ? Math.round( score * 100 ) : null,
		metrics,
		bytes: audits[ 'total-byte-weight' ]?.numericValue ? Math.round( audits[ 'total-byte-weight' ].numericValue / 1024 ) : null,
		requests: audits[ 'network-requests' ]?.details?.items?.length ?? null,
		opportunities,
		lighthouse: lhr.lighthouseVersion ?? '',
	};
}

export async function runLighthouse( url ) {
	const chrome = await chromeLauncher.launch( {
		chromePath: chromium.executablePath(),
		chromeFlags: [ '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage' ],
	} );
	try {
		const result = await lighthouse( url, { port: chrome.port, output: 'json', logLevel: 'error', onlyCategories: [ 'performance' ] } );
		if ( ! result?.lhr ) {
			return { error: 'Lighthouse no devolvió informe' };
		}
		if ( result.lhr.runtimeError ) {
			return { error: `${ result.lhr.runtimeError.code }: ${ result.lhr.runtimeError.message }`.slice( 0, 200 ) };
		}
		return summarize( result.lhr );
	} catch ( e ) {
		return { error: String( e.message ).split( '\n' )[ 0 ].slice( 0, 200 ) };
	} finally {
		await chrome.kill();
	}
}
