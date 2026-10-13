import { create } from 'zustand';

export type Route = 'panel' | 'diagnostico' | 'cache' | 'cloudflare' | 'woocommerce' | 'navegacion' | 'carga' | 'firewall' | 'ajustes' | 'registro' | 'avanzado';

const ROUTES: Route[] = [ 'panel', 'diagnostico', 'cache', 'cloudflare', 'woocommerce', 'navegacion', 'carga', 'firewall', 'ajustes', 'registro', 'avanzado' ];

function fromHash(): Route {
	const value = window.location.hash.replace( /^#\/?/, '' ) as Route;
	return ROUTES.includes( value ) ? value : 'panel';
}

interface RouterState {
	route: Route;
	go: ( route: Route ) => void;
}

// Navegación por hash: el admin es una sola página de WordPress (admin.php?page=imagina-turbo).
export const useRouter = create<RouterState>( ( set ) => ( {
	route: fromHash(),
	go: ( route ) => {
		if ( window.location.hash !== '#/' + route ) {
			window.location.hash = '/' + route;
		}
		set( { route } );
	},
} ) );

window.addEventListener( 'hashchange', () => useRouter.setState( { route: fromHash() } ) );
