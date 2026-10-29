import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, History, KeyRound, RefreshCw, Unplug } from 'lucide-react';
import { useState } from 'react';
import { KeyValue, PageHeader, RiskBadge, StatusDot } from '@/components/common';
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
import type { CfAction, CfAnalytics, CfApplyResult, CfChange, CfPlan, CfSetting, CfSnapshot, CfStatus, CfZone, ZarazStatus } from '@/lib/types';
import { formatDate } from '@/lib/utils';

export const ACTIONS: Record<CfAction, { label: string; tone: 'neutral' | 'green' | 'amber' | 'red' | 'blue' }> = {
	create: { label: __( 'Crear' ), tone: 'blue' },
	update: { label: __( 'Modificar' ), tone: 'blue' },
	delete: { label: __( 'Eliminar' ), tone: 'red' },
	none: { label: __( 'Sin cambios' ), tone: 'neutral' },
	drifted: { label: __( 'Modificada a mano' ), tone: 'amber' },
	blocked: { label: __( 'Bloqueado' ), tone: 'neutral' },
};

export const RISK_LABELS = { safe: __( 'Seguro' ), moderate: __( 'Moderado' ), invasive: __( 'Invasivo' ) };

const PERMISSIONS: Record<string, string> = {
	zone_read: __( 'Zona: leer' ),
	settings_read: __( 'Ajustes de zona: leer' ),
	rulesets_read: __( 'Reglas: leer' ),
	analytics_read: __( 'Analíticas: leer' ),
	waf_read: __( 'Firewall: leer' ),
};

function show( value: unknown ): string {
	if ( value === null || value === undefined ) {
		return '—';
	}
	if ( typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean' ) {
		return String( value );
	}
	return JSON.stringify( value );
}

export function Cloudflare() {
	const { data, isLoading, error, isFetching, refetch } = useQuery( { queryKey: [ 'cf' ], queryFn: () => api<CfStatus>( 'cloudflare/status' ) } );
	const connected = data?.token.state === 'ok' && data.zone !== null;

	return (
		<>
			<PageHeader
				title={ __( 'Cloudflare' ) }
				description={ __( 'Reglas de caché, ajustes de zona, purga y snapshots por API. Cada cambio se propone, se aplica con un snapshot previo y se puede revertir.' ) }
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
					{ data.simulator && <Notice level="warning">{ __( 'Conectado a un simulador de la API (ITB_CF_API_BASE en wp-config.php). Solo para pruebas.' ) }</Notice> }
					{ ! data.available && <Notice level="error">{ __( 'Este servidor no tiene la extensión sodium de PHP: el token no se puede guardar cifrado y Cloudflare queda desactivado.' ) }</Notice> }
					<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2 itb:*:min-w-0">
						<ConnectionCard status={ data } />
						{ connected && <ZoneCard status={ data } /> }
					</div>
					{ connected && ! data.enabled && <Notice level="info">{ __( 'El módulo Cloudflare está apagado: puedes revisar la zona, pero para aplicar cambios y purgar el edge actívalo en el Panel.' ) }</Notice> }
					{ connected && data.guard.blocked && <Notice level="warning">{ data.guard.reason }</Notice> }
					{ connected && <PlanCard status={ data } /> }
					{ connected && <ZarazCard /> }
					{ connected && (
						<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2 itb:*:min-w-0">
							<AnalyticsCard />
							<SnapshotsCard status={ data } />
						</div>
					) }
					{ connected && data.footprint.rules + data.footprint.settings > 0 && <RemoveAllCard status={ data } /> }
				</div>
			) }
		</>
	);
}

