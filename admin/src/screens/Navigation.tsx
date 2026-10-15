import { useQuery, useQueryClient } from '@tanstack/react-query';
import { RefreshCw, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { KeyValue, PageHeader } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Input, Select } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { ApiError, api } from '@/lib/api';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import { useAction } from '@/lib/queries';
import type { LazyRenderRule, NavigationSettings, NavigationStatus, RumInsightsData, RumReportData } from '@/lib/types';
import { formatDate } from '@/lib/utils';

const ms = ( v: number | null ) => ( v === null ? '—' : v >= 1000 ? `${ ( v / 1000 ).toFixed( 2 ) } s` : `${ v } ms` );
const pct = ( v: number | null ) => ( v === null ? '—' : `${ v } %` );

// Umbrales "bueno" de Core Web Vitals (web.dev): LCP 2,5 s, INP 200 ms, CLS 0,1.
const tone = ( v: number | null, good: number, poor: number ): 'neutral' | 'green' | 'amber' | 'red' => ( v === null ? 'neutral' : v <= good ? 'green' : v <= poor ? 'amber' : 'red' );

export function Navigation() {
	const { data, isLoading, error, isFetching, refetch } = useQuery( { queryKey: [ 'navigation' ], queryFn: () => api<NavigationStatus>( 'navigation/status' ) } );
	return (
		<>
			<PageHeader
				title={ __( 'Navegación' ) }
				description={ __( 'Speculation Rules (prefetch y, si lo activas, prerender), prefetch predictivo desde el RUM, bfcache, View Transitions y la imagen LCP de cada plantilla. Métricas de visitantes reales, muestreadas y sin datos personales.' ) }
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
					{ ! data.permalink && <Notice level="warning">{ __( 'Sin enlaces permanentes, WordPress no especula ninguna navegación.' ) }</Notice> }
					{ data.prefetch_probe === 'refused' && data.settings.spec_mode === 'prefetch' && (
						<Notice level="warning">
							{ __( 'Cloudflare rechaza las precargas (responde 503 a las peticiones con Sec-Purpose: prefetch): Speed Brain está activo y el HTML no se guarda en el edge. Así ninguna navegación sale precargada. Desactiva Speed Brain en Cloudflare → Velocidad → Optimización → Contenido, activa la caché de HTML en Cloudflare, o cambia el modo a prerender (Cloudflare sí lo deja pasar).' ) }
						</Notice>
					) }
					<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2 itb:*:min-w-0">
						<SettingsCard status={ data } />
						<SummaryCard report={ data.report } status={ data } />
					</div>
					<LazyRenderCard status={ data } />
					<VitalsCard report={ data.report } />
					<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2 itb:*:min-w-0">
						<InsightsCard insights={ data.insights } report={ data.report } />
						<BfcacheCard status={ data } />
					</div>
				</div>
			) }
		</>
	);
}

function Toggle( { label, help, checked, onChange }: { label: string; help: string; checked: boolean; onChange: ( v: boolean ) => void } ) {
	return (
		<label className="itb:flex itb:items-center itb:justify-between itb:gap-3">
			<span>
				<span className="itb:font-medium">{ label }</span>
				<span className="itb:block itb:text-xs itb:text-zinc-500">{ help }</span>
			</span>
			<Switch checked={ checked } onCheckedChange={ onChange } aria-label={ label } />
		</label>
	);
}

