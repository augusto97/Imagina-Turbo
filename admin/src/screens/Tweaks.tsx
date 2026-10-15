import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Copy, Download, FlaskConical, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PageHeader, RiskBadge } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { ApiError, api } from '@/lib/api';
import { __ } from '@/lib/i18n';
import { useAction } from '@/lib/queries';
import type { PerfmattersReport, ShieldStatus, ShieldTestCase, Tweak, TweaksStatus } from '@/lib/types';
import { RISK_LABELS } from './Cloudflare';

export function Tweaks() {
	const shield = useQuery( { queryKey: [ 'shield' ], queryFn: () => api<ShieldStatus>( 'shield/status' ) } );
	const tweaks = useQuery( { queryKey: [ 'tweaks' ], queryFn: () => api<TweaksStatus>( 'tweaks/status' ) } );
	const fetching = shield.isFetching || tweaks.isFetching;
	return (
		<>
			<PageHeader
				title={ __( 'Ajustes rápidos' ) }
				description={ __( 'Escudo del servidor y los ajustes que antes hacía Perfmatters, cada uno con su interruptor y su nivel de riesgo.' ) }
				actions={
					<Button
						variant="outline"
						size="sm"
						onClick={ () => {
							void shield.refetch();
							void tweaks.refetch();
						} }
						disabled={ fetching }
					>
						<RefreshCw className={ fetching ? 'itb:animate-spin' : '' } aria-hidden />
						{ __( 'Actualizar' ) }
					</Button>
				}
			/>
			{ ( shield.error || tweaks.error ) && <Notice level="error">{ ( shield.error ?? tweaks.error )?.message }</Notice> }
			{ ( shield.isLoading || tweaks.isLoading ) && <Skeleton className="itb:h-64" /> }
			<div className="itb:flex itb:flex-col itb:gap-4">
				{ tweaks.data?.perfmatters && <PerfmattersCard /> }
				<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2 itb:*:min-w-0">
					{ shield.data && <ShieldCard status={ shield.data } /> }
					{ shield.data && <CronCard status={ shield.data } /> }
				</div>
				{ tweaks.data && <TweaksCard status={ tweaks.data } /> }
			</div>
		</>
	);
}

const SHIELD: { id: 'static' | 'author' | 'xmlrpc' | 'rest_users' | 'heartbeat' | 'prefetch'; label: string; description: string }[] = [
	{ id: 'static', label: __( '404 baratos para assets' ), description: __( 'Una imagen, CSS o JS que no existe responde 404 antes de cargar plugins y tema, cacheable 10 minutos.' ) },
	{ id: 'author', label: __( 'Bloquear ?author=N' ), description: __( 'Evita que se descubran los nombres de usuario. Con sesión iniciada no se toca.' ) },
	{ id: 'xmlrpc', label: __( 'Bloquear XML-RPC' ), description: __( 'Vía clásica de fuerza bruta y pingbacks masivos.' ) },
	{ id: 'rest_users', label: __( 'Cerrar /wp-json/wp/v2/users a anónimos' ), description: __( 'La lista de usuarios por la REST API solo con sesión o credenciales.' ) },
	{ id: 'heartbeat', label: __( 'Controlar Heartbeat' ), description: __( 'Fuera del frontend para visitantes; en el admin y el editor, cada N segundos.' ) },
	{ id: 'prefetch', label: __( 'Rechazar precargas con efectos' ), description: __( 'Una precarga especulativa (de cualquier script o de Cloudflare) de agregar al carrito, cerrar sesión, enlaces con nonce, carrito, pago o cuenta responde 503 sin ejecutar nada.' ) },
];

