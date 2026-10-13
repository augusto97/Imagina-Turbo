import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Flame, Play, RefreshCw, Search, Square, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { KeyValue, PageHeader } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Input, Select } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { api, ApiError } from '@/lib/api';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import { useAction } from '@/lib/queries';
import type { CacheSettings, CacheStatus, InspectResult } from '@/lib/types';
import { formatDate } from '@/lib/utils';

const HOUR = 3600;
const DAY = 86400;

const TTL_OPTIONS = [ HOUR, 4 * HOUR, 10 * HOUR, DAY, 3 * DAY, 7 * DAY ];
const SWR_OPTIONS = [ 0, HOUR, DAY, 7 * DAY ];
const SIE_OPTIONS = [ 0, DAY, 3 * DAY, 7 * DAY ];

const ADAPTERS: Record<string, string> = {
	litespeed: 'LiteSpeed Cache',
	'wp-rocket': 'WP Rocket',
	none: __( 'Ninguno (solo edge)' ),
};

function duration( seconds: number ): string {
	if ( seconds === 0 ) {
		return __( 'No' );
	}
	if ( seconds % DAY === 0 ) {
		const days = seconds / DAY;
		return `${ days } ${ days === 1 ? __( 'día' ) : __( 'días' ) }`;
	}
	const hours = seconds / HOUR;
	return `${ hours } ${ hours === 1 ? __( 'hora' ) : __( 'horas' ) }`;
}

export function Cache() {
	const client = useQueryClient();
	const { data, isLoading, error, isFetching, refetch } = useQuery( { queryKey: [ 'cache' ], queryFn: () => api<CacheStatus>( 'cache/status' ), refetchInterval: 15_000 } );
	const onDone = ( status: CacheStatus ) => client.setQueryData( [ 'cache' ], status );

	return (
		<>
			<PageHeader
				title={ __( 'Caché' ) }
				description={ __( 'Coordina la caché del servidor con el edge: etiqueta páginas, sincroniza purgas y recalienta lo purgado.' ) }
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
					{ ! data.enabled && <EnableCard /> }
					<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2">
						<AdapterCard status={ data } />
						<SettingsCard status={ data } onDone={ onDone } />
						<QueueCard status={ data } onDone={ onDone } />
						<PurgeCard status={ data } onDone={ onDone } />
					</div>
					<InspectCard />
				</div>
			) }
		</>
	);
}

function EnableCard() {
	const [ open, setOpen ] = useState( false );
	const client = useQueryClient();
	const action = useAction( () => api( 'modules/cache_coordinator', 'PUT', { enabled: true, confirm: true } ) );
	return (
		<Notice level="warning">
			<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-3">
				<span>{ __( 'El coordinador de caché está apagado. Es de riesgo moderado: empieza solo sincronizando purgas y recalentando; las cabeceras para el edge se activan después, tras verificar.' ) }</span>
				<Button size="sm" onClick={ () => setOpen( true ) }>
					{ __( 'Activar' ) }
				</Button>
			</div>
			{ action.error && <div className="itb:mt-2">{ action.error.message }</div> }
			<ConfirmDialog
				open={ open }
				onOpenChange={ setOpen }
				title={ __( '¿Activar el coordinador de caché?' ) }
				description={ __( 'Sincronizará las purgas con el plugin de caché, recalentará lo purgado y corregirá los nonces de las páginas cacheadas. No emite cabeceras al edge hasta que lo decidas. Queda registrado y se apaga desde aquí o desde el Panel.' ) }
				confirmLabel={ __( 'Activar' ) }
				pending={ action.isPending }
				onConfirm={ () => action.mutate( undefined, { onSettled: () => setOpen( false ), onSuccess: () => void client.invalidateQueries( { queryKey: [ 'cache' ] } ) } ) }
			/>
		</Notice>
	);
}