function SettingsCard( { status }: { status: NavigationStatus } ) {
	const client = useQueryClient();
	const [ values, setValues ] = useState<NavigationSettings>( status.settings );
	const [ confirm, setConfirm ] = useState( false );
	useEffect( () => setValues( status.settings ), [ status ] );
	const save = useAction( ( withConfirm: boolean ) => api<NavigationStatus>( 'navigation/settings', 'PUT', { ...values, confirm: withConfirm } ) );
	const set = <K extends keyof NavigationSettings>( key: K, value: NavigationSettings[ K ] ) => setValues( { ...values, [ key ]: value } );
	const submit = ( withConfirm: boolean ) =>
		save.mutate( withConfirm, {
			onSuccess: ( s ) => client.setQueryData( [ 'navigation' ], s ),
			onError: ( e ) => {
				if ( e instanceof ApiError && e.status === 428 ) {
					setConfirm( true );
				}
			},
			onSettled: () => withConfirm && setConfirm( false ),
		} );
	const off = Object.entries( status.modules ).filter( ( [ , m ] ) => ! m.enabled );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Ajustes' ) }</CardTitle>
				<CardDescription>{ status.core_api ? __( 'Usa la API de Speculation Rules del core de WordPress.' ) : __( 'WordPress anterior a 6.8: Imagina Turbo imprime las reglas.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ off.length > 0 && <Notice level="info">{ `${ __( 'Módulos apagados:' ) } ${ off.map( ( [ id, m ] ) => ( m.forced_off ? `${ id } (${ m.forced_off })` : id ) ).join( ', ' ) }` }</Notice> }
				<div className="itb:grid itb:gap-3 itb:sm:grid-cols-2">
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:font-medium">{ __( 'Modo' ) }</span>
						<Select value={ values.spec_mode } onChange={ ( e ) => set( 'spec_mode', e.target.value as NavigationSettings[ 'spec_mode' ] ) }>
							<option value="prefetch">{ __( 'Prefetch (seguro)' ) }</option>
							<option value="prerender">{ __( 'Prerender (moderado)' ) }</option>
						</Select>
					</label>
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:font-medium">{ __( 'Anticipación' ) }</span>
						<Select value={ values.spec_eagerness } onChange={ ( e ) => set( 'spec_eagerness', e.target.value as NavigationSettings[ 'spec_eagerness' ] ) }>
							<option value="conservative">{ __( 'Al pulsar (conservadora)' ) }</option>
							<option value="moderate">{ __( 'Al pasar el cursor (moderada)' ) }</option>
							<option value="eager">{ __( 'En cuanto se ve el enlace (alta)' ) }</option>
						</Select>
					</label>
				</div>
				<Toggle label={ __( 'Prefetch predictivo' ) } help={ __( 'Precarga las 2–4 páginas que los visitantes abren después desde cada plantilla (RUM, recalculado cada día).' ) } checked={ values.spec_predictive } onChange={ ( v ) => set( 'spec_predictive', v ) } />
				<Toggle label={ __( 'Respaldo para Safari y Firefox' ) } help={ __( 'Script de 1,4 KB que precarga al tocar o pulsar, solo en navegadores sin Speculation Rules.' ) } checked={ values.spec_fallback } onChange={ ( v ) => set( 'spec_fallback', v ) } />
				<Toggle label={ __( 'View Transitions' ) } help={ __( 'Transición suave entre páginas del mismo sitio (Chrome y Safari recientes). Cambia cómo se ve la navegación: revísala.' ) } checked={ values.view_transitions } onChange={ ( v ) => set( 'view_transitions', v ) } />
				<Toggle label={ __( 'Prioridad de la imagen LCP' ) } help={ __( 'La imagen principal (la que mide el RUM, la del producto y los primeros productos de un listado) se descarga desde el HTML, sin esperar al lazy load ni a la reescritura de WebP por JavaScript.' ) } checked={ values.hints_lcp } onChange={ ( v ) => set( 'hints_lcp', v ) } />
				<Toggle label={ __( 'Fachadas de YouTube y Vimeo' ) } help={ __( 'Miniatura y botón; el reproductor se carga al tocar. No se aplica a reproductores controlados por otro script.' ) } checked={ values.hints_facades } onChange={ ( v ) => set( 'hints_facades', v ) } />
				<label className="itb:flex itb:items-center itb:justify-between itb:gap-3">
					<span>
						<span className="itb:font-medium">{ __( 'Muestra del RUM' ) }</span>
						<span className="itb:block itb:text-xs itb:text-zinc-500">{ status.hub_rum ? __( 'Los beacons van al hub.' ) : __( 'Los beacons van a un endpoint local que no carga plugins.' ) }</span>
					</span>
					<span className="itb:flex itb:items-center itb:gap-1">
						<Input className="itb:h-8 itb:w-20" type="number" min={ 0 } max={ 100 } step={ 1 } value={ Math.round( values.rum_rate * 100 ) } onChange={ ( e ) => set( 'rum_rate', Math.max( 0, Math.min( 100, Number( e.target.value ) || 0 ) ) / 100 ) } aria-label={ __( 'Muestra del RUM (%)' ) } />
						<span>%</span>
					</span>
				</label>
				<div>
					<Button size="sm" disabled={ save.isPending } onClick={ () => submit( false ) }>
						{ save.isPending ? __( 'Guardando…' ) : __( 'Guardar' ) }
					</Button>
				</div>
				{ save.isSuccess && <Notice level="success">{ __( 'Ajustes guardados.' ) }</Notice> }
				{ save.error && ! ( save.error instanceof ApiError && save.error.status === 428 ) && <Notice level="error">{ save.error.message }</Notice> }
			</CardContent>
			<ConfirmDialog
				open={ confirm }
				onOpenChange={ ( open ) => {
					setConfirm( open );
					// Cancelar deja el modo como estaba guardado.
					if ( ! open && ! save.isPending ) {
						setValues( ( v ) => ( { ...v, spec_mode: status.settings.spec_mode } ) );
					}
				} }
				title={ __( '¿Activar el prerender?' ) }
				description={ __( 'El navegador carga y ejecuta las páginas antes de que el visitante las abra: la analítica y los píxeles pueden contar visitas que no ocurrieron, y algunos scripts de terceros no lo esperan. Imagina Turbo espera a la activación en sus propios scripts. Revisa la analítica unos días después.' ) }
				confirmLabel={ __( 'Activar' ) }
				pending={ save.isPending }
				onConfirm={ () => submit( true ) }
			/>
		</Card>
	);
}

function LazyRenderCard( { status }: { status: NavigationStatus } ) {
	const client = useQueryClient();
	const saved = status.settings.lazy_render;
	const [ rules, setRules ] = useState<LazyRenderRule[]>( saved );
	const [ draft, setDraft ] = useState( { selector: '', templates: '', height: 600 } );
	useEffect( () => setRules( status.settings.lazy_render ), [ status ] );
	const save = useAction( ( next: LazyRenderRule[] ) => api<NavigationStatus>( 'navigation/settings', 'PUT', { lazy_render: next } ) );
	const persist = ( next: LazyRenderRule[] ) => save.mutate( next, { onSuccess: ( s ) => client.setQueryData( [ 'navigation' ], s ) } );
	const missing = status.lazy_presets.filter( ( p ) => ! rules.some( ( r ) => r.selector === p.selector ) );
	const add = () => {
		const templates = draft.templates.split( ',' ).map( ( t ) => t.trim() ).filter( Boolean );
		persist( [ ...rules, { selector: draft.selector.trim(), templates, height: draft.height } ] );
		setDraft( { selector: '', templates: '', height: 600 } );
	};
	return (
		<Card>
			<CardHeader>
				<div className="itb:flex itb:items-center itb:gap-2">
					<CardTitle>{ __( 'Renderizado diferido' ) }</CardTitle>
					<Badge tone="amber">{ __( 'Moderado' ) }</Badge>
				</div>
				<CardDescription>
					{ __( 'content-visibility: el navegador no maqueta ni pinta estas secciones hasta que el visitante se acerca. Menos trabajo al cargar y al interactuar en páginas largas. Úsalo solo en secciones que empiezan fuera de pantalla y revisa la página: la barra de desplazamiento puede ajustarse al llegar.' ) }
				</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ rules.length === 0 && <Notice level="info">{ __( 'Sin reglas: no se agrega nada a las páginas.' ) }</Notice> }
				{ rules.map( ( rule ) => (
					<div key={ rule.selector } className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-2 itb:rounded-md itb:border itb:border-zinc-200 itb:px-3 itb:py-2">
						<span className="itb:min-w-0">
							<span className="itb:font-mono itb:text-xs">{ rule.selector }</span>
							<span className="itb:block itb:text-xs itb:text-zinc-500">
								{ rule.templates.length ? rule.templates.join( ', ' ) : __( 'Todas las plantillas' ) } · { rule.height } px
							</span>
						</span>
						<Button variant="outline" size="sm" disabled={ save.isPending } onClick={ () => persist( rules.filter( ( r ) => r.selector !== rule.selector ) ) }>
							{ __( 'Quitar' ) }
						</Button>
					</div>
				) ) }
				{ missing.length > 0 && (
					<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
						<Button size="sm" variant="outline" disabled={ save.isPending } onClick={ () => persist( [ ...rules, ...missing ] ) }>
							<Sparkles aria-hidden />
							{ `${ __( 'Agregar las sugeridas para' ) } ${ status.theme }` }
						</Button>
						<span className="itb:text-xs itb:text-zinc-500">{ missing.map( ( m ) => m.selector ).join( ' · ' ) }</span>
					</div>
				) }
				<div className="itb:grid itb:gap-2 itb:sm:grid-cols-[1fr_1fr_6rem_auto] itb:sm:items-end">
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:font-medium">{ __( 'Selector CSS' ) }</span>
						<Input value={ draft.selector } placeholder=".site-footer" onChange={ ( e ) => setDraft( { ...draft, selector: e.target.value } ) } />
					</label>
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:font-medium">{ __( 'Plantillas (vacío = todas)' ) }</span>
						<Input value={ draft.templates } placeholder="tax:product_cat, single:product" onChange={ ( e ) => setDraft( { ...draft, templates: e.target.value } ) } />
					</label>
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:font-medium">{ __( 'Alto (px)' ) }</span>
						<Input type="number" min={ 50 } max={ 3000 } value={ draft.height } onChange={ ( e ) => setDraft( { ...draft, height: Number( e.target.value ) || 600 } ) } />
					</label>
					<Button size="sm" className="itb:h-9" disabled={ save.isPending || draft.selector.trim() === '' } onClick={ add }>
						{ __( 'Agregar' ) }
					</Button>
				</div>
				{ save.error && <Notice level="error">{ save.error.message }</Notice> }
			</CardContent>
		</Card>
	);
}

