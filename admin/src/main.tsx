import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import { ApiError } from './lib/api';
import './index.css';

const client = new QueryClient( {
	defaultOptions: {
		queries: {
			staleTime: 15_000,
			// No se reintenta un 401/403 (sesión expirada) ni un error de validación.
			retry: ( count, error ) => ! ( error instanceof ApiError && error.status >= 400 && error.status < 500 ) && count < 2,
		},
	},
} );

const root = document.getElementById( 'itb-admin-root' );
if ( root ) {
	createRoot( root ).render(
		<StrictMode>
			<QueryClientProvider client={ client }>
				<App />
			</QueryClientProvider>
		</StrictMode>
	);
}
