import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Database as DatabaseIcon, RefreshCw, RotateCcw } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { KeyValue, PageHeader } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Input, Select } from '@/components/ui/input';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import { useAction } from '@/lib/queries';
import type { DbAudit, DbStatus } from '@/lib/types';
import { formatBytes, formatDate } from '@/lib/utils';

const KEY = [ 'database' ];

type Action = 'transients' | 'action_scheduler' | 'revisions' | 'autoload';

const ACTION_LABELS: Record<Action, string> = {
	transients: __( 'Transients expirados' ),
	action_scheduler: __( 'Action Scheduler antiguo' ),
	revisions: __( 'Revisiones sobrantes' ),
	autoload: __( 'Autoload de plugins inactivos' ),
};

export function Database() {
	const { data, isLoading, error, isFetching, refetch } = useQuery( { queryKey: KEY, queryFn: () => api<DbStatus>( 'db/status' ) } );
	const client = useQueryClient();
	const audit = useAction( () => api<DbStatus>( 'db/audit', 'POST' ) );
	const runAudit = () => audit.mutate( undefined, { onSuccess: ( d ) => client.setQueryData( KEY, d ) } );
	return (
		<>
			<PageHeader
				title={ __( 'Base de datos' ) }
				description={ __( 'Auditoría de lo que pesa en cada request y limpiezas con respaldo restaurable. Nunca se borran tablas.' ) }
				actions={
					<div className="itb:flex itb:gap-2">
						<Button variant="outline" size="sm" onClick={ () => void refetch() } disabled={ isFetching }>
							<RefreshCw className={ isFetching ? 'itb:animate-spin' : '' } aria-hidden />
							{ __( 'Actualizar' ) }
						</Button>
						<Button size="sm" onClick={ runAudit } disabled={ audit.isPending }>
							<DatabaseIcon aria-hidden />
							{ audit.isPending ? __( 'Auditando…' ) : __( 'Auditar ahora' ) }
						</Button>
					</div>
				}
			/>
			{ error && <Notice level="error">{ error.message }</Notice> }
			{ audit.error && <Notice level="error">{ audit.error.message }</Notice> }
			{ isLoading && <Skeleton className="itb:h-64" /> }
			{ data && (
				<div className="itb:flex itb:flex-col itb:gap-4">
					{ ! data.enabled && <EnableCard /> }
					{ ! data.audit && <Notice level="info">{ __( 'Todavía no hay auditoría. Pulsa "Auditar ahora" (solo lee).' ) }</Notice> }
					{ data.audit && <AuditCards status={ data } audit={ data.audit } /> }
					<BackupsCard status={ data } />
					<ScheduleCard status={ data } />
				</div>
			) }
		</>
	);
}

function EnableCard() {
	const [ open, setOpen ] = useState( false );
	const client = useQueryClient();
	const action = useAction( () => api( 'modules/database', 'PUT', { enabled: true, confirm: true } ) );
	return (
		<Notice level="warning">
			<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-3">
				<span>{ __( 'Las acciones están apagadas: la auditoría funciona igual. Actívalas para limpiar con respaldo.' ) }</span>
				<Button size="sm" onClick={ () => setOpen( true ) }>
					{ __( 'Activar' ) }
				</Button>
			</div>
			{ action.error && <div className="itb:mt-2">{ action.error.message }</div> }
			<ConfirmDialog
				open={ open }
				onOpenChange={ setOpen }
				title={ __( '¿Activar las acciones de base de datos?' ) }
				description={ __( 'Es de riesgo moderado. Cada acción pide confirmación, guarda antes las filas en un respaldo restaurable durante 30 días y deja un evento en el registro.' ) }
				confirmLabel={ __( 'Activar' ) }
				pending={ action.isPending }
				onConfirm={ () =>
					action.mutate( undefined, {
						onSettled: () => setOpen( false ),
						onSuccess: () => void client.invalidateQueries( { queryKey: KEY } ),
					} )
				}
			/>
		</Notice>
	);
}