function SummaryCard( { report, status }: { report: RumReportData; status: NavigationStatus } ) {
	const n = report.navigation;
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Navegación (7 días, RUM)' ) }</CardTitle>
				<CardDescription>{ __( 'Objetivo: más del 30 % de las navegaciones servidas por prerender o prefetch en Chrome.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:text-[13px]">
				{ ! status.modules.rum.enabled && <Notice level="info">{ __( 'El RUM está apagado: sin datos de visitantes.' ) }</Notice> }
				<dl className="itb:m-0">
					<KeyValue label={ __( 'Navegaciones medidas' ) }>{ n.navigations.toLocaleString( config.locale ) }</KeyValue>
					<KeyValue label={ __( 'Servidas especuladas' ) }>
						<Badge tone={ n.speculated_pct === null ? 'neutral' : n.speculated_pct >= 30 ? 'green' : 'amber' }>{ pct( n.speculated_pct ) }</Badge>
					</KeyValue>
					<KeyValue label={ __( 'Prerender · prefetch' ) }>{ `${ n.prerendered } · ${ n.prefetched }` }</KeyValue>
					<KeyValue label={ __( 'Vuelta atrás desde bfcache' ) }>{ pct( n.bfcache_pct ) }</KeyValue>
					<KeyValue label={ __( 'Recargas' ) }>{ n.reloads }</KeyValue>
				</dl>
			</CardContent>
		</Card>
	);
}

function VitalsCard( { report }: { report: RumReportData } ) {
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Core Web Vitals por plantilla (p75, 7 días)' ) }</CardTitle>
				<CardDescription>{ __( 'Se muestra el p75 con al menos 10 muestras. Verde: LCP ≤ 2,5 s, INP ≤ 200 ms, CLS ≤ 0,1.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:overflow-x-auto itb:text-[13px]">
				{ report.vitals.length === 0 ? (
					<p className="itb:m-0 itb:text-zinc-500">{ __( 'Todavía no hay datos.' ) }</p>
				) : (
					<table className="itb:w-full itb:border-collapse">
						<thead>
							<tr className="itb:text-left itb:text-xs itb:text-zinc-500">
								<th className="itb:py-1 itb:pr-3 itb:font-medium">{ __( 'Plantilla' ) }</th>
								<th className="itb:py-1 itb:pr-3 itb:font-medium">{ __( 'Disp.' ) }</th>
								<th className="itb:py-1 itb:pr-3 itb:text-right itb:font-medium">{ __( 'Visitas' ) }</th>
								<th className="itb:py-1 itb:pr-3 itb:text-right itb:font-medium">LCP</th>
								<th className="itb:py-1 itb:pr-3 itb:text-right itb:font-medium">INP</th>
								<th className="itb:py-1 itb:pr-3 itb:text-right itb:font-medium">CLS</th>
								<th className="itb:py-1 itb:text-right itb:font-medium">TTFB</th>
							</tr>
						</thead>
						<tbody>
							{ report.vitals.slice( 0, 20 ).map( ( v ) => (
								<tr key={ v.template + v.device } className="itb:border-t itb:border-zinc-100">
									<td className="itb:py-1.5 itb:pr-3">
										<code className="itb:text-xs">{ v.template }</code>
									</td>
									<td className="itb:py-1.5 itb:pr-3">{ v.device === 'm' ? __( 'Móvil' ) : __( 'Escritorio' ) }</td>
									<td className="itb:py-1.5 itb:pr-3 itb:text-right itb:tabular-nums">{ v.n }</td>
									<td className="itb:py-1.5 itb:pr-3 itb:text-right">
										<Badge tone={ tone( v.lcp, 2500, 4000 ) }>{ ms( v.lcp ) }</Badge>
									</td>
									<td className="itb:py-1.5 itb:pr-3 itb:text-right">
										<Badge tone={ tone( v.inp, 200, 500 ) }>{ ms( v.inp ) }</Badge>
									</td>
									<td className="itb:py-1.5 itb:pr-3 itb:text-right">
										<Badge tone={ tone( v.cls, 0.1, 0.25 ) }>{ v.cls === null ? '—' : v.cls.toFixed( 3 ) }</Badge>
									</td>
									<td className="itb:py-1.5 itb:text-right itb:tabular-nums">{ ms( v.ttfb ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</CardContent>
		</Card>
	);
}

function InsightsCard( { insights, report }: { insights: RumInsightsData; report: RumReportData } ) {
	const client = useQueryClient();
	const run = useAction( () => api<RumInsightsData>( 'navigation/insights', 'POST' ) );
	const predicted = Object.entries( insights.predicted );
	const lcp = Object.entries( insights.lcp );
	return (
		<Card>
			<CardHeader>
				<div className="itb:flex itb:flex-wrap itb:items-start itb:justify-between itb:gap-3">
					<div>
						<CardTitle>{ __( 'Lo que el RUM decidió' ) }</CardTitle>
						<CardDescription>{ __( 'Solo con muchas muestras de muchos visitantes distintos y contenido publicado del sitio: los beacons no se autentican.' ) }</CardDescription>
					</div>
					<Button size="sm" variant="outline" disabled={ run.isPending } onClick={ () => run.mutate( undefined, { onSuccess: () => void client.invalidateQueries( { queryKey: [ 'navigation' ] } ) } ) }>
						<Sparkles aria-hidden />
						{ run.isPending ? __( 'Calculando…' ) : __( 'Recalcular' ) }
					</Button>
				</div>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				<div>
					<p className="itb:m-0 itb:mb-1 itb:font-medium">{ __( 'Prefetch predictivo' ) }</p>
					{ predicted.length === 0 ? (
						<p className="itb:m-0 itb:text-xs itb:text-zinc-500">{ __( 'Aún no hay transiciones con suficientes visitantes (20 por página siguiente, de al menos 10 visitantes).' ) }</p>
					) : (
						<ul className="itb:m-0 itb:list-none itb:p-0">
							{ predicted.map( ( [ template, paths ] ) => (
								<li key={ template } className="itb:py-0.5">
									<code className="itb:text-xs">{ template }</code> → { paths.map( ( p ) => <code key={ p } className="itb:mr-1 itb:text-xs">{ p }</code> ) }
								</li>
							) ) }
						</ul>
					) }
				</div>
				<div>
					<p className="itb:m-0 itb:mb-1 itb:font-medium">{ __( 'Imagen LCP (móvil)' ) }</p>
					{ lcp.length === 0 ? (
						<p className="itb:m-0 itb:text-xs itb:text-zinc-500">{ __( 'Aún no hay una imagen que sea LCP en el 60 % de las visitas de una plantilla (50 muestras, 20 visitantes).' ) }</p>
					) : (
						<ul className="itb:m-0 itb:list-none itb:p-0">
							{ lcp.map( ( [ template, l ] ) => (
								<li key={ template } className="itb:py-0.5">
									<code className="itb:text-xs">{ template }</code> → { l.kind === 'featured' ? __( 'imagen destacada' ) : <code className="itb:text-xs">{ l.src }</code> } <span className="itb:text-xs itb:text-zinc-500">{ `(${ Math.round( l.share * 100 ) } %)` }</span>
								</li>
							) ) }
						</ul>
					) }
				</div>
				{ report.next.length > 0 && (
					<details className="itb:text-xs">
						<summary className="itb:cursor-pointer itb:text-zinc-600">{ __( 'Transiciones registradas' ) }</summary>
						<ul className="itb:m-0 itb:mt-1 itb:list-none itb:p-0">
							{ report.next.slice( 0, 15 ).map( ( e ) => (
								<li key={ e.template + e.device + e.value } className="itb:flex itb:justify-between itb:gap-2">
									<span className="itb:truncate">
										<code>{ e.template }</code> → <code>{ e.value }</code>
									</span>
									<span className="itb:tabular-nums">{ e.n }</span>
								</li>
							) ) }
						</ul>
					</details>
				) }
				{ insights.generated > 0 && <p className="itb:m-0 itb:text-xs itb:text-zinc-500">{ `${ __( 'Calculado:' ) } ${ formatDate( insights.generated, config.locale ) }` }</p> }
				{ run.error && <Notice level="error">{ run.error.message }</Notice> }
			</CardContent>
		</Card>
	);
}

function BfcacheCard( { status }: { status: NavigationStatus } ) {
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'bfcache' ) }</CardTitle>
				<CardDescription>{ __( 'Por qué una vuelta atrás no se sirvió desde la memoria del navegador (Chrome, notRestoredReasons) y qué páginas propias salen con no-store.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ status.report.bfcache.length === 0 ? (
					<p className="itb:m-0 itb:text-zinc-500">{ __( 'Sin causas registradas.' ) }</p>
				) : (
					<ul className="itb:m-0 itb:list-none itb:p-0">
						{ status.report.bfcache.map( ( r ) => (
							<li key={ r.reason } className="itb:flex itb:justify-between itb:gap-2 itb:border-b itb:border-zinc-100 itb:py-1 itb:last:border-b-0">
								<code className="itb:text-xs">{ r.reason }</code>
								<span className="itb:tabular-nums">{ r.n }</span>
							</li>
						) ) }
					</ul>
				) }
				<div>
					<p className="itb:m-0 itb:mb-1 itb:font-medium">{ __( 'Páginas propias con Cache-Control: no-store (48 h)' ) }</p>
					{ status.no_store.length === 0 ? (
						<p className="itb:m-0 itb:text-xs itb:text-zinc-500">{ __( 'Ninguna en las muestras del perfilador.' ) }</p>
					) : (
						<ul className="itb:m-0 itb:list-none itb:p-0 itb:text-xs">
							{ status.no_store.map( ( r ) => (
								<li key={ r.route } className="itb:flex itb:justify-between itb:gap-2">
									<code>{ r.route }</code>
									<span>{ r.n }</span>
								</li>
							) ) }
						</ul>
					) }
					<p className="itb:m-0 itb:mt-1 itb:text-xs itb:text-zinc-500">{ __( 'Solo visitantes anónimos y respuestas 200: el no-store de las sesiones y de los 404 es de WordPress y es correcto. Firefox no guarda en el bfcache una página con no-store; Chrome sí, y la descarta si cambian las cookies. Una página pública con no-store se revisa en Caché y en Diagnóstico.' ) }</p>
				</div>
			</CardContent>
		</Card>
	);
}
