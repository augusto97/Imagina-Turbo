import { type ClassValue, clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// tailwind-merge necesita conocer el prefijo para resolver conflictos entre clases "itb:".
const twMerge = extendTailwindMerge( { prefix: 'itb' } );

export function cn( ...inputs: ClassValue[] ): string {
	return twMerge( clsx( inputs ) );
}

export function formatDate( value: unknown, locale: string ): string {
	// Solo texto o número: un arreglo PHP vacío llega como [] y [].at es una función, no una fecha.
	if ( ( typeof value !== 'string' && typeof value !== 'number' ) || value === '' || value === 0 ) {
		return '—';
	}
	// Las fechas de la base vienen en UTC sin zona ("2026-10-13 10:00:00").
	const date = typeof value === 'number' ? new Date( value * 1000 ) : new Date( value.replace( ' ', 'T' ) + 'Z' );
	if ( Number.isNaN( date.getTime() ) ) {
		return String( value );
	}
	return new Intl.DateTimeFormat( locale, { dateStyle: 'medium', timeStyle: 'short' } ).format( date );
}

export function downloadFile( name: string, content: string, type: string ): void {
	const blob = new Blob( [ content ], { type } );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = name;
	document.body.appendChild( link );
	link.click();
	link.remove();
	URL.revokeObjectURL( url );
}

export function formatBytes( bytes: number, locale: string ): string {
	const number = new Intl.NumberFormat( locale, { maximumFractionDigits: 1 } );
	if ( bytes >= 1048576 ) {
		return `${ number.format( bytes / 1048576 ) } MB`;
	}
	if ( bytes >= 1024 ) {
		return `${ number.format( bytes / 1024 ) } KB`;
	}
	return `${ number.format( bytes ) } B`;
}