function ShieldCard( { status }: { status: ShieldStatus } ) {
	const client = useQueryClient();
	const [ values, setValues ] = useState( status.settings );
	useEffect( () => setValues( status.settings ), [ status ] );
	const save = useAction( () => api<ShieldStatus>( 'shield/settings', 'PUT', values ) );
	const test = useAction( () => api<Record<string, ShieldTestCase>>( 'shield/test', 'POST' ) );
	const num = ( key: 'heartbeat_admin' | 'heartbeat_editor' | 'filter_limit', value: string ) => setValues( { ...values, [ key ]: Number.parseInt( value, 10 ) || 0 } );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Escudo del servidor' ) }</CardTitle>
				<CardDescription>{ __( 'Corta el tráfico basura en el loader, antes de cargar plugins y tema.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ ! status.enabled && <Notice level="info">{ __( 'El módulo Escudo está apagado (Panel).' ) }</Notice> }
				{ SHIELD.map( ( s ) => {
					const required = ( s.id === 'xmlrpc' || s.id === 'rest_users' ) && status.required[ s.id ];
					return (
						<label key={ s.id } className="itb:flex itb:items-center itb:justify-between itb:gap-3">
							<span>
								<span className="itb:font-medium">{ s.label }</span>
								<span className="itb:block itb:text-xs itb:text-zinc-500">{ s.description }</span>
								{ required && <span className="itb:block itb:text-xs itb:text-amber-700">{ __( 'Un plugin activo lo necesita (p. ej. Jetpack o la app móvil): queda abierto aunque esté marcado.' ) }</span> }
							</span>
							<Switch checked={ values[ s.id ] } onCheckedChange={ ( v ) => setValues( { ...values, [ s.id ]: v } ) } aria-label={ s.label } />
						</label>
					);
				} ) }
				<div className="itb:grid itb:gap-3 itb:sm:grid-cols-3">
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:font-medium">{ __( 'Heartbeat admin (s)' ) }</span>
						<Input type="number" min={ 15 } max={ 120 } value={ values.heartbeat_admin } onChange={ ( e ) => num( 'heartbeat_admin', e.target.value ) } />
					</label>
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:font-medium">{ __( 'Heartbeat editor (s)' ) }</span>
						<Input type="number" min={ 15 } max={ 120 } value={ values.heartbeat_editor } onChange={ ( e ) => num( 'heartbeat_editor', e.target.value ) } />
					</label>
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:font-medium">{ __( 'Filtros de Woo para noindex' ) }</span>
						<Input type="number" min={ 0 } max={ 20 } value={ values.filter_limit } onChange={ ( e ) => num( 'filter_limit', e.target.value ) } />
					</label>
				</div>
				<p className="itb:m-0 itb:text-xs itb:text-zinc-500">{ __( 'Con más de N parámetros de filtro (filter_*, min_price…), la página lleva X-Robots-Tag: noindex, nofollow y los buscadores dejan de recorrer combinaciones infinitas. 0 = desactivado.' ) }</p>
				<div className="itb:flex itb:flex-wrap itb:gap-2">
					<Button size="sm" disabled={ save.isPending } onClick={ () => save.mutate( undefined, { onSuccess: ( s ) => client.setQueryData( [ 'shield' ], s ) } ) }>
						{ save.isPending ? __( 'Guardando…' ) : __( 'Guardar' ) }
					</Button>
					<Button size="sm" variant="outline" disabled={ test.isPending } onClick={ () => test.mutate( undefined ) }>
						<FlaskConical aria-hidden />
						{ test.isPending ? __( 'Probando…' ) : __( 'Probar como visitante' ) }
					</Button>
				</div>
				{ save.isSuccess && <Notice level="success">{ __( 'Ajustes guardados.' ) }</Notice> }
				{ save.error && <Notice level="error">{ save.error.message }</Notice> }
				{ test.error && <Notice level="error">{ test.error.message }</Notice> }
				{ test.data && (
					<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-1 itb:p-0">
						{ Object.entries( test.data ).map( ( [ key, c ] ) => (
							<li key={ key } className="itb:flex itb:flex-wrap itb:items-center itb:gap-1.5">
								<span className="itb:w-28 itb:font-medium">{ SHIELD.find( ( s ) => s.id === key )?.label ?? key }</span>
								{ c.error ? (
									<Badge tone="red">{ c.error }</Badge>
								) : (
									<>
										<Badge>{ c.status }</Badge>
										<Badge tone={ c.shield ? 'green' : 'neutral' }>{ c.shield ? `${ __( 'Escudo' ) }: ${ c.shield }` : __( 'Sin escudo' ) }</Badge>
										<span className="itb:text-xs itb:text-zinc-500">{ `${ c.ms } ms` }</span>
									</>
								) }
							</li>
						) ) }
					</ul>
				) }
			</CardContent>
		</Card>
	);
}

function CopyLine( { text }: { text: string } ) {
	const [ copied, setCopied ] = useState( false );
	return (
		<div className="itb:flex itb:items-start itb:gap-2">
			<code className="itb:min-w-0 itb:flex-1 itb:rounded itb:bg-zinc-50 itb:p-2 itb:text-[11px] itb:break-all">{ text }</code>
			<Button
				size="sm"
				variant="outline"
				aria-label={ __( 'Copiar' ) }
				onClick={ () => {
					void navigator.clipboard?.writeText( text ).then( () => setCopied( true ) );
				} }
			>
				<Copy aria-hidden />
				{ copied ? __( 'Copiado' ) : __( 'Copiar' ) }
			</Button>
		</div>
	);
}

function CronCard( { status }: { status: ShieldStatus } ) {
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Cron del sistema' ) }</CardTitle>
				<CardDescription>{ __( 'WP-Cron se dispara con visitas: con caché en el edge casi no llegan visitas al servidor y las tareas se atrasan. Mejor un cron real cada 5 minutos.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ status.cron.disabled ? (
					<Notice level="success">{ __( 'DISABLE_WP_CRON está activo. Comprueba que exista la tarea del sistema.' ) }</Notice>
				) : (
					<Notice level="info">{ __( 'WP-Cron corre con las visitas. Agrega la tarea y después define DISABLE_WP_CRON en wp-config.php (Imagina Turbo no edita ese archivo).' ) }</Notice>
				) }
				<div className="itb:flex itb:flex-col itb:gap-1">
					<span className="itb:text-xs itb:font-medium">{ __( 'Con WP-CLI (recomendado)' ) }</span>
					<CopyLine text={ status.cron.wp_cli } />
				</div>
				<div className="itb:flex itb:flex-col itb:gap-1">
					<span className="itb:text-xs itb:font-medium">{ __( 'Con curl' ) }</span>
					<CopyLine text={ status.cron.command } />
				</div>
				<CopyLine text="define( 'DISABLE_WP_CRON', true );" />
				<ul className="itb:m-0 itb:list-disc itb:pl-5 itb:text-xs itb:text-zinc-600">
					<li>{ __( 'ServerAvatar: Aplicación → Cron Jobs → agregar, con el usuario del sistema de la aplicación.' ) }</li>
					<li>{ __( 'Plesk: Herramientas y configuración → Tareas programadas (o el dominio → Tareas programadas).' ) }</li>
				</ul>
			</CardContent>
		</Card>
	);
}

