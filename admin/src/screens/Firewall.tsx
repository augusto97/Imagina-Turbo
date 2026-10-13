import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, FileCode, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
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
import type { CfApplyResult, FirewallEvents, FirewallStatus, HtaccessResult } from '@/lib/types';
import { formatDate } from '@/lib/utils';
import { ChangeRow } from './Cloudflare';

const RULES: { id: 'exploits' | 'surface' | 'bots'; label: string; description: string }[] = [
	{ id: 'exploits', label: __( 'Ataques en la URL' ), description: __( 'Recorrido de directorios, inyección SQL y XSS en el query, archivos sensibles (.git, .env, .sql, .bak, phpunit) y métodos TRACE/TRACK/DEBUG.' ) },
	{ id: 'surface', label: __( 'Superficie de WordPress' ), description: __( 'Enumeración de usuarios, PHP dentro de uploads, readme.html, license.txt, copias de wp-config y XML-RPC (salvo que un plugin lo necesite).' ) },
	{ id: 'bots', label: __( 'Escáneres conocidos' ), description: __( 'sqlmap, nikto, wpscan, nuclei y otros, por su user agent.' ) },
];

export function Firewall() {
	const { data, isLoading, error, isFetching, refetch } = useQuery( { queryKey: [ 'firewall' ], queryFn: () => api<FirewallStatus>( 'firewall/status' ) } );
	return (
		<>
			<PageHeader
				title={ __( 'Firewall' ) }
				description={ __( 'Por capas: reglas en el edge de Cloudflare (inspiradas en el firewall 8G de Jeff Starr), límite de peticiones a login y checkout, origen cerrado y, en Apache, reglas en el .htaccess.' ) }
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
					<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2 itb:*:min-w-0">
						<RulesCard status={ data } />
						<OriginCard status={ data } />
					</div>
					<EdgeChangesCard status={ data } />
					<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2 itb:*:min-w-0">
						<EventsCard initial={ data.events } connected={ data.cloudflare.connected } />
						<HtaccessCard status={ data } />
					</div>
				</div>
			) }
		</>
	);
}

function EnableCard() {
	const [ open, setOpen ] = useState( false );
	const client = useQueryClient();
	const action = useAction( () => api( 'modules/firewall', 'PUT', { enabled: true, confirm: true } ) );
	return (
		<Notice level="warning">
			<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-3">
				<span>{ __( 'El firewall está apagado. Al activarlo, sus reglas aparecen en los cambios propuestos para Cloudflare; nada se aplica hasta que lo confirmes.' ) }</span>
				<Button size="sm" onClick={ () => setOpen( true ) }>
					{ __( 'Activar' ) }
				</Button>
			</div>
			{ action.error && <div className="itb:mt-2">{ action.error.message }</div> }
			<ConfirmDialog
				open={ open }
				onOpenChange={ setOpen }
				title={ __( '¿Activar el firewall?' ) }
				description={ __( 'Es de riesgo moderado. Las reglas entran en modo desafío durante 7 días (una persona pasa, un bot no) y después se propone pasarlas a bloqueo. Los webhooks de pasarelas de pago quedan excluidos. Queda registrado.' ) }
				confirmLabel={ __( 'Activar' ) }
				pending={ action.isPending }
				onConfirm={ () =>
					action.mutate( undefined, {
						onSettled: () => setOpen( false ),
						onSuccess: () => void client.invalidateQueries( { queryKey: [ 'firewall' ] } ),
					} )
				}
			/>
		</Notice>
	);
}

function RulesCard( { status }: { status: FirewallStatus } ) {
	const client = useQueryClient();
	const [ rules, setRules ] = useState( status.settings.rules );
	const [ ratelimit, setRatelimit ] = useState( status.settings.ratelimit );
	useEffect( () => {
		setRules( status.settings.rules );
		setRatelimit( status.settings.ratelimit );
	}, [ status ] );
	const save = useAction( () => api<FirewallStatus>( 'firewall/settings', 'PUT', { rules, ratelimit } ) );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Reglas en el edge' ) }</CardTitle>
				<CardDescription>{ __( 'Qué reglas propone Imagina Turbo para la zona. Cada una excluye /wc-api/, ?wc-api= y las rutas de webhooks de las pasarelas detectadas.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ RULES.map( ( r ) => (
					<label key={ r.id } className="itb:flex itb:items-center itb:justify-between itb:gap-3">
						<span>
							<span className="itb:font-medium">{ r.label }</span>
							<span className="itb:block itb:text-xs itb:text-zinc-500">{ r.description }</span>
						</span>
						<Switch checked={ rules[ r.id ] } onCheckedChange={ ( v ) => setRules( { ...rules, [ r.id ]: v } ) } aria-label={ r.label } />
					</label>
				) ) }
				<label className="itb:flex itb:items-center itb:justify-between itb:gap-3">
					<span>
						<span className="itb:font-medium">{ __( 'Límite de peticiones: login y checkout' ) }</span>
						<span className="itb:block itb:text-xs itb:text-zinc-500">{ __( 'Más de 5 peticiones en 10 s por IP a wp-login.php, al login personalizado o al checkout. Frena fuerza bruta y card testing.' ) }</span>
					</span>
					<Switch checked={ ratelimit } onCheckedChange={ setRatelimit } aria-label={ __( 'Límite de peticiones' ) } />
				</label>
				<div>
					<Button size="sm" disabled={ save.isPending } onClick={ () => save.mutate( undefined, { onSuccess: ( s ) => client.setQueryData( [ 'firewall' ], s ) } ) }>
						{ save.isPending ? __( 'Guardando…' ) : __( 'Guardar' ) }
					</Button>
				</div>
				{ save.isSuccess && <Notice level="success">{ __( 'Guardado. Revisa abajo los cambios para Cloudflare.' ) }</Notice> }
				{ save.error && <Notice level="error">{ save.error.message }</Notice> }
			</CardContent>
		</Card>
	);
}

