import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Flame, Play, RefreshCw, Square } from 'lucide-react';
import { useEffect, useState } from 'react';
import { KeyValue, PageHeader } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Select } from '@/components/ui/input';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import { useAction } from '@/lib/queries';
import { useRouter } from '@/lib/router';
import type { AuditReport, Period, PerVisitReport, PhaseCost, PlatformCheck, PlatformReport, PluginCost, ProfilerSummary, RouteCost, Sampling, WorkersReport } from '@/lib/types';
import { cn, formatDate } from '@/lib/utils';

const PERIODS: { value: Period; label: string }[] = [
	{ value: '1h', label: __( 'Última hora' ) },
	{ value: '24h', label: __( 'Últimas 24 h' ) },
	{ value: '48h', label: __( 'Últimas 48 h' ) },
	{ value: '7d', label: __( 'Últimos 7 días' ) },
	{ value: '30d', label: __( 'Últimos 30 días' ) },
];

const TYPES: Record<string, string> = {
	FrontendHtml: __( 'Páginas' ),
	AdminAjax: 'admin-ajax',
	WcAjax: 'wc-ajax',
	Rest: 'REST',
	StoreApi: 'Store API',
	Admin: __( 'Admin' ),
	Cron: 'Cron',
	Login: __( 'Login' ),
	XmlRpc: 'XML-RPC',
	Feed: 'Feed',
	Sitemap: 'Sitemap',
	Robots: 'robots.txt',
	StaticMiss: __( 'Estáticos inexistentes' ),
	Cart: __( 'Carrito' ),
	Checkout: __( 'Checkout' ),
	Account: __( 'Mi cuenta' ),
	Other: __( 'Otros' ),
};

const PHASES: Record<string, string> = {
	loader: __( 'Arranque y núcleo' ),
	muplugins_loaded: __( 'Plugins MU' ),
	plugins_loaded: __( 'Carga de plugins' ),
	after_setup_theme: __( 'Tema' ),
	init: 'init',
	wp_loaded: 'wp_loaded',
	admin_init: 'admin_init',
	template_redirect: __( 'Consulta principal' ),
	shutdown: __( 'Render y respuesta' ),
};

const RATES = [ 0, 0.001, 0.005, 0.01, 0.02, 0.05, 0.1 ];

const number = new Intl.NumberFormat( config.locale, { maximumFractionDigits: 1 } );
const ms = ( value: number ) => `${ number.format( value ) } ms`;
const pct = ( value: number ) => `${ number.format( value ) } %`;

function componentLabel( component: string ): { name: string; kind: string } {
	const [ first, ...rest ] = component.split( ':' );
	const kind = first ?? '';
	const name = rest.join( ':' ) || component;
	const kinds: Record<string, string> = { plugin: __( 'Plugin' ), mu: 'MU', theme: __( 'Tema' ), core: 'WordPress', other: __( 'Otro' ) };
	return { name: kind === 'core' ? __( 'Núcleo de WordPress' ) : kind === 'other' ? __( 'Sin atribuir' ) : name, kind: kinds[ kind ] ?? kind };
}

export function Diagnostics() {
	const [ period, setPeriod ] = useState<Period>( '24h' );
	const [ type, setType ] = useState( '' );
	const client = useQueryClient();
	const query = { period, type };

	const summary = useQuery( { queryKey: [ 'profiler', 'summary', period, type ], queryFn: () => api<ProfilerSummary>( 'profiler/summary', 'GET', undefined, query ) } );
	const plugins = useQuery( { queryKey: [ 'profiler', 'plugins', period, type ], queryFn: () => api<{ samples: number; items: PluginCost[] }>( 'profiler/plugins', 'GET', undefined, query ) } );
	const routes = useQuery( { queryKey: [ 'profiler', 'routes', period, type ], queryFn: () => api<{ source: string; items: RouteCost[] }>( 'profiler/routes', 'GET', undefined, query ) } );
	const fetching = summary.isFetching || plugins.isFetching || routes.isFetching;

	return (
		<>
			<PageHeader
				title={ __( 'Diagnóstico' ) }
				description={ __( 'Cuánto cuesta cada plugin y cada fase del request, medido con muestras reales del sitio.' ) }
				actions={
					<>
						<Select className="itb:w-40" value={ period } onChange={ ( e ) => setPeriod( e.target.value as Period ) } aria-label={ __( 'Periodo' ) }>
							{ PERIODS.map( ( p ) => (
								<option key={ p.value } value={ p.value }>
									{ p.label }
								</option>
							) ) }
						</Select>
						<Select className="itb:w-44" value={ type } onChange={ ( e ) => setType( e.target.value ) } aria-label={ __( 'Tipo de request' ) }>
							<option value="">{ __( 'Todos los requests' ) }</option>
							{ Object.entries( TYPES ).map( ( [ value, label ] ) => (
								<option key={ value } value={ value }>
									{ label }
								</option>
							) ) }
						</Select>
						<Button variant="outline" size="sm" className="itb:h-9" disabled={ fetching } onClick={ () => void client.invalidateQueries( { queryKey: [ 'profiler' ] } ) }>
							<RefreshCw className={ fetching ? 'itb:animate-spin' : '' } aria-hidden />
							{ __( 'Actualizar' ) }
						</Button>
					</>
				}
			/>
			{ summary.error && <Notice level="error">{ summary.error.message }</Notice> }
			{ summary.isLoading && <Skeleton className="itb:h-64" /> }
			{ summary.data && (
				<div className="itb:flex itb:flex-col itb:gap-6">
					<SamplingCard data={ summary.data } />
					{ summary.data.summary.samples === 0 ? (
						<Notice level="info">
							{ __( 'Todavía no hay muestras en este periodo. Con poco tráfico, activa el muestreo intensivo durante 15 minutos y navega el sitio; las muestras de tu cookie de diagnóstico no cuentan aquí porque las de un administrador sesgan el resultado.' ) }
						</Notice>
					) : (
						<>
							<Kpis data={ summary.data } />
							<PluginsCard items={ plugins.data?.items } loading={ plugins.isLoading } type={ type } />
							<div className="itb:grid itb:gap-4 itb:xl:grid-cols-2">
								<PhasesCard phases={ summary.data.phases } mixed={ type === '' } />
								<TypesCard byType={ summary.data.summary.by_type } total={ summary.data.summary.samples } />
							</div>
							<RoutesCard items={ routes.data?.items } source={ routes.data?.source } loading={ routes.isLoading } />
							<PerVisitCard />
							<WorkersCard />
						</>
					) }
					<PlatformCard />
					<div className="itb:grid itb:gap-4 itb:xl:grid-cols-2">
						<AuditCard />
						<PassiveCard data={ summary.data } />
					</div>
					{ summary.data.latest_diag && <LatestDiagCard diag={ summary.data.latest_diag } /> }
				</div>
			) }
		</>
	);
}

