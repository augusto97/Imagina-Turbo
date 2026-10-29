import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { api } from '@/lib/api';
import { __ } from '@/lib/i18n';
import { useAction } from '@/lib/queries';
import type { TurnstileForm, TurnstileStatus } from '@/lib/types';

interface TurnstileApi {
	render: ( el: HTMLElement, options: Record<string, unknown> ) => string;
	remove: ( id: string ) => void;
}

const FORMS: { id: TurnstileForm; label: string; description: string; woo?: boolean }[] = [
	{ id: 'login', label: __( 'Inicio de sesión' ), description: __( 'wp-login.php (también la URL de acceso personalizada) y el formulario de Mi cuenta de WooCommerce.' ) },
	{ id: 'register', label: __( 'Registro' ), description: __( 'Registro de wp-login.php y de Mi cuenta. Frena las cuentas falsas.' ) },
	{ id: 'lostpassword', label: __( 'Recuperar contraseña' ), description: __( 'Evita que un bot use el formulario para enviar correos en masa.' ) },
	{ id: 'checkout', label: __( 'Pago' ), description: __( 'Pago clásico y por bloques. Frena las pruebas de tarjetas robadas. Los botones exprés (Apple Pay, Google Pay, PayPal) no pasan por aquí, y quien gestiona la tienda tampoco.' ), woo: true },
];

/** api.js de Turnstile con render explícito (una sola vez). */
function loadTurnstile( src: string ): Promise<TurnstileApi> {
	const w = window as unknown as { turnstile?: TurnstileApi; itbAdminTs?: Promise<TurnstileApi> };
	if ( w.turnstile ) {
		return Promise.resolve( w.turnstile );
	}
	w.itbAdminTs ??= new Promise( ( resolve, reject ) => {
		const s = document.createElement( 'script' );
		s.src = `${ src }?render=explicit`;
		s.async = true;
		s.onload = () => ( w.turnstile ? resolve( w.turnstile ) : reject( new Error( 'turnstile' ) ) );
		s.onerror = () => reject( new Error( __( 'No se pudo cargar Turnstile desde Cloudflare.' ) ) );
		document.head.appendChild( s );
	} );
	return w.itbAdminTs;
}

export function TurnstileCard() {
	const { data, isLoading, error } = useQuery( { queryKey: [ 'turnstile' ], queryFn: () => api<TurnstileStatus>( 'turnstile' ) } );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Turnstile' ) }</CardTitle>
				<CardDescription>
					{ __( 'Verificación invisible de Cloudflare (gratis) en los formularios que atacan los bots. El visitante normal no ve nada; solo si Cloudflare duda aparece una casilla. Si Cloudflare no responde, el formulario sigue funcionando.' ) }
				</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-4 itb:text-[13px]">
				{ isLoading && <Skeleton className="itb:h-40" /> }
				{ error && <Notice level="error">{ error.message }</Notice> }
				{ data && <TurnstileBody status={ data } /> }
			</CardContent>
		</Card>
	);
}

