import { useQuery, useQueryClient } from '@tanstack/react-query';
import { RefreshCw, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { KeyValue, PageHeader } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Textarea } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { api } from '@/lib/api';
import { __ } from '@/lib/i18n';
import { useAction } from '@/lib/queries';
import type { WooInspect, WooInspectCase, WooStatus } from '@/lib/types';

const lines = ( text: string ) =>
	text
		.split( '\n' )
		.map( ( l ) => l.trim() )
		.filter( Boolean );

export function Woo() {
	const { data, isLoading, error, isFetching, refetch } = useQuery( { queryKey: [ 'woo' ], queryFn: () => api<WooStatus>( 'woo/status' ) } );
	return (
		<>
			<PageHeader
				title={ __( 'WooCommerce' ) }
				description={ __( 'Carrito por Store API: el catálogo se sirve desde la caché aunque el visitante tenga productos en el carrito.' ) }
				actions={
					<Button variant="outline" size="sm" onClick={ () => void refetch() } disabled={ isFetching }>
						<RefreshCw className={ isFetching ? 'itb:animate-spin' : '' } aria-hidden />
						{ __( 'Actualizar' ) }
					</Button>
				}
			/>
			{ error && <Notice level="error">{ error.message }</Notice> }
			{ isLoading && <Skeleton className="itb:h-64" /> }
			{ data && ! data.woocommerce && <Notice level="info">{ __( 'WooCommerce no está activo en este sitio.' ) }</Notice> }
			{ data?.woocommerce && (
				<div className="itb:flex itb:flex-col itb:gap-4">
					{ ! data.enabled && <EnableCard /> }
					<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2">
						<HowCard status={ data } />
						<SelectorsCard status={ data } />
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
	const action = useAction( () => api( 'modules/woo_cart', 'PUT', { enabled: true, confirm: true } ) );
	return (
		<Notice level="warning">
			<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-3">
				<span>{ __( 'El carrito por Store API está apagado. Es de riesgo moderado: antes de activarlo, revisa que el contador del mini-carrito del tema se actualice (abajo puedes probarlo).' ) }</span>
				<Button size="sm" onClick={ () => setOpen( true ) }>
					{ __( 'Activar' ) }
				</Button>
			</div>
			{ action.error && <div className="itb:mt-2">{ action.error.message }</div> }
			<ConfirmDialog
				open={ open }
				onOpenChange={ setOpen }
				title={ __( '¿Activar el carrito por Store API?' ) }
				description={ __( 'Se reemplaza cart-fragments por un script propio que actualiza contador y total, y las páginas con carrito pasan a servirse desde la caché del edge. Comprueba después agregar, quitar y pagar. Queda registrado y se apaga desde aquí o desde el Panel.' ) }
				confirmLabel={ __( 'Activar' ) }
				pending={ action.isPending }
				onConfirm={ () =>
					action.mutate( undefined, {
						onSettled: () => setOpen( false ),
						onSuccess: () => void client.invalidateQueries( { queryKey: [ 'woo' ] } ),
					} )
				}
			/>
		</Notice>
	);
}

function HowCard( { status }: { status: WooStatus } ) {
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Cómo funciona en este sitio' ) }</CardTitle>
				<CardDescription>{ __( 'Sin el pedido a wc-ajax=get_refreshed_fragments en cada página.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				<dl className="itb:m-0">
					<KeyValue label={ __( 'Tema' ) }>
						<span className="itb:inline-flex itb:items-center itb:gap-1.5">
							{ status.theme.slug }
							{ status.theme.known ? (
								<Badge tone={ status.theme.verified ? 'green' : 'amber' }>{ status.theme.verified ? __( 'Selectores verificados' ) : __( 'Selectores sin verificar' ) }</Badge>
							) : (
								<Badge>{ __( 'Sin selectores propios' ) }</Badge>
							) }
						</span>
					</KeyValue>
					<KeyValue label={ __( 'Total del encabezado' ) }>{ status.tax_incl ? __( 'Subtotal con impuestos' ) : __( 'Subtotal sin impuestos' ) }</KeyValue>
					<KeyValue label={ __( 'Geolocalización con caché' ) }>{ status.geolocation ? __( 'Sí (el parámetro ?v= forma parte de la llave)' ) : __( 'No' ) }</KeyValue>
				</dl>
				<ul className="itb:m-0 itb:list-disc itb:pl-5 itb:text-zinc-600">
					<li>{ __( 'Contador y total: se leen de la Store API (GET, sin nonce) y se guardan en el navegador. Con el carrito vacío no hay ninguna petición.' ) }</li>
					<li>{ __( 'El desplegable con la lista de productos se pide solo al pasar el cursor o tocar el mini-carrito.' ) }</li>
					<li>{ __( 'Quien tiene carrito recibe páginas de la caché, pero nunca genera la copia que se guarda: su HTML puede llevar sus productos.' ) }</li>
					<li>{ __( 'Carrito, checkout, cuenta, ?add-to-cart=, wc-ajax y la Store API nunca se cachean.' ) }</li>
				</ul>
			</CardContent>
		</Card>
	);
}

function SelectorsCard( { status }: { status: WooStatus } ) {
	const client = useQueryClient();
	const [ count, setCount ] = useState( status.custom.count.join( '\n' ) );
	const [ total, setTotal ] = useState( status.custom.total.join( '\n' ) );
	const [ recent, setRecent ] = useState( status.recently_viewed );
	useEffect( () => {
		setCount( status.custom.count.join( '\n' ) );
		setTotal( status.custom.total.join( '\n' ) );
		setRecent( status.recently_viewed );
	}, [ status ] );
	const save = useAction( () => api<WooStatus>( 'woo/settings', 'PUT', { count_selectors: lines( count ), total_selectors: lines( total ), recently_viewed: recent } ) );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Mini-carrito del tema' ) }</CardTitle>
				<CardDescription>{ __( 'Elementos que se actualizan. Agrega selectores CSS si el tema o un constructor de cabeceras usa otros.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				<div>
					<p className="itb:m-0 itb:mb-1 itb:font-medium">{ __( 'En uso' ) }</p>
					<ul className="itb:m-0 itb:flex itb:list-none itb:flex-col itb:gap-1 itb:p-0">
						{ status.selectors.count.map( ( s ) => (
							<li key={ 'c' + s } className="itb:flex itb:items-center itb:gap-2">
								<Badge tone="blue">{ __( 'Contador' ) }</Badge>
								<code className="itb:text-xs itb:break-all">{ s }</code>
							</li>
						) ) }
						{ status.selectors.total.map( ( s ) => (
							<li key={ 't' + s } className="itb:flex itb:items-center itb:gap-2">
								<Badge tone="green">{ __( 'Total' ) }</Badge>
								<code className="itb:text-xs itb:break-all">{ s }</code>
							</li>
						) ) }
					</ul>
				</div>
				<label className="itb:flex itb:flex-col itb:gap-1">
					<span className="itb:font-medium">{ __( 'Selectores propios del contador (uno por línea)' ) }</span>
					<Textarea value={ count } onChange={ ( e ) => setCount( e.target.value ) } placeholder=".mi-cabecera .contador" aria-label={ __( 'Selectores del contador' ) } />
				</label>
				<label className="itb:flex itb:flex-col itb:gap-1">
					<span className="itb:font-medium">{ __( 'Selectores propios del total (uno por línea)' ) }</span>
					<Textarea value={ total } onChange={ ( e ) => setTotal( e.target.value ) } placeholder=".mi-cabecera .woocommerce-Price-amount" aria-label={ __( 'Selectores del total' ) } />
				</label>
				<label className="itb:flex itb:items-center itb:justify-between itb:gap-3">
					<span>
						<span className="itb:font-medium">{ __( 'Productos vistos sin Set-Cookie' ) }</span>
						<span className="itb:block itb:text-xs itb:text-zinc-500">{ __( 'Con el widget de productos vistos, WooCommerce envía una cookie en cada producto y la página deja de ser cacheable. La escribe el navegador.' ) }</span>
					</span>
					<Switch checked={ recent } onCheckedChange={ setRecent } aria-label={ __( 'Productos vistos sin Set-Cookie' ) } />
				</label>
				<div>
					<Button
						size="sm"
						disabled={ save.isPending }
						onClick={ () => save.mutate( undefined, { onSuccess: ( s ) => client.setQueryData( [ 'woo' ], s ) } ) }
					>
						{ save.isPending ? __( 'Guardando…' ) : __( 'Guardar' ) }
					</Button>
				</div>
				{ save.isSuccess && <Notice level="success">{ __( 'Ajustes guardados.' ) }</Notice> }
				{ save.error && <Notice level="error">{ save.error.message }</Notice> }
			</CardContent>
		</Card>
	);
}

function CaseResult( { title, result, expectStore }: { title: string; result: WooInspectCase; expectStore: boolean } ) {
	if ( result.error ) {
		return <Notice level="error">{ `${ title }: ${ result.error }` }</Notice>;
	}
	const stored = result.edge !== undefined && result.edge !== '' && ! result.edge.includes( 'no-store' );
	return (
		<div className="itb:rounded-md itb:border itb:border-zinc-200 itb:p-3">
			<p className="itb:m-0 itb:mb-2 itb:font-medium">{ title }</p>
			<div className="itb:flex itb:flex-wrap itb:gap-1.5">
				<Badge>{ result.status }</Badge>
				{ result.edge ? <Badge tone={ stored === expectStore ? 'green' : 'red' }>{ stored ? __( 'El edge la guarda' ) : __( 'El edge no la guarda' ) }</Badge> : <Badge>{ __( 'Sin cabecera para el edge' ) }</Badge> }
				<Badge tone={ result.script ? 'green' : 'amber' }>{ result.script ? __( 'Script del carrito presente' ) : __( 'Sin script del carrito' ) }</Badge>
				<Badge tone={ result.fragments ? 'red' : 'green' }>{ result.fragments ? __( 'cart-fragments original cargado' ) : __( 'Sin cart-fragments' ) }</Badge>
				{ ( result.set_cookies ?? [] ).length > 0 && <Badge tone="red">{ `Set-Cookie: ${ ( result.set_cookies ?? [] ).join( ', ' ) }` }</Badge> }
			</div>
		</div>
	);
}

function InspectCard() {
	const inspect = useAction( () => api<WooInspect>( 'woo/inspect', 'POST' ) );
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Probar' ) }</CardTitle>
				<CardDescription>{ __( 'Pide la portada como visitante sin carrito y con carrito. La primera debe poder guardarse en el edge; la segunda nunca.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-3 itb:text-[13px]">
				<div>
					<Button size="sm" variant="outline" disabled={ inspect.isPending } onClick={ () => inspect.mutate( undefined ) }>
						<Search aria-hidden />
						{ inspect.isPending ? __( 'Probando…' ) : __( 'Probar la portada' ) }
					</Button>
				</div>
				{ inspect.error && <Notice level="error">{ inspect.error.message }</Notice> }
				{ inspect.data && (
					<div className="itb:grid itb:gap-3 itb:md:grid-cols-2">
						<CaseResult title={ __( 'Sin carrito' ) } result={ inspect.data.empty } expectStore />
						<CaseResult title={ __( 'Con carrito' ) } result={ inspect.data.cart } expectStore={ false } />
					</div>
				) }
				<p className="itb:m-0 itb:text-xs itb:text-zinc-500">{ __( 'Que el edge guarde la página sin carrito requiere el coordinador de caché en modo «Emitir cabeceras para el edge».' ) }</p>
			</CardContent>
		</Card>
	);
}
