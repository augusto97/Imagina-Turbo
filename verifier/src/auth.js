/**
 * Firma de las peticiones del sitio al verificador.
 *
 * La misma cadena canónica que el hub (docs/04): MÉTODO\nRUTA\nTIMESTAMP\nNONCE\nsha256_hex(cuerpo),
 * HMAC-SHA256 con la clave del sitio. Ventana de 5 minutos y nonces de un solo uso. El plugin firma
 * con ImaginaTurbo\Hub\Signer.
 */
import { createHash, createHmac, timingSafeEqual } from 'node:crypto';

export const MAX_SKEW = 300;

export function canonical( method, path, timestamp, nonce, body ) {
	return `${ method.toUpperCase() }\n${ path }\n${ timestamp }\n${ nonce }\n${ createHash( 'sha256' ).update( body ).digest( 'hex' ) }`;
}

export function sign( key, method, path, timestamp, nonce, body ) {
	return createHmac( 'sha256', key ).update( canonical( method, path, timestamp, nonce, body ) ).digest( 'hex' );
}

/**
 * Nonces vistos dentro de la ventana (en memoria: un reinicio no reabre la ventana de 5 minutos para
 * más de un intento por nonce porque la firma también caduca).
 */
export class NonceCache {
	constructor() {
		this.seen = new Map();
	}

	/** true si el nonce es nuevo (y queda registrado). */
	use( site, nonce, now ) {
		for ( const [ k, t ] of this.seen ) {
			if ( now - t > MAX_SKEW * 2 ) {
				this.seen.delete( k );
			}
		}
		const key = `${ site }:${ nonce }`;
		if ( this.seen.has( key ) ) {
			return false;
		}
		this.seen.set( key, now );
		return true;
	}
}

/**
 * Comprueba la firma. Devuelve el id del sitio o un error.
 *
 * @param {Record<string, string|undefined>} headers Cabeceras en minúsculas.
 * @param {(id: string) => {key: string}|undefined} findSite Sitio por id.
 */
export function verify( { method, path, headers, body, findSite, nonces, now = Math.floor( Date.now() / 1000 ) } ) {
	const site = headers[ 'x-itb-site' ] ?? '';
	const timestamp = headers[ 'x-itb-timestamp' ] ?? '';
	const nonce = headers[ 'x-itb-nonce' ] ?? '';
	const signature = headers[ 'x-itb-signature' ] ?? '';
	const config = /^[A-Za-z0-9_.-]{1,100}$/.test( site ) ? findSite( site ) : undefined;
	if ( ! config ) {
		return { error: 'unknown_site' };
	}
	if ( ! /^\d{1,12}$/.test( timestamp ) || ! /^[a-f0-9]{32}$/.test( nonce ) || ! /^[a-f0-9]{64}$/.test( signature ) ) {
		return { error: 'bad_headers' };
	}
	if ( Math.abs( now - Number( timestamp ) ) > MAX_SKEW ) {
		return { error: 'expired' };
	}
	const expected = Buffer.from( sign( config.key, method, path, Number( timestamp ), nonce, body ), 'hex' );
	if ( ! timingSafeEqual( expected, Buffer.from( signature, 'hex' ) ) ) {
		return { error: 'bad_signature' };
	}
	if ( ! nonces.use( site, nonce, now ) ) {
		return { error: 'replay' };
	}
	return { site };
}
