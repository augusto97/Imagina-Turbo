import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, Lightbulb, Plus, RefreshCw, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Input, Select } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import { useAction } from '@/lib/queries';
import type { AssetInventory, AssetRule, AssetsStatus, AssetSuggestion, InventoryAsset } from '@/lib/types';
import { formatBytes, formatDate } from '@/lib/utils';

const KEY = [ 'assets' ];

const STATUS: Record<AssetRule[ 'status' ], { label: string; tone: 'neutral' | 'green' | 'blue' }> = {
	testing: { label: __( 'En prueba' ), tone: 'blue' },
	active: { label: __( 'Activa' ), tone: 'green' },
	disabled: { label: __( 'Desactivada' ), tone: 'neutral' },
};

interface Draft {
	handle: string;
	type: 'js' | 'css';
	mode: 'never' | 'only';
	templates: string[];
	ids: string;
	post_types: string[];
	note: string;
}

export function Assets() {
	const { data, isLoading, error, isFetching, refetch } = useQuery( { queryKey: KEY, queryFn: () => api<AssetsStatus>( 'assets/status' ) } );
	const [ draft, setDraft ] = useState<Draft | null>( null );
	return (
		<>
			<PageHeader
				title={ __( 'Assets por ruta' ) }
				description={ __( 'Quita el CSS y el JavaScript que una página no usa, sin tocar el HTML. Cada regla empieza en prueba: solo la ves tú con la previsualización hasta que la actives.' ) }
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
					<PreviewCard status={ data } />
					<RulesCard status={ data } />
					{ draft && <DraftCard status={ data } draft={ draft } onChange={ setDraft } onClose={ () => setDraft( null ) } /> }
					<InspectCard status={ data } onRule={ setDraft } />
					<SuggestionsCard status={ data } onRule={ setDraft } />
				</div>
			) }
		</>
	);
}

