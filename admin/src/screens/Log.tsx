import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { ChevronLeft, ChevronRight, Download } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { PageHeader } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Input, Select } from '@/components/ui/input';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import type { EventItem, EventsPage } from '@/lib/types';
import { downloadFile, formatDate } from '@/lib/utils';

const PER_PAGE = 50;

const severities: Record<EventItem[ 'severity' ], { label: string; tone: 'neutral' | 'amber' | 'red' }> = {
	0: { label: __( 'Info' ), tone: 'neutral' },
	1: { label: __( 'Aviso' ), tone: 'amber' },
	2: { label: __( 'Error' ), tone: 'red' },
};

const column = createColumnHelper<EventItem>();

export function Log() {
	const [ page, setPage ] = useState( 1 );
	const [ module, setModule ] = useState( '' );
	const [ severity, setSeverity ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ expanded, setExpanded ] = useState<number | null>( null );
	const [ exportError, setExportError ] = useState( '' );

	const filters = { module, severity: severity === '' ? undefined : Number( severity ), search };

	const { data, isLoading, error, isFetching } = useQuery( {
		queryKey: [ 'events', page, module, severity, search ],
		queryFn: () => api<EventsPage>( 'events', 'GET', undefined, { ...filters, page, per_page: PER_PAGE } ),
		placeholderData: keepPreviousData,
	} );

	const columns = useMemo(
		() => [
			column.accessor( 'created_at', { header: __( 'Fecha' ), cell: ( info ) => <span className="itb:whitespace-nowrap itb:text-zinc-500">{ formatDate( info.getValue(), config.locale ) }</span> } ),
			column.accessor( 'severity', {
				header: __( 'Nivel' ),
				cell: ( info ) => {
					const meta = severities[ info.getValue() ] ?? severities[ 0 ];
					return <Badge tone={ meta.tone }>{ meta.label }</Badge>;
				},
			} ),
			column.accessor( 'module', { header: __( 'Módulo' ), cell: ( info ) => <code className="itb:text-xs">{ info.getValue() }</code> } ),
			column.accessor( 'type', { header: __( 'Tipo' ), cell: ( info ) => <code className="itb:text-xs">{ info.getValue() }</code> } ),
			column.accessor( 'message', { header: __( 'Mensaje' ), cell: ( info ) => <span className="itb:text-zinc-900">{ info.getValue() }</span> } ),
		],
		[]
	);

	const table = useReactTable( { data: data?.items ?? [], columns, getCoreRowModel: getCoreRowModel() } );
	const totalPages = data ? Math.max( 1, Math.ceil( data.total / PER_PAGE ) ) : 1;

	const exportAs = async ( format: 'csv' | 'json' ) => {
		setExportError( '' );
		try {
			const items = await api<EventItem[]>( 'events/export', 'GET', undefined, filters );
			const stamp = new Date().toISOString().slice( 0, 10 );
			if ( format === 'json' ) {
				downloadFile( `imagina-turbo-registro-${ stamp }.json`, JSON.stringify( items, null, 2 ), 'application/json' );
				return;
			}
			const escape = ( value: unknown ) => `"${ String( value ?? '' ).replace( /"/g, '""' ) }"`;
			const rows = [ [ 'id', 'fecha_utc', 'modulo', 'tipo', 'nivel', 'mensaje', 'contexto' ].join( ',' ) ];
			for ( const item of items ) {
				rows.push( [ item.id, item.created_at, item.module, item.type, item.severity, item.message, item.context ? JSON.stringify( item.context ) : '' ].map( escape ).join( ',' ) );
			}
			downloadFile( `imagina-turbo-registro-${ stamp }.csv`, '﻿' + rows.join( '\n' ), 'text/csv;charset=utf-8' );
		} catch ( e ) {
			setExportError( e instanceof Error ? e.message : String( e ) );
		}
	};

	const resetPage = () => {
		setPage( 1 );
		setExpanded( null );
	};

	return (
		<>
			<PageHeader
				title={ __( 'Registro' ) }
				description={ __( 'Cambios, aplicaciones, reversiones, modo seguro y errores. Se conservan 180 días.' ) }
				actions={
					<>
						<Button variant="outline" size="sm" onClick={ () => void exportAs( 'csv' ) }>
							<Download aria-hidden />
							CSV
						</Button>
						<Button variant="outline" size="sm" onClick={ () => void exportAs( 'json' ) }>
							<Download aria-hidden />
							JSON
						</Button>
					</>
				}
			/>

			<div className="itb:mb-4 itb:grid itb:gap-2 itb:sm:grid-cols-3">
				<Select
					aria-label={ __( 'Módulo' ) }
					value={ module }
					onChange={ ( e ) => {
						setModule( e.target.value );
						resetPage();
					} }
				>
					<option value="">{ __( 'Todos los módulos' ) }</option>
					{ ( data?.modules ?? [] ).map( ( m ) => (
						<option key={ m } value={ m }>
							{ m }
						</option>
					) ) }
				</Select>
				<Select
					aria-label={ __( 'Nivel mínimo' ) }
					value={ severity }
					onChange={ ( e ) => {
						setSeverity( e.target.value );
						resetPage();
					} }
				>
					<option value="">{ __( 'Todos los niveles' ) }</option>
					<option value="1">{ __( 'Avisos y errores' ) }</option>
					<option value="2">{ __( 'Solo errores' ) }</option>
				</Select>
				<Input
					type="search"
					placeholder={ __( 'Buscar en el mensaje…' ) }
					aria-label={ __( 'Buscar' ) }
					value={ search }
					maxLength={ 100 }
					onChange={ ( e ) => {
						setSearch( e.target.value );
						resetPage();
					} }
				/>
			</div>

			{ error && <Notice level="error">{ error.message }</Notice> }
			{ exportError && <Notice level="error" className="itb:mb-3">{ exportError }</Notice> }

			<Card className="itb:overflow-hidden">
				{ isLoading ? (
					<div className="itb:flex itb:flex-col itb:gap-2 itb:p-4">
						{ [ 1, 2, 3, 4, 5 ].map( ( i ) => (
							<Skeleton key={ i } className="itb:h-8" />
						) ) }
					</div>
				) : (
					<div className="itb:overflow-x-auto">
						<table className="itb:w-full itb:text-left itb:text-[13px]">
							<thead className="itb:bg-zinc-50 itb:text-xs itb:text-zinc-500">
								{ table.getHeaderGroups().map( ( group ) => (
									<tr key={ group.id }>
										{ group.headers.map( ( header ) => (
											<th key={ header.id } scope="col" className="itb:border-b itb:border-zinc-200 itb:px-4 itb:py-2.5 itb:font-medium">
												{ flexRender( header.column.columnDef.header, header.getContext() ) }
											</th>
										) ) }
									</tr>
								) ) }
							</thead>
							<tbody>
								{ table.getRowModel().rows.length === 0 && (
									<tr>
										<td colSpan={ columns.length } className="itb:px-4 itb:py-10 itb:text-center itb:text-zinc-500">
											{ __( 'No hay eventos con estos filtros.' ) }
										</td>
									</tr>
								) }
								{ table.getRowModel().rows.map( ( row ) => (
									<Fragment key={ row.id }>
										<tr
											className="itb:cursor-pointer itb:border-b itb:border-zinc-100 itb:hover:bg-zinc-50"
											onClick={ () => setExpanded( expanded === row.original.id ? null : row.original.id ) }
											aria-expanded={ row.original.context ? expanded === row.original.id : undefined }
										>
											{ row.getVisibleCells().map( ( cell ) => (
												<td key={ cell.id } className="itb:px-4 itb:py-2.5 itb:align-top">
													{ flexRender( cell.column.columnDef.cell, cell.getContext() ) }
												</td>
											) ) }
										</tr>
										{ expanded === row.original.id && row.original.context && (
											<tr className="itb:border-b itb:border-zinc-100 itb:bg-zinc-50">
												<td colSpan={ columns.length } className="itb:px-4 itb:py-3">
													<pre className="itb:overflow-x-auto itb:rounded-md itb:bg-white itb:p-3 itb:font-mono itb:text-xs itb:text-zinc-700">
														{ JSON.stringify( row.original.context, null, 2 ) }
													</pre>
												</td>
											</tr>
										) }
									</Fragment>
								) ) }
							</tbody>
						</table>
					</div>
				) }
				<div className="itb:flex itb:items-center itb:justify-between itb:border-t itb:border-zinc-200 itb:px-4 itb:py-2.5 itb:text-xs itb:text-zinc-500">
					<span>
						{ data ? `${ data.total } ${ __( 'eventos' ) }` : '' } { isFetching && ! isLoading ? '· ' + __( 'actualizando…' ) : '' }
					</span>
					<div className="itb:flex itb:items-center itb:gap-2">
						<Button variant="outline" size="icon" aria-label={ __( 'Página anterior' ) } disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
							<ChevronLeft aria-hidden />
						</Button>
						<span>
							{ page } / { totalPages }
						</span>
						<Button variant="outline" size="icon" aria-label={ __( 'Página siguiente' ) } disabled={ page >= totalPages } onClick={ () => setPage( ( p ) => p + 1 ) }>
							<ChevronRight aria-hidden />
						</Button>
					</div>
				</div>
			</Card>
		</>
	);
}
