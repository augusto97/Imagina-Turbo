import { useQuery, useQueryClient } from '@tanstack/react-query';
import { RefreshCw, RotateCcw, Send } from 'lucide-react';
import { useState } from 'react';
import { KeyValue, PageHeader } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Switch } from '@/components/ui/switch';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import { useAction } from '@/lib/queries';
import { useRouter } from '@/lib/router';
import type { FleetDrift, FleetStatus } from '@/lib/types';
import { formatDate } from '@/lib/utils';

const KEY = [ 'fleet' ];

const KIND: Record<FleetDrift[ 'kind' ], string> = {
	setting: __( 'Ajuste' ),
	module: __( 'Módulo' ),
	tweak: __( 'Ajuste rápido' ),
	cloudflare: 'Cloudflare',
};

const RISK: Record<FleetDrift[ 'risk' ], { label: string; tone: 'green' | 'amber' | 'red' }> = {
	safe: { label: __( 'Seguro' ), tone: 'green' },
	moderate: { label: __( 'Moderado' ), tone: 'amber' },
	invasive: { label: __( 'Invasivo' ), tone: 'red' },
};

function show( value: unknown ): string {
	if ( value === true ) {
		return __( 'activo' );
	}
	if ( value === false ) {
		return __( 'apagado' );
	}
	if ( value === 'apply' ) {
		return __( 'aplicar' );
	}
	if ( value === 'manual' ) {
		return __( 'revisar a mano' );
	}
	const states: Record<string, string> = { create: __( 'falta' ), update: __( 'distinta' ), drifted: __( 'cambiada a mano' ), blocked: __( 'bloqueada' ) };
	if ( typeof value === 'string' && states[ value ] ) {
		return states[ value ];
	}
	if ( value === null || value === undefined ) {
		return '—';
	}
	return typeof value === 'object' ? JSON.stringify( value ) : String( value );
}

export function Fleet() {
	const { data, isLoading, error, isFetching, refetch } = useQuery( { queryKey: KEY, queryFn: () => api<FleetStatus>( 'fleet/status' ) } );
	const client = useQueryClient();
	const sync = useAction( () => api<FleetStatus>( 'fleet/sync', 'POST' ) );
	const go = useRouter( ( s ) => s.go );
	return (
		<>
			<PageHeader
				title={ __( 'Flota' ) }
				description={ __( 'La configuración estándar que el hub propone para tus sitios: qué se desvía en este, aplicarla con un snapshot para revertir y, si lo autorizas, dejar que el hub la aplique por lotes.' ) }
				actions={
					<div className="itb:flex itb:gap-2">
						<Button variant="outline" size="sm" onClick={ () => void refetch() } disabled={ isFetching }>
							<RefreshCw className={ isFetching ? 'itb:animate-spin' : '' } aria-hidden />
							{ __( 'Actualizar' ) }
						</Button>
						{ data?.hub.registered && (
							<Button size="sm" disabled={ sync.isPending } onClick={ () => sync.mutate( undefined, { onSuccess: ( d ) => client.setQueryData( KEY, d ) } ) }>
								<Send aria-hidden />
								{ sync.isPending ? __( 'Consultando…' ) : __( 'Consultar el hub' ) }
							</Button>
						) }
					</div>
				}
			/>
			{ error && <Notice level="error">{ error.message }</Notice> }
			{ sync.error && <Notice level="error">{ sync.error.message }</Notice> }
			{ isLoading && <Skeleton className="itb:h-64" /> }
			{ data && ! data.hub.registered && (
				<Notice level="info">
					<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-3">
						<span>{ __( 'Este sitio no está registrado en el hub. La flota necesita el hub; el resto del plugin funciona igual sin él.' ) }</span>
						<Button size="sm" variant="outline" onClick={ () => go( 'avanzado' ) }>
							{ __( 'Conectar el hub' ) }
						</Button>
					</div>
				</Notice>
			) }
			{ data?.hub.registered && (
				<div className="itb:flex itb:flex-col itb:gap-4">
					<PolicyCard status={ data } />
					<RemoteCard status={ data } />
					<SnapshotsCard status={ data } />
				</div>
			) }
		</>
	);
}