function ActionButton( { action, disabled, options, label, onDone }: { action: Action; disabled: boolean; options?: string[]; label?: string; onDone?: () => void } ) {
	const client = useQueryClient();
	const [ open, setOpen ] = useState( false );
	const run = useAction( () => api<DbStatus>( `db/actions/${ action }`, 'POST', { confirm: true, options } ) );
	const result = run.data?.result;
	return (
		<div className="itb:flex itb:flex-col itb:gap-1">
			<div>
				<Button size="sm" variant="outline" disabled={ disabled || run.isPending } onClick={ () => setOpen( true ) }>
					{ label ?? __( 'Limpiar' ) }
				</Button>
			</div>
			{ run.error && <Notice level="error">{ run.error.message }</Notice> }
			{ result && (
				<div className="itb:text-zinc-500">
					{ `${ result.affected } ${ __( 'filas' ) }${ result.batch ? ` · ${ __( 'lote' ) } ${ result.batch }` : '' }${ result.more ? ` · ${ __( 'quedan más: vuelve a ejecutarla' ) }` : '' }` }
				</div>
			) }
			<ConfirmDialog
				open={ open }
				onOpenChange={ setOpen }
				title={ ACTION_LABELS[ action ] }
				description={ __( 'Las filas afectadas se guardan antes en un respaldo que puedes restaurar desde esta pantalla durante 30 días.' ) }
				confirmLabel={ __( 'Ejecutar' ) }
				pending={ run.isPending }
				onConfirm={ () =>
					run.mutate( undefined, {
						onSettled: () => setOpen( false ),
						onSuccess: ( d ) => {
							client.setQueryData( KEY, d );
							onDone?.();
						},
					} )
				}
			/>
		</div>
	);
}

function Section( { title, description, children }: { title: string; description?: string; children: ReactNode } ) {
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ title }</CardTitle>
				{ description && <CardDescription>{ description }</CardDescription> }
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">{ children }</CardContent>
		</Card>
	);
}

const OWNER: Record<DbAudit[ 'autoload' ][ 'top' ][ number ][ 'owner' ], { label: string; tone: 'neutral' | 'green' | 'amber' | 'blue' }> = {
	core: { label: __( 'Núcleo' ), tone: 'neutral' },
	active: { label: __( 'Plugin activo' ), tone: 'green' },
	inactive: { label: __( 'Plugin inactivo' ), tone: 'amber' },
	unknown: { label: __( 'Desconocido' ), tone: 'blue' },
};