function TurnstileBody( { status }: { status: TurnstileStatus } ) {
	const client = useQueryClient();
	const set = ( s: TurnstileStatus ) => client.setQueryData( [ 'turnstile' ], s );
	const [ siteKey, setSiteKey ] = useState( status.site_key );
	const [ secret, setSecret ] = useState( '' );
	const [ forms, setForms ] = useState( status.forms );
	const [ skip, setSkip ] = useState( status.skip );
	const [ removing, setRemoving ] = useState( false );
	useEffect( () => {
		setForms( status.forms );
		setSkip( status.skip );
	}, [ status ] );
	const saveKeys = useAction( () => api<TurnstileStatus>( 'turnstile', 'PUT', { site_key: siteKey.trim(), ...( secret.trim() ? { secret: secret.trim() } : {} ) } ) );
	const saveForms = useAction( () => api<TurnstileStatus>( 'turnstile', 'PUT', { forms, skip } ) );
	const remove = useAction( () => api<TurnstileStatus>( 'turnstile', 'DELETE' ) );
	const enableModule = useAction( () => api( 'modules/turnstile', 'PUT', { enabled: true, confirm: true } ) );
	const keysSaved = status.site_key !== '' && status.secret.state === 'ok';

	return (
		<>
			{ ! status.enabled && (
				<Notice level="warning">
					<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-3">
						<span>{ __( 'El módulo Turnstile está apagado. Puedes guardar y probar las claves; los formularios se protegen al activarlo.' ) }</span>
						<Button size="sm" disabled={ enableModule.isPending } onClick={ () => enableModule.mutate( undefined, { onSuccess: () => void client.invalidateQueries( { queryKey: [ 'turnstile' ] } ) } ) }>
							{ __( 'Activar módulo' ) }
						</Button>
					</div>
					{ enableModule.error && <div className="itb:mt-2">{ enableModule.error.message }</div> }
				</Notice>
			) }
			{ status.conflicts.length > 0 && (
				<Notice level="warning">{ `${ __( 'Otro plugin ya pone captcha en los formularios:' ) } ${ status.conflicts.join( ', ' ) }. ${ __( 'Usa uno solo en cada formulario para no pedir dos verificaciones.' ) }` }</Notice>
			) }
			{ status.secret.state === 'rotated' && <Notice level="warning">{ __( 'Las claves de wp-config.php cambiaron y la clave secreta ya no se puede leer: vuelve a pegarla.' ) }</Notice> }

			<section className="itb:flex itb:flex-col itb:gap-2">
				<span className="itb:font-semibold">{ __( '1. Claves' ) }</span>
				<span className="itb:text-xs itb:text-zinc-500">
					{ __( 'En Cloudflare → Turnstile, crea un widget en modo «Managed» con el dominio del sitio y copia sus dos claves.' ) }{ ' ' }
					<a className="itb:inline-flex itb:items-center itb:gap-1" href={ status.dashboard } target="_blank" rel="noreferrer">
						<ExternalLink className="itb:size-3.5" aria-hidden />
						{ __( 'Abrir Turnstile' ) }
					</a>
				</span>
				<div className="itb:grid itb:gap-2 itb:sm:grid-cols-2">
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:font-medium">{ __( 'Clave del sitio' ) }</span>
						<Input value={ siteKey } onChange={ ( e ) => setSiteKey( e.target.value ) } placeholder="0x4AAAAAAA…" autoComplete="off" />
					</label>
					<label className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:font-medium">{ __( 'Clave secreta' ) }</span>
						<Input type="password" value={ secret } onChange={ ( e ) => setSecret( e.target.value ) } placeholder={ status.secret.last4 ? `••••${ status.secret.last4 }` : '0x4AAAAAAA…' } autoComplete="new-password" />
					</label>
				</div>
				<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
					<Button
						size="sm"
						disabled={ saveKeys.isPending || siteKey.trim() === '' }
						onClick={ () =>
							saveKeys.mutate( undefined, {
								onSuccess: ( s ) => {
									setSecret( '' );
									set( s );
								},
							} )
						}
					>
						{ saveKeys.isPending ? __( 'Guardando…' ) : __( 'Guardar claves' ) }
					</Button>
					{ keysSaved && (
						<Button size="sm" variant="outline" onClick={ () => setRemoving( true ) }>
							{ __( 'Quitar claves' ) }
						</Button>
					) }
					{ status.secret.state === 'ok' && <Badge tone="green">{ `${ __( 'Secreta guardada' ) } ••••${ status.secret.last4 }` }</Badge> }
				</div>
				{ saveKeys.error && <Notice level="error">{ saveKeys.error.message }</Notice> }
			</section>

			{ keysSaved && <TestSection status={ status } onResult={ set } /> }

			<section className="itb:flex itb:flex-col itb:gap-2">
				<span className="itb:font-semibold">{ __( '3. Formularios' ) }</span>
				{ ! status.verified && <span className="itb:text-xs itb:text-zinc-500">{ __( 'Se pueden activar cuando la prueba de las claves pase.' ) }</span> }
				{ FORMS.filter( ( f ) => ! f.woo || status.woo ).map( ( f ) => (
					<label key={ f.id } className="itb:flex itb:items-center itb:justify-between itb:gap-3">
						<span>
							<span className="itb:font-medium">{ f.label }</span>
							<span className="itb:block itb:text-xs itb:text-zinc-500">{ f.description }</span>
						</span>
						<Switch checked={ forms[ f.id ] } disabled={ ! status.verified } onCheckedChange={ ( v ) => setForms( { ...forms, [ f.id ]: v } ) } aria-label={ f.label } />
					</label>
				) ) }
				{ status.woo && forms.checkout && status.gateways.length > 0 && (
					<div className="itb:flex itb:flex-col itb:gap-1">
						<span className="itb:text-xs itb:font-medium">{ __( 'Pasarelas sin verificación en el pago' ) }</span>
						<span className="itb:text-xs itb:text-zinc-500">{ __( 'Solo si una pasarela envía el pedido sin pasar por el formulario y falla con Turnstile.' ) }</span>
						{ status.gateways.map( ( g ) => (
							<label key={ g.id } className="itb:flex itb:items-center itb:gap-2 itb:text-xs">
								<input type="checkbox" checked={ skip.includes( g.id ) } onChange={ () => setSkip( skip.includes( g.id ) ? skip.filter( ( x ) => x !== g.id ) : [ ...skip, g.id ] ) } />
								{ `${ g.title } (${ g.id })` }
							</label>
						) ) }
					</div>
				) }
				<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
					<Button size="sm" disabled={ ! status.verified || saveForms.isPending } onClick={ () => saveForms.mutate( undefined, { onSuccess: set } ) }>
						{ saveForms.isPending ? __( 'Guardando…' ) : __( 'Guardar formularios' ) }
					</Button>
					{ status.active ? <Badge tone="green">{ __( 'Protegiendo' ) }</Badge> : <Badge>{ __( 'Sin proteger' ) }</Badge> }
				</div>
				{ saveForms.error && <Notice level="error">{ saveForms.error.message }</Notice> }
			</section>

			<ConfirmDialog
				open={ removing }
				onOpenChange={ setRemoving }
				title={ __( '¿Quitar las claves de Turnstile?' ) }
				description={ __( 'Se borran las dos claves y se apagan todos los formularios.' ) }
				confirmLabel={ __( 'Quitar' ) }
				pending={ remove.isPending }
				onConfirm={ () =>
					remove.mutate( undefined, {
						onSettled: () => setRemoving( false ),
						onSuccess: ( s ) => {
							setSiteKey( '' );
							set( s );
						},
					} )
				}
			/>
		</>
	);
}

