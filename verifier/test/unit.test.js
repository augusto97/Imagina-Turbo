import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PNG } from 'pngjs';
import { NonceCache, sign, verify } from '../src/auth.js';
import { phpErrors, visualDiff } from '../src/battery.js';
import { summarize } from '../src/lighthouse.js';
import { compare, resolvePages, slug } from '../src/runner.js';

const KEY = 'a'.repeat( 64 );
const site = ( id ) => ( id === 'tienda' ? { key: KEY } : undefined );

function signed( { method = 'POST', path = '/v1/runs', body = '{}', now = 1_800_000_000, nonce = '0123456789abcdef0123456789abcdef', key = KEY } = {} ) {
	return {
		method,
		path,
		body: Buffer.from( body ),
		now,
		headers: {
			'x-itb-site': 'tienda',
			'x-itb-timestamp': String( now ),
			'x-itb-nonce': nonce,
			'x-itb-signature': sign( key, method, path, now, nonce, body ),
		},
	};
}

test( 'la firma del plugin (cadena canónica del hub) se acepta una sola vez', () => {
	const nonces = new NonceCache();
	const req = signed();
	assert.deepEqual( verify( { ...req, findSite: site, nonces } ), { site: 'tienda' } );
	assert.equal( verify( { ...req, findSite: site, nonces } ).error, 'replay' );
} );

test( 'firma, ventana, sitio y cabeceras inválidos se rechazan', () => {
	const nonces = new NonceCache();
	assert.equal( verify( { ...signed( { key: 'b'.repeat( 64 ) } ), findSite: site, nonces } ).error, 'bad_signature' );
	const old = signed();
	assert.equal( verify( { ...old, now: old.now + 301, findSite: site, nonces } ).error, 'expired' );
	const other = signed();
	other.headers[ 'x-itb-site' ] = 'otra';
	assert.equal( verify( { ...other, findSite: site, nonces } ).error, 'unknown_site' );
	const bad = signed();
	bad.headers[ 'x-itb-nonce' ] = 'xyz';
	assert.equal( verify( { ...bad, findSite: site, nonces } ).error, 'bad_headers' );
	// La ruta forma parte de la firma: no sirve para otro endpoint.
	const moved = signed();
	assert.equal( verify( { ...moved, path: '/v1/baseline', findSite: site, nonces } ).error, 'bad_signature' );
} );

test( 'el PHP del plugin firma igual que el verificador', () => {
	// Vector calculado con ImaginaTurbo\Hub\Signer::sign( KEY, 'POST', '/v1/runs', 1800000000, nonce, '{}' ).
	assert.equal( sign( KEY, 'POST', '/v1/runs', 1_800_000_000, '0123456789abcdef0123456789abcdef', '{}' ), '2ce8d15bbe575520ae499a6cddb7b1329729f1b4aa08879974f80f51a9497596' );
} );

test( 'solo páginas del propio sitio', () => {
	const ok = resolvePages( 'https://tienda.com', [ { label: 'Portada', url: '/' }, { url: 'https://tienda.com/p/x/', flow: 'add_to_cart' }, { url: '/x', flow: 'borrar' } ] );
	assert.equal( ok.error, undefined );
	assert.deepEqual( ok.pages.map( ( p ) => [ p.label, p.url, p.flow ] ), [ [ 'Portada', 'https://tienda.com/', undefined ], [ '/p/x/', 'https://tienda.com/p/x/', 'add_to_cart' ], [ '/x', 'https://tienda.com/x', undefined ] ] );
	assert.match( resolvePages( 'https://tienda.com', [ { url: 'https://otra.com/' } ] ).error, /Solo páginas de tienda.com/ );
	assert.match( resolvePages( 'https://tienda.com', [ { url: 'file:///etc/passwd' } ] ).error, /Solo páginas/ );
	assert.equal( resolvePages( 'https://tienda.com', [] ).error, 'Sin páginas' );
	assert.equal( resolvePages( 'https://tienda.com', Array.from( { length: 30 }, () => ( { url: '/' } ) ) ).pages.length, 15 );
	assert.equal( slug( 'Categoría: Pinturas' ), 'categoria-pinturas' );
} );

test( 'la nocturna avisa de fallos y caídas de Lighthouse', () => {
	const run = { results: [ { label: 'Portada', pass: true, failures: [], lighthouse: { score: 70 } }, { label: 'Ficha', pass: false, failures: [ 'JS: x is not defined' ], lighthouse: { score: 80 } } ] };
	const previous = { results: [ { label: 'Portada', lighthouse: { score: 85 } }, { label: 'Ficha', lighthouse: { score: 82 } } ] };
	assert.deepEqual( compare( run, previous ), [ 'Portada: Lighthouse 85 → 70', 'Ficha: JS: x is not defined' ] );
	assert.deepEqual( compare( { results: [ run.results[ 0 ] ] }, undefined ), [] );
} );

test( 'errores de PHP en el HTML', () => {
	assert.deepEqual( phpErrors( '<p>ok</p>' ), [] );
	assert.deepEqual( phpErrors( '<br />\n<b>Warning</b>: Undefined variable $x in /a.php' ), [ 'Warning:' ] );
	assert.equal( phpErrors( '<p>There has been a critical error on this website.</p>' ).length, 1 );
} );

test( 'diferencia visual por píxeles', () => {
	const png = ( w, h, paint ) => {
		const p = new PNG( { width: w, height: h } );
		for ( let i = 0; i < w * h; i++ ) {
			const on = paint( i % w, Math.floor( i / w ) );
			p.data.set( on ? [ 0, 0, 0, 255 ] : [ 255, 255, 255, 255 ], i * 4 );
		}
		return PNG.sync.write( p );
	};
	const a = png( 10, 10, () => false );
	assert.equal( visualDiff( a, a ), 0 );
	assert.equal( visualDiff( a, png( 10, 10, ( x ) => x < 5 ) ), 0.5 );
	assert.equal( visualDiff( a, png( 10, 20, () => false ) ), 1 );
} );

test( 'resumen de Lighthouse', () => {
	const s = summarize( {
		lighthouseVersion: '13.5.0',
		categories: { performance: { score: 0.734 } },
		audits: {
			'largest-contentful-paint': { numericValue: 2512.4 },
			'cumulative-layout-shift': { numericValue: 0.0512 },
			'total-byte-weight': { numericValue: 1536000 },
			'network-requests': { details: { items: [ 1, 2, 3 ] } },
			'render-blocking-resources': { id: 'render-blocking-resources', title: 'Bloqueo', details: { type: 'opportunity', overallSavingsMs: 820 } },
			'unused-css-rules': { id: 'unused-css-rules', title: 'CSS', details: { type: 'opportunity', overallSavingsMs: 40 } },
		},
	} );
	assert.equal( s.score, 73 );
	assert.equal( s.metrics.lcp, 2512 );
	assert.equal( s.metrics.cls, 0.051 );
	assert.equal( s.metrics.tbt, null );
	assert.equal( s.bytes, 1500 );
	assert.equal( s.requests, 3 );
	assert.deepEqual( s.opportunities, [ { id: 'render-blocking-resources', title: 'Bloqueo', savings_ms: 820 } ] );
} );
