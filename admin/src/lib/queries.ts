import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from './api';
import type { CompatReport, Status } from './types';

export function useStatus() {
	return useQuery( { queryKey: [ 'status' ], queryFn: () => api<Status>( 'status' ), refetchOnWindowFocus: true } );
}

export function useCompat() {
	return useQuery( { queryKey: [ 'compat' ], queryFn: () => api<CompatReport>( 'compat/report' ), staleTime: 60_000 } );
}

/**
 * Mutación que, al terminar, refresca el estado general.
 */
export function useAction<TVars, TResult>( fn: ( vars: TVars ) => Promise<TResult> ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: fn,
		onSettled: () => {
			void client.invalidateQueries( { queryKey: [ 'status' ] } );
			void client.invalidateQueries( { queryKey: [ 'events' ] } );
		},
	} );
}