function useNow( intervalMs: number ): number {
	const [ now, setNow ] = useState( () => Math.floor( Date.now() / 1000 ) );
	useEffect( () => {
		const id = window.setInterval( () => setNow( Math.floor( Date.now() / 1000 ) ), intervalMs );
		return () => window.clearInterval( id );
	}, [ intervalMs ] );
	return now;
}

function SamplingCard( { data }: { data: ProfilerSummary } ) {
	const client = useQueryClient();
	const go = useRouter( ( state ) => state.go );
	const now = useNow( 15_000 );
	const refresh = () => void client.invalidateQueries( { queryKey: [ 'profiler' ] } );
	const rate = useAction( ( sample_rate: number ) => api<Sampling>( 'profiler/settings', 'PUT', { sample_rate } ) );
	const intensive = useAction( ( enabled: boolean ) => api<Sampling>( 'profiler/intensive', 'POST', { enabled } ) );
	const sampling = data.sampling;
	const remaining = Math.max( 0, sampling.intensive_until - now );
	const sources = data.sources;

	return (
		<Card>
			<CardHeader>
				<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-2">
					<CardTitle>{ __( 'Muestreo' ) }</CardTitle>
					{ ! sampling.enabled ? (
						<Badge tone="amber">{ __( 'Perfilador desactivado' ) }</Badge>
					) : remaining > 0 ? (
						<Badge tone="blue">
							<Flame className="itb:size-3" aria-hidden />
							{ __( 'Intensivo' ) } · { Math.ceil( remaining / 60 ) } min
						</Badge>
					) : (
						<Badge tone="green">{ __( 'Activo' ) }</Badge>
					) }
				</div>
				<CardDescription>
					{ __( 'Solo los requests elegidos al azar se miden y se guardan; el resto cuesta un número aleatorio. Las muestras crudas viven 48 h y los agregados por hora, 30 días.' ) }
				</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-4">
				<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
					<label className="itb:text-[13px] itb:text-zinc-600" htmlFor="itb-rate">
						{ __( 'Requests muestreados' ) }
					</label>
					<Select
						id="itb-rate"
						className="itb:w-28"
						value={ String( sampling.rate ) }
						disabled={ ! sampling.enabled || rate.isPending }
						onChange={ ( e ) => rate.mutate( Number( e.target.value ), { onSuccess: refresh } ) }
					>
						{ ( RATES.includes( sampling.rate ) ? RATES : [ ...RATES, sampling.rate ].sort( ( a, b ) => a - b ) ).map( ( r ) => (
							<option key={ r } value={ String( r ) }>
								{ r === 0 ? __( 'Ninguno' ) : pct( r * 100 ) }
							</option>
						) ) }
					</Select>
					{ remaining > 0 ? (
						<Button variant="outline" size="sm" className="itb:h-9" disabled={ intensive.isPending } onClick={ () => intensive.mutate( false, { onSuccess: refresh } ) }>
							<Square aria-hidden />
							{ __( 'Detener intensivo' ) }
						</Button>
					) : (
						<Button variant="outline" size="sm" className="itb:h-9" disabled={ ! sampling.enabled || intensive.isPending } onClick={ () => intensive.mutate( true, { onSuccess: refresh } ) }>
							<Flame aria-hidden />
							{ __( 'Muestreo intensivo 15 min' ) }
						</Button>
					) }
				</div>
				{ ! sampling.enabled && (
					<Notice level="warning">
						{ __( 'El perfilador está apagado (por ajuste, solapamiento, kill switch o modo seguro). Revisa los módulos en el ' ) }
						<a href="#/panel" onClick={ ( e ) => {
								e.preventDefault();
								go( 'panel' );
							} } className="itb:underline">
							{ __( 'Panel' ) }
						</a>
						.
					</Notice>
				) }
				<dl className="itb:m-0 itb:grid itb:gap-x-6 itb:sm:grid-cols-2 itb:xl:grid-cols-4">
					<KeyValue label={ __( 'Muestras al azar' ) }>{ sources.random }</KeyValue>
					<KeyValue label={ __( 'Muestras intensivas' ) }>{ sources.intensive }</KeyValue>
					<KeyValue label={ __( 'Con cookie de diagnóstico' ) }>{ sources.diag }</KeyValue>
					<KeyValue label={ __( 'Último agregado horario' ) }>{ formatDate( data.rollup.last_hour ? Date.parse( data.rollup.last_hour ) / 1000 : null, config.locale ) }</KeyValue>
				</dl>
				{ ( rate.error || intensive.error ) && <Notice level="error">{ ( rate.error ?? intensive.error )?.message }</Notice> }
			</CardContent>
		</Card>
	);
}

