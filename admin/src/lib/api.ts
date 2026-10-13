import { config } from './config';
import { __ } from './i18n';

export class ApiError extends Error {
	constructor(
		message: string,
		public readonly code: string,
		public readonly status: number,
		public readonly data: Record<string, unknown> = {}
	) {
		super( message );
		this.name = 'ApiError';
	}
}

type Method = 'GET' | 'POST' | 'PUT' | 'DELETE';

// El nonce puede renovarse (WordPress lo devuelve en X-WP-Nonce); se guarda el último.
let nonce = config.nonce;

export async function api<T>( path: string, method: Method = 'GET', body?: unknown, query?: Record<string, string | number | undefined> ): Promise<T> {
	const url = new URL( config.restUrl + path.replace( /^\//, '' ), window.location.origin );
	if ( query ) {
		for ( const [ key, value ] of Object.entries( query ) ) {
			if ( value !== undefined && value !== '' ) {
				url.searchParams.set( key, String( value ) );
			}
		}
	}

	let response: Response;
	try {
		response = await fetch( url.toString(), {
			method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': nonce,
				Accept: 'application/json',
				...( body !== undefined ? { 'Content-Type': 'application/json' } : {} ),
			},
			body: body !== undefined ? JSON.stringify( body ) : undefined,
		} );
	} catch {
		throw new ApiError( __( 'No se pudo conectar con el sitio. Revisa tu conexión.' ), 'network', 0 );
	}

	const renewed = response.headers.get( 'X-WP-Nonce' );
	if ( renewed ) {
		nonce = renewed;
	}

	const text = await response.text();
	let json: unknown = null;
	try {
		json = text ? JSON.parse( text ) : null;
	} catch {
		// Un aviso de PHP antes del JSON rompe el parseo: se informa en lugar de fallar en silencio.
		throw new ApiError( __( 'Respuesta no válida del servidor. Revisa el registro de errores de PHP.' ), 'invalid_json', response.status );
	}

	if ( ! response.ok ) {
		const error = ( json ?? {} ) as { code?: string; message?: string; data?: Record<string, unknown> };
		const fallbackMessage = response.status === 403 ? __( 'Tu sesión expiró o no tienes permisos. Recarga la página.' ) : __( 'Error inesperado.' );
		throw new ApiError( error.message ?? fallbackMessage, error.code ?? 'error', response.status, error.data ?? {} );
	}

	return json as T;
}