function AuditCards( { status, audit }: { status: DbStatus; audit: DbAudit } ) {
	const number = new Intl.NumberFormat( config.locale );
	const [ selected, setSelected ] = useState<string[]>( [] );
	const autoloaded = audit.inactive_options.items.filter( ( o ) => o.autoload );
	const off = ! status.enabled;
	return (
		<>
			<div className="itb:text-[13px] itb:text-zinc-500">{ `${ __( 'Auditoría del' ) } ${ formatDate( audit.generated_at, config.locale ) }` }</div>
			<Section title={ __( 'Autoload' ) } description={ __( 'Opciones que WordPress lee en cada request. Por encima de ~800 KB se nota en el TTFB.' ) }>
				<div className="itb:flex itb:flex-wrap itb:gap-6">
					<KeyValue label={ __( 'Tamaño' ) }>{ formatBytes( audit.autoload.bytes, config.locale ) }</KeyValue>
					<KeyValue label={ __( 'Opciones' ) }>{ number.format( audit.autoload.count ) }</KeyValue>
				</div>
				<div className="itb:overflow-x-auto">
					<table className="itb:w-full itb:border-collapse itb:text-left">
						<tbody>
							{ audit.autoload.top.map( ( o ) => (
								<tr key={ o.name } className="itb:border-t itb:border-zinc-100">
									<td className="itb:py-1 itb:pr-3">
										<code className="itb:break-all">{ o.name }</code>
									</td>
									<td className="itb:py-1 itb:pr-3">
										<Badge tone={ OWNER[ o.owner ].tone }>{ OWNER[ o.owner ].label }</Badge> { o.plugin }
									</td>
									<td className="itb:py-1 itb:text-right itb:tabular-nums">{ formatBytes( o.bytes, config.locale ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			</Section>
			<Section title={ __( 'Opciones de plugins inactivos' ) } description={ __( 'Atribuidas por el nombre de la carpeta del plugin. Quitarlas del autoload no las borra: si vuelves a activar el plugin, las lee igual.' ) }>
				<div className="itb:flex itb:flex-wrap itb:gap-6">
					<KeyValue label={ __( 'Opciones' ) }>{ number.format( audit.inactive_options.count ) }</KeyValue>
					<KeyValue label={ __( 'Con autoload' ) }>{ number.format( audit.inactive_options.autoloaded ) }</KeyValue>
					<KeyValue label={ __( 'Tamaño' ) }>{ formatBytes( audit.inactive_options.bytes, config.locale ) }</KeyValue>
				</div>
				{ autoloaded.length > 0 && (
					<>
						<div className="itb:flex itb:flex-col itb:gap-1">
							{ autoloaded.map( ( o ) => (
								<label key={ o.name } className="itb:flex itb:items-center itb:gap-2">
									<input type="checkbox" checked={ selected.includes( o.name ) } onChange={ () => setSelected( selected.includes( o.name ) ? selected.filter( ( n ) => n !== o.name ) : [ ...selected, o.name ] ) } />
									<code className="itb:break-all">{ o.name }</code>
									<span className="itb:text-zinc-500">{ `${ o.plugin } · ${ formatBytes( o.bytes, config.locale ) }` }</span>
								</label>
							) ) }
						</div>
						<ActionButton action="autoload" disabled={ off || selected.length === 0 } options={ selected } label={ __( 'Quitar del autoload' ) } onDone={ () => setSelected( [] ) } />
					</>
				) }
			</Section>
			<div className="itb:grid itb:gap-4 itb:md:grid-cols-3">
				<Section title={ ACTION_LABELS.transients }>
					<KeyValue label={ __( 'En wp_options' ) }>{ number.format( audit.transients.expired ) }</KeyValue>
					{ audit.transients.object_cache && <div className="itb:text-zinc-500">{ __( 'Hay caché de objetos: los transients nuevos no van a la base; estos son restos.' ) }</div> }
					<ActionButton action="transients" disabled={ off || audit.transients.expired === 0 } />
				</Section>
				<Section title={ ACTION_LABELS.action_scheduler }>
					{ audit.action_scheduler.available ? (
						<>
							<KeyValue label={ __( 'Terminadas hace más de 30 días' ) }>{ number.format( audit.action_scheduler.old ) }</KeyValue>
							<div className="itb:text-zinc-500">{ `${ __( 'Completadas' ) } ${ audit.action_scheduler.complete } · ${ __( 'fallidas' ) } ${ audit.action_scheduler.failed } · ${ __( 'canceladas' ) } ${ audit.action_scheduler.canceled } · ${ __( 'pendientes' ) } ${ audit.action_scheduler.pending } · ${ __( 'registros' ) } ${ audit.action_scheduler.logs }` }</div>
							<ActionButton action="action_scheduler" disabled={ off || audit.action_scheduler.old === 0 } />
						</>
					) : (
						<div className="itb:text-zinc-500">{ __( 'No hay tablas de Action Scheduler.' ) }</div>
					) }
				</Section>
				<Section title={ ACTION_LABELS.revisions }>
					<KeyValue label={ __( 'Revisiones' ) }>{ number.format( audit.revisions.total ) }</KeyValue>
					<div className="itb:text-zinc-500">{ `${ number.format( audit.revisions.excess ) } ${ __( 'sobran si se conservan' ) } ${ status.keep } ${ __( 'por contenido' ) } (${ audit.revisions.posts } ${ __( 'contenidos' ) })` }</div>
					<ActionButton action="revisions" disabled={ off || audit.revisions.excess === 0 } />
				</Section>
			</div>
			<Section title={ __( 'Otros hallazgos' ) } description={ __( 'Solo información: estas cosas no se cambian desde aquí.' ) }>
				<KeyValue label={ __( 'postmeta huérfano' ) }>{ number.format( audit.orphan_postmeta ) }</KeyValue>
				{ audit.hpos.woo && (
					<KeyValue label="HPOS">
						{ audit.hpos.enabled ? ( audit.hpos.sync ? __( 'Activo, con sincronización' ) : __( 'Activo' ) ) : __( 'Inactivo' ) }
					</KeyValue>
				) }
				{ audit.hpos.recommendation && <Notice level="info">{ audit.hpos.recommendation }</Notice> }
				{ ! audit.hpos.enabled && ( audit.hpos.incompatible ?? [] ).length > 0 && (
					<Notice level="warning">{ `${ __( 'Estos plugins activos declaran no ser compatibles con HPOS; WooCommerce no deja activarlo mientras sigan activos:' ) } ${ ( audit.hpos.incompatible ?? [] ).join( ', ' ) }` }</Notice>
				) }
				{ audit.hpos.woo && ! audit.hpos.enabled && audit.hpos.incompatible !== undefined && audit.hpos.incompatible.length === 0 && (
					<div className="itb:text-zinc-500">{ __( 'Ningún plugin activo declara incompatibilidad con HPOS. Actívalo con la sincronización encendida, revisa pedidos nuevos unos días y después apaga la sincronización.' ) }</div>
				) }
				<KeyValue label={ __( 'Índices de postmeta' ) }>{ `${ number.format( audit.postmeta_index.rows ) } ${ __( 'filas' ) } · ${ audit.postmeta_index.indexes.join( ' · ' ) }` }</KeyValue>
				{ audit.postmeta_index.recommendation && <Notice level="info">{ audit.postmeta_index.recommendation }</Notice> }
				{ audit.tables.some( ( t ) => t.owner === 'inactive' || t.owner === 'removed' ) && (
					<Notice level="info">
						{ __( 'Hay tablas de plugins inactivos o desinstalados. No frenan el sitio (nadie las consulta), pero ocupan espacio y alargan las copias de seguridad. Bórralas desde el propio plugin (opción de borrar datos al desinstalar) o con un respaldo previo desde phpMyAdmin. Imagina Turbo no borra tablas.' ) }
					</Notice>
				) }
				<div className="itb:overflow-x-auto">
					<table className="itb:w-full itb:border-collapse itb:text-left">
						<thead className="itb:text-zinc-500">
							<tr>
								<th className="itb:py-1 itb:pr-3 itb:font-normal">{ __( 'Tabla' ) }</th>
								<th className="itb:py-1 itb:pr-3 itb:font-normal">{ __( 'De' ) }</th>
								<th className="itb:py-1 itb:pr-3 itb:text-right itb:font-normal">{ __( 'Filas (aprox.)' ) }</th>
								<th className="itb:py-1 itb:text-right itb:font-normal">{ __( 'Tamaño' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ audit.tables.map( ( t ) => (
								<tr key={ t.name } className="itb:border-t itb:border-zinc-100">
									<td className="itb:py-1 itb:pr-3">
										<code className="itb:break-all">{ t.name }</code>
									</td>
									<td className="itb:py-1 itb:pr-3">
										{ t.owner === 'core' && <span className="itb:text-zinc-500">WordPress</span> }
										{ t.owner === 'active' && <span className="itb:text-zinc-500">{ t.plugin }</span> }
										{ t.owner === 'inactive' && <Badge tone="amber">{ `${ t.plugin } · ${ __( 'inactivo' ) }` }</Badge> }
										{ t.owner === 'removed' && <Badge tone="amber">{ `${ t.plugin } · ${ __( 'desinstalado' ) }` }</Badge> }
									</td>
									<td className="itb:py-1 itb:pr-3 itb:text-right itb:tabular-nums">{ number.format( t.rows ) }</td>
									<td className="itb:py-1 itb:text-right itb:tabular-nums">{ formatBytes( t.bytes, config.locale ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			</Section>
		</>
	);
}

function BackupsCard( { status }: { status: DbStatus } ) {
	const client = useQueryClient();
	const [ batch, setBatch ] = useState<string | null>( null );
	const restore = useAction( ( id: string ) => api<DbStatus>( `db/restore/${ id }`, 'POST', { confirm: true } ) );
	return (
		<Section title={ __( 'Respaldos' ) } description={ __( 'Cada acción guarda las filas que tocó. Se conservan 30 días.' ) }>
			{ status.batches.length === 0 && <p className="itb:m-0 itb:text-zinc-500">{ __( 'Sin respaldos.' ) }</p> }
			{ status.batches.map( ( b ) => (
				<div key={ b.batch } className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-2 itb:border-t itb:border-zinc-100 itb:pt-2">
					<span>
						<code>{ b.batch }</code> { ACTION_LABELS[ b.action as Action ] ?? b.action } · { b.rows } { __( 'filas' ) } · { formatDate( b.created_at, config.locale ) }
					</span>
					{ b.restored ? (
						<Badge tone="neutral">{ __( 'Restaurado' ) }</Badge>
					) : (
						<Button size="sm" variant="outline" onClick={ () => setBatch( b.batch ) }>
							<RotateCcw aria-hidden />
							{ __( 'Restaurar' ) }
						</Button>
					) }
				</div>
			) ) }
			{ restore.error && <Notice level="error">{ restore.error.message }</Notice> }
			{ restore.data?.restored && <Notice level="success">{ `${ restore.data.restored.restored } ${ __( 'filas restauradas' ) }, ${ restore.data.restored.skipped } ${ __( 'ya existían' ) }` }</Notice> }
			<ConfirmDialog
				open={ batch !== null }
				onOpenChange={ ( o ) => ! o && setBatch( null ) }
				title={ __( '¿Restaurar el lote?' ) }
				description={ __( 'Las filas borradas vuelven a insertarse (las que ya existan se dejan como están) y los valores cambiados vuelven al anterior.' ) }
				confirmLabel={ __( 'Restaurar' ) }
				pending={ restore.isPending }
				onConfirm={ () => batch && restore.mutate( batch, { onSuccess: ( d ) => client.setQueryData( KEY, d ), onSettled: () => setBatch( null ) } ) }
			/>
		</Section>
	);
}

function ScheduleCard( { status }: { status: DbStatus } ) {
	const client = useQueryClient();
	const [ values, setValues ] = useState( status.settings );
	useEffect( () => setValues( status.settings ), [ status.settings ] );
	const save = useAction( () => api<DbStatus>( 'db/settings', 'PUT', values ) );
	const toggle = ( a: string ) => setValues( { ...values, db_schedule: values.db_schedule.includes( a ) ? values.db_schedule.filter( ( x ) => x !== a ) : [ ...values.db_schedule, a ] } );
	return (
		<Section title={ __( 'Limpieza programada' ) } description={ __( 'Opcional. Corre las acciones elegidas cada N días con el mismo respaldo. El autoload siempre es manual.' ) }>
			<div className="itb:flex itb:flex-wrap itb:gap-x-4 itb:gap-y-1">
				{ ( [ 'transients', 'action_scheduler', 'revisions' ] as const ).map( ( a ) => (
					<label key={ a } className="itb:flex itb:items-center itb:gap-1">
						<input type="checkbox" checked={ values.db_schedule.includes( a ) } onChange={ () => toggle( a ) } />
						{ ACTION_LABELS[ a ] }
					</label>
				) ) }
			</div>
			<div className="itb:grid itb:gap-3 itb:md:grid-cols-3">
				<label className="itb:flex itb:flex-col itb:gap-1">
					<span className="itb:font-medium">{ __( 'Cada cuántos días' ) }</span>
					<Input type="number" min={ 1 } max={ 90 } value={ values.db_schedule_days } onChange={ ( e ) => setValues( { ...values, db_schedule_days: Number( e.target.value ) } ) } />
				</label>
				<label className="itb:flex itb:flex-col itb:gap-1">
					<span className="itb:font-medium">{ __( 'Historial de Action Scheduler' ) }</span>
					<Select value={ String( values.db_as_retention ) } onChange={ ( e ) => setValues( { ...values, db_as_retention: Number( e.target.value ) } ) }>
						<option value="0">{ __( 'El de Action Scheduler (31 días)' ) }</option>
						<option value="14">{ __( '14 días' ) }</option>
						<option value="7">{ __( '7 días' ) }</option>
						<option value="3">{ __( '3 días' ) }</option>
					</Select>
					<span className="itb:text-zinc-500">{ __( 'Cuánto guarda el propio limpiador de Action Scheduler de las tareas terminadas y sus registros. Las pendientes no se tocan. Requiere el módulo activo.' ) }</span>
				</label>
				<label className="itb:flex itb:flex-col itb:gap-1">
					<span className="itb:font-medium">{ __( 'Revisiones a conservar por contenido' ) }</span>
					<Input type="number" min={ 1 } max={ 100 } value={ values.db_keep_revisions } onChange={ ( e ) => setValues( { ...values, db_keep_revisions: Number( e.target.value ) } ) } />
					<span className="itb:text-zinc-500">{ __( 'Si "Limitar revisiones" está activo en Ajustes rápidos, manda ese valor.' ) }</span>
				</label>
			</div>
			{ ! status.enabled && values.db_schedule.length > 0 && <Notice level="warning">{ __( 'No correrá mientras el módulo esté apagado.' ) }</Notice> }
			{ status.last_run > 0 && <div className="itb:text-zinc-500">{ `${ __( 'Última ejecución' ) }: ${ formatDate( status.last_run, config.locale ) }` }</div> }
			{ save.error && <Notice level="error">{ save.error.message }</Notice> }
			<div>
				<Button size="sm" disabled={ save.isPending } onClick={ () => save.mutate( undefined, { onSuccess: ( d ) => client.setQueryData( KEY, d ) } ) }>
					{ __( 'Guardar' ) }
				</Button>
			</div>
		</Section>
	);
}