function Kpis( { data }: { data: ProfilerSummary } ) {
	const s = data.summary;
	const items = [
		{ label: __( 'Tiempo PHP p50' ), value: ms( s.ms_p50 ), hint: `p95 ${ ms( s.ms_p95 ) }` },
		{ label: __( 'Memoria pico media' ), value: `${ number.format( s.mem_avg_mb ) } MB`, hint: __( 'Por request' ) },
		{ label: __( 'Consultas medias' ), value: number.format( s.queries_avg ), hint: __( 'A la base de datos' ) },
		{ label: __( 'Cacheables' ), value: pct( s.cacheable_pct ), hint: `${ __( 'Por Cloudflare' ) }: ${ pct( s.via_cf_pct ) }` },
		{ label: __( 'Costo del perfilador' ), value: `${ number.format( s.overhead_us_avg / 1000 ) } ms`, hint: __( 'Medio, en requests muestreados' ) },
	];
	return (
		<div className="itb:grid itb:gap-4 itb:sm:grid-cols-2 itb:xl:grid-cols-5">
			{ items.map( ( item ) => (
				<Card key={ item.label }>
					<CardContent className="itb:pt-5">
						<div className="itb:text-xs itb:font-medium itb:text-zinc-500">{ item.label }</div>
						<div className="itb:mt-2 itb:text-lg itb:font-semibold itb:tracking-tight itb:tabular-nums">{ item.value }</div>
						<div className="itb:mt-1 itb:text-xs itb:text-zinc-500">{ item.hint }</div>
					</CardContent>
				</Card>
			) ) }
			<p className="itb:text-xs itb:text-zinc-500 itb:sm:col-span-2 itb:xl:col-span-5">
				{ __( 'Fuente: muestras del origen (PHP)' ) } · { data.summary.samples } { __( 'requests' ) } · { __( 'últimas' ) } { data.sample_hours } h
			</p>
		</div>
	);
}