function AdapterCard( { status }: { status: CacheStatus } ) {
	const adapter = status.adapter;
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Caché de origen' ) }</CardTitle>
				<CardDescription>{ __( 'El plugin de caché de página del sitio. Imagina Turbo no cachea páginas: coordina.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				<dl className="itb:m-0">
					<KeyValue label={ __( 'Plugin' ) }>{ ADAPTERS[ adapter.id ] ?? adapter.id }</KeyValue>
					<KeyValue label={ __( 'Servidor' ) }>{ adapter.server }</KeyValue>
					<KeyValue label={ __( 'Cabeceras en los aciertos' ) }>
						{ adapter.headers_on_hit ? <Badge tone="green">{ __( 'Sí' ) }</Badge> : <Badge tone="amber">{ __( 'No: purga del edge por URL' ) }</Badge> }
						{ ! adapter.verified && <Badge className="itb:ml-1">{ __( 'Sin verificar en este servidor' ) }</Badge> }
					</KeyValue>
					<KeyValue label={ __( 'HTML en el edge' ) }>{ adapter.edge_html ? __( 'Posible' ) : __( 'No (sus aciertos no llevan cabeceras)' ) }</KeyValue>
					<KeyValue label={ __( 'Edge conectado' ) }>{ status.edge.available ? __( 'Sí' ) : __( 'No (Cloudflare llega en la fase 2b)' ) }</KeyValue>
					<KeyValue label={ __( 'Prefijo de tags' ) }>
						<code className="itb:text-xs">{ status.tags.prefix }-</code>
					</KeyValue>
				</dl>
			</CardContent>
		</Card>
	);
}

function SettingsCard( { status, onDone }: { status: CacheStatus; onDone: ( s: CacheStatus ) => void } ) {
	const [ form, setForm ] = useState<CacheSettings>( status.settings );
	const [ confirmHeaders, setConfirmHeaders ] = useState( false );
	const save = useAction( ( vars: Partial<CacheSettings> & { confirm?: boolean } ) => api<CacheStatus>( 'cache/settings', 'PUT', vars ) );

	useEffect( () => setForm( status.settings ), [ status.settings ] );

	const dirty = JSON.stringify( form ) !== JSON.stringify( status.settings );
	const capped = ! form.nonce_refresh && form.edge_ttl > 10 * HOUR;
	const submit = ( confirm = false ) =>
		save.mutate( { ...form, confirm }, { onSuccess: onDone, onSettled: () => setConfirmHeaders( false ) } );

	const onSave = () => {
		if ( form.mode === 'headers' && status.settings.mode !== 'headers' ) {
			setConfirmHeaders( true );
			return;
		}
		submit();
	};

	const select = ( key: 'edge_ttl' | 'swr' | 'sie', options: number[] ) => (
		<Select className="itb:w-32" value={ String( form[ key ] ) } onChange={ ( e ) => setForm( { ...form, [ key ]: Number( e.target.value ) } ) } aria-label={ key }>
			{ ( options.includes( form[ key ] ) ? options : [ ...options, form[ key ] ].sort( ( a, b ) => a - b ) ).map( ( o ) => (
				<option key={ o } value={ o }>
					{ duration( o ) }
				</option>
			) ) }
		</Select>
	);

	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Ajustes' ) }</CardTitle>
				<CardDescription>{ __( 'El edge guarda las páginas anónimas el tiempo indicado; las purgas por tag las renuevan al cambiar el contenido.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				<fieldset className="itb:m-0 itb:flex itb:flex-col itb:gap-2 itb:border-0 itb:p-0">
					<legend className="itb:mb-1 itb:font-medium">{ __( 'Modo' ) }</legend>
					{ ( [
						[ 'sync', __( 'Solo sincronizar purgas' ), __( 'Purga y recalienta junto al plugin de caché. Sin cabeceras para el edge.' ) ],
						[ 'headers', __( 'Emitir cabeceras para el edge' ), __( 'Además, Cloudflare-CDN-Cache-Control y Cache-Tag en las páginas cacheables.' ) ],
					] as const ).map( ( [ value, label, hint ] ) => (
						<label key={ value } className="itb:flex itb:cursor-pointer itb:items-start itb:gap-2">
							<input type="radio" name="itb-mode" className="itb:mt-0.5 itb:shrink-0" checked={ form.mode === value } onChange={ () => setForm( { ...form, mode: value } ) } />
							<span>
								<span className="itb:font-medium">{ label }</span>
								<span className="itb:block itb:text-xs itb:text-zinc-500">{ hint }</span>
							</span>
						</label>
					) ) }
				</fieldset>
				<div className="itb:grid itb:gap-2 itb:sm:grid-cols-3">
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:text-zinc-500">{ __( 'Tiempo en el edge' ) }</span>
						{ select( 'edge_ttl', TTL_OPTIONS ) }
					</label>
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:text-zinc-500">{ __( 'Servir viejo mientras renueva' ) }</span>
						{ select( 'swr', SWR_OPTIONS ) }
					</label>
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:text-zinc-500">{ __( 'Servir viejo si el origen falla' ) }</span>
						{ select( 'sie', SIE_OPTIONS ) }
					</label>
				</div>
				<label className="itb:flex itb:items-center itb:justify-between itb:gap-3">
					<span>
						<span className="itb:font-medium">{ __( 'Refrescar nonces en páginas cacheadas' ) }</span>
						<span className="itb:block itb:text-xs itb:text-zinc-500">{ __( 'Los nonces caducan en 12–24 h. Sin esto, el tiempo en el edge se limita a 10 h.' ) }</span>
					</span>
					<Switch checked={ form.nonce_refresh } onCheckedChange={ ( v ) => setForm( { ...form, nonce_refresh: v } ) } aria-label={ __( 'Refrescar nonces' ) } />
				</label>
				<label className="itb:flex itb:items-center itb:justify-between itb:gap-3">
					<span>
						<span className="itb:font-medium">{ __( 'Recalentar después de purgar' ) }</span>
						<span className="itb:block itb:text-xs itb:text-zinc-500">{ __( 'Una URL a la vez; se pausa si la carga del servidor sube.' ) }</span>
					</span>
					<Switch checked={ form.warm } onCheckedChange={ ( v ) => setForm( { ...form, warm: v } ) } aria-label={ __( 'Recalentar' ) } />
				</label>
				{ capped && <Notice level="warning">{ __( 'Sin refresco de nonces el edge guardará como máximo 10 h.' ) }</Notice> }
				<div>
					<Button size="sm" disabled={ ! dirty || save.isPending } onClick={ onSave }>
						{ save.isPending ? __( 'Guardando…' ) : __( 'Guardar' ) }
					</Button>
				</div>
				{ save.error && <Notice level="error">{ save.error.message }</Notice> }
			</CardContent>
			<ConfirmDialog
				open={ confirmHeaders }
				onOpenChange={ setConfirmHeaders }
				title={ __( '¿Emitir cabeceras para el edge?' ) }
				description={ __( 'Cloudflare podrá guardar las páginas anónimas el tiempo indicado. Confirma que revisaste con «Probar una URL» que carrito, checkout y cuenta salen con no-store y que las páginas cacheables no envían cookies. Queda registrado; para revertir, vuelve a «Solo sincronizar purgas».' ) }
				confirmLabel={ __( 'Emitir cabeceras' ) }
				pending={ save.isPending }
				onConfirm={ () => submit( true ) }
			/>
		</Card>
	);
}

function QueueCard( { status, onDone }: { status: CacheStatus; onDone: ( s: CacheStatus ) => void } ) {
	const warm = useAction( ( action: 'seed' | 'stop' ) => api<CacheStatus>( 'cache/warm', 'POST', { action } ) );
	const w = status.warmer;
	const last = status.last;
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Purgas y recalentado' ) }</CardTitle>
				<CardDescription>{ __( 'El origen se purga al instante; el edge y el recalentado, en lotes cada 30 segundos.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				<dl className="itb:m-0 itb:mb-3">
					<KeyValue label={ __( 'Pendiente' ) }>
						{ status.queue.all ? __( 'Purga completa' ) : `${ status.queue.tags } ${ __( 'tags' ) } · ${ status.queue.urls } URLs` }
					</KeyValue>
					<KeyValue label={ __( 'Última sincronización' ) }>
						{ typeof last.at === 'number' && last.at > 0 ? `${ formatDate( last.at, config.locale ) } · ${ last.all ? __( 'todo' ) : `${ last.tags ?? 0 } ${ __( 'tags' ) }, ${ last.urls ?? 0 } URLs` }` : '—' }
					</KeyValue>
					<KeyValue label={ __( 'En cola para recalentar' ) }>{ status.queue.warm }</KeyValue>
					<KeyValue label={ __( 'Recalentadas' ) }>
						{ w.warmed } { w.errors > 0 && <Badge tone="amber">{ `${ w.errors } ${ __( 'con error' ) }` }</Badge> } { w.avg_ms > 0 && <span className="itb:text-zinc-500">· { w.avg_ms } ms</span> }
					</KeyValue>
					{ w.paused_at > 0 && (
						<KeyValue label={ __( 'Pausado por carga' ) }>
							{ formatDate( w.paused_at, config.locale ) } · { w.load.toFixed( 2 ) }
						</KeyValue>
					) }
				</dl>
				<div className="itb:flex itb:flex-wrap itb:gap-2">
					<Button size="sm" variant="outline" disabled={ ! status.enabled || warm.isPending } onClick={ () => warm.mutate( 'seed', { onSuccess: onDone } ) }>
						<Flame aria-hidden />
						{ __( 'Recalentar todo el sitio' ) }
					</Button>
					<Button size="sm" variant="ghost" disabled={ status.queue.warm === 0 || warm.isPending } onClick={ () => warm.mutate( 'stop', { onSuccess: onDone } ) }>
						<Square aria-hidden />
						{ __( 'Detener' ) }
					</Button>
				</div>
				{ status.sitemaps.length > 0 && <p className="itb:mt-2 itb:text-xs itb:text-zinc-500">{ `${ __( 'Sitemap' ) }: ${ status.sitemaps.join( ', ' ) }` }</p> }
				{ warm.error && <Notice level="error" className="itb:mt-3">{ warm.error.message }</Notice> }
			</CardContent>
		</Card>
	);
}

function PurgeCard( { status, onDone }: { status: CacheStatus; onDone: ( s: CacheStatus ) => void } ) {
	const [ type, setType ] = useState<'url' | 'tag' | 'all'>( 'url' );
	const [ value, setValue ] = useState( '' );
	const [ confirmAll, setConfirmAll ] = useState( false );
	const purge = useAction( ( vars: { type: string; value: string; confirm?: boolean } ) => api<CacheStatus>( 'cache/purge', 'POST', vars ) );
	const run = () => {
		if ( type === 'all' ) {
			setConfirmAll( true );
			return;
		}
		purge.mutate( { type, value }, { onSuccess: onDone } );
	};
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Purga manual' ) }</CardTitle>
				<CardDescription>{ __( 'Purga el plugin de caché y, si está conectado, el edge. Lo purgado se recalienta.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				<div className="itb:flex itb:flex-wrap itb:gap-2">
					<Select className="itb:w-28" value={ type } onChange={ ( e ) => setType( e.target.value as 'url' | 'tag' | 'all' ) } aria-label={ __( 'Tipo de purga' ) }>
						<option value="url">URL</option>
						<option value="tag">Tag</option>
						<option value="all">{ __( 'Todo' ) }</option>
					</Select>
					{ type !== 'all' && (
						<Input
							className="itb:min-w-0 itb:flex-1"
							value={ value }
							onChange={ ( e ) => setValue( e.target.value ) }
							placeholder={ type === 'url' ? config.adminUrl.replace( /wp-admin\/?$/, '' ) + '…' : 'p-123, t-45, home, shop' }
							aria-label={ __( 'Qué purgar' ) }
						/>
					) }
					<Button size="sm" className="itb:h-9" variant={ type === 'all' ? 'destructive' : 'default' } disabled={ ! status.enabled || purge.isPending || ( type !== 'all' && value.trim() === '' ) } onClick={ run }>
						<Trash2 aria-hidden />
						{ __( 'Purgar' ) }
					</Button>
				</div>
				{ purge.isSuccess && <Notice level="success" className="itb:mt-3">{ __( 'Purgado. El recalentado sigue en segundo plano.' ) }</Notice> }
				{ purge.error && <Notice level="error" className="itb:mt-3">{ purge.error.message }</Notice> }
			</CardContent>
			<ConfirmDialog
				open={ confirmAll }
				onOpenChange={ setConfirmAll }
				title={ __( '¿Purgar toda la caché?' ) }
				description={ __( 'Todas las páginas se vuelven a generar en PHP hasta que el recalentado las llene de nuevo. En un sitio con tráfico, la carga del servidor sube durante unos minutos.' ) }
				confirmLabel={ __( 'Purgar todo' ) }
				destructive
				pending={ purge.isPending }
				onConfirm={ () => purge.mutate( { type: 'all', value: '', confirm: true }, { onSuccess: onDone, onSettled: () => setConfirmAll( false ) } ) }
			/>
		</Card>
	);
}

function InspectCard() {
	const [ url, setUrl ] = useState( config.adminUrl.replace( /wp-admin\/?$/, '' ) );
	const inspect = useAction( ( target: string ) => api<InspectResult>( 'cache/inspect', 'POST', { url: target } ) );
	const r = inspect.data;
	const edge = r?.headers[ 'cloudflare-cdn-cache-control' ];
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Probar una URL' ) }</CardTitle>
				<CardDescription>{ __( 'Pide la URL como visitante anónimo desde el propio servidor y muestra las cabeceras de caché que salen del origen.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				<form
					className="itb:flex itb:flex-wrap itb:gap-2"
					onSubmit={ ( e ) => {
						e.preventDefault();
						inspect.mutate( url );
					} }
				>
					<Input className="itb:min-w-0 itb:flex-1" value={ url } onChange={ ( e ) => setUrl( e.target.value ) } aria-label="URL" />
					<Button type="submit" size="sm" className="itb:h-9" variant="outline" disabled={ inspect.isPending }>
						<Search aria-hidden />
						{ inspect.isPending ? __( 'Probando…' ) : __( 'Probar' ) }
					</Button>
				</form>
				{ inspect.error && <Notice level="error" className="itb:mt-3">{ inspect.error instanceof ApiError ? inspect.error.message : String( inspect.error ) }</Notice> }
				{ r && (
					<div className="itb:mt-3 itb:flex itb:flex-col itb:gap-2">
						<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2 itb:text-[13px]">
							<Badge>{ r.status }</Badge>
							{ edge === undefined ? (
								<Badge>{ __( 'Sin cabecera para el edge' ) }</Badge>
							) : edge.includes( 'no-store' ) ? (
								<Badge tone="amber">{ __( 'El edge no la guarda' ) }</Badge>
							) : (
								<Badge tone="green">{ __( 'El edge la puede guardar' ) }</Badge>
							) }
							{ r.cookies.length > 0 && <Badge tone="red">{ `Set-Cookie: ${ r.cookies.join( ', ' ) }` }</Badge> }
						</div>
						<dl className="itb:m-0">
							{ Object.entries( r.headers ).map( ( [ name, value ] ) => (
								<KeyValue key={ name } label={ name }>
									<code className="itb:text-xs itb:break-all">{ value }</code>
								</KeyValue>
							) ) }
						</dl>
						<p className="itb:m-0 itb:text-xs itb:text-zinc-500">
							<Play className="itb:inline itb:size-3" aria-hidden /> { __( 'Cloudflare quita Cache-Tag y Cloudflare-CDN-Cache-Control antes de responder al visitante: aquí se ven porque la petición va directa al origen.' ) }
						</p>
					</div>
				) }
			</CardContent>
		</Card>
	);
}