/** Widget real con la clave del sitio: su token se verifica en el servidor con la clave secreta. */
function TestSection( { status, onResult }: { status: TurnstileStatus; onResult: ( s: TurnstileStatus ) => void } ) {
	const box = useRef<HTMLDivElement>( null );
	const [ open, setOpen ] = useState( false );
	const [ loadError, setLoadError ] = useState( '' );
	const test = useAction( ( token: string ) => api<TurnstileStatus>( 'turnstile/test', 'POST', { token } ) );
	useEffect( () => {
		if ( ! open || ! box.current ) {
			return;
		}
		let id = '';
		let api_: TurnstileApi | null = null;
		const el = box.current;
		loadTurnstile( status.api_js )
			.then( ( t ) => {
				api_ = t;
				id = t.render( el, {
					sitekey: status.site_key,
					action: 'itb-admin-test',
					callback: ( token: string ) => test.mutate( token, { onSuccess: onResult } ),
					'error-callback': ( code: string ) => setLoadError( `${ __( 'Turnstile no pudo mostrarse con esta clave del sitio' ) } (${ code }). ${ __( 'Revisa que el dominio esté en el widget de Cloudflare.' ) }` ),
				} );
			} )
			.catch( ( e: Error ) => setLoadError( e.message ) );
		return () => {
			if ( api_ && id ) {
				api_.remove( id );
			}
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- un widget por apertura.
	}, [ open, status.site_key ] );
	const result = test.data?.test;
	return (
		<section className="itb:flex itb:flex-col itb:gap-2">
			<span className="itb:font-semibold">{ __( '2. Prueba' ) }</span>
			<span className="itb:text-xs itb:text-zinc-500">{ __( 'Resuelve el widget aquí: el servidor verifica el token con la clave secreta. Sin esta prueba no se activa ningún formulario, para que unas claves mal puestas no dejen a nadie fuera.' ) }</span>
			<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
				{ status.verified ? <Badge tone="green">{ __( 'Claves probadas' ) }</Badge> : <Badge tone="amber">{ __( 'Sin probar' ) }</Badge> }
				<Button size="sm" variant="outline" onClick={ () => setOpen( true ) } disabled={ open && ! result }>
					<ShieldCheck aria-hidden />
					{ __( 'Probar las claves' ) }
				</Button>
			</div>
			{ open && <div ref={ box } /> }
			{ test.isPending && <span className="itb:text-xs itb:text-zinc-500">{ __( 'Verificando…' ) }</span> }
			{ loadError && <Notice level="error">{ loadError }</Notice> }
			{ test.error && <Notice level="error">{ test.error.message }</Notice> }
			{ result && ( result.ok ? <Notice level="success">{ __( 'Las claves funcionan. Ya puedes activar los formularios.' ) }</Notice> : <Notice level="error">{ `${ __( 'Cloudflare rechazó el token' ) }: ${ result.codes.join( ', ' ) || '—' }. ${ __( 'Revisa que la clave secreta sea la del mismo widget.' ) }` }</Notice> ) }
		</section>
	);
}