function TweaksCard( { status }: { status: TweaksStatus } ) {
	const client = useQueryClient();
	const [ pending, setPending ] = useState<Tweak | null>( null );
	const [ revisions, setRevisions ] = useState( status.revisions );
	const [ autosave, setAutosave ] = useState( status.autosave );
	useEffect( () => {
		setRevisions( status.revisions );
		setAutosave( status.autosave );
	}, [ status ] );
	const save = useAction( ( vars: { tweaks?: Record<string, boolean>; revisions?: number; autosave?: number; confirm?: boolean } ) => api<TweaksStatus>( 'tweaks/settings', 'PUT', vars ) );
	const done = ( s: TweaksStatus ) => client.setQueryData( [ 'tweaks' ], s );
	const toggle = ( t: Tweak, on: boolean ) => {
		if ( on && t.risk !== 'safe' ) {
			setPending( t );
			return;
		}
		save.mutate( { tweaks: { [ t.id ]: on } }, { onSuccess: done } );
	};
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Ajustes' ) }</CardTitle>
				<CardDescription>{ __( 'Los seguros vienen activos. Los moderados e invasivos piden confirmación y quedan en el registro.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-1 itb:text-[13px]">
				{ ! status.enabled && <Notice level="info">{ status.forced_off ? `${ __( 'Apagado por coexistencia:' ) } ${ status.forced_off }` : __( 'El módulo Ajustes rápidos está apagado (Panel).' ) }</Notice> }
				<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:p-0">
					{ status.tweaks.map( ( t ) => (
						<li key={ t.id } className="itb:flex itb:items-center itb:justify-between itb:gap-3 itb:border-b itb:border-zinc-100 itb:py-2.5 itb:last:border-b-0">
							<span className="itb:min-w-0">
								<span className="itb:flex itb:flex-wrap itb:items-center itb:gap-1.5">
									<span className="itb:font-medium">{ t.label }</span>
									<RiskBadge risk={ t.risk } label={ RISK_LABELS[ t.risk ] } />
								</span>
								<span className="itb:block itb:text-xs itb:text-zinc-500">{ t.description }</span>
								{ t.id === 'revisions' && t.enabled && (
									<span className="itb:mt-1 itb:flex itb:items-center itb:gap-2">
										<Input className="itb:h-8 itb:w-20" type="number" min={ 0 } max={ 100 } value={ revisions } onChange={ ( e ) => setRevisions( Number.parseInt( e.target.value, 10 ) || 0 ) } aria-label={ __( 'Revisiones' ) } />
										<Button size="sm" variant="outline" disabled={ save.isPending } onClick={ () => save.mutate( { revisions }, { onSuccess: done } ) }>
											{ __( 'Guardar' ) }
										</Button>
									</span>
								) }
								{ t.id === 'autosave' && t.enabled && (
									<span className="itb:mt-1 itb:flex itb:items-center itb:gap-2">
										<Input className="itb:h-8 itb:w-24" type="number" min={ 30 } max={ 86400 } value={ autosave } onChange={ ( e ) => setAutosave( Number.parseInt( e.target.value, 10 ) || 30 ) } aria-label={ __( 'Segundos' ) } />
										<Button size="sm" variant="outline" disabled={ save.isPending } onClick={ () => save.mutate( { autosave }, { onSuccess: done } ) }>
											{ __( 'Guardar' ) }
										</Button>
									</span>
								) }
							</span>
							<Switch checked={ t.enabled } disabled={ save.isPending } onCheckedChange={ ( v ) => toggle( t, v ) } aria-label={ t.label } />
						</li>
					) ) }
				</ul>
				{ save.error && ! ( save.error instanceof ApiError && save.error.status === 428 ) && <Notice level="error">{ save.error.message }</Notice> }
			</CardContent>
			<ConfirmDialog
				open={ pending !== null }
				onOpenChange={ ( o ) => ! o && setPending( null ) }
				title={ pending ? `¿${ pending.label }?` : '' }
				description={ `${ pending?.description ?? '' } ${ pending?.risk === 'invasive' ? __( 'Es invasivo: verifica después las páginas clave (portada, producto, carrito, checkout).' ) : __( 'Es de riesgo moderado: revisa después que todo funcione.' ) }` }
				confirmLabel={ __( 'Activar' ) }
				pending={ save.isPending }
				onConfirm={ () =>
					pending &&
					save.mutate(
						{ tweaks: { [ pending.id ]: true }, confirm: true },
						{
							onSettled: () => setPending( null ),
							onSuccess: done,
						}
					)
				}
			/>
		</Card>
	);
}

function PerfmattersCard() {
	const client = useQueryClient();
	const [ open, setOpen ] = useState( false );
	const { data } = useQuery( { queryKey: [ 'perfmatters' ], queryFn: () => api<PerfmattersReport>( 'tweaks/perfmatters' ) } );
	const run = useAction( () => api<TweaksStatus>( 'tweaks/perfmatters/import', 'POST', { confirm: true } ) );
	if ( ! data?.found ) {
		return null;
	}
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Migrar desde Perfmatters' ) }</CardTitle>
				<CardDescription>{ __( 'Se encontraron ajustes de Perfmatters. Estos son sus equivalentes en Imagina Turbo.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				{ data.active && <Notice level="warning">{ __( 'Perfmatters sigue activo: mientras lo esté, los ajustes que se solapan quedan apagados aquí. Importa y después desactívalo.' ) }</Notice> }
				{ data.mapped.length > 0 && (
					<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-1 itb:p-0">
						{ data.mapped.map( ( m ) => (
							<li key={ m.from + m.to } className="itb:flex itb:flex-wrap itb:items-center itb:gap-1.5">
								<code className="itb:text-xs">{ m.from }</code>
								<span aria-hidden>→</span>
								<code className="itb:text-xs">{ m.to }</code>
								<Badge>{ m.value }</Badge>
							</li>
						) ) }
					</ul>
				) }
				{ data.unmapped.length > 0 && (
					<div>
						<p className="itb:m-0 itb:mb-1 itb:font-medium">{ __( 'Sin equivalente directo' ) }</p>
						<ul className="itb:m-0 itb:list-disc itb:pl-5 itb:text-zinc-600">
							{ data.unmapped.map( ( u ) => (
								<li key={ u.from }>
									<code className="itb:text-xs">{ u.from }</code>: { u.where }
								</li>
							) ) }
						</ul>
					</div>
				) }
				<div>
					<Button size="sm" disabled={ data.mapped.length === 0 || run.isPending } onClick={ () => setOpen( true ) }>
						<Download aria-hidden />
						{ __( 'Importar' ) }
					</Button>
				</div>
				{ run.data && <Notice level="success">{ `${ __( 'Ajustes importados:' ) } ${ run.data.imported ?? 0 }.` }</Notice> }
				{ run.error && <Notice level="error">{ run.error.message }</Notice> }
			</CardContent>
			<ConfirmDialog
				open={ open }
				onOpenChange={ setOpen }
				title={ __( '¿Importar los ajustes de Perfmatters?' ) }
				description={ __( 'Se activan los equivalentes listados (incluidos los moderados o invasivos que ya tenías en Perfmatters). Perfmatters no se toca. Queda registrado y cada ajuste se puede apagar aquí.' ) }
				confirmLabel={ __( 'Importar' ) }
				pending={ run.isPending }
				onConfirm={ () =>
					run.mutate( undefined, {
						onSettled: () => setOpen( false ),
						onSuccess: ( s ) => {
							client.setQueryData( [ 'tweaks' ], s );
							void client.invalidateQueries( { queryKey: [ 'shield' ] } );
						},
					} )
				}
			/>
		</Card>
	);
}
