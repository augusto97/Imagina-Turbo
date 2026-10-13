import { useQuery, useQueryClient } from '@tanstack/react-query';
import { FlaskConical, Play, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import { useAction } from '@/lib/queries';
import type { FilterCandidate, FilterProbe, FilterRule, FilterStatus } from '@/lib/types';
import { formatDate } from '@/lib/utils';

const STATUS: Record<FilterRule[ 'status' ], { label: string; tone: 'neutral' | 'green' | 'amber' | 'red' | 'blue' }> = {
	testing: { label: __( 'En prueba' ), tone: 'blue' },
	active: { label: __( 'Activa' ), tone: 'green' },
	failed: { label: __( 'Falló la prueba' ), tone: 'red' },
	disabled: { label: __( 'Desactivada' ), tone: 'neutral' },
};

interface Draft {
	key: string;
	plugins: string[];
	allMethods: boolean | null;
	probes: FilterProbe[];
}

export function PluginFilter() {
	const { data, isLoading, error, isFetching, refetch } = useQuery( { queryKey: [ 'plugin-filter' ], queryFn: () => api<FilterStatus>( 'plugin-filter/status' ) } );
	const [ draft, setDraft ] = useState<Draft | null>( null );
	return (
		<>
			<PageHeader
				title={ __( 'Carga condicional' ) }
				description={ __( 'Quita plugins que no hacen falta en un endpoint concreto (wc-ajax, admin-ajax, REST). Cada regla se prueba antes: el sitio se llama a sí mismo con y sin ella y solo se activa si 20 pares seguidos responden igual.' ) }
				actions={
					<Button variant="outline" size="sm" onClick={ () => void refetch() } disabled={ isFetching }>
						<RefreshCw className={ isFetching ? 'itb:animate-spin' : '' } aria-hidden />
						{ __( 'Actualizar' ) }
					</Button>
				}
			/>
			{ error && <Notice level="error">{ error.message }</Notice> }
			{ isLoading && <Skeleton className="itb:h-64" /> }
			{ data && (
				<div className="itb:flex itb:flex-col itb:gap-4">
					{ ! data.enabled && <EnableCard status={ data } /> }
					{ data.faults_pending && <Notice level="warning">{ __( 'Hubo un error fatal en un request filtrado: la regla se desactiva en el próximo request de admin.' ) }</Notice> }
					<RulesCard status={ data } />
					{ draft ? (
						<DraftCard status={ data } draft={ draft } onChange={ setDraft } onClose={ () => setDraft( null ) } />
					) : (
						<CandidatesCard
							status={ data }
							onPick={ ( c ) =>
								setDraft( {
									key: c.key,
									plugins: c.plugins.map( ( p ) => p.plugin ),
									allMethods: null,
									probes: [],
								} )
							}
							onNew={ () => setDraft( { key: '', plugins: [], allMethods: null, probes: [] } ) }
						/>
					) }
					<HowCard />
				</div>
			) }
		</>
	);
}

function EnableCard( { status }: { status: FilterStatus } ) {
	const [ open, setOpen ] = useState( false );
	const client = useQueryClient();
	const action = useAction( () => api( 'modules/plugin_filter', 'PUT', { enabled: true, confirm: true } ) );
	if ( status.forced_off ) {
		return <Notice level="warning">{ `${ __( 'Apagado: otro plugin ya filtra la lista de plugins activos y solo uno puede hacerlo.' ) } (${ status.forced_off })` }</Notice>;
	}
	return (
		<Notice level="warning">
			<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-3">
				<span>{ __( 'La carga condicional está apagada. Es invasiva: actívala para poder probar reglas. Ninguna regla se aplica sin pasar su experimento.' ) }</span>
				<Button size="sm" onClick={ () => setOpen( true ) }>
					{ __( 'Activar' ) }
				</Button>
			</div>
			{ action.error && <div className="itb:mt-2">{ action.error.message }</div> }
			<ConfirmDialog
				open={ open }
				onOpenChange={ setOpen }
				title={ __( '¿Activar la carga condicional?' ) }
				description={ __( 'Es de riesgo invasivo. Activarla no quita ningún plugin: habilita los experimentos. Las reglas que pasen se aplican solo en su endpoint, nunca con sesión iniciada, ni en el admin, el checkout o la Store API. Un error fatal en un request filtrado desactiva la regla sola.' ) }
				confirmLabel={ __( 'Activar' ) }
				pending={ action.isPending }
				onConfirm={ () =>
					action.mutate( undefined, {
						onSettled: () => setOpen( false ),
						onSuccess: () => void client.invalidateQueries( { queryKey: [ 'plugin-filter' ] } ),
					} )
				}
			/>
		</Notice>
	);
}

function pluginName( status: FilterStatus, file: string ): string {
	return status.plugins.find( ( p ) => p.file === file )?.name ?? file;
}

function RulesCard( { status }: { status: FilterStatus } ) {
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Reglas' ) }</CardTitle>
				<CardDescription>{ __( 'Cada regla dice qué plugins no se cargan en un endpoint. Las activas se revisan una vez al día con 5 pares.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ status.rules.length === 0 && <p className="itb:m-0 itb:text-zinc-500">{ __( 'Todavía no hay reglas. Empieza por un candidato.' ) }</p> }
				{ status.rules.map( ( r ) => (
					<RuleRow key={ r.id } rule={ r } status={ status } />
				) ) }
			</CardContent>
		</Card>
	);
}

function RuleRow( { rule, status }: { rule: FilterRule; status: FilterStatus } ) {
	const client = useQueryClient();
	const [ running, setRunning ] = useState( false );
	const [ runError, setRunError ] = useState( '' );
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const ev = rule.evidence;
	const update = ( r: FilterRule ) =>
		client.setQueryData<FilterStatus>( [ 'plugin-filter' ], ( old ) => ( old ? { ...old, rules: old.rules.map( ( x ) => ( x.id === r.id ? r : x ) ) } : old ) );
	const run = async ( recheck: boolean ) => {
		setRunning( true );
		setRunError( '' );
		try {
			let current = rule;
			// Cada paso tiene un presupuesto de tiempo: se repite hasta terminar.
			for ( let i = 0; i < 20; i++ ) {
				current = await api<FilterRule>( `plugin-filter/rules/${ rule.id }/${ recheck ? 'recheck' : 'step' }`, 'POST' );
				update( current );
				if ( recheck || current.status !== 'testing' ) {
					break;
				}
			}
		} catch ( e ) {
			setRunError( e instanceof Error ? e.message : String( e ) );
		} finally {
			setRunning( false );
			void client.invalidateQueries( { queryKey: [ 'plugin-filter' ] } );
			void client.invalidateQueries( { queryKey: [ 'events' ] } );
		}
	};
	const disable = useAction( () => api<FilterStatus>( `plugin-filter/rules/${ rule.id }/disable`, 'POST' ) );
	const remove = useAction( () => api<FilterStatus>( `plugin-filter/rules/${ rule.id }`, 'DELETE' ) );
	const saving = ev.baseline.n > 0 ? { ms: ev.baseline.ms - ev.filtered.ms, kb: ev.baseline.kb - ev.filtered.kb } : null;
	return (
		<div className="itb:rounded-md itb:border itb:border-zinc-200 itb:p-3">
			<div className="itb:flex itb:flex-wrap itb:items-start itb:justify-between itb:gap-2">
				<div className="itb:min-w-0">
					<span className="itb:flex itb:flex-wrap itb:items-center itb:gap-1.5">
						<span className="itb:font-medium">{ rule.label }</span>
						<Badge tone={ STATUS[ rule.status ].tone }>{ STATUS[ rule.status ].label }</Badge>
						{ rule.all_methods ? <Badge>{ __( 'Todos los métodos' ) }</Badge> : <Badge>{ __( 'Solo GET' ) }</Badge> }
					</span>
					<code className="itb:block itb:text-xs itb:text-zinc-500">{ rule.key }</code>
				</div>
				<div className="itb:flex itb:flex-wrap itb:gap-2">
					{ rule.status === 'testing' && (
						<Button size="sm" disabled={ running || ! status.enabled } onClick={ () => void run( false ) }>
							<Play aria-hidden />
							{ running ? __( 'Probando…' ) : __( 'Continuar la prueba' ) }
						</Button>
					) }
					{ rule.status === 'active' && (
						<Button size="sm" variant="outline" disabled={ running || ! status.enabled } onClick={ () => void run( true ) }>
							<FlaskConical aria-hidden />
							{ running ? __( 'Revisando…' ) : __( 'Revisar ahora' ) }
						</Button>
					) }
					{ ( rule.status === 'active' || rule.status === 'testing' ) && (
						<Button size="sm" variant="outline" disabled={ disable.isPending } onClick={ () => disable.mutate( undefined, { onSuccess: ( s ) => client.setQueryData( [ 'plugin-filter' ], s ) } ) }>
							{ __( 'Desactivar' ) }
						</Button>
					) }
					<Button size="sm" variant="outline" aria-label={ __( 'Eliminar' ) } onClick={ () => setConfirmDelete( true ) }>
						<Trash2 aria-hidden />
					</Button>
				</div>
			</div>
			<p className="itb:m-0 itb:mt-2 itb:text-xs">
				<span className="itb:text-zinc-500">{ __( 'No se cargan:' ) } </span>
				{ rule.plugins.map( ( p ) => pluginName( status, p ) ).join( ', ' ) }
			</p>
			<div className="itb:mt-2 itb:flex itb:flex-wrap itb:items-center itb:gap-x-4 itb:gap-y-1 itb:text-xs itb:text-zinc-600">
				<span>{ `${ __( 'Pares iguales' ) }: ${ ev.pairs } / ${ ev.required }` }</span>
				{ saving && <span>{ `${ __( 'Tiempo' ) }: ${ ev.baseline.ms } → ${ ev.filtered.ms } ms` }</span> }
				{ saving && <span>{ `${ __( 'Memoria' ) }: ${ Math.round( ev.baseline.kb / 102.4 ) / 10 } → ${ Math.round( ev.filtered.kb / 102.4 ) / 10 } MB` }</span> }
				{ Object.values( ev.modes ).includes( 'structural' ) && <span>{ __( 'Comparación estructural (la respuesta varía entre llamadas)' ) }</span> }
				{ rule.checked_at > 0 && <span>{ `${ __( 'Revisada' ) }: ${ formatDate( rule.checked_at, config.locale ) }` }</span> }
			</div>
			{ rule.status === 'testing' && (
				<div className="itb:mt-2 itb:h-1.5 itb:overflow-hidden itb:rounded-full itb:bg-zinc-100" role="progressbar" aria-valuenow={ ev.pairs } aria-valuemax={ ev.required } aria-label={ __( 'Progreso del experimento' ) }>
					<div className="itb:h-full itb:bg-zinc-900" style={ { width: `${ Math.min( 100, ( 100 * ev.pairs ) / Math.max( 1, ev.required ) ) }%` } } />
				</div>
			) }
			{ rule.reason && <Notice level={ rule.status === 'failed' ? 'error' : 'info' } className="itb:mt-2">{ rule.reason }</Notice> }
			{ ev.mismatch && ev.mismatch.field !== 'request' && (
				<details className="itb:mt-2 itb:text-xs">
					<summary className="itb:cursor-pointer itb:text-zinc-600">{ `${ __( 'Diferencia en' ) } ${ ev.mismatch.field } (${ rule.probes[ ev.mismatch.probe ]?.path ?? '' })` }</summary>
					<div className="itb:mt-1 itb:grid itb:gap-2 itb:md:grid-cols-2">
						<pre className="itb:m-0 itb:overflow-auto itb:whitespace-pre-wrap itb:break-all itb:rounded itb:bg-zinc-50 itb:p-2 itb:text-[11px]">{ `${ __( 'Con todos los plugins' ) }\n${ ev.mismatch.baseline }` }</pre>
						<pre className="itb:m-0 itb:overflow-auto itb:whitespace-pre-wrap itb:break-all itb:rounded itb:bg-zinc-50 itb:p-2 itb:text-[11px]">{ `${ __( 'Con la regla' ) }\n${ ev.mismatch.filtered }` }</pre>
					</div>
				</details>
			) }
			{ rule.inactive.length > 0 && <Notice level="info" className="itb:mt-2">{ `${ __( 'Ya no están activos (no se aplican):' ) } ${ rule.inactive.join( ', ' ) }` }</Notice> }
			{ runError && <Notice level="error" className="itb:mt-2">{ runError }</Notice> }
			{ ( disable.error || remove.error ) && <Notice level="error" className="itb:mt-2">{ ( disable.error ?? remove.error )?.message }</Notice> }
			<ConfirmDialog
				open={ confirmDelete }
				onOpenChange={ setConfirmDelete }
				title={ __( '¿Eliminar la regla?' ) }
				description={ __( 'Los plugins vuelven a cargarse en ese endpoint. Su evidencia se pierde; queda el registro.' ) }
				confirmLabel={ __( 'Eliminar' ) }
				pending={ remove.isPending }
				onConfirm={ () =>
					remove.mutate( undefined, {
						onSettled: () => setConfirmDelete( false ),
						onSuccess: ( s ) => client.setQueryData( [ 'plugin-filter' ], s ),
					} )
				}
			/>
		</div>
	);
}

function CandidatesCard( { status, onPick, onNew }: { status: FilterStatus; onPick: ( c: FilterCandidate ) => void; onNew: () => void } ) {
	return (
		<Card>
			<CardHeader>
				<div className="itb:flex itb:flex-wrap itb:items-start itb:justify-between itb:gap-3">
					<div>
						<CardTitle>{ __( 'Candidatos' ) }</CardTitle>
						<CardDescription>{ __( 'Endpoints con muestras del perfilador (48 h) y los plugins que más cuestan en ellos. Es una propuesta: revisa la lista antes de probar.' ) }</CardDescription>
					</div>
					<Button size="sm" variant="outline" disabled={ ! status.enabled } onClick={ onNew }>
						<Plus aria-hidden />
						{ __( 'Regla a mano' ) }
					</Button>
				</div>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-2 itb:text-[13px]">
				{ status.candidates.length === 0 && <p className="itb:m-0 itb:text-zinc-500">{ __( 'Sin candidatos: el perfilador aún no tiene muestras de wc-ajax, admin-ajax o REST. El muestreo intensivo (Diagnóstico) acelera esto.' ) }</p> }
				{ status.candidates.map( ( c ) => (
					<div key={ c.key } className="itb:flex itb:flex-wrap itb:items-start itb:justify-between itb:gap-3 itb:border-b itb:border-zinc-100 itb:py-2 itb:last:border-b-0">
						<div className="itb:min-w-0 itb:flex-1">
							<span className="itb:font-medium">{ c.label }</span>
							<span className="itb:block itb:text-xs itb:text-zinc-500">
								{ c.samples > 0 ? `${ c.samples } ${ __( 'muestras' ) } · ${ c.ms_avg } ms · ${ c.mem_avg_mb } MB` : __( 'Sin muestras todavía' ) }
								{ c.saving_ms > 0 && ` · ${ __( 'ahorro estimado' ) } ${ c.saving_ms } ms` }
							</span>
							{ c.blocked ? (
								<span className="itb:block itb:text-xs itb:text-amber-700">{ c.blocked }</span>
							) : (
								<span className="itb:block itb:text-xs">{ c.plugins.length > 0 ? c.plugins.map( ( p ) => pluginName( status, p.plugin ) ).join( ', ' ) : __( 'Ningún plugin cuesta lo suficiente aquí.' ) }</span>
							) }
						</div>
						<Button size="sm" variant="outline" disabled={ ! status.enabled || c.blocked !== '' || c.plugins.length === 0 } onClick={ () => onPick( c ) }>
							<FlaskConical aria-hidden />
							{ __( 'Probar' ) }
						</Button>
					</div>
				) ) }
			</CardContent>
		</Card>
	);
}

function DraftCard( { status, draft, onChange, onClose }: { status: FilterStatus; draft: Draft; onChange: ( d: Draft ) => void; onClose: () => void } ) {
	const client = useQueryClient();
	const create = useAction( () =>
		api<FilterRule>( 'plugin-filter/rules', 'POST', {
			key: draft.key.trim(),
			plugins: draft.plugins,
			...( draft.allMethods === null ? {} : { all_methods: draft.allMethods } ),
			...( draft.probes.length > 0 ? { probes: draft.probes } : {} ),
		} )
	);
	const toggle = ( file: string ) => onChange( { ...draft, plugins: draft.plugins.includes( file ) ? draft.plugins.filter( ( p ) => p !== file ) : [ ...draft.plugins, file ] } );
	const writes = draft.allMethods ?? ( draft.key.startsWith( 'WcAjax' ) || draft.key.startsWith( 'AdminAjax' ) );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Nueva regla' ) }</CardTitle>
				<CardDescription>{ __( 'Se guarda en prueba y se ejecuta el experimento. Si las respuestas cambian, la regla no se activa y verás la diferencia.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				<label className="itb:flex itb:flex-col itb:gap-1">
					<span className="itb:font-medium">{ __( 'Endpoint' ) }</span>
					<Input value={ draft.key } onChange={ ( e ) => onChange( { ...draft, key: e.target.value } ) } placeholder="WcAjax:get_refreshed_fragments · AdminAjax:accion · Rest:namespace/v1" aria-label={ __( 'Endpoint' ) } />
				</label>
				<fieldset className="itb:m-0 itb:border-0 itb:p-0">
					<legend className="itb:mb-1 itb:font-medium">{ __( 'Plugins que no se cargan' ) }</legend>
					<div className="itb:grid itb:gap-1 itb:sm:grid-cols-2">
						{ status.plugins
							.filter( ( p ) => p.file !== status.self )
							.map( ( p ) => (
								<label key={ p.file } className="itb:flex itb:items-center itb:gap-2">
									<input type="checkbox" checked={ draft.plugins.includes( p.file ) } onChange={ () => toggle( p.file ) } />
									<span className="itb:truncate">{ p.name }</span>
								</label>
							) ) }
					</div>
				</fieldset>
				<label className="itb:flex itb:items-center itb:justify-between itb:gap-3">
					<span>
						<span className="itb:font-medium">{ __( 'También en POST' ) }</span>
						<span className="itb:block itb:text-xs itb:text-zinc-500">{ __( 'En una petición que escribe, los plugins quitados no ejecutan sus efectos: analítica del servidor, sincronización, CRM, correos. El experimento no los ve.' ) }</span>
					</span>
					<Switch checked={ writes } onCheckedChange={ ( v ) => onChange( { ...draft, allMethods: v } ) } aria-label={ __( 'También en POST' ) } />
				</label>
				<ProbesEditor probes={ draft.probes } onChange={ ( probes ) => onChange( { ...draft, probes } ) } />
				<div className="itb:flex itb:flex-wrap itb:gap-2">
					<Button
						size="sm"
						disabled={ create.isPending || draft.key.trim() === '' || draft.plugins.length === 0 }
						onClick={ () =>
							create.mutate( undefined, {
								onSuccess: () => {
									void client.invalidateQueries( { queryKey: [ 'plugin-filter' ] } );
									onClose();
								},
							} )
						}
					>
						<FlaskConical aria-hidden />
						{ create.isPending ? __( 'Creando…' ) : __( 'Crear y probar' ) }
					</Button>
					<Button size="sm" variant="outline" onClick={ onClose }>
						{ __( 'Cancelar' ) }
					</Button>
				</div>
				{ create.error && <Notice level="error">{ create.error.message }</Notice> }
				<p className="itb:m-0 itb:text-xs itb:text-zinc-500">{ __( 'Después de crearla, pulsa «Continuar la prueba» en la regla.' ) }</p>
			</CardContent>
		</Card>
	);
}

function ProbesEditor( { probes, onChange }: { probes: FilterProbe[]; onChange: ( p: FilterProbe[] ) => void } ) {
	const set = ( i: number, patch: Partial<FilterProbe> ) => onChange( probes.map( ( p, j ) => ( j === i ? { ...p, ...patch } : p ) ) );
	return (
		<div className="itb:flex itb:flex-col itb:gap-2">
			<span className="itb:font-medium">{ __( 'Peticiones de prueba' ) }</span>
			<span className="itb:text-xs itb:text-zinc-500">{ __( 'Vacío = las de por defecto para ese endpoint. Rutas del propio sitio; en POST, el cuerpo como formulario (a=1&b=2).' ) }</span>
			{ probes.map( ( p, i ) => (
				<div key={ i } className="itb:flex itb:flex-col itb:gap-1 itb:rounded-md itb:border itb:border-zinc-200 itb:p-2">
					<div className="itb:flex itb:gap-2">
						<Select className="itb:w-24" value={ p.method } onChange={ ( e ) => set( i, { method: e.target.value as FilterProbe[ 'method' ] } ) } aria-label={ __( 'Método' ) }>
							<option>GET</option>
							<option>POST</option>
						</Select>
						<Input value={ p.path } onChange={ ( e ) => set( i, { path: e.target.value } ) } placeholder="/wp-json/mi/v1/ruta?x=1" aria-label={ __( 'Ruta' ) } />
						<Button size="sm" variant="outline" aria-label={ __( 'Quitar' ) } onClick={ () => onChange( probes.filter( ( _, j ) => j !== i ) ) }>
							<Trash2 aria-hidden />
						</Button>
					</div>
					{ p.method === 'POST' && <Textarea className="itb:min-h-12" value={ p.body } onChange={ ( e ) => set( i, { body: e.target.value } ) } placeholder="action=mi_accion&id=1" aria-label={ __( 'Cuerpo' ) } /> }
				</div>
			) ) }
			{ probes.length < 5 && (
				<div>
					<Button size="sm" variant="outline" onClick={ () => onChange( [ ...probes, { method: 'GET', path: '/', body: '' } ] ) }>
						<Plus aria-hidden />
						{ __( 'Agregar petición' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

function HowCard() {
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Protecciones' ) }</CardTitle>
			</CardHeader>
			<CardContent className="itb:text-[13px]">
				<ul className="itb:m-0 itb:list-disc itb:pl-5 itb:text-zinc-600">
					<li>{ __( 'Nunca se filtra con sesión iniciada, en el admin, login, cron, checkout, cuenta, la Store API, wp/v2 ni wc-ajax de checkout.' ) }</li>
					<li>{ __( 'Si un plugin guarda la lista de activos durante un request filtrado (Freemius lo hace), los plugins ocultos se reinsertan: nunca quedan desactivados.' ) }</li>
					<li>{ __( 'Las reglas de reescritura no se regeneran mientras falten plugins; si están vacías, ese request no se filtra.' ) }</li>
					<li>{ __( 'Un error fatal en un request filtrado desactiva la regla y cuenta para el modo seguro.' ) }</li>
					<li>{ __( 'Solo un plugin puede filtrar la lista de activos: con Perfmatters o Plugin Organizer activos, el módulo se apaga.' ) }</li>
				</ul>
			</CardContent>
		</Card>
	);
}