function ConnectionCard( { status }: { status: CfStatus } ) {
	const client = useQueryClient();
	const [ token, setToken ] = useState( '' );
	const [ confirmForget, setConfirmForget ] = useState( false );
	const connect = useAction( ( value: string ) => api<CfStatus>( 'cloudflare/connect', 'POST', { token: value } ) );
	const forget = useAction( () => api<CfStatus>( 'cloudflare/connect', 'DELETE', { confirm: true } ) );
	const leftovers = status.footprint.rules + status.footprint.settings;
	const done = ( s: CfStatus ) => {
		client.setQueryData( [ 'cf' ], s );
		void client.invalidateQueries( { queryKey: [ 'cf-plan' ] } );
		void client.invalidateQueries( { queryKey: [ 'cf-zone' ] } );
	};
	const state = status.token.state;

	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Conexión' ) }</CardTitle>
				<CardDescription>{ __( 'Un API Token limitado a esta zona. Se guarda cifrado y nunca se exporta.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ state === 'rotated' && <Notice level="warning">{ __( 'Las claves de wp-config.php cambiaron y el token ya no se puede leer. Vuelve a pegarlo.' ) }</Notice> }
				{ state === 'broken' && <Notice level="error">{ __( 'El token guardado está dañado. Vuelve a pegarlo.' ) }</Notice> }
				{ status.error && <Notice level="error">{ status.error }</Notice> }
				{ state === 'ok' && status.zone && (
					<dl className="itb:m-0">
						<KeyValue label={ __( 'Token' ) }>
							<code className="itb:text-xs">••••{ status.token.last4 }</code>
						</KeyValue>
						<KeyValue label={ __( 'Zona' ) }>{ status.zone.name }</KeyValue>
						<KeyValue label={ __( 'Plan' ) }>{ status.zone.plan_name || status.zone.plan }</KeyValue>
						{ Object.entries( status.permissions ).map( ( [ key, ok ] ) => (
							<KeyValue key={ key } label={ PERMISSIONS[ key ] ?? key }>
								<span className="itb:inline-flex itb:items-center itb:gap-1.5">
									<StatusDot ok={ ok } />
									{ ok ? __( 'Sí' ) : __( 'Falta' ) }
								</span>
							</KeyValue>
						) ) }
						<KeyValue label={ __( 'Última revisión' ) }>{ formatDate( status.checked_at, config.locale ) }</KeyValue>
					</dl>
				) }
				<form
					className="itb:flex itb:flex-col itb:gap-2"
					onSubmit={ ( e ) => {
						e.preventDefault();
						connect.mutate( token.trim(), {
							onSuccess: ( s ) => {
								setToken( '' );
								done( s );
							},
						} );
					} }
				>
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:font-medium">{ state === 'ok' ? __( 'Reemplazar el token' ) : __( 'API Token' ) }</span>
						<Input type="password" autoComplete="off" value={ token } onChange={ ( e ) => setToken( e.target.value ) } aria-label={ __( 'API Token' ) } disabled={ ! status.available } />
					</label>
					<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
						<Button type="submit" size="sm" disabled={ ! status.available || token.trim().length < 30 || connect.isPending }>
							<KeyRound aria-hidden />
							{ connect.isPending ? __( 'Verificando…' ) : __( 'Verificar y guardar' ) }
						</Button>
						{ state !== 'none' && (
							<Button type="button" size="sm" variant="ghost" onClick={ () => setConfirmForget( true ) }>
								<Unplug aria-hidden />
								{ __( 'Desconectar' ) }
							</Button>
						) }
						<a className="itb:inline-flex itb:items-center itb:gap-1 itb:text-xs itb:text-zinc-600 itb:underline" href={ status.token_url } target="_blank" rel="noreferrer">
							{ __( 'Crear el token en Cloudflare' ) }
							<ExternalLink className="itb:size-3" aria-hidden />
						</a>
					</div>
					{ connect.error && <Notice level="error">{ connect.error.message }</Notice> }
				</form>
				<details className="itb:text-xs itb:text-zinc-600">
					<summary className="itb:cursor-pointer">{ __( 'Permisos que necesita el token' ) }</summary>
					<ul className="itb:mt-2 itb:list-disc itb:pl-5">
						{ status.token_permissions.map( ( p ) => (
							<li key={ p }>{ p }</li>
						) ) }
					</ul>
					<p className="itb:mt-2">{ __( 'Limítalo a la zona de este sitio. Imagina Turbo nunca toca DNS, SSL ni certificados: solo los lee.' ) }</p>
				</details>
			</CardContent>
			<ConfirmDialog
				open={ confirmForget }
				onOpenChange={ setConfirmForget }
				title={ __( '¿Desconectar Cloudflare?' ) }
				description={
					leftovers > 0
						? `${ __( 'Imagina Turbo todavía tiene en la zona' ) } ${ status.footprint.rules } ${ __( 'reglas y' ) } ${ status.footprint.settings } ${ __( 'ajustes cambiados. Sin el token ya no se pueden quitar desde aquí: usa antes "Quitar todo lo de Imagina Turbo".' ) }`
						: __( 'Se borra el token guardado. No quedan reglas ni ajustes de Imagina Turbo en la zona.' )
				}
				confirmLabel={ __( 'Desconectar' ) }
				destructive
				pending={ forget.isPending }
				onConfirm={ () => forget.mutate( undefined, { onSuccess: done, onSettled: () => setConfirmForget( false ) } ) }
			/>
		</Card>
	);
}

/** Nombres legibles de los valores de los ajustes (los demás se muestran tal cual). */
const VALUE_LABELS: Record<string, string> = {
	on: __( 'Activado' ),
	off: __( 'Desactivado' ),
	keep: __( 'No tocar' ),
	block: __( 'Bloquear' ),
	disallow: __( 'Solo pedirlo en robots.txt' ),
	only_on_ad_pages: __( 'Bloquear en páginas con anuncios' ),
	disabled: __( 'Permitir' ),
	strict: 'Full (strict)',
};

function valueLabel( id: string, value: unknown ): string {
	if ( id === 'browser_cache_ttl' && typeof value === 'number' ) {
		return value === 0 ? __( 'Respetar las cabeceras del sitio' ) : TTL_LABELS[ value ] ?? `${ value } s`;
	}
	return typeof value === 'string' && VALUE_LABELS[ value ] ? VALUE_LABELS[ value ] : show( value );
}

const TTL_LABELS: Record<number, string> = { 1800: '30 min', 14400: '4 h', 86400: '1 día', 2678400: '1 mes', 31536000: '1 año' };

/** Smart Shield: lo que agrupa y en qué plan está. */
const SHIELD_PARTS: Record<string, string> = {
	smart_tiered_cache: __( 'Smart Tiered Cache (se gestiona arriba)' ),
	smart_routing: __( 'Argo Smart Routing (de pago)' ),
	cache_reserve: __( 'Cache Reserve (Enterprise)' ),
	regional_tiered_cache: __( 'Regional Tiered Cache (Enterprise)' ),
};

function ZoneCard( { status }: { status: CfStatus } ) {
	const client = useQueryClient();
	const { data, error, isLoading } = useQuery( { queryKey: [ 'cf-zone' ], queryFn: () => api<CfZone>( 'cloudflare/zone' ), enabled: status.token.state === 'ok' } );
	const [ prefs, setPrefs ] = useState<Record<string, string | number>>( {} );
	const save = useAction( () => api<{ prefs: Record<string, string | number> }>( 'cloudflare/zone-prefs', 'PUT', { prefs } ) );
	const choice = ( s: CfSetting ) => prefs[ s.id ] ?? s.recommended;
	const dirty = Object.keys( prefs ).length > 0;
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Zona' ) }</CardTitle>
				<CardDescription>{ __( 'Estado actual leído de Cloudflare y lo que Imagina Turbo aplicará en cada ajuste. Elige otro valor o «No tocar» y el plan se ajusta; nada cambia en Cloudflare hasta aplicarlo. SSL, DNS y certificados solo se leen.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ isLoading && <Skeleton className="itb:h-40" /> }
				{ error && <Notice level="error">{ error.message }</Notice> }
				{ data?.alerts.map( ( a, i ) => (
					<Notice key={ i } level={ a.level }>
						{ a.message }
					</Notice>
				) ) }
				{ data && (
					<>
						<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-3 itb:p-0">
							{ data.settings.map( ( s ) => (
								<li key={ s.id } className="itb:flex itb:flex-col itb:gap-1.5 itb:border-b itb:border-zinc-100 itb:pb-3 itb:last:border-0">
									<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-2">
										<span className="itb:font-medium">{ s.label }</span>
										<span className="itb:inline-flex itb:flex-wrap itb:items-center itb:gap-1.5">
											<span className="itb:text-xs itb:text-zinc-500">{ __( 'Ahora:' ) }</span>
											<code className="itb:text-xs">{ s.readable ? valueLabel( s.id, s.value ) : '—' }</code>
											{ s.read_only && <Badge>{ __( 'Solo lectura' ) }</Badge> }
											{ ! s.read_only && s.managed && s.readable && show( s.value ) !== show( s.recommended ) && <Badge tone="amber">{ `→ ${ valueLabel( s.id, s.recommended ) }` }</Badge> }
											{ ! s.read_only && ! s.managed && <Badge>{ __( 'Sin gestionar' ) }</Badge> }
										</span>
									</div>
									<span className="itb:text-xs itb:text-zinc-500">{ s.note }</span>
									{ s.error && <span className="itb:text-xs itb:text-amber-700">{ s.error }</span> }
									{ ! s.read_only && (
										<label className="itb:flex itb:items-center itb:gap-2">
											<span className="itb:text-xs itb:text-zinc-600">{ __( 'Imagina Turbo:' ) }</span>
											<Select
												className="itb:h-8 itb:max-w-72 itb:text-xs"
												value={ String( choice( s ) ) }
												aria-label={ s.label }
												onChange={ ( e ) => {
													const raw = e.target.value;
													const value = typeof s.options[ 0 ] === 'number' && raw !== 'keep' ? Number( raw ) : raw;
													setPrefs( { ...prefs, [ s.id ]: value } );
												} }
											>
												<option value="keep">{ VALUE_LABELS.keep }</option>
												{ s.options.map( ( o ) => (
													<option key={ String( o ) } value={ String( o ) }>
														{ valueLabel( s.id, o ) }
														{ o === s.default ? ` (${ __( 'recomendado' ) })` : '' }
													</option>
												) ) }
											</Select>
										</label>
									) }
								</li>
							) ) }
						</ul>
						{ dirty && (
							<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
								<Button
									size="sm"
									disabled={ save.isPending }
									onClick={ () =>
										save.mutate( undefined, {
											onSuccess: () => {
												setPrefs( {} );
												void client.invalidateQueries( { queryKey: [ 'cf-zone' ] } );
												void client.invalidateQueries( { queryKey: [ 'cf-plan' ] } );
											},
										} )
									}
								>
									{ save.isPending ? __( 'Guardando…' ) : __( 'Guardar y revisar el plan' ) }
								</Button>
								<Button size="sm" variant="outline" onClick={ () => setPrefs( {} ) }>
									{ __( 'Descartar' ) }
								</Button>
							</div>
						) }
						{ save.error && <Notice level="error">{ save.error.message }</Notice> }
						<dl className="itb:m-0">
							{ data.phases.map( ( p ) => (
								<KeyValue key={ p.phase } label={ p.label }>
									{ `${ p.own } ${ __( 'propias' ) } · ${ p.foreign } ${ __( 'del cliente' ) }${ p.limit > 0 ? ` · ${ __( 'máx.' ) } ${ p.limit }` : '' }` }
								</KeyValue>
							) ) }
						</dl>
						<div className="itb:flex itb:flex-col itb:gap-1.5">
							<span className="itb:font-medium">Smart Shield</span>
							{ data.smart_shield ? (
								<dl className="itb:m-0">
									{ Object.entries( data.smart_shield ).map( ( [ key, part ] ) => (
										<KeyValue key={ key } label={ SHIELD_PARTS[ key ] ?? key }>
											<code className="itb:text-xs">{ valueLabel( key, part.value ) }</code>
										</KeyValue>
									) ) }
								</dl>
							) : (
								<span className="itb:text-xs itb:text-zinc-500">{ __( 'No se pudo leer (permiso Zone Settings del token).' ) }</span>
							) }
							<span className="itb:text-xs itb:text-zinc-500">{ __( 'Agrupa la conexión optimizada con el servidor. En el plan gratuito, lo que se puede activar es Smart Tiered Cache, que ya aparece arriba.' ) }</span>
						</div>
						<div className="itb:flex itb:flex-col itb:gap-1.5">
							<span className="itb:font-medium">{ __( 'Solo desde el panel de Cloudflare' ) }</span>
							<span className="itb:text-xs itb:text-zinc-500">
								{ __( 'Crawler Hints avisa a Bing y Yandex (IndexNow) cuando cambia una página para que no la recorran a ciegas: menos visitas de bots al servidor. No tiene API, se activa en Caché → Configuración.' ) }
							</span>
							<span className="itb:flex itb:flex-wrap itb:gap-2">
								<a className="itb:inline-flex itb:items-center itb:gap-1 itb:text-xs" href={ data.dashboard.crawler_hints } target="_blank" rel="noreferrer">
									<ExternalLink className="itb:size-3.5" aria-hidden />
									Crawler Hints
								</a>
								<a className="itb:inline-flex itb:items-center itb:gap-1 itb:text-xs" href={ data.dashboard.ai_crawl } target="_blank" rel="noreferrer">
									<ExternalLink className="itb:size-3.5" aria-hidden />
									AI Crawl Control
								</a>
							</span>
						</div>
					</>
				) }
			</CardContent>
		</Card>
	);
}

function PlanCard( { status }: { status: CfStatus } ) {
	const client = useQueryClient();
	const { data, error, isLoading, refetch, isFetching } = useQuery( { queryKey: [ 'cf-plan' ], queryFn: () => api<CfPlan>( 'cloudflare/plan' ) } );
	const [ selected, setSelected ] = useState<string[] | null>( null );
	const [ confirm, setConfirm ] = useState( false );
	const apply = useAction( ( ids: string[] ) => api<CfApplyResult>( 'cloudflare/apply', 'POST', { ids, confirm: true } ) );
	const pending = ( data?.changes ?? [] ).filter( ( c ) => c.action !== 'none' && c.action !== 'blocked' );
	const chosen = selected ?? pending.filter( ( c ) => c.action !== 'drifted' && c.risk === 'safe' ).map( ( c ) => c.id );
	const toggle = ( id: string ) => setSelected( chosen.includes( id ) ? chosen.filter( ( x ) => x !== id ) : [ ...chosen, id ] );
	const canApply = status.enabled && ! status.guard.blocked;

	return (
		<Card>
			<CardHeader>
				<div className="itb:flex itb:flex-wrap itb:items-start itb:justify-between itb:gap-3">
					<div>
						<CardTitle>{ __( 'Cambios propuestos' ) }</CardTitle>
						<CardDescription>{ __( 'Diferencia entre la zona y lo que Imagina Turbo recomienda para este sitio. Solo toca reglas propias (ref itb-*) y los ajustes listados.' ) }</CardDescription>
					</div>
					<Button size="sm" variant="outline" onClick={ () => void refetch() } disabled={ isFetching }>
						<RefreshCw className={ isFetching ? 'itb:animate-spin' : '' } aria-hidden />
						{ __( 'Revisar de nuevo' ) }
					</Button>
				</div>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ isLoading && <Skeleton className="itb:h-40" /> }
				{ error && <Notice level="error">{ error.message }</Notice> }
				{ data?.notes.map( ( n, i ) => (
					<Notice key={ i } level="info">
						{ n }
					</Notice>
				) ) }
				{ data && (
					<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-2 itb:p-0">
						{ data.changes.map( ( c ) => (
							<ChangeRow key={ c.id } change={ c } checked={ chosen.includes( c.id ) } onToggle={ () => toggle( c.id ) } disabled={ ! canApply || c.action === 'none' || c.action === 'blocked' } />
						) ) }
					</ul>
				) }
				{ data && pending.length === 0 && <Notice level="success">{ __( 'La zona ya está como se recomienda.' ) }</Notice> }
				<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
					<Button size="sm" disabled={ ! canApply || chosen.length === 0 || apply.isPending } onClick={ () => setConfirm( true ) }>
						{ __( 'Aplicar seleccionados' ) }
						{ chosen.length > 0 && ` (${ chosen.length })` }
					</Button>
					{ ! canApply && <span className="itb:text-xs itb:text-zinc-500">{ status.guard.blocked ? __( 'Aplicar no está permitido en este dominio.' ) : __( 'Activa el módulo Cloudflare para aplicar.' ) }</span> }
				</div>
				{ apply.data && (
					<Notice level={ apply.data.failed.length > 0 ? 'error' : 'success' }>
						{ apply.data.failed.length > 0
							? `${ __( 'Algunos cambios fallaron' ) }${ apply.data.rolled_back ? ` ${ __( 'y se restauró el snapshot previo.' ) }` : '.' } ${ apply.data.failed.map( ( f ) => `${ f.id }: ${ f.error }` ).join( ' · ' ) }`
							: `${ __( 'Cambios aplicados. Snapshot previo' ) } #${ apply.data.snapshot_id }.` }
					</Notice>
				) }
				{ apply.error && <Notice level="error">{ apply.error.message }</Notice> }
			</CardContent>
			<ConfirmDialog
				open={ confirm }
				onOpenChange={ setConfirm }
				title={ __( '¿Aplicar los cambios en Cloudflare?' ) }
				description={ __( 'Antes de tocar la zona se guarda un snapshot con el estado actual de las reglas propias y los ajustes afectados. Si algo falla a mitad, se restaura solo. Queda en el registro.' ) }
				confirmLabel={ __( 'Aplicar' ) }
				pending={ apply.isPending }
				onConfirm={ () =>
					apply.mutate( chosen, {
						onSettled: () => {
							setConfirm( false );
							setSelected( null );
							void client.invalidateQueries( { queryKey: [ 'cf-plan' ] } );
							void client.invalidateQueries( { queryKey: [ 'cf-zone' ] } );
							void client.invalidateQueries( { queryKey: [ 'cf-snapshots' ] } );
						},
					} )
				}
			/>
		</Card>
	);
}

export function ChangeRow( { change, checked, onToggle, disabled }: { change: CfChange; checked: boolean; onToggle: () => void; disabled: boolean } ) {
	const a = ACTIONS[ change.action ];
	return (
		<li className="itb:rounded-md itb:border itb:border-zinc-200 itb:p-3">
			<label className="itb:flex itb:cursor-pointer itb:items-start itb:gap-2">
				<input type="checkbox" className="itb:mt-0.5 itb:shrink-0" checked={ checked && ! disabled } disabled={ disabled } onChange={ onToggle } aria-label={ change.title } />
				<span className="itb:min-w-0 itb:flex-1">
					<span className="itb:flex itb:flex-wrap itb:items-center itb:gap-1.5">
						<span className="itb:font-medium">{ change.title }</span>
						<Badge tone={ a.tone }>{ a.label }</Badge>
						<RiskBadge risk={ change.risk } label={ RISK_LABELS[ change.risk ] } />
					</span>
					<span className="itb:mt-0.5 itb:block itb:text-xs itb:text-zinc-500">{ change.reason || change.description }</span>
					{ change.action !== 'none' && change.action !== 'blocked' && (
						<details className="itb:mt-1 itb:text-xs">
							<summary className="itb:cursor-pointer itb:text-zinc-600">{ __( 'Ver lo que se enviará' ) }</summary>
							<div className="itb:mt-1 itb:grid itb:gap-2 itb:md:grid-cols-2">
								<pre className="itb:m-0 itb:max-h-64 itb:overflow-auto itb:rounded itb:bg-zinc-50 itb:p-2 itb:text-[11px]">{ `${ __( 'Actual' ) }\n${ JSON.stringify( change.current, null, 2 ) }` }</pre>
								<pre className="itb:m-0 itb:max-h-64 itb:overflow-auto itb:rounded itb:bg-zinc-50 itb:p-2 itb:text-[11px]">{ `${ __( 'Propuesto' ) }\n${ JSON.stringify( change.desired, null, 2 ) }` }</pre>
							</div>
						</details>
					) }
				</span>
			</label>
		</li>
	);
}

function SnapshotsCard( { status }: { status: CfStatus } ) {
	const client = useQueryClient();
	const { data } = useQuery( { queryKey: [ 'cf-snapshots' ], queryFn: () => api<CfSnapshot[]>( 'cloudflare/snapshots' ) } );
	const [ target, setTarget ] = useState<CfSnapshot | null>( null );
	const restore = useAction( ( id: number ) => api<CfApplyResult>( `cloudflare/snapshots/${ id }/restore`, 'POST', { confirm: true } ) );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Snapshots' ) }</CardTitle>
				<CardDescription>{ __( 'Estado de la zona antes de cada cambio. Restaurar solo afecta las reglas propias y los ajustes que se tocaron.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-2 itb:text-[13px]">
				{ data && data.length === 0 && <p className="itb:m-0 itb:text-zinc-500">{ __( 'Todavía no hay snapshots.' ) }</p> }
				<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:p-0">
					{ data?.map( ( s ) => (
						<li key={ s.id } className="itb:flex itb:items-center itb:justify-between itb:gap-3 itb:border-b itb:border-zinc-100 itb:py-2 itb:last:border-b-0">
							<span className="itb:min-w-0">
								<span className="itb:font-medium">#{ s.id }</span> <span className="itb:text-zinc-500">{ formatDate( s.created_at, config.locale ) }</span>
								<span className="itb:block itb:truncate itb:text-xs itb:text-zinc-500">{ s.reason }</span>
							</span>
							<Button size="sm" variant="outline" disabled={ status.guard.blocked || restore.isPending } onClick={ () => setTarget( s ) }>
								<History aria-hidden />
								{ __( 'Restaurar' ) }
							</Button>
						</li>
					) ) }
				</ul>
				{ restore.data && <Notice level={ restore.data.failed.length > 0 ? 'error' : 'success' }>{ restore.data.failed.length > 0 ? restore.data.failed.map( ( f ) => `${ f.id }: ${ f.error }` ).join( ' · ' ) : __( 'Snapshot restaurado.' ) }</Notice> }
				{ restore.error && <Notice level="error">{ restore.error.message }</Notice> }
			</CardContent>
			<ConfirmDialog
				open={ target !== null }
				onOpenChange={ ( o ) => ! o && setTarget( null ) }
				title={ __( '¿Restaurar este snapshot?' ) }
				description={ __( 'Las reglas propias y los ajustes que se tocaron vuelven al estado guardado. Antes se guarda un snapshot del estado actual, así que también esto se puede deshacer.' ) }
				confirmLabel={ __( 'Restaurar' ) }
				pending={ restore.isPending }
				onConfirm={ () =>
					target &&
					restore.mutate( target.id, {
						onSettled: () => {
							setTarget( null );
							void client.invalidateQueries( { queryKey: [ 'cf-plan' ] } );
							void client.invalidateQueries( { queryKey: [ 'cf-zone' ] } );
							void client.invalidateQueries( { queryKey: [ 'cf-snapshots' ] } );
						},
					} )
				}
			/>
		</Card>
	);
}

function RemoveAllCard( { status }: { status: CfStatus } ) {
	const client = useQueryClient();
	const [ open, setOpen ] = useState( false );
	const remove = useAction( () => api<CfApplyResult>( 'cloudflare/remove-all', 'POST', { confirm: true } ) );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Quitar todo lo de Imagina Turbo' ) }</CardTitle>
				<CardDescription>
					{ `${ __( 'En la zona hay' ) } ${ status.footprint.rules } ${ __( 'reglas de Imagina Turbo y' ) } ${ status.footprint.settings } ${ __( 'ajustes que cambió. Úsalo antes de desactivar o borrar el plugin: borra sus reglas (las tuyas no se tocan) y devuelve los ajustes a su valor anterior. Guarda antes un snapshot, así que también se puede deshacer.' ) }` }
				</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-2 itb:text-[13px]">
				<div>
					<Button size="sm" variant="destructive" disabled={ status.guard.blocked || remove.isPending } onClick={ () => setOpen( true ) }>
						{ __( 'Quitar todo de Cloudflare' ) }
					</Button>
				</div>
				{ remove.data && <Notice level={ remove.data.failed.length > 0 ? 'error' : 'success' }>{ remove.data.failed.length > 0 ? remove.data.failed.map( ( f ) => `${ f.id }: ${ f.error }` ).join( ' · ' ) : __( 'Listo: la zona ya no tiene nada de Imagina Turbo.' ) }</Notice> }
				{ remove.error && <Notice level="error">{ remove.error.message }</Notice> }
			</CardContent>
			<ConfirmDialog
				open={ open }
				onOpenChange={ setOpen }
				title={ __( '¿Quitar todo lo de Imagina Turbo de Cloudflare?' ) }
				description={ __( 'Se borran sus reglas de caché, reescritura y firewall, y los ajustes de zona que cambió vuelven a su valor anterior. Antes se guarda un snapshot para poder deshacerlo.' ) }
				confirmLabel={ __( 'Quitar todo' ) }
				destructive
				pending={ remove.isPending }
				onConfirm={ () =>
					remove.mutate( undefined, {
						onSettled: () => {
							setOpen( false );
							void client.invalidateQueries( { queryKey: [ 'cf' ] } );
							void client.invalidateQueries( { queryKey: [ 'cf-plan' ] } );
							void client.invalidateQueries( { queryKey: [ 'cf-zone' ] } );
							void client.invalidateQueries( { queryKey: [ 'cf-snapshots' ] } );
						},
					} )
				}
			/>
		</Card>
	);
}

function AnalyticsCard() {
	const { data, error, isLoading } = useQuery( { queryKey: [ 'cf-analytics' ], queryFn: () => api<CfAnalytics>( 'cloudflare/analytics' ), staleTime: 300_000 } );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Edge (últimas 24 h)' ) }</CardTitle>
				<CardDescription>{ __( 'Peticiones y aciertos de caché según Cloudflare. Se actualiza cada hora.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ isLoading && <Skeleton className="itb:h-32" /> }
				{ error && <Notice level="error">{ error.message }</Notice> }
				{ data && ! data.available && <Notice level="info">{ data.error || __( 'Sin datos de analíticas todavía.' ) }</Notice> }
				{ data?.available && (
					<>
						<dl className="itb:m-0">
							<KeyValue label={ __( 'Peticiones' ) }>{ data.requests.toLocaleString( config.locale ) }</KeyValue>
							<KeyValue label={ __( 'Servidas desde caché' ) }>{ data.ratio === null ? '—' : `${ Math.round( data.ratio * 1000 ) / 10 } %` }</KeyValue>
							{ data.by_status.slice( 0, 6 ).map( ( s ) => (
								<KeyValue key={ s.status } label={ s.status }>
									{ s.requests.toLocaleString( config.locale ) }
								</KeyValue>
							) ) }
						</dl>
						{ data.top_uncached.length > 0 && (
							<div>
								<p className="itb:m-0 itb:mb-1 itb:font-medium">{ __( 'Rutas que más llegan al origen' ) }</p>
								<ul className="itb:m-0 itb:list-none itb:p-0">
									{ data.top_uncached.slice( 0, 8 ).map( ( r ) => (
										<li key={ r.path } className="itb:flex itb:justify-between itb:gap-3 itb:py-0.5">
											<code className="itb:truncate itb:text-xs">{ r.path }</code>
											<span className="itb:tabular-nums">{ r.requests.toLocaleString( config.locale ) }</span>
										</li>
									) ) }
								</ul>
							</div>
						) }
						<p className="itb:m-0 itb:text-xs itb:text-zinc-500">
							{ __( 'Actualizado:' ) } { formatDate( data.fetched_at, config.locale ) }
						</p>
					</>
				) }
			</CardContent>
		</Card>
	);
}

function ZarazCard() {
	const client = useQueryClient();
	const { data, isLoading, error } = useQuery( { queryKey: [ 'zaraz' ], queryFn: () => api<ZarazStatus>( 'cloudflare/zaraz' ) } );
	const [ ga4, setGa4 ] = useState( false );
	const [ meta, setMeta ] = useState( false );
	const [ token, setToken ] = useState( '' );
	const [ confirm, setConfirm ] = useState<'' | 'apply' | 'revert'>( '' );
	const done = { onSuccess: ( s: ZarazStatus ) => client.setQueryData( [ 'zaraz' ], s ) };
	const scan = useAction( () => api<ZarazStatus>( 'cloudflare/zaraz/scan', 'POST' ) );
	const apply = useAction( () => api<ZarazStatus>( 'cloudflare/zaraz/apply', 'POST', { ga4, meta, meta_token: token, confirm: true } ) );
	const revert = useAction( () => api<ZarazStatus>( 'cloudflare/zaraz/revert', 'POST', { confirm: true } ) );
	if ( isLoading ) {
		return <Skeleton className="itb:h-40" />;
	}
	if ( error || ! data ) {
		return <Notice level="error">{ error?.message ?? '' }</Notice>;
	}
	const ga4Item = data.plugins.ga4.find( ( g ) => g.takeover );
	const metaItem = data.plugins.meta.find( ( m ) => m.takeover );
	const active = data.takeover.ga4 || data.takeover.meta;
	const ids = data.page?.ids;
	const gtm = [ ...new Set( [ ...data.plugins.gtm.map( ( g ) => g.id ), ...( ids?.gtm ?? [] ) ] ) ];
	return (
		<Card>
			<CardHeader>
				<div className="itb:flex itb:items-center itb:gap-2">
					<CardTitle>{ __( 'Zaraz: Google Analytics y Meta desde Cloudflare' ) }</CardTitle>
					<Badge tone="amber">{ __( 'Moderado' ) }</Badge>
				</div>
				<CardDescription>
					{ __( 'Cloudflare carga Google Analytics 4 y el píxel de Meta desde sus servidores: la página deja de descargar gtag.js y fbevents.js (cientos de KB de JavaScript de terceros). Los eventos de la tienda (producto visto, añadido al carrito, pago iniciado, pedido completado) se envían con la API de e-commerce de Zaraz. Gratis hasta 1 millón de eventos al mes por cuenta; si se pasa sin el plan de pago, Zaraz se apaga hasta el mes siguiente. Necesita el permiso Zona → Zaraz → Editar en el token.' ) }
				</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ data.zone && ! data.zone.ok && <Notice level="warning">{ data.zone.error }</Notice> }
				<div className="itb:grid itb:gap-2 itb:sm:grid-cols-2">
					<KeyValue label={ __( 'Google Analytics 4' ) }>{ data.plugins.ga4.map( ( g ) => `${ g.id } (${ g.source })` ).join( ', ' ) || '—' }</KeyValue>
					<KeyValue label={ __( 'Píxel de Meta' ) }>{ data.plugins.meta.map( ( m ) => `${ m.id } (${ m.source })` ).join( ', ' ) || '—' }</KeyValue>
					<KeyValue label={ __( 'Google Tag Manager' ) }>{ gtm.join( ', ' ) || '—' }</KeyValue>
					<KeyValue label={ __( 'En Zaraz' ) }>{ data.zone?.tools?.map( ( t ) => `${ t.name }${ t.enabled ? '' : ` (${ __( 'apagada' ) })` }` ).join( ', ' ) || '—' }</KeyValue>
				</div>
				{ ids && (
					<p className="itb:text-xs itb:text-zinc-500">
						{ `${ __( 'En el HTML de la portada' ) } (${ formatDate( data.page?.at, config.locale ) }): ` }
						{ [ ...ids.ga4, ...ids.google_tag, ...ids.gtm, ...ids.meta ].join( ', ' ) || __( 'ninguna etiqueta' ) }
					</p>
				) }
				{ gtm.length > 0 && <Notice level="info">{ __( 'Un contenedor de Google Tag Manager no se puede pasar a Zaraz. Revisa qué etiquetas tiene: si repite Google Analytics o Meta, quítalas del contenedor para no contar dos veces.' ) }</Notice> }
				<div>
					<Button size="sm" variant="outline" disabled={ scan.isPending } onClick={ () => scan.mutate( undefined, done ) }>
						<RefreshCw className={ scan.isPending ? 'itb:animate-spin' : '' } aria-hidden />
						{ __( 'Buscar etiquetas en la portada' ) }
					</Button>
				</div>
				{ active ? (
					<>
						<Notice level="success">
							{ `${ __( 'Zaraz reemplaza en el sitio a:' ) } ${ [ data.takeover.ga4 ? `Google Analytics ${ data.state.ga4 ?? '' }` : '', data.takeover.meta ? `Meta ${ data.state.meta ?? '' }` : '' ].filter( Boolean ).join( ' · ' ) }. ${ __( 'Las etiquetas de Site Kit y Meta for WooCommerce dejan de imprimirse. Si alguien quita las herramientas de Zaraz, lo apaga o pausa la zona, vuelven solas en la siguiente revisión horaria. Si quitas el proxy de Cloudflare (nube gris) de este dominio, revierte Zaraz aquí primero.' ) }` }
						</Notice>
						{ data.state.published === false && <Notice level="warning">{ __( 'La configuración quedó como borrador: publícala en Cloudflare → Zaraz.' ) }</Notice> }
						<div>
							<Button size="sm" variant="outline" disabled={ revert.isPending } onClick={ () => setConfirm( 'revert' ) }>
								{ __( 'Volver a las etiquetas del sitio' ) }
							</Button>
						</div>
					</>
				) : (
					<>
						<label className="itb:flex itb:items-center itb:justify-between itb:gap-3">
							<span>
								<span className="itb:font-medium">{ ga4Item ? `Google Analytics 4 → Zaraz (${ ga4Item.id })` : __( 'Google Analytics 4 → Zaraz' ) }</span>
								<span className="itb:block itb:text-xs itb:text-zinc-500">{ ga4Item ? __( 'Se apaga la etiqueta de Site Kit (y sus eventos de conversión: los reemplazan los de Zaraz).' ) : __( 'Solo se puede tomar el de Site Kit. Si viene de otro plugin o del tema, configúralo en Zaraz a mano y quítalo del sitio.' ) }</span>
							</span>
							<Switch checked={ ga4 } disabled={ ! ga4Item } onCheckedChange={ setGa4 } aria-label="GA4" />
						</label>
						<label className="itb:flex itb:items-center itb:justify-between itb:gap-3">
							<span>
								<span className="itb:font-medium">{ metaItem ? `Meta → Zaraz (${ metaItem.id })` : __( 'Meta → Zaraz' ) }</span>
								<span className="itb:block itb:text-xs itb:text-zinc-500">{ metaItem ? __( 'Se apagan el píxel y la API de conversiones de Meta for WooCommerce: Zaraz los envía desde Cloudflare (el catálogo sigue igual).' ) : __( 'Solo se puede tomar el de Meta for WooCommerce.' ) }</span>
							</span>
							<Switch checked={ meta } disabled={ ! metaItem } onCheckedChange={ setMeta } aria-label="Meta" />
						</label>
						{ meta && (
							<label className="itb:flex itb:flex-col itb:gap-1">
								<span className="itb:text-xs itb:font-medium">{ __( 'Token de la API de conversiones de Meta' ) }</span>
								<Input type="password" value={ token } autoComplete="off" onChange={ ( e ) => setToken( e.target.value ) } placeholder="EAA…" />
								<span className="itb:text-xs itb:text-zinc-500">{ __( 'Administrador de eventos → tu píxel → Configuración → API de conversiones → Generar token de acceso. Se envía a Cloudflare y no se guarda en el sitio.' ) }</span>
							</label>
						) }
						<div>
							<Button size="sm" disabled={ apply.isPending || ! data.ready || ! data.cloudflare_module || ( ! ga4 && ! meta ) || ( meta && token.trim().length < 40 ) } onClick={ () => setConfirm( 'apply' ) }>
								{ apply.isPending ? __( 'Configurando…' ) : __( 'Configurar Zaraz' ) }
							</Button>
						</div>
						{ ! data.cloudflare_module && <Notice level="info">{ __( 'Activa el módulo Cloudflare para configurar Zaraz.' ) }</Notice> }
					</>
				) }
				{ ( apply.error || revert.error || scan.error ) && <Notice level="error">{ ( apply.error ?? revert.error ?? scan.error )?.message }</Notice> }
				{ revert.data?.result && ! revert.data.result.ok && <Notice level="warning">{ revert.data.result.error }</Notice> }
			</CardContent>
			<ConfirmDialog
				open={ confirm !== '' }
				onOpenChange={ ( open ) => ! open && setConfirm( '' ) }
				title={ confirm === 'apply' ? __( '¿Pasar la analítica a Zaraz?' ) : __( '¿Volver a las etiquetas del sitio?' ) }
				description={
					confirm === 'apply'
						? __( 'Se agregan las herramientas a Zaraz con e-commerce y, si Cloudflare las acepta, se apagan las etiquetas del sitio y se purga la caché. Revisa en Google Analytics (Tiempo real) y en el Administrador de eventos de Meta que lleguen las visitas y las compras.' )
						: __( 'Las etiquetas del sitio vuelven en el acto (se purga la caché) y se quitan de Zaraz las herramientas de Imagina Turbo.' )
				}
				confirmLabel={ confirm === 'apply' ? __( 'Configurar' ) : __( 'Volver' ) }
				pending={ apply.isPending || revert.isPending }
				onConfirm={ () => ( confirm === 'apply' ? apply : revert ).mutate( undefined, { ...done, onSettled: () => setConfirm( '' ) } ) }
			/>
		</Card>
	);
}