function EnableCard( { status }: { status: AssetsStatus } ) {
	const [ open, setOpen ] = useState( false );
	const client = useQueryClient();
	const action = useAction( () => api( 'modules/asset_manager', 'PUT', { enabled: true, confirm: true } ) );
	if ( status.forced_off ) {
		return <Notice level="warning">{ `${ __( 'Apagado por solapamiento con otro plugin:' ) } ${ status.forced_off }` }</Notice>;
	}
	return (
		<Notice level="warning">
			<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-3">
				<span>{ __( 'El gestor de assets está apagado. Puedes inspeccionar páginas y preparar reglas; para que se apliquen (en la previsualización o a todos) hay que activarlo.' ) }</span>
				<Button size="sm" onClick={ () => setOpen( true ) }>
					{ __( 'Activar' ) }
				</Button>
			</div>
			{ action.error && <div className="itb:mt-2">{ action.error.message }</div> }
			<ConfirmDialog
				open={ open }
				onOpenChange={ setOpen }
				title={ __( '¿Activar el gestor de assets?' ) }
				description={ __( 'Es de riesgo moderado. Activarlo no quita nada por sí solo: aplica las reglas activas a todos los visitantes y las que están en prueba solo a ti, con la previsualización.' ) }
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

function PreviewCard( { status }: { status: AssetsStatus } ) {
	const client = useQueryClient();
	const toggle = useAction( ( on: boolean ) => api<AssetsStatus>( 'assets/preview', 'POST', { on } ) );
	const testing = status.rules.filter( ( r ) => r.status === 'testing' ).length;
	return (
		<Card>
			<CardContent className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-3 itb:pt-6">
				<div className="itb:flex itb:items-center itb:gap-3">
					<Switch
						checked={ status.preview }
						disabled={ toggle.isPending }
						onCheckedChange={ ( on ) => toggle.mutate( on, { onSuccess: ( d ) => client.setQueryData( KEY, d ) } ) }
						aria-label={ __( 'Previsualizar reglas en prueba' ) }
					/>
					<div className="itb:text-[13px]">
						<div className="itb:font-medium">{ __( 'Previsualizar reglas en prueba en este navegador' ) }</div>
						<div className="itb:text-zinc-500">
							{ status.preview
								? __( 'Activa durante 2 horas. Recorre las páginas afectadas con la sesión iniciada: esas páginas no se guardan en caché.' )
								: `${ testing } ${ __( 'reglas en prueba. Solo tú las ves mientras la previsualización está activa.' ) }` }
						</div>
					</div>
				</div>
				<Button variant="outline" size="sm" asChild>
					<a href={ status.home } target="_blank" rel="noreferrer">
						<ExternalLink aria-hidden />
						{ __( 'Abrir el sitio' ) }
					</a>
				</Button>
				{ toggle.error && <Notice level="error">{ toggle.error.message }</Notice> }
				{ ! status.enabled && status.preview && <Notice level="warning">{ __( 'Con el módulo apagado la previsualización no aplica nada.' ) }</Notice> }
			</CardContent>
		</Card>
	);
}

function places( rule: Pick<AssetRule, 'templates' | 'ids' | 'post_types'> ): string {
	const parts = [ ...rule.templates, ...rule.post_types.map( ( t ) => `${ __( 'tipo' ) } ${ t }` ) ];
	if ( rule.ids.length > 0 ) {
		parts.push( `${ __( 'ids' ) } ${ rule.ids.slice( 0, 8 ).join( ', ' ) }${ rule.ids.length > 8 ? '…' : '' }` );
	}
	return parts.join( ' · ' ) || '—';
}

function RulesCard( { status }: { status: AssetsStatus } ) {
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Reglas' ) }</CardTitle>
				<CardDescription>{ __( '"Nunca aquí" quita el asset en los lugares elegidos; "Solo aquí" lo quita en todo el sitio salvo en ellos. Si otro asset que se queda lo necesita, WordPress lo sigue cargando.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-2 itb:text-[13px]">
				{ status.rules.length === 0 && <p className="itb:m-0 itb:text-zinc-500">{ __( 'Todavía no hay reglas. Inspecciona una página o analiza el sitio para ver sugerencias.' ) }</p> }
				{ status.rules.map( ( r ) => (
					<RuleRow key={ r.id } rule={ r } />
				) ) }
			</CardContent>
		</Card>
	);
}

function RuleRow( { rule }: { rule: AssetRule } ) {
	const client = useQueryClient();
	const [ confirm, setConfirm ] = useState< 'activate' | 'delete' | null >( null );
	const act = useAction( ( { path, method, body }: { path: string; method: 'POST' | 'DELETE'; body?: unknown } ) => api<AssetsStatus>( path, method, body ) );
	const run = ( path: string, method: 'POST' | 'DELETE' = 'POST', body?: unknown ) =>
		act.mutate( { path, method, body }, { onSuccess: ( d ) => client.setQueryData( KEY, d ), onSettled: () => setConfirm( null ) } );
	return (
		<div className="itb:flex itb:flex-col itb:gap-1 itb:rounded-md itb:border itb:border-zinc-200 itb:p-3">
			<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-2">
				<div className="itb:flex itb:min-w-0 itb:flex-wrap itb:items-center itb:gap-2">
					<Badge tone={ STATUS[ rule.status ].tone }>{ STATUS[ rule.status ].label }</Badge>
					<code className="itb:break-all">{ rule.handle }</code>
					<Badge tone="neutral">{ rule.type.toUpperCase() }</Badge>
					<span className="itb:text-zinc-600">{ rule.mode === 'never' ? __( 'Nunca aquí:' ) : __( 'Solo aquí:' ) }</span>
					<span className="itb:min-w-0 itb:break-words itb:text-zinc-600">{ places( rule ) }</span>
				</div>
				<div className="itb:flex itb:flex-wrap itb:gap-1">
					{ rule.status !== 'active' && (
						<Button size="sm" onClick={ () => setConfirm( 'activate' ) } disabled={ act.isPending }>
							{ __( 'Activar' ) }
						</Button>
					) }
					{ rule.status === 'active' && (
						<Button size="sm" variant="outline" onClick={ () => run( `assets/rules/${ rule.id }/test` ) } disabled={ act.isPending }>
							{ __( 'Volver a prueba' ) }
						</Button>
					) }
					{ rule.status !== 'disabled' && (
						<Button size="sm" variant="outline" onClick={ () => run( `assets/rules/${ rule.id }/disable` ) } disabled={ act.isPending }>
							{ __( 'Desactivar' ) }
						</Button>
					) }
					<Button size="sm" variant="ghost" onClick={ () => setConfirm( 'delete' ) } disabled={ act.isPending } aria-label={ __( 'Eliminar' ) }>
						<Trash2 aria-hidden />
					</Button>
				</div>
			</div>
			{ rule.note && <div className="itb:text-zinc-500">{ rule.note }</div> }
			{ rule.verified_at > 0 && <div className="itb:text-zinc-500">{ `${ __( 'Verificada' ) } ${ formatDate( rule.verified_at, config.locale ) }` }</div> }
			{ act.error && <Notice level="error">{ act.error.message }</Notice> }
			<ConfirmDialog
				open={ confirm !== null }
				onOpenChange={ ( o ) => ! o && setConfirm( null ) }
				title={ confirm === 'delete' ? __( '¿Eliminar la regla?' ) : __( '¿Activar para todos los visitantes?' ) }
				description={
					confirm === 'delete'
						? __( 'El asset vuelve a cargarse donde la regla lo quitaba.' )
						: __( 'Confirma que revisaste con la previsualización las páginas afectadas (formularios, carrito, menús). Se purga la caché de páginas para que el cambio se vea.' )
				}
				confirmLabel={ confirm === 'delete' ? __( 'Eliminar' ) : __( 'Activar' ) }
				destructive={ confirm === 'delete' }
				pending={ act.isPending }
				onConfirm={ () => ( confirm === 'delete' ? run( `assets/rules/${ rule.id }`, 'DELETE' ) : run( `assets/rules/${ rule.id }/activate`, 'POST', { confirm: true } ) ) }
			/>
		</div>
	);
}

function DraftCard( { status, draft, onChange, onClose }: { status: AssetsStatus; draft: Draft; onChange: ( d: Draft ) => void; onClose: () => void } ) {
	const client = useQueryClient();
	const create = useAction( () =>
		api<AssetsStatus>( 'assets/rules', 'POST', {
			...draft,
			ids: draft.ids
				.split( /[\s,]+/ )
				.map( ( s ) => parseInt( s, 10 ) )
				.filter( ( n ) => n > 0 ),
		} )
	);
	const toggle = ( list: string[], value: string ) => ( list.includes( value ) ? list.filter( ( v ) => v !== value ) : [ ...list, value ] );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Nueva regla (en prueba)' ) }</CardTitle>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				<div className="itb:grid itb:gap-3 itb:md:grid-cols-3">
					<label className="itb:flex itb:min-w-0 itb:flex-col itb:gap-1">
						<span className="itb:font-medium">{ __( 'Handle' ) }</span>
						<Input value={ draft.handle } onChange={ ( e ) => onChange( { ...draft, handle: e.target.value } ) } />
					</label>
					<label className="itb:flex itb:min-w-0 itb:flex-col itb:gap-1">
						<span className="itb:font-medium">{ __( 'Tipo' ) }</span>
						<Select value={ draft.type } onChange={ ( e ) => onChange( { ...draft, type: e.target.value as Draft[ 'type' ] } ) }>
							<option value="js">JavaScript</option>
							<option value="css">CSS</option>
						</Select>
					</label>
					<label className="itb:flex itb:min-w-0 itb:flex-col itb:gap-1">
						<span className="itb:font-medium">{ __( 'Modo' ) }</span>
						<Select value={ draft.mode } onChange={ ( e ) => onChange( { ...draft, mode: e.target.value as Draft[ 'mode' ] } ) }>
							<option value="never">{ __( 'Nunca aquí (quitar en estos lugares)' ) }</option>
							<option value="only">{ __( 'Solo aquí (quitar en el resto del sitio)' ) }</option>
						</Select>
					</label>
				</div>
				<fieldset className="itb:m-0 itb:flex itb:flex-col itb:gap-1 itb:border-0 itb:p-0">
					<legend className="itb:mb-1 itb:font-medium">{ __( 'Plantillas' ) }</legend>
					<div className="itb:flex itb:flex-wrap itb:gap-x-4 itb:gap-y-1">
						{ status.templates.map( ( t ) => (
							<label key={ t } className="itb:flex itb:items-center itb:gap-1">
								<input type="checkbox" checked={ draft.templates.includes( t ) } onChange={ () => onChange( { ...draft, templates: toggle( draft.templates, t ) } ) } />
								<code>{ t }</code>
							</label>
						) ) }
					</div>
				</fieldset>
				<fieldset className="itb:m-0 itb:flex itb:flex-col itb:gap-1 itb:border-0 itb:p-0">
					<legend className="itb:mb-1 itb:font-medium">{ __( 'Tipos de contenido' ) }</legend>
					<div className="itb:flex itb:flex-wrap itb:gap-x-4 itb:gap-y-1">
						{ status.post_types.map( ( t ) => (
							<label key={ t } className="itb:flex itb:items-center itb:gap-1">
								<input type="checkbox" checked={ draft.post_types.includes( t ) } onChange={ () => onChange( { ...draft, post_types: toggle( draft.post_types, t ) } ) } />
								<code>{ t }</code>
							</label>
						) ) }
					</div>
				</fieldset>
				<label className="itb:flex itb:flex-col itb:gap-1">
					<span className="itb:font-medium">{ __( 'IDs de páginas o entradas (separados por comas)' ) }</span>
					<Input value={ draft.ids } onChange={ ( e ) => onChange( { ...draft, ids: e.target.value } ) } />
				</label>
				<label className="itb:flex itb:flex-col itb:gap-1">
					<span className="itb:font-medium">{ __( 'Nota' ) }</span>
					<Input value={ draft.note } maxLength={ 200 } onChange={ ( e ) => onChange( { ...draft, note: e.target.value } ) } />
				</label>
				{ create.error && <Notice level="error">{ create.error.message }</Notice> }
				<div className="itb:flex itb:gap-2">
					<Button
						size="sm"
						disabled={ create.isPending || draft.handle === '' }
						onClick={ () =>
							create.mutate( undefined, {
								onSuccess: ( d ) => {
									client.setQueryData( KEY, d );
									onClose();
								},
							} )
						}
					>
						<Plus aria-hidden />
						{ __( 'Crear en prueba' ) }
					</Button>
					<Button size="sm" variant="ghost" onClick={ onClose }>
						{ __( 'Cancelar' ) }
					</Button>
				</div>
			</CardContent>
		</Card>
	);
}

function InspectCard( { status, onRule }: { status: AssetsStatus; onRule: ( d: Draft ) => void } ) {
	const [ path, setPath ] = useState( '/' );
	const [ result, setResult ] = useState<AssetInventory | null>( null );
	const inspect = useAction( () => api<AssetInventory>( 'assets/inspect', 'POST', { path } ) );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Inspeccionar una página' ) }</CardTitle>
				<CardDescription>{ __( 'El sitio se pide la página a sí mismo como visitante anónimo y lista lo que imprimió, sin reglas aplicadas.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				<form
					className="itb:flex itb:gap-2"
					onSubmit={ ( e ) => {
						e.preventDefault();
						inspect.mutate( undefined, { onSuccess: setResult } );
					} }
				>
					<Input value={ path } onChange={ ( e ) => setPath( e.target.value ) } placeholder="/contacto/" aria-label={ __( 'Ruta' ) } />
					<Button size="sm" type="submit" disabled={ inspect.isPending }>
						<Search aria-hidden />
						{ inspect.isPending ? __( 'Inspeccionando…' ) : __( 'Inspeccionar' ) }
					</Button>
				</form>
				{ inspect.error && <Notice level="error">{ inspect.error.message }</Notice> }
				{ result && (
					<>
						<div className="itb:text-zinc-600">
							{ `${ __( 'Plantilla' ) }: ` }
							<code>{ result.context.template }</code>
							{ result.context.id > 0 && ` · id ${ result.context.id } · ${ result.context.post_type }` }
							{ ` · HTTP ${ result.status }` }
						</div>
						{ ( [ 'js', 'css' ] as const ).map( ( type ) => (
							<AssetTable key={ type } type={ type } assets={ result[ type ] } onRule={ ( a ) => onRule( { handle: a.handle, type, mode: 'never', templates: [ result.context.template ], ids: '', post_types: [], note: '' } ) } />
						) ) }
						{ ( result.shortcodes.length > 0 || result.blocks.length > 0 ) && (
							<div className="itb:text-zinc-500">
								{ __( 'Contenido renderizado:' ) } { [ ...result.shortcodes.map( ( s ) => `[${ s }]` ), ...result.blocks ].join( ', ' ) }
							</div>
						) }
					</>
				) }
				{ ! result && status.rules.length === 0 && <p className="itb:m-0 itb:text-zinc-500">{ __( 'Empieza por la portada o por una página con formulario.' ) }</p> }
			</CardContent>
		</Card>
	);
}

function AssetTable( { type, assets, onRule }: { type: 'js' | 'css'; assets: InventoryAsset[]; onRule: ( a: InventoryAsset ) => void } ) {
	const total = assets.reduce( ( sum, a ) => sum + a.size, 0 );
	return (
		<div className="itb:overflow-x-auto">
			<div className="itb:mb-1 itb:font-medium">{ `${ type === 'js' ? 'JavaScript' : 'CSS' } · ${ assets.length } · ${ formatBytes( total, config.locale ) }` }</div>
			<table className="itb:w-full itb:border-collapse itb:text-left">
				<thead className="itb:text-zinc-500">
					<tr>
						<th className="itb:py-1 itb:pr-3 itb:font-normal">{ __( 'Handle' ) }</th>
						<th className="itb:py-1 itb:pr-3 itb:font-normal">{ __( 'Origen' ) }</th>
						<th className="itb:py-1 itb:pr-3 itb:text-right itb:font-normal">{ __( 'Tamaño' ) }</th>
						<th className="itb:py-1 itb:pr-3 itb:font-normal">{ __( 'Estado' ) }</th>
						<th className="itb:py-1 itb:font-normal" />
					</tr>
				</thead>
				<tbody>
					{ assets.map( ( a ) => (
						<tr key={ a.handle } className="itb:border-t itb:border-zinc-100">
							<td className="itb:py-1 itb:pr-3">
								<code className="itb:break-all">{ a.handle }</code>
							</td>
							<td className="itb:py-1 itb:pr-3 itb:text-zinc-600">{ a.owner ? `${ a.origin }: ${ a.owner }` : a.origin }</td>
							<td className="itb:py-1 itb:pr-3 itb:text-right itb:tabular-nums">{ a.size > 0 ? formatBytes( a.size, config.locale ) : '—' }</td>
							<td className="itb:py-1 itb:pr-3 itb:text-zinc-600">
								{ a.rules.length > 0 && <Badge tone={ a.rules.some( ( r ) => r.status === 'active' ) ? 'green' : 'blue' }>{ __( 'Con regla' ) }</Badge> }
								{ a.needed_by.length > 0 ? ` ${ __( 'Lo necesita' ) }: ${ a.needed_by.join( ', ' ) }` : ! a.enqueued ? ` ${ __( 'Dependencia' ) }` : '' }
								{ a.footer ? ` · ${ __( 'pie' ) }` : '' }
							</td>
							<td className="itb:py-1 itb:text-right">
								{ a.enqueued && ! a.protected && a.needed_by.length === 0 && a.rules.length === 0 && (
									<Button size="sm" variant="ghost" onClick={ () => onRule( a ) }>
										{ __( 'Quitar…' ) }
									</Button>
								) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function SuggestionsCard( { status, onRule }: { status: AssetsStatus; onRule: ( d: Draft ) => void } ) {
	const client = useQueryClient();
	const scan = useAction( () => api<AssetsStatus>( 'assets/suggest', 'POST' ) );
	const s = status.suggestions;
	const pick = ( x: AssetSuggestion ) => onRule( { handle: x.handle, type: x.type, mode: x.mode, templates: x.templates, ids: x.ids.join( ', ' ), post_types: [], note: x.note } );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Sugerencias' ) }</CardTitle>
				<CardDescription>{ __( 'Formularios y sliders solo donde se usan; WooCommerce solo en la tienda. Se inventaría la portada y una página de cada tipo, y se busca el uso en el contenido, Elementor y los widgets.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-2 itb:text-[13px]">
				<div>
					<Button size="sm" variant="outline" disabled={ scan.isPending } onClick={ () => scan.mutate( undefined, { onSuccess: ( d ) => client.setQueryData( KEY, d ) } ) }>
						<Lightbulb aria-hidden />
						{ scan.isPending ? __( 'Analizando…' ) : __( 'Analizar el sitio' ) }
					</Button>
				</div>
				{ scan.error && <Notice level="error">{ scan.error.message }</Notice> }
				{ s && (
					<>
						<div className="itb:text-zinc-500">{ `${ __( 'Analizado' ) } ${ formatDate( s.generated_at, config.locale ) }: ${ s.scanned.map( ( p ) => p.path ).join( ', ' ) }` }</div>
						{ s.errors.map( ( e ) => (
							<Notice key={ e } level="warning">
								{ e }
							</Notice>
						) ) }
						{ s.rules.length === 0 && s.unused.length === 0 && <p className="itb:m-0 itb:text-zinc-500">{ __( 'Nada que sugerir con lo encontrado.' ) }</p> }
						{ s.rules.map( ( x ) => (
							<div key={ `${ x.type }|${ x.handle }` } className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-2 itb:rounded-md itb:border itb:border-zinc-200 itb:p-2">
								<div className="itb:flex itb:min-w-0 itb:flex-wrap itb:items-center itb:gap-2">
									<Badge tone="amber">{ __( 'Moderado' ) }</Badge>
									<code className="itb:break-all">{ x.handle }</code>
									<Badge tone="neutral">{ x.type.toUpperCase() }</Badge>
									<span className="itb:text-zinc-600">{ x.note }</span>
								</div>
								<Button size="sm" variant="outline" onClick={ () => pick( x ) }>
									{ __( 'Preparar regla' ) }
								</Button>
							</div>
						) ) }
						{ s.unused.map( ( u ) => (
							<Notice key={ u.plugin } level="info">
								{ `${ u.label }: ${ __( 'no aparece en ningún contenido. Si no lo usas, desactivarlo ahorra más que quitar sus assets.' ) }` }
							</Notice>
						) ) }
					</>
				) }
			</CardContent>
		</Card>
	);
}