function OriginCard( { status }: { status: FirewallStatus } ) {
	const o = status.origin;
	const level = o.state === 'open' || o.state === 'no_cloudflare' ? 'warning' : o.state === 'closed' ? 'success' : 'info';
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Origen cerrado' ) }</CardTitle>
				<CardDescription>{ __( 'Si el servidor acepta tráfico directo, quien conozca su IP se salta el firewall y la caché del edge. Se calcula con las muestras del perfilador de los últimos 7 días.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				<Notice level={ level }>{ o.message }</Notice>
				{ o.real_ip_missing && (
					<Notice level="warning">
						{ __( 'El servidor ve la IP de Cloudflare en lugar de la del visitante: CrowdSec, los logs y los límites por IP no funcionan bien. En OpenLiteSpeed: «Use Client IP in Header» = «Trusted IP Only» con los rangos de Cloudflare como IPs de confianza; en Apache, mod_remoteip.' ) }
					</Notice>
				) }
				<dl className="itb:m-0">
					<KeyValue label={ __( 'Por Cloudflare' ) }>{ o.cloudflare }</KeyValue>
					<KeyValue label={ __( 'Directas al servidor' ) }>{ o.direct }</KeyValue>
					<KeyValue label={ __( 'Locales (cron, recalentado, proxy)' ) }>{ o.local }</KeyValue>
				</dl>
				<ul className="itb:m-0 itb:list-disc itb:pl-5 itb:text-zinc-600">
					<li>{ __( 'En el firewall del VPS (ServerAvatar, UFW o Plesk), permite 80 y 443 solo desde los rangos de Cloudflare.' ) }</li>
					<li>{ __( 'O usa Authenticated Origin Pulls si el servidor lo soporta.' ) }</li>
				</ul>
				<a href={ o.ranges_url } target="_blank" rel="noreferrer" className="itb:inline-flex itb:items-center itb:gap-1 itb:text-xs itb:text-zinc-600 itb:underline">
					{ __( 'Rangos de IP oficiales de Cloudflare' ) }
					<ExternalLink className="itb:size-3" aria-hidden />
				</a>
			</CardContent>
		</Card>
	);
}

function EdgeChangesCard( { status }: { status: FirewallStatus } ) {
	const client = useQueryClient();
	const [ selected, setSelected ] = useState<string[]>( [] );
	const [ confirm, setConfirm ] = useState( false );
	const apply = useAction( ( ids: string[] ) => api<CfApplyResult>( 'cloudflare/apply', 'POST', { ids, confirm: true } ) );
	const cf = status.cloudflare;
	const canApply = cf.connected && cf.module_enabled && status.enabled;
	const toggle = ( id: string ) => setSelected( selected.includes( id ) ? selected.filter( ( x ) => x !== id ) : [ ...selected, id ] );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Cambios para Cloudflare' ) }</CardTitle>
				<CardDescription>{ __( 'Reglas del firewall y del límite de peticiones que faltan, cambiaron o sobran en la zona. Se aplican con snapshot previo, igual que en la pantalla Cloudflare.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ ! cf.connected && <Notice level="info">{ __( 'Conecta Cloudflare (pantalla Cloudflare) para proponer reglas en el edge.' ) }</Notice> }
				{ cf.connected && cf.waf_permission === false && <Notice level="warning">{ __( 'El token no puede leer las reglas del firewall. Agrégale el permiso «Zone WAF: Edit».' ) }</Notice> }
				{ status.error && <Notice level="error">{ status.error }</Notice> }
				{ status.notes.map( ( n, i ) => (
					<Notice key={ i } level="info">
						{ n }
					</Notice>
				) ) }
				{ status.changes.length > 0 && (
					<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-2 itb:p-0">
						{ status.changes.map( ( c ) => (
							<ChangeRow key={ c.id } change={ c } checked={ selected.includes( c.id ) } onToggle={ () => toggle( c.id ) } disabled={ ! canApply || c.action === 'none' || c.action === 'blocked' } />
						) ) }
					</ul>
				) }
				{ cf.connected && status.changes.length === 0 && ! status.error && <p className="itb:m-0 itb:text-zinc-500">{ status.enabled ? __( 'No hay cambios del firewall pendientes.' ) : __( 'Activa el firewall para ver sus reglas.' ) }</p> }
				{ cf.connected && (
					<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
						<Button size="sm" disabled={ ! canApply || selected.length === 0 || apply.isPending } onClick={ () => setConfirm( true ) }>
							{ __( 'Aplicar seleccionados' ) }
							{ selected.length > 0 && ` (${ selected.length })` }
						</Button>
						{ ! cf.module_enabled && <span className="itb:text-xs itb:text-zinc-500">{ __( 'Activa el módulo Cloudflare para aplicar.' ) }</span> }
					</div>
				) }
				{ apply.data && (
					<Notice level={ apply.data.failed.length > 0 ? 'error' : 'success' }>
						{ apply.data.failed.length > 0 ? apply.data.failed.map( ( f ) => `${ f.id }: ${ f.error }` ).join( ' · ' ) : `${ __( 'Cambios aplicados. Snapshot previo' ) } #${ apply.data.snapshot_id }.` }
					</Notice>
				) }
				{ apply.error && <Notice level="error">{ apply.error.message }</Notice> }
			</CardContent>
			<ConfirmDialog
				open={ confirm }
				onOpenChange={ setConfirm }
				title={ __( '¿Aplicar las reglas del firewall?' ) }
				description={ __( 'Se guarda un snapshot de la zona antes de tocarla. Las reglas nuevas entran en modo desafío: una persona real pasa con un clic o sin hacer nada; un bot no. Revisa el registro de eventos los primeros días.' ) }
				confirmLabel={ __( 'Aplicar' ) }
				pending={ apply.isPending }
				onConfirm={ () =>
					apply.mutate( selected, {
						onSettled: () => {
							setConfirm( false );
							setSelected( [] );
							void client.invalidateQueries( { queryKey: [ 'firewall' ] } );
							void client.invalidateQueries( { queryKey: [ 'cf-plan' ] } );
						},
					} )
				}
			/>
		</Card>
	);
}

function EventsCard( { initial, connected }: { initial: FirewallEvents; connected: boolean } ) {
	const { data, isFetching, refetch } = useQuery( { queryKey: [ 'firewall-events' ], queryFn: () => api<FirewallEvents>( 'firewall/events' ), initialData: initial, enabled: false } );
	return (
		<Card>
			<CardHeader>
				<div className="itb:flex itb:flex-wrap itb:items-start itb:justify-between itb:gap-3">
					<div>
						<CardTitle>{ __( 'Eventos (últimas 24 h)' ) }</CardTitle>
						<CardDescription>{ __( 'Lo que el firewall de Cloudflare desafió o bloqueó, por regla. Se actualiza cada hora.' ) }</CardDescription>
					</div>
					{ connected && (
						<Button size="sm" variant="outline" onClick={ () => void refetch() } disabled={ isFetching }>
							<RefreshCw className={ isFetching ? 'itb:animate-spin' : '' } aria-hidden />
							{ __( 'Consultar' ) }
						</Button>
					) }
				</div>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-2 itb:text-[13px]">
				{ ! data.available && <p className="itb:m-0 itb:text-zinc-500">{ data.error || __( 'Sin datos todavía.' ) }</p> }
				{ data.available && data.events.length === 0 && <p className="itb:m-0 itb:text-zinc-500">{ __( 'Ningún evento en las últimas 24 horas.' ) }</p> }
				<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:p-0">
					{ data.events.map( ( e ) => (
						<li key={ e.rule + e.action } className="itb:flex itb:items-start itb:justify-between itb:gap-3 itb:border-b itb:border-zinc-100 itb:py-2 itb:last:border-b-0">
							<span className="itb:min-w-0">
								<span className="itb:block itb:truncate itb:font-medium">{ e.rule.replace( /^\[imagina-turbo\] /, '' ) }</span>
								<span className="itb:block itb:truncate itb:text-xs itb:text-zinc-500">
									<code>{ e.top_path }</code>
									{ e.top_country && ` · ${ e.top_country }` }
								</span>
							</span>
							<span className="itb:flex itb:shrink-0 itb:items-center itb:gap-1.5">
								<Badge tone={ e.action === 'block' ? 'red' : 'amber' }>{ e.action }</Badge>
								<span className="itb:tabular-nums">{ e.count.toLocaleString( config.locale ) }</span>
							</span>
						</li>
					) ) }
				</ul>
				{ data.fetched_at ? (
					<p className="itb:m-0 itb:text-xs itb:text-zinc-500">
						{ __( 'Actualizado:' ) } { formatDate( data.fetched_at, config.locale ) }
					</p>
				) : null }
			</CardContent>
		</Card>
	);
}

function HtaccessCard( { status }: { status: FirewallStatus } ) {
	const client = useQueryClient();
	const [ target, setTarget ] = useState<'apply' | 'remove' | null>( null );
	const h = status.htaccess;
	const action = useAction( ( a: 'apply' | 'remove' ) => api<HtaccessResult & { status: FirewallStatus }>( 'firewall/htaccess', 'POST', { action: a, confirm: true } ) );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Reglas en el servidor (.htaccess)' ) }</CardTitle>
				<CardDescription>{ __( 'Para sitios sin Cloudflare o como segunda capa en Apache/Plesk. Se respalda el archivo, se prueba la portada, un producto y la REST API, y si algo deja de responder se restaura solo.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ ! h.applicable && <Notice level="info">{ __( 'Este servidor no usa .htaccess (nginx): la protección va en Cloudflare y en la configuración del servidor.' ) }</Notice> }
				{ h.ols && <Notice level="info">{ __( 'OpenLiteSpeed solo lee las reglas de reescritura del .htaccess y puede necesitar reiniciarse para tomarlas. Son opcionales: la protección principal es Cloudflare + CrowdSec.' ) }</Notice> }
				<dl className="itb:m-0">
					<KeyValue label={ __( 'Servidor' ) }>{ h.server }</KeyValue>
					<KeyValue label={ __( 'Estado' ) }>{ h.active ? <Badge tone="green">{ __( 'Reglas escritas' ) }</Badge> : <Badge>{ __( 'Sin reglas' ) }</Badge> }</KeyValue>
					<KeyValue label={ __( 'Escribible' ) }>{ h.writable ? __( 'Sí' ) : __( 'No' ) }</KeyValue>
					{ h.backup_at > 0 && <KeyValue label={ __( 'Último respaldo' ) }>{ formatDate( h.backup_at, config.locale ) }</KeyValue> }
				</dl>
				<details className="itb:text-xs">
					<summary className="itb:cursor-pointer itb:text-zinc-600">{ __( 'Ver las reglas' ) }</summary>
					<pre className="itb:mt-1 itb:max-h-64 itb:overflow-auto itb:rounded itb:bg-zinc-50 itb:p-2 itb:text-[11px]">{ h.block }</pre>
				</details>
				{ h.applicable && (
					<div className="itb:flex itb:flex-wrap itb:gap-2">
						<Button size="sm" variant="outline" disabled={ ! h.writable || action.isPending } onClick={ () => setTarget( h.active ? 'remove' : 'apply' ) }>
							<FileCode aria-hidden />
							{ h.active ? __( 'Quitar las reglas' ) : __( 'Escribir las reglas' ) }
						</Button>
					</div>
				) }
				{ action.data && (
					<Notice level={ action.data.ok ? 'success' : 'error' }>
						{ action.data.ok ? ( h.ols ? __( 'Hecho. Las páginas de prueba respondieron 200. Reinicia OpenLiteSpeed (en ServerAvatar: reiniciar el servicio web) para que lea las reglas y vuelve a comprobar el sitio.' ) : __( 'Hecho. Las páginas de prueba respondieron 200.' ) ) : action.data.error }
					</Notice>
				) }
				{ action.error && <Notice level="error">{ action.error.message }</Notice> }
			</CardContent>
			<ConfirmDialog
				open={ target !== null }
				onOpenChange={ ( o ) => ! o && setTarget( null ) }
				title={ target === 'remove' ? __( '¿Quitar las reglas del .htaccess?' ) : __( '¿Escribir las reglas en el .htaccess?' ) }
				description={ __( 'Se guarda un respaldo del archivo completo. Después se piden la portada, un producto y la REST API; si alguna no responde 200, se restaura el respaldo automáticamente. Queda registrado.' ) }
				confirmLabel={ target === 'remove' ? __( 'Quitar' ) : __( 'Escribir' ) }
				pending={ action.isPending }
				onConfirm={ () =>
					target &&
					action.mutate( target, {
						onSettled: () => setTarget( null ),
						onSuccess: ( r ) => client.setQueryData( [ 'firewall' ], r.status ),
					} )
				}
			/>
		</Card>
	);
}
