import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, FileCode, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { KeyValue, PageHeader } from '@/components/common';
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
import type { CfApplyResult, FirewallAction, FirewallCustom, FirewallEvents, FirewallRuleId, FirewallStatus, HtaccessResult } from '@/lib/types';
import { formatDate } from '@/lib/utils';
import { ChangeRow } from './Cloudflare';
import { TurnstileCard } from './TurnstileCard';

const RULES: { id: Exclude<FirewallRuleId, 'countries'>; label: string; description: string }[] = [
	{ id: 'exploits', label: __( 'Ataques en la URL' ), description: __( 'Recorrido de directorios, inyección SQL y XSS en el query, archivos sensibles (.git, .env, .sql, .bak, phpunit) y métodos TRACE/TRACK/DEBUG.' ) },
	{ id: 'surface', label: __( 'Superficie de WordPress' ), description: __( 'Enumeración de usuarios, PHP dentro de uploads, readme.html, license.txt, copias de wp-config y XML-RPC (salvo que un plugin lo necesite).' ) },
	{ id: 'bots', label: __( 'Escáneres conocidos' ), description: __( 'sqlmap, nikto, wpscan, nuclei y otros, por su user agent.' ) },
	{ id: 'facets', label: __( 'Combinaciones de filtros para crawlers' ), description: __( 'Google, Bing, Meta y otros crawlers que piden dos filtros de la tienda a la vez, varios valores de un filtro o el precio con otro filtro se cortan en Cloudflare, sin llegar al servidor. Cada filtro por separado sigue abierto, salvo que el escudo esté en «Todos los filtros» (Ajustes rápidos). Apagada por defecto; ocupa una de las 5 reglas del plan Free.' ) },
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
					<RulesEditor status={ data } />
					<EdgeChangesCard status={ data } />
					<OriginCard status={ data } />
					<TurnstileCard />
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

const ACTION_LABELS: Record<FirewallAction, string> = {
	auto: __( 'Automático: desafío 7 días y después bloqueo' ),
	challenge: __( 'Siempre desafío' ),
	block: __( 'Siempre bloqueo' ),
};

/** Una entrada por línea (o separadas por comas). */
const lines = ( text: string ): string[] =>
	text
		.split( /[\n,]+/ )
		.map( ( l ) => l.trim() )
		.filter( Boolean );

const seconds = ( n: number ): string => ( n >= 3600 ? `${ n / 3600 } h` : n >= 60 ? `${ n / 60 } min` : `${ n } s` );

/**
 * Reglas, excepciones, escáneres y límite de peticiones (0.23.0): todo se guarda junto y nada cambia en
 * Cloudflare hasta aplicarlo abajo.
 */
function RulesEditor( { status }: { status: FirewallStatus } ) {
	const client = useQueryClient();
	const opts = status.options;
	const [ rules, setRules ] = useState( status.settings.rules );
	const [ ratelimit, setRatelimit ] = useState( status.settings.ratelimit );
	const [ custom, setCustom ] = useState<FirewallCustom>( status.settings.custom );
	const [ ips, setIps ] = useState( status.settings.custom.allow_ips.join( '\n' ) );
	const [ paths, setPaths ] = useState( status.settings.custom.allow_paths.join( '\n' ) );
	const [ bots, setBots ] = useState( status.settings.custom.bots_extra.join( '\n' ) );
	const [ countries, setCountries ] = useState( status.settings.custom.countries.join( ', ' ) );
	const [ rlPaths, setRlPaths ] = useState( status.settings.custom.rl_paths.join( '\n' ) );
	useEffect( () => {
		setRules( status.settings.rules );
		setRatelimit( status.settings.ratelimit );
		setCustom( status.settings.custom );
		setIps( status.settings.custom.allow_ips.join( '\n' ) );
		setPaths( status.settings.custom.allow_paths.join( '\n' ) );
		setBots( status.settings.custom.bots_extra.join( '\n' ) );
		setCountries( status.settings.custom.countries.join( ', ' ) );
		setRlPaths( status.settings.custom.rl_paths.join( '\n' ) );
	}, [ status ] );
	const payload = {
		rules,
		ratelimit,
		custom: {
			...custom,
			allow_ips: lines( ips ),
			allow_paths: lines( paths ),
			bots_extra: lines( bots ),
			countries: countries
				.split( /[\s,;]+/ )
				.map( ( c ) => c.trim().toUpperCase() )
				.filter( Boolean ),
			rl_paths: lines( rlPaths ),
		},
	};
	const dirty = JSON.stringify( payload ) !== JSON.stringify( { rules: status.settings.rules, ratelimit: status.settings.ratelimit, custom: status.settings.custom } );
	const save = useAction( () => api<FirewallStatus>( 'firewall/settings', 'PUT', payload ) );
	const action = ( id: FirewallRuleId ) => custom.actions[ id ] ?? ( id === 'countries' ? 'challenge' : 'auto' );
	const setAction = ( id: FirewallRuleId, value: FirewallAction ) => setCustom( { ...custom, actions: { ...custom.actions, [ id ]: value } } );
	const actionSelect = ( id: FirewallRuleId, label: string ) => (
		<Select className="itb:h-8 itb:w-auto itb:max-w-full itb:text-xs" value={ action( id ) } onChange={ ( e ) => setAction( id, e.target.value as FirewallAction ) } aria-label={ `${ __( 'Acción' ) }: ${ label }` } disabled={ ! rules[ id ] }>
			{ ( Object.keys( ACTION_LABELS ) as FirewallAction[] ).map( ( a ) => (
				<option key={ a } value={ a }>
					{ ACTION_LABELS[ a ] }
				</option>
			) ) }
		</Select>
	);
	const toggleBot = ( bot: string, active: boolean ) => setCustom( { ...custom, bots_off: active ? custom.bots_off.filter( ( b ) => b !== bot ) : [ ...custom.bots_off, bot ] } );
	const addMyIp = () => {
		const current = lines( ips );
		if ( opts.client.ip && ! current.includes( opts.client.ip ) ) {
			setIps( [ ...current, opts.client.ip ].join( '\n' ) );
		}
	};
	const free = opts.plan === 'free';

	return (
		<>
			<Card>
				<CardHeader>
					<CardTitle>{ __( 'Reglas en el edge' ) }</CardTitle>
					<CardDescription>{ __( 'Qué reglas propone Imagina Turbo para la zona y qué hace cada una. Todas excluyen /wc-api/, ?wc-api=, las rutas de webhooks de las pasarelas detectadas y las excepciones de abajo.' ) }</CardDescription>
				</CardHeader>
				<CardContent className="itb:flex itb:flex-col itb:gap-4 itb:text-[13px]">
					{ RULES.map( ( r ) => (
						<div key={ r.id } className="itb:flex itb:flex-col itb:gap-2 itb:border-b itb:border-zinc-100 itb:pb-3 itb:sm:flex-row itb:sm:items-center itb:sm:justify-between">
							<label className="itb:flex itb:min-w-0 itb:flex-1 itb:items-start itb:gap-3">
								<Switch checked={ rules[ r.id ] } onCheckedChange={ ( v ) => setRules( { ...rules, [ r.id ]: v } ) } aria-label={ r.label } />
								<span className="itb:min-w-0">
									<span className="itb:font-medium">{ r.label }</span>
									<span className="itb:block itb:text-xs itb:text-zinc-500">{ r.description }</span>
								</span>
							</label>
							{ actionSelect( r.id, r.label ) }
						</div>
					) ) }

					<div className="itb:flex itb:flex-col itb:gap-2 itb:border-b itb:border-zinc-100 itb:pb-3">
						<div className="itb:flex itb:flex-col itb:gap-2 itb:sm:flex-row itb:sm:items-center itb:sm:justify-between">
							<label className="itb:flex itb:min-w-0 itb:flex-1 itb:items-start itb:gap-3">
								<Switch checked={ rules.countries } onCheckedChange={ ( v ) => setRules( { ...rules, countries: v } ) } aria-label={ __( 'Países' ) } />
								<span className="itb:min-w-0">
									<span className="itb:font-medium">{ __( 'Países' ) }</span>
									<span className="itb:block itb:text-xs itb:text-zinc-500">{ __( 'Desafía o bloquea visitas según el país. Google, Bing y los demás bots verificados siempre pasan, para no perder posicionamiento. Apagada por defecto; ocupa una de las 5 reglas del plan Free.' ) }</span>
								</span>
							</label>
							{ actionSelect( 'countries', __( 'Países' ) ) }
						</div>
						{ rules.countries && (
							<div className="itb:grid itb:gap-2 itb:pl-12 itb:sm:grid-cols-[auto_1fr]">
								<Select className="itb:h-9 itb:text-xs" value={ custom.country_mode } onChange={ ( e ) => setCustom( { ...custom, country_mode: e.target.value as FirewallCustom[ 'country_mode' ] } ) } aria-label={ __( 'Qué países' ) }>
									<option value="listed">{ __( 'Estos países' ) }</option>
									<option value="others">{ __( 'Todos menos estos países' ) }</option>
								</Select>
								<Input value={ countries } onChange={ ( e ) => setCountries( e.target.value ) } placeholder="CN, RU, KP" aria-label={ __( 'Códigos de país' ) } />
								<span className="itb:text-xs itb:text-zinc-500 itb:sm:col-span-2">
									{ __( 'Códigos de dos letras (ISO 3166-1), separados por comas. T1 es la red Tor.' ) }
									{ opts.client.country && ` ${ __( 'Tú estás en' ) } ${ opts.client.country }.` }
								</span>
								{ custom.country_mode === 'others' && action( 'countries' ) !== 'challenge' && (
									<Notice level="warning" className="itb:sm:col-span-2">
										{ __( 'Con «Todos menos estos» y bloqueo, quien viaje o use una VPN de otro país no podrá entrar, tampoco al admin. Incluye tu país o agrega tu IP a las excepciones.' ) }
									</Notice>
								) }
							</div>
						) }
					</div>

					<div className="itb:flex itb:flex-col itb:gap-2">
						<label className="itb:flex itb:items-start itb:gap-3">
							<Switch checked={ ratelimit } onCheckedChange={ setRatelimit } aria-label={ __( 'Límite de peticiones' ) } />
							<span>
								<span className="itb:font-medium">{ __( 'Límite de peticiones: login y checkout' ) }</span>
								<span className="itb:block itb:text-xs itb:text-zinc-500">{ __( 'Corta a una IP que pide demasiadas veces wp-login.php, el login personalizado, el checkout o las rutas que agregues. Frena fuerza bruta y card testing.' ) }</span>
							</span>
						</label>
						{ ratelimit && (
							<div className="itb:grid itb:gap-3 itb:pl-12 itb:sm:grid-cols-3">
								<label className="itb:flex itb:flex-col itb:gap-1">
									<span className="itb:text-xs itb:font-medium">{ __( 'Peticiones' ) }</span>
									<Input type="number" min={ opts.limits.requests[ 0 ] } max={ opts.limits.requests[ 1 ] } value={ custom.rl_requests } onChange={ ( e ) => setCustom( { ...custom, rl_requests: Number( e.target.value ) } ) } />
								</label>
								<label className="itb:flex itb:flex-col itb:gap-1">
									<span className="itb:text-xs itb:font-medium">{ __( 'En' ) }</span>
									<Select value={ custom.rl_period } onChange={ ( e ) => setCustom( { ...custom, rl_period: Number( e.target.value ) } ) }>
										{ ( opts.periods.length ? opts.periods : [ 10 ] ).map( ( p ) => (
											<option key={ p } value={ p }>
												{ seconds( p ) }
											</option>
										) ) }
									</Select>
								</label>
								<label className="itb:flex itb:flex-col itb:gap-1">
									<span className="itb:text-xs itb:font-medium">{ __( 'Bloqueo durante' ) }</span>
									<Select value={ custom.rl_timeout } onChange={ ( e ) => setCustom( { ...custom, rl_timeout: Number( e.target.value ) } ) }>
										<option value={ 0 }>{ free ? __( 'Automático (10 s)' ) : __( 'Automático (10 min)' ) }</option>
										{ opts.timeouts.map( ( t ) => (
											<option key={ t } value={ t }>
												{ seconds( t ) }
											</option>
										) ) }
									</Select>
								</label>
								<label className="itb:flex itb:flex-col itb:gap-1 itb:sm:col-span-3">
									<span className="itb:text-xs itb:font-medium">{ __( 'Rutas adicionales (una por línea, exactas)' ) }</span>
									<Textarea className="itb:min-h-14 itb:font-mono itb:text-xs" value={ rlPaths } onChange={ ( e ) => setRlPaths( e.target.value ) } placeholder="/mi-cuenta/lost-password" />
								</label>
								{ free && <span className="itb:text-xs itb:text-zinc-500 itb:sm:col-span-3">{ __( 'Plan Free: Cloudflare solo permite 10 s de ventana y 10 s de bloqueo.' ) }</span> }
							</div>
						) }
					</div>
				</CardContent>
			</Card>

			<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2 itb:*:min-w-0">
				<Card>
					<CardHeader>
						<CardTitle>{ __( 'Excepciones' ) }</CardTitle>
						<CardDescription>{ __( 'IPs y rutas que ninguna regla del firewall toca: la oficina, un ERP o una app que se conecta al sitio. No gastan reglas del plan.' ) }</CardDescription>
					</CardHeader>
					<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
						<label className="itb:flex itb:flex-col itb:gap-1">
							<span className="itb:font-medium">{ __( 'IPs o rangos (una por línea)' ) }</span>
							<Textarea className="itb:min-h-20 itb:font-mono itb:text-xs" value={ ips } onChange={ ( e ) => setIps( e.target.value ) } placeholder={ '203.0.113.10\n198.51.100.0/24' } />
							<span className="itb:text-xs itb:text-zinc-500">{ `${ __( 'Máximo' ) } ${ opts.limits.ips }. ${ __( 'Rangos de como mucho /16 en IPv4 y /32 en IPv6.' ) }` }</span>
						</label>
						{ opts.client.ip && (
							<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2 itb:text-xs itb:text-zinc-600">
								<span>
									{ __( 'Tu IP ahora:' ) } <code>{ opts.client.ip }</code>
								</span>
								<Button size="sm" variant="outline" onClick={ addMyIp } disabled={ lines( ips ).includes( opts.client.ip ) }>
									{ __( 'Agregar mi IP' ) }
								</Button>
							</div>
						) }
						{ ! opts.ratelimit_ip && lines( ips ).length > 0 && <span className="itb:text-xs itb:text-zinc-500">{ __( 'En tu plan, el límite de peticiones no puede excluir IPs (Cloudflare solo deja mirar la ruta); las demás reglas sí las excluyen.' ) }</span> }
						<label className="itb:flex itb:flex-col itb:gap-1">
							<span className="itb:font-medium">{ __( 'Rutas (una por línea)' ) }</span>
							<Textarea className="itb:min-h-20 itb:font-mono itb:text-xs" value={ paths } onChange={ ( e ) => setPaths( e.target.value ) } placeholder={ '/wp-json/mi-erp\n/integraciones' } />
							<span className="itb:text-xs itb:text-zinc-500">{ `${ __( 'Tal como se ven en la URL. Incluye todo lo que hay debajo. Máximo' ) } ${ opts.limits.paths }.` }</span>
						</label>
					</CardContent>
				</Card>

				<Card>
					<CardHeader>
						<CardTitle>{ __( 'Escáneres conocidos' ) }</CardTitle>
						<CardDescription>{ __( 'User agents que corta la regla «Escáneres conocidos». Desmarca los que necesites y agrega los tuyos.' ) }</CardDescription>
					</CardHeader>
					<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
						<div className="itb:grid itb:grid-cols-2 itb:gap-x-4 itb:gap-y-1 itb:sm:grid-cols-3">
							{ opts.bad_bots.map( ( bot ) => (
								<label key={ bot } className="itb:flex itb:items-center itb:gap-1.5">
									<input type="checkbox" checked={ ! custom.bots_off.includes( bot ) } onChange={ ( e ) => toggleBot( bot, e.target.checked ) } />
									<code className="itb:text-xs">{ bot }</code>
								</label>
							) ) }
						</div>
						<label className="itb:flex itb:flex-col itb:gap-1">
							<span className="itb:font-medium">{ __( 'Otros (uno por línea)' ) }</span>
							<Textarea className="itb:min-h-16 itb:font-mono itb:text-xs" value={ bots } onChange={ ( e ) => setBots( e.target.value ) } placeholder="mi-scraper" />
							<span className="itb:text-xs itb:text-zinc-500">{ __( 'Se compara sin mayúsculas. No se acepta un texto que aparezca en navegadores o en Google (por ejemplo «mozilla» o «chrome»).' ) }</span>
						</label>
					</CardContent>
				</Card>
			</div>

			<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-3">
				<Button size="sm" disabled={ save.isPending || ! dirty } onClick={ () => save.mutate( undefined, { onSuccess: ( s ) => client.setQueryData( [ 'firewall' ], s ) } ) }>
					{ save.isPending ? __( 'Guardando…' ) : __( 'Guardar las reglas' ) }
				</Button>
				{ dirty && <span className="itb:text-xs itb:text-amber-700">{ __( 'Hay cambios sin guardar.' ) }</span> }
				{ save.isSuccess && ! dirty && <span className="itb:text-xs itb:text-emerald-700">{ __( 'Guardado. Revisa abajo los cambios para Cloudflare: nada se aplica hasta que lo confirmes.' ) }</span> }
			</div>
			{ save.error && <Notice level="error">{ save.error.message }</Notice> }
		</>
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
				{ cf.connected && cf.waf_permission === false && <Notice level="warning">{ __( 'El token no tiene «Zone → Zone WAF → Edit». Edítalo en Cloudflare → Mi perfil → API Tokens y agrega el permiso; el token no cambia. Después pulsa «Comprobar permisos» en la pantalla Cloudflare.' ) }</Notice> }
				{ status.error && <Notice level="error">{ status.error }</Notice> }
				{ status.notes.map( ( n, i ) => (
					<Notice key={ i } level="info">
						{ n }
					</Notice>
				) ) }
				{ status.changes.length > 0 && (
					<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-2 itb:p-0">
						{ status.changes.map( ( c ) => (
							<ChangeRow key={ c.id } change={ c } checked={ selected.includes( c.id ) } onToggle={ () => toggle( c.id ) } disabled={ ! canApply || c.action === 'none' || c.action === 'blocked' || c.action === 'unmanaged' } />
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