function PolicyCard( { status }: { status: FleetStatus } ) {
	const client = useQueryClient();
	const [ open, setOpen ] = useState( false );
	const apply = useAction( () => api<FleetStatus>( 'fleet/apply', 'POST', { confirm: true } ) );
	const { policy, drift } = status.state;
	const moderate = drift.filter( ( d ) => d.risk !== 'safe' && d.desired !== 'manual' );
	const applicable = drift.filter( ( d ) => d.desired !== 'manual' );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ policy ? policy.name : __( 'Sin plantilla' ) }</CardTitle>
				<CardDescription>
					{ policy
						? `${ policy.id } · ${ __( 'versión' ) } ${ policy.version } · ${ __( 'recibida' ) } ${ formatDate( status.state.fetched_at, config.locale ) }`
						: __( 'El hub todavía no tiene una plantilla para este sitio.' ) }
				</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ status.state.error && <Notice level="warning">{ `${ __( 'Última consulta:' ) } ${ status.state.error }` }</Notice> }
				{ policy && policy.ignored.length > 0 && (
					<Notice level="info">{ `${ __( 'Ignorado (no permitido por plantilla o desconocido):' ) } ${ policy.ignored.join( ', ' ) }` }</Notice>
				) }
				{ status.state.applied && (
					<div className="itb:text-zinc-500">
						{ `${ __( 'Aplicada' ) }: ${ status.state.applied.id } v${ status.state.applied.version } · ${ status.state.applied.source === 'hub' ? __( 'desde el hub' ) : __( 'desde el admin' ) } · ${ formatDate( status.state.applied.at, config.locale ) }` }
					</div>
				) }
				{ status.state.cloudflare_error && <Notice level="warning">{ status.state.cloudflare_error }</Notice> }
				{ policy && drift.length === 0 && <Notice level="success">{ __( 'El sitio coincide con la plantilla.' ) }</Notice> }
				{ drift.length > 0 && (
					<div className="itb:overflow-x-auto">
						<table className="itb:w-full itb:border-collapse itb:text-left">
							<thead className="itb:text-zinc-500">
								<tr>
									<th className="itb:py-1 itb:pr-3 itb:font-normal">{ __( 'Qué' ) }</th>
									<th className="itb:py-1 itb:pr-3 itb:font-normal">{ __( 'Ahora' ) }</th>
									<th className="itb:py-1 itb:pr-3 itb:font-normal">{ __( 'Plantilla' ) }</th>
									<th className="itb:py-1 itb:font-normal">{ __( 'Riesgo' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ drift.map( ( d ) => (
									<tr key={ `${ d.kind }:${ d.key }` } className="itb:border-t itb:border-zinc-100">
										<td className="itb:py-1 itb:pr-3">
											<span className="itb:text-zinc-500">{ KIND[ d.kind ] }</span> <code className="itb:break-all">{ d.label }</code>
										</td>
										<td className="itb:py-1 itb:pr-3 itb:break-all">{ show( d.current ) }</td>
										<td className="itb:py-1 itb:pr-3 itb:break-all">{ show( d.desired ) }</td>
										<td className="itb:py-1">
											<Badge tone={ RISK[ d.risk ]?.tone ?? 'amber' }>{ RISK[ d.risk ]?.label ?? d.risk }</Badge>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
				{ apply.error && <Notice level="error">{ apply.error.message }</Notice> }
				{ apply.data?.result && (
					<Notice level={ apply.data.result.skipped.length > 0 ? 'warning' : 'success' }>
						{ `${ apply.data.result.applied.length } ${ __( 'cambios aplicados' ) }${ apply.data.result.skipped.length > 0 ? ` · ${ __( 'sin aplicar' ) }: ${ apply.data.result.skipped.map( ( s ) => `${ s.key } (${ s.reason })` ).join( '; ' ) }` : '' }` }
					</Notice>
				) }
				{ policy && applicable.length > 0 && (
					<div>
						<Button size="sm" onClick={ () => setOpen( true ) } disabled={ apply.isPending }>
							{ __( 'Aplicar la plantilla' ) }
						</Button>
					</div>
				) }
				<ConfirmDialog
					open={ open }
					onOpenChange={ setOpen }
					title={ __( '¿Aplicar la plantilla de flota?' ) }
					description={
						<>
							<p className="itb:m-0">{ `${ applicable.length } ${ __( 'diferencias.' ) } ${ __( 'Antes se guarda un snapshot de los ajustes (y Cloudflare guarda el suyo de la zona).' ) }` }</p>
							{ moderate.length > 0 && <p className="itb:mt-2 itb:mb-0">{ `${ __( 'No seguras:' ) } ${ moderate.map( ( d ) => d.label ).join( ', ' ) }` }</p> }
						</>
					}
					confirmLabel={ __( 'Aplicar' ) }
					pending={ apply.isPending }
					onConfirm={ () => apply.mutate( undefined, { onSettled: () => setOpen( false ), onSuccess: ( d ) => client.setQueryData( KEY, d ) } ) }
				/>
			</CardContent>
		</Card>
	);
}

function RemoteCard( { status }: { status: FleetStatus } ) {
	const client = useQueryClient();
	const [ open, setOpen ] = useState( false );
	const save = useAction( ( on: boolean ) => api<FleetStatus>( 'fleet/settings', 'PUT', { fleet_remote: on, confirm: true } ) );
	const set = ( on: boolean ) => save.mutate( on, { onSettled: () => setOpen( false ), onSuccess: ( d ) => client.setQueryData( KEY, d ) } );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Aplicación por lotes desde el hub' ) }</CardTitle>
				<CardDescription>{ __( 'El hub puede pedir el estado de este sitio y, si lo autorizas, aplicar la plantilla vigente en un lote de sitios (también sus reglas de Cloudflare). Las peticiones van firmadas con la clave del sitio y caducan en 5 minutos.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				<div className="itb:flex itb:items-center itb:gap-3">
					<Switch checked={ status.remote } disabled={ save.isPending } onCheckedChange={ ( on ) => ( on ? setOpen( true ) : set( false ) ) } aria-label={ __( 'Permitir que el hub aplique la plantilla' ) } />
					<span className="itb:font-medium">{ __( 'Permitir que el hub aplique la plantilla' ) }</span>
				</div>
				<KeyValue label={ __( 'Endpoint' ) }>
					<code className="itb:break-all">{ status.endpoint }</code>
				</KeyValue>
				{ save.error && <Notice level="error">{ save.error.message }</Notice> }
				<ConfirmDialog
					open={ open }
					onOpenChange={ setOpen }
					title={ __( '¿Autorizar al hub?' ) }
					description={ __( 'El hub podrá aplicar la plantilla sin que nadie la confirme aquí: ajustes, módulos seguros y moderados y reglas de Cloudflare. Nunca lo invasivo, nunca reglas cambiadas a mano en el panel de Cloudflare, siempre con snapshot y evento en el registro.' ) }
					confirmLabel={ __( 'Autorizar' ) }
					pending={ save.isPending }
					onConfirm={ () => set( true ) }
				/>
			</CardContent>
		</Card>
	);
}

function SnapshotsCard( { status }: { status: FleetStatus } ) {
	const client = useQueryClient();
	const [ id, setId ] = useState<string | null>( null );
	const revert = useAction( ( snapshot: string ) => api<FleetStatus>( `fleet/revert/${ snapshot }`, 'POST', { confirm: true } ) );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Snapshots' ) }</CardTitle>
				<CardDescription>{ __( 'Los ajustes de antes de cada aplicación (los últimos 10). Los cambios de Cloudflare se revierten con sus snapshots, en la pantalla Cloudflare.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-2 itb:text-[13px]">
				{ status.snapshots.length === 0 && <p className="itb:m-0 itb:text-zinc-500">{ __( 'Todavía no se aplicó ninguna plantilla.' ) }</p> }
				{ status.snapshots.map( ( s ) => (
					<div key={ s.id } className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-2 itb:border-t itb:border-zinc-100 itb:pt-2">
						<span>
							<code>{ s.id }</code> { `${ s.policy } · ${ s.source === 'hub' ? __( 'desde el hub' ) : __( 'desde el admin' ) } · ${ formatDate( s.created_at, config.locale ) }` }
						</span>
						<Button size="sm" variant="outline" onClick={ () => setId( s.id ) }>
							<RotateCcw aria-hidden />
							{ __( 'Revertir' ) }
						</Button>
					</div>
				) ) }
				{ revert.error && <Notice level="error">{ revert.error.message }</Notice> }
				<ConfirmDialog
					open={ id !== null }
					onOpenChange={ ( o ) => ! o && setId( null ) }
					title={ __( '¿Volver a estos ajustes?' ) }
					description={ __( 'Los ajustes, módulos y ajustes rápidos que la plantilla puede tocar vuelven al estado de ese momento.' ) }
					confirmLabel={ __( 'Revertir' ) }
					pending={ revert.isPending }
					onConfirm={ () => id && revert.mutate( id, { onSettled: () => setId( null ), onSuccess: ( d ) => client.setQueryData( KEY, d ) } ) }
				/>
			</CardContent>
		</Card>
	);
}