function PluginsCard( { items, loading, type }: { items?: PluginCost[]; loading: boolean; type: string } ) {
	const [ all, setAll ] = useState( false );
	const rows = ( items ?? [] ).filter( ( item ) => item.total_ms >= 0.05 );
	const shown = all ? rows : rows.slice( 0, 15 );
	const max = Math.max( 0.001, ...rows.map( ( r ) => r.total_ms ) );

	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Costo por plugin' ) }</CardTitle>
				<CardDescription>
					{ __( 'Milisegundos medios por request: carga del plugin más sus funciones en los hooks principales (init, wp, wp_head…). Los requests donde el plugin no aparece cuentan como cero.' ) }
					{ type === '' && ' ' + __( 'Filtra por tipo de request para ver dónde pesa cada uno.' ) }
				</CardDescription>
			</CardHeader>
			<CardContent>
				{ loading && <Skeleton className="itb:h-40" /> }
				{ ! loading && rows.length === 0 && <Notice level="info">{ __( 'Sin datos de plugins en este periodo.' ) }</Notice> }
				{ rows.length > 0 && (
					<>
						<div className="itb:mb-3 itb:flex itb:gap-4 itb:text-xs itb:text-zinc-500" aria-hidden>
							<span className="itb:flex itb:items-center itb:gap-1.5">
								<span className="itb:inline-block itb:size-2.5 itb:rounded-sm itb:bg-sky-700" />
								{ __( 'Carga' ) }
							</span>
							<span className="itb:flex itb:items-center itb:gap-1.5">
								<span className="itb:inline-block itb:size-2.5 itb:rounded-sm itb:bg-sky-300" />
								{ __( 'Hooks' ) }
							</span>
						</div>
						<div className="itb:overflow-x-auto">
							<table className="itb:w-full itb:min-w-[640px] itb:border-collapse itb:text-[13px]">
								<thead>
									<tr className="itb:border-b itb:border-zinc-200 itb:text-left itb:text-xs itb:text-zinc-500">
										<th className="itb:py-2 itb:pr-3 itb:font-medium">{ __( 'Componente' ) }</th>
										<th className="itb:w-[38%] itb:py-2 itb:pr-3 itb:font-medium">{ __( 'Tiempo' ) }</th>
										<th className="itb:py-2 itb:pr-3 itb:text-right itb:font-medium">{ __( '% del request' ) }</th>
										<th className="itb:py-2 itb:pr-3 itb:text-right itb:font-medium">{ __( 'Memoria' ) }</th>
										<th className="itb:py-2 itb:text-right itb:font-medium">{ __( 'Presente en' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ shown.map( ( row ) => {
										const label = componentLabel( row.component );
										return (
											<tr key={ row.component } className="itb:border-b itb:border-zinc-100 itb:last:border-b-0">
												<td className="itb:py-2 itb:pr-3">
													<div className="itb:flex itb:items-center itb:gap-2">
														<span className="itb:font-medium itb:text-zinc-900">{ label.name }</span>
														<span className="itb:text-[11px] itb:text-zinc-400">{ label.kind }</span>
													</div>
												</td>
												<td className="itb:py-2 itb:pr-3">
													<div className="itb:flex itb:items-center itb:gap-2">
														<div className="itb:flex itb:h-2.5 itb:flex-1 itb:overflow-hidden itb:rounded-sm itb:bg-zinc-100" title={ `${ __( 'Carga' ) } ${ ms( row.load_ms ) } · ${ __( 'Hooks' ) } ${ ms( row.hook_ms ) }` }>
															<div className="itb:h-full itb:bg-sky-700" style={ { width: `${ ( row.load_ms / max ) * 100 }%` } } />
															<div className="itb:h-full itb:bg-sky-300" style={ { width: `${ ( row.hook_ms / max ) * 100 }%` } } />
														</div>
														<span className="itb:w-16 itb:text-right itb:tabular-nums">{ ms( row.total_ms ) }</span>
													</div>
												</td>
												<td className="itb:py-2 itb:pr-3 itb:text-right itb:tabular-nums">{ pct( row.share_pct ) }</td>
												<td className="itb:py-2 itb:pr-3 itb:text-right itb:tabular-nums">{ row.kb >= 1024 ? `${ number.format( row.kb / 1024 ) } MB` : `${ number.format( row.kb ) } KB` }</td>
												<td className="itb:py-2 itb:text-right itb:tabular-nums">{ pct( row.seen_pct ) }</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>
						{ rows.length > 15 && (
							<Button variant="ghost" size="sm" className="itb:mt-2" onClick={ () => setAll( ! all ) }>
								{ all ? __( 'Ver menos' ) : `${ __( 'Ver los' ) } ${ rows.length }` }
							</Button>
						) }
					</>
				) }
			</CardContent>
		</Card>
	);
}

function PhasesCard( { phases, mixed }: { phases: PhaseCost[]; mixed: boolean } ) {
	const total = Math.max( 0.001, phases.reduce( ( sum, p ) => sum + p.ms, 0 ) );
	let start = 0;
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Fases del request' ) }</CardTitle>
				<CardDescription>
					{ __( 'Duración media de cada etapa, en orden. Cada fase termina cuando WordPress dispara su hook.' ) }
					{ mixed && ' ' + __( 'Mezcla tipos de request: filtra por uno para leerla mejor.' ) }
				</CardDescription>
			</CardHeader>
			<CardContent>
				<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-1.5 itb:p-0">
					{ phases.map( ( phase ) => {
						const left = ( start / total ) * 100;
						const width = Math.max( 0.5, ( phase.ms / total ) * 100 );
						start += phase.ms;
						return (
							<li key={ phase.phase } className="itb:grid itb:grid-cols-[minmax(0,10rem)_1fr_4.5rem] itb:items-center itb:gap-3 itb:text-[13px]">
								<span className="itb:truncate itb:text-zinc-600" title={ phase.phase }>
									{ PHASES[ phase.phase ] ?? phase.phase }
								</span>
								<div className="itb:relative itb:h-2.5 itb:rounded-sm itb:bg-zinc-100">
									<div className="itb:absolute itb:inset-y-0 itb:rounded-sm itb:bg-sky-600" style={ { left: `${ left }%`, width: `${ Math.min( width, 100 - left ) }%` } } />
								</div>
								<span className="itb:text-right itb:tabular-nums">{ ms( phase.ms ) }</span>
							</li>
						);
					} ) }
				</ul>
				<p className="itb:mt-3 itb:text-xs itb:text-zinc-500">
					{ __( 'Total medio' ) }: { ms( total ) }
				</p>
			</CardContent>
		</Card>
	);
}

function TypesCard( { byType, total }: { byType: Record<string, number>; total: number } ) {
	const entries = Object.entries( byType );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Requests por tipo' ) }</CardTitle>
				<CardDescription>{ __( 'Cómo se reparten las muestras. AJAX y REST suelen ser la mitad del trabajo de PHP en una tienda.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-1.5 itb:p-0">
					{ entries.map( ( [ name, count ] ) => (
						<li key={ name } className="itb:grid itb:grid-cols-[minmax(0,10rem)_1fr_4.5rem] itb:items-center itb:gap-3 itb:text-[13px]">
							<span className="itb:truncate itb:text-zinc-600">{ TYPES[ name ] ?? name }</span>
							<div className="itb:h-2.5 itb:rounded-sm itb:bg-zinc-100">
								<div className="itb:h-full itb:rounded-sm itb:bg-sky-600" style={ { width: `${ ( count / total ) * 100 }%` } } />
							</div>
							<span className="itb:text-right itb:tabular-nums">{ pct( ( 100 * count ) / total ) }</span>
						</li>
					) ) }
				</ul>
			</CardContent>
		</Card>
	);
}

function QueriesSection( { q }: { q: NonNullable<NonNullable<ProfilerSummary[ 'latest_diag' ]>[ 'db_queries' ]> } ) {
	const sqlRow = ( sql: string, right: string, component: string, key: string ) => (
		<li key={ key } className="itb:flex itb:flex-col itb:gap-0.5 itb:border-t itb:border-zinc-100 itb:pt-1">
			<code className="itb:break-all itb:text-[11px]">{ sql }</code>
			<span className="itb:text-xs itb:text-zinc-500">{ `${ componentLabel( component ).name } · ${ right }` }</span>
		</li>
	);
	return (
		<div className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px] itb:lg:col-span-2">
			<div className="itb:font-medium">{ `${ __( 'Consultas a la base de datos' ) }: ${ q.count } · ${ ms( q.ms ) } · ${ formatDate( q.at, config.locale ) }` }</div>
			<div className="itb:text-xs itb:text-zinc-500">{ __( 'Solo de tus requests con la cookie de diagnóstico. Los valores se reemplazan por «?»: no se guarda ningún dato.' ) }</div>
			<ul className="itb:m-0 itb:flex itb:list-none itb:flex-wrap itb:gap-x-4 itb:gap-y-1 itb:p-0">
				{ q.components.slice( 0, 8 ).map( ( c ) => (
					<li key={ c.component }>{ `${ componentLabel( c.component ).name }: ${ c.count } · ${ ms( c.ms ) }` }</li>
				) ) }
			</ul>
			{ q.slow.length > 0 && (
				<div>
					<div className="itb:mb-1 itb:font-medium">{ __( 'Lentas (50 ms o más)' ) }</div>
					<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-1 itb:p-0">{ q.slow.map( ( s, i ) => sqlRow( s.sql, ms( s.ms ), s.component, `s${ i }` ) ) }</ul>
				</div>
			) }
			{ q.repeated.length > 0 && (
				<div>
					<div className="itb:mb-1 itb:font-medium">{ __( 'Repetidas (la misma consulta con distintos valores: suele ser un bucle que pregunta una por una)' ) }</div>
					<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-1 itb:p-0">{ q.repeated.map( ( r, i ) => sqlRow( r.sql, `${ r.count } × · ${ ms( r.ms ) }`, r.component, `r${ i }` ) ) }</ul>
				</div>
			) }
		</div>
	);
}

function RoutesCard( { items, source, loading }: { items?: RouteCost[]; source?: string; loading: boolean } ) {
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Rutas y endpoints más pesados' ) }</CardTitle>
				<CardDescription>
					{ __( 'Ordenados por tiempo total consumido (mediana × requests). Las páginas se agrupan por plantilla, nunca por URL.' ) }{ ' ' }
					{ source === 'hourly' ? __( 'Fuente: agregados horarios.' ) : __( 'Fuente: muestras crudas.' ) }
				</CardDescription>
			</CardHeader>
			<CardContent>
				{ loading && <Skeleton className="itb:h-32" /> }
				{ ! loading && ( items ?? [] ).length === 0 && (
					<Notice level="info">{ source === 'hourly' ? __( 'Aún no hay agregados horarios: se generan cada hora a partir de las muestras.' ) : __( 'Sin datos en este periodo.' ) }</Notice>
				) }
				{ ( items ?? [] ).length > 0 && (
					<div className="itb:overflow-x-auto">
						<table className="itb:w-full itb:min-w-[640px] itb:border-collapse itb:text-[13px]">
							<thead>
								<tr className="itb:border-b itb:border-zinc-200 itb:text-left itb:text-xs itb:text-zinc-500">
									<th className="itb:py-2 itb:pr-3 itb:font-medium">{ __( 'Tipo' ) }</th>
									<th className="itb:py-2 itb:pr-3 itb:font-medium">{ __( 'Ruta' ) }</th>
									<th className="itb:py-2 itb:pr-3 itb:text-right itb:font-medium">{ __( 'Muestras' ) }</th>
									<th className="itb:py-2 itb:pr-3 itb:text-right itb:font-medium">p50</th>
									<th className="itb:py-2 itb:pr-3 itb:text-right itb:font-medium">p95</th>
									<th className="itb:py-2 itb:pr-3 itb:text-right itb:font-medium">{ __( 'Memoria p95' ) }</th>
									<th className="itb:py-2 itb:text-right itb:font-medium">{ __( 'Consultas' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ ( items ?? [] ).slice( 0, 30 ).map( ( row ) => (
									<tr key={ row.request_type + row.route_key } className="itb:border-b itb:border-zinc-100 itb:last:border-b-0">
										<td className="itb:py-2 itb:pr-3">
											<Badge>{ TYPES[ row.request_type ] ?? row.request_type }</Badge>
										</td>
										<td className="itb:max-w-xs itb:truncate itb:py-2 itb:pr-3 itb:font-mono itb:text-xs" title={ row.route_key }>
											{ row.route_key }
										</td>
										<td className="itb:py-2 itb:pr-3 itb:text-right itb:tabular-nums">{ row.n }</td>
										<td className="itb:py-2 itb:pr-3 itb:text-right itb:tabular-nums">{ ms( row.ms_p50 ) }</td>
										<td className={ cn( 'itb:py-2 itb:pr-3 itb:text-right itb:tabular-nums', row.ms_p95 > 1000 && 'itb:font-semibold itb:text-red-700' ) }>{ ms( row.ms_p95 ) }</td>
										<td className="itb:py-2 itb:pr-3 itb:text-right itb:tabular-nums">{ number.format( row.mem_p95 / 1024 ) } MB</td>
										<td className="itb:py-2 itb:text-right itb:tabular-nums">{ row.queries_avg }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</CardContent>
		</Card>
	);
}

function PerVisitCard() {
	const report = useQuery( { queryKey: [ 'profiler', 'per-visit' ], queryFn: () => api<PerVisitReport>( 'profiler/per-visit' ) } );
	const data = report.data;
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Peticiones de los visitantes después de cargar la página' ) }</CardTitle>
				<CardDescription>
					{ __( 'AJAX, REST y Store API que piden los visitantes sin sesión. La página puede estar en caché, pero estas peticiones ejecutan PHP con todos los plugins cada vez. Frecuencia y costo estimados con el muestreo de las últimas 48 h.' ) }
				</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3">
				{ report.isLoading && <Skeleton className="itb:h-24" /> }
				{ report.error && <Notice level="error">{ report.error.message }</Notice> }
				{ data && data.views_per_day === null && (
					<Notice level="info">{ __( 'Sin datos suficientes del RUM para estimar las páginas vistas: se muestra el costo por día, no la proporción por visita.' ) }</Notice>
				) }
				{ data && data.items.length === 0 && <Notice level="info">{ __( 'Sin peticiones de visitantes con suficientes muestras. Con poco tráfico, sube el muestreo o espera más horas.' ) }</Notice> }
				{ data && data.items.length > 0 && (
					<div className="itb:overflow-x-auto">
						<table className="itb:w-full itb:min-w-[640px] itb:border-collapse itb:text-[13px]">
							<thead>
								<tr className="itb:border-b itb:border-zinc-200 itb:text-left itb:text-xs itb:text-zinc-500">
									<th className="itb:py-2 itb:pr-3 itb:font-medium">{ __( 'Petición' ) }</th>
									<th className="itb:py-2 itb:pr-3 itb:text-right itb:font-medium">{ __( 'Por día' ) }</th>
									<th className="itb:py-2 itb:pr-3 itb:text-right itb:font-medium">{ __( 'Por visita' ) }</th>
									<th className="itb:py-2 itb:pr-3 itb:text-right itb:font-medium">{ __( 'Media' ) }</th>
									<th className="itb:py-2 itb:text-right itb:font-medium">{ __( 'CPU por día' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ data.items.map( ( row ) => (
									<tr key={ row.request_type + row.route_key } className="itb:border-b itb:border-zinc-100 itb:align-top itb:last:border-b-0">
										<td className="itb:py-2 itb:pr-3">
											<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
												<Badge>{ TYPES[ row.request_type ] ?? row.request_type }</Badge>
												<span className="itb:font-mono itb:text-xs">{ row.route_key || '—' }</span>
												{ row.per_visit && <Badge tone="amber">{ __( 'en cada visita' ) }</Badge> }
												{ row.rule === 'active' && <Badge tone="green">{ __( 'con regla de carga condicional' ) }</Badge> }
											</div>
											<p className="itb:mt-1 itb:max-w-xl itb:text-xs itb:text-zinc-500">{ row.hint }</p>
										</td>
										<td className="itb:py-2 itb:pr-3 itb:text-right itb:tabular-nums">~{ number.format( row.per_day ) }</td>
										<td className="itb:py-2 itb:pr-3 itb:text-right itb:tabular-nums">{ row.per_view === null ? '—' : number.format( row.per_view ) }</td>
										<td className="itb:py-2 itb:pr-3 itb:text-right itb:tabular-nums">{ ms( row.ms_avg ) }</td>
										<td className="itb:py-2 itb:text-right itb:tabular-nums">{ duration( row.cpu_s_day ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</CardContent>
		</Card>
	);
}

function WorkersCard() {
	const report = useQuery( { queryKey: [ 'profiler', 'workers' ], queryFn: () => api<WorkersReport>( 'profiler/workers' ) } );
	const data = report.data;
	const near = data && data.children !== null && data.peak >= data.children * 0.7;
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Trabajadores PHP ocupados' ) }</CardTitle>
				<CardDescription>
					{ __( 'Cuántos procesos PHP están trabajando a la vez, en promedio y en la hora más cargada (48 h, estimado con el muestreo). Si se acerca al número de procesos del servidor, las peticiones hacen cola y aparecen errores 502/503 aunque cada una sea rápida.' ) }
				</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ report.isLoading && <Skeleton className="itb:h-20" /> }
				{ report.error && <Notice level="error">{ report.error.message }</Notice> }
				{ data && data.top.length === 0 && <Notice level="info">{ __( 'Sin muestras aleatorias en las últimas 48 h.' ) }</Notice> }
				{ data && data.top.length > 0 && (
					<>
						<div className="itb:flex itb:flex-wrap itb:gap-6">
							<KeyValue label={ __( 'Promedio' ) }>{ number.format( data.avg ) }</KeyValue>
							<KeyValue label={ __( 'Hora pico' ) }>{ `${ number.format( data.peak ) } (${ data.peak_hour }:00 UTC)` }</KeyValue>
							{ data.children !== null && <KeyValue label={ __( 'Procesos del servidor' ) }>{ data.children }</KeyValue> }
						</div>
						{ near && <Notice level="warning">{ __( 'En la hora pico se usa más del 70 % de los procesos PHP: reduce lo que ocupa más tiempo abajo o pide más procesos al servidor.' ) }</Notice> }
						<table className="itb:w-full itb:border-collapse itb:text-left">
							<thead className="itb:text-xs itb:text-zinc-500">
								<tr>
									<th className="itb:py-1 itb:pr-3 itb:font-medium">{ __( 'Qué los ocupa' ) }</th>
									<th className="itb:py-1 itb:pr-3 itb:text-right itb:font-medium">{ __( 'Del tiempo' ) }</th>
									<th className="itb:py-1 itb:pr-3 itb:text-right itb:font-medium">p95</th>
									<th className="itb:py-1 itb:text-right itb:font-medium">{ __( 'Más de 5 s' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ data.top.map( ( row ) => (
									<tr key={ row.request_type + row.route_key } className="itb:border-t itb:border-zinc-100">
										<td className="itb:py-1 itb:pr-3">
											<Badge>{ TYPES[ row.request_type ] ?? row.request_type }</Badge> <span className="itb:font-mono itb:text-xs">{ row.route_key }</span>
										</td>
										<td className="itb:py-1 itb:pr-3 itb:text-right itb:tabular-nums">{ number.format( row.share_pct ) } %</td>
										<td className="itb:py-1 itb:pr-3 itb:text-right itb:tabular-nums">{ ms( row.ms_p95 ) }</td>
										<td className={ cn( 'itb:py-1 itb:text-right itb:tabular-nums', row.slow > 0 && 'itb:font-semibold itb:text-red-700' ) }>{ row.slow }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</>
				) }
			</CardContent>
		</Card>
	);
}

function PlatformCard() {
	const report = useQuery( { queryKey: [ 'platform' ], queryFn: () => api<PlatformReport>( 'diagnostics/platform' ) } );
	const groups: [ string, PlatformCheck[] | null | undefined ][] = [
		[ 'Elementor', report.data?.elementor ],
		[ 'WoodMart', report.data?.woodmart ],
		[ 'WooCommerce', report.data?.woocommerce ],
		[ __( 'Servidor y base de datos' ), report.data?.server ],
	];
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Ajustes de la plataforma' ) }</CardTitle>
				<CardDescription>{ __( 'Ajustes de Elementor, WoodMart, WooCommerce y el servidor que cambian la velocidad. Solo recomendaciones: Imagina Turbo no los cambia.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-4 itb:text-[13px]">
				{ report.isLoading && <Skeleton className="itb:h-24" /> }
				{ report.error && <Notice level="error">{ report.error.message }</Notice> }
				{ groups
					.filter( ( [ , checks ] ) => checks && checks.length > 0 )
					.map( ( [ title, checks ] ) => (
						<div key={ title } className="itb:flex itb:flex-col itb:gap-2">
							<div className="itb:text-xs itb:font-medium itb:uppercase itb:text-zinc-500">{ title }</div>
							{ ( checks ?? [] ).map( ( c ) => (
								<div key={ c.id } className="itb:flex itb:items-start itb:gap-2">
									<Badge tone={ c.status === 'ok' ? 'green' : c.status === 'warn' ? 'amber' : 'neutral' }>{ c.status === 'ok' ? __( 'Bien' ) : c.status === 'warn' ? __( 'Revisar' ) : __( 'Info' ) }</Badge>
									<span>
										<span className="itb:font-medium">{ c.label }</span>
										{ c.advice && <span className="itb:block itb:text-xs itb:text-zinc-500">{ c.advice }</span> }
									</span>
								</div>
							) ) }
						</div>
					) ) }
			</CardContent>
		</Card>
	);
}

/** Segundos de CPU por día, legibles. */
function duration( seconds: number ): string {
	if ( seconds >= 3600 ) {
		return `${ number.format( seconds / 3600 ) } h`;
	}
	if ( seconds >= 60 ) {
		return `${ number.format( seconds / 60 ) } min`;
	}
	return `${ seconds } s`;
}

function AuditCard() {
	const client = useQueryClient();
	const last = useQuery( { queryKey: [ 'audit' ], queryFn: () => api<AuditReport | null>( 'diagnostics/audit' ) } );
	const run = useAction( () => api<AuditReport>( 'diagnostics/audit', 'POST' ) );
	const report = last.data;

	return (
		<Card>
			<CardHeader>
				<div className="itb:flex itb:items-center itb:justify-between itb:gap-2">
					<CardTitle>{ __( 'Auditoría de cacheabilidad' ) }</CardTitle>
					<Button size="sm" variant="outline" disabled={ run.isPending } onClick={ () => run.mutate( undefined, { onSuccess: ( data ) => client.setQueryData( [ 'audit' ], data ) } ) }>
						<Play aria-hidden />
						{ run.isPending ? __( 'Revisando…' ) : __( 'Revisar ahora' ) }
					</Button>
				</div>
				<CardDescription>
					{ __( 'Pide páginas representativas como un visitante anónimo y revisa si envían cookies o Cache-Control privado, que impiden la caché y el bfcache. Corre también una vez al día.' ) }
				</CardDescription>
			</CardHeader>
			<CardContent>
				{ last.isLoading && <Skeleton className="itb:h-24" /> }
				{ run.error && <Notice level="error" className="itb:mb-3">{ run.error.message }</Notice> }
				{ ! last.isLoading && ! report && <Notice level="info">{ __( 'Todavía no se ha ejecutado.' ) }</Notice> }
				{ report && (
					<>
						<p className="itb:mb-3 itb:text-xs itb:text-zinc-500">
							{ formatDate( report.at, config.locale ) } · { report.issues === 0 ? __( 'sin problemas' ) : `${ report.issues } ${ report.issues === 1 ? __( 'problema' ) : __( 'problemas' ) }` }
						</p>
						<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-2 itb:p-0">
							{ report.results.map( ( result ) => (
								<li key={ result.url } className="itb:rounded-md itb:border itb:border-zinc-200 itb:p-3">
									<div className="itb:flex itb:items-center itb:justify-between itb:gap-2">
										<a href={ result.url } target="_blank" rel="noreferrer" className="itb:truncate itb:text-[13px] itb:font-medium itb:text-zinc-900 itb:hover:underline">
											{ result.label }
										</a>
										<Badge tone={ result.verdict === 'ok' || result.verdict === 'info' ? 'green' : result.verdict === 'warning' ? 'amber' : 'red' }>
											{ result.verdict === 'ok' || result.verdict === 'info' ? __( 'Cacheable' ) : result.verdict === 'warning' ? __( 'Revisar' ) : __( 'Error' ) }
										</Badge>
									</div>
									{ result.issues.length > 0 && (
										<ul className="itb:mt-2 itb:flex itb:list-disc itb:flex-col itb:gap-1 itb:pl-4 itb:text-xs itb:text-zinc-700">
											{ result.issues.map( ( issue ) => (
												<li key={ issue }>{ issue }</li>
											) ) }
										</ul>
									) }
									{ ( result.notes ?? [] ).length > 0 && (
										<ul className="itb:mt-2 itb:flex itb:list-none itb:flex-col itb:gap-1 itb:p-0 itb:text-xs itb:text-zinc-500">
											{ ( result.notes ?? [] ).map( ( note ) => (
												<li key={ note }>{ note }</li>
											) ) }
										</ul>
									) }
									{ result.cookies.length > 0 && (
										<div className="itb:mt-2 itb:flex itb:flex-col itb:gap-1">
											{ result.cookies.map( ( cookie ) => (
												<div key={ cookie.name } className="itb:text-xs itb:text-zinc-500">
													<code className="itb:text-zinc-800">{ cookie.name }</code> — { cookie.hint }
												</div>
											) ) }
										</div>
									) }
									{ result.headers[ 'cache-control' ] && (
										<div className="itb:mt-2 itb:truncate itb:font-mono itb:text-[11px] itb:text-zinc-400" title={ result.headers[ 'cache-control' ] }>
											Cache-Control: { result.headers[ 'cache-control' ] }
										</div>
									) }
								</li>
							) ) }
						</ul>
					</>
				) }
			</CardContent>
		</Card>
	);
}

function PassiveCard( { data }: { data: ProfilerSummary } ) {
	const passive = data.passive;
	const cookies = Object.entries( passive.cookies );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Cookies en tráfico real' ) }</CardTitle>
				<CardDescription>{ __( 'Lo que respondieron las páginas muestreadas (200, HTML o feed). Incluye visitas con sesión, así que una cookie de carrito aquí puede ser legítima.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				{ passive.eligible === 0 ? (
					<Notice level="info">{ __( 'Sin páginas muestreadas en este periodo.' ) }</Notice>
				) : (
					<>
						<dl className="itb:m-0 itb:mb-3">
							<KeyValue label={ __( 'Páginas analizadas' ) }>{ passive.eligible }</KeyValue>
							<KeyValue label={ __( 'Con Cache-Control privado o no-store' ) }>
								{ passive.private_cache } ({ pct( ( 100 * passive.private_cache ) / passive.eligible ) })
							</KeyValue>
						</dl>
						{ cookies.length === 0 ? (
							<Notice level="success">{ __( 'Ninguna página envió cookies.' ) }</Notice>
						) : (
							<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-1 itb:p-0">
								{ cookies.map( ( [ name, count ] ) => (
									<li key={ name } className="itb:flex itb:justify-between itb:gap-3 itb:text-[13px]">
										<code className="itb:truncate">{ name }</code>
										<span className="itb:tabular-nums itb:text-zinc-500">
											{ count } · { pct( ( 100 * count ) / passive.eligible ) }
										</span>
									</li>
								) ) }
							</ul>
						) }
					</>
				) }
			</CardContent>
		</Card>
	);
}

function LatestDiagCard( { diag }: { diag: NonNullable<ProfilerSummary[ 'latest_diag' ]> } ) {
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Último request con tu cookie de diagnóstico' ) }</CardTitle>
				<CardDescription>
					{ formatDate( diag.created_at, config.locale ) } · <Badge>{ TYPES[ diag.request_type ] ?? diag.request_type }</Badge> <code className="itb:text-xs">{ diag.route_key }</code>
				</CardDescription>
			</CardHeader>
			<CardContent className="itb:grid itb:gap-6 itb:lg:grid-cols-2">
				<dl className="itb:m-0">
					<KeyValue label={ __( 'Tiempo PHP' ) }>{ ms( diag.ms ) }</KeyValue>
					<KeyValue label={ __( 'Memoria pico' ) }>{ number.format( diag.mem_kb / 1024 ) } MB</KeyValue>
					<KeyValue label={ __( 'Consultas' ) }>{ diag.queries }</KeyValue>
					<KeyValue label={ __( 'Costo del perfilador' ) }>{ ms( diag.overhead_us / 1000 ) }</KeyValue>
				</dl>
				<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-1 itb:p-0">
					{ diag.plugins.slice( 0, 8 ).map( ( p ) => (
						<li key={ p.component } className="itb:flex itb:justify-between itb:gap-3 itb:text-[13px]">
							<span className="itb:truncate">{ componentLabel( p.component ).name }</span>
							<span className="itb:tabular-nums itb:text-zinc-500">{ ms( p.total_ms ) }</span>
						</li>
					) ) }
				</ul>
				{ diag.db_queries && <QueriesSection q={ diag.db_queries } /> }
			</CardContent>
		</Card>
	);
}
