import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Bot, Check, Copy, Unplug } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Notice } from '@/components/ui/feedback';
import { Switch } from '@/components/ui/switch';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import { useAction } from '@/lib/queries';
import { formatDate } from '@/lib/utils';

interface ConnectorConnection {
	id: number;
	client: string;
	client_host: string;
	user: string;
	level: 'adjust' | 'read';
	created_at: number;
	last_used_at: number;
}

interface ConnectorChange {
	id: string;
	at: number;
	summary: string;
	result: 'applied' | 'reverted' | 'undone' | 'pending' | 'interrupted';
	reasons?: string[];
}

interface ConnectorStatus {
	enabled: boolean;
	url: string;
	problems: string[];
	egress: string;
	connections: ConnectorConnection[];
	changes: ConnectorChange[];
	activity: { id: number; created_at: string; message: string }[];
}

/**
 * Conector de Claude (0.22.0): el dueño del sitio conecta Claude con una URL y un inicio de sesión.
 */
export function ConnectorCard() {
	const client = useQueryClient();
	const { data, error } = useQuery( { queryKey: [ 'connector' ], queryFn: () => api<ConnectorStatus>( 'connector' ) } );
	const [ confirm, setConfirm ] = useState( false );
	const [ cutAll, setCutAll ] = useState( false );
	const [ copied, setCopied ] = useState( false );
	const toggle = useAction( ( vars: { enabled: boolean; confirm: boolean } ) => api( 'modules/connector', 'PUT', vars ) );
	const revoke = useAction( ( id: number | 'all' ) => api<ConnectorStatus>( id === 'all' ? 'connector/connections' : `connector/connections/${ id }`, 'DELETE' ) );
	const refresh = () => void client.invalidateQueries( { queryKey: [ 'connector' ] } );

	if ( error ) {
		return <Notice level="error">{ error.message }</Notice>;
	}
	if ( ! data ) {
		return null;
	}
	const copy = () => {
		void navigator.clipboard?.writeText( data.url ).then( () => {
			setCopied( true );
			window.setTimeout( () => setCopied( false ), 2000 );
		} );
	};

	return (
		<Card className="itb:lg:col-span-2">
			<CardHeader>
				<CardTitle className="itb:flex itb:items-center itb:gap-2">
					<Bot aria-hidden className="itb:size-4" />
					{ __( 'Conector de Claude' ) }
				</CardTitle>
				<CardDescription>{ __( 'El dueño del sitio conecta Claude (claude.ai, la app de escritorio, el móvil o Claude Code) y le pide que diagnostique y acelere el sitio. Claude cambia lo seguro directamente y lo de riesgo moderado con una prueba de las páginas clave que lo deshace solo si algo falla. No puede tocar Cloudflare, el firewall, el escudo, Turnstile, archivos, usuarios, pedidos ni otros plugins.' ) }</CardDescription>
			</CardHeader>
			<CardContent className="itb:flex itb:flex-col itb:gap-4 itb:text-[13px]">
				<label className="itb:flex itb:items-center itb:justify-between itb:gap-3">
					<span className="itb:font-medium">{ __( 'Activar el conector' ) }</span>
					<Switch
						checked={ data.enabled }
						disabled={ toggle.isPending }
						onCheckedChange={ ( v ) => ( v ? setConfirm( true ) : toggle.mutate( { enabled: false, confirm: false }, { onSettled: refresh } ) ) }
						aria-label={ __( 'Activar el conector' ) }
					/>
				</label>
				{ data.problems.map( ( p ) => (
					<Notice key={ p } level="warning">{ p }</Notice>
				) ) }
				{ toggle.error && <Notice level="error">{ toggle.error.message }</Notice> }

				{ data.enabled && (
					<>
						<div className="itb:flex itb:flex-col itb:gap-1.5">
							<span className="itb:font-medium">{ __( 'URL del conector' ) }</span>
							<div className="itb:flex itb:min-w-0 itb:items-center itb:gap-2">
								<code className="itb:min-w-0 itb:flex-1 itb:truncate itb:rounded itb:bg-zinc-100 itb:px-2 itb:py-1.5 itb:text-xs">{ data.url }</code>
								<Button variant="outline" size="sm" onClick={ copy }>
									{ copied ? <Check aria-hidden /> : <Copy aria-hidden /> }
									{ copied ? __( 'Copiada' ) : __( 'Copiar' ) }
								</Button>
							</div>
						</div>
						<ol className="itb:m-0 itb:flex itb:list-decimal itb:flex-col itb:gap-1 itb:pl-5 itb:text-zinc-600">
							<li>{ __( 'En Claude: Personalizar → Conectores → Añadir conector personalizado.' ) }</li>
							<li>{ __( 'Pega la URL de arriba, ponle un nombre (por ejemplo, el del sitio) y pulsa Conectar.' ) }</li>
							<li>{ __( 'Claude abre este WordPress: entra con tu usuario de administrador, elige «Diagnosticar y ajustar» o «Solo diagnosticar» y pulsa Permitir.' ) }</li>
							<li>{ __( 'En un chat, activa el conector y pide, por ejemplo: «Revisa y acelera mi sitio con Imagina Turbo».' ) }</li>
						</ol>
						<p className="itb:m-0 itb:text-xs itb:text-zinc-500">
							{ __( 'Si el sitio usa Cloudflare con Bot Fight Mode o reglas propias que bloqueen bots, deja pasar las peticiones de Anthropic a esta URL (rango' ) } <code>{ data.egress }</code>).
						</p>

						<div className="itb:flex itb:flex-col itb:gap-2">
							<div className="itb:flex itb:items-center itb:justify-between itb:gap-2">
								<span className="itb:font-medium">{ __( 'Accesos activos' ) }</span>
								{ data.connections.length > 1 && (
									<Button variant="outline" size="sm" onClick={ () => setCutAll( true ) }>
										<Unplug aria-hidden />
										{ __( 'Cortar todos' ) }
									</Button>
								) }
							</div>
							{ data.connections.length === 0 && <p className="itb:m-0 itb:text-zinc-500">{ __( 'Ninguno todavía.' ) }</p> }
							{ data.connections.map( ( c ) => (
								<div key={ c.id } className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-2 itb:rounded-md itb:border itb:border-zinc-200 itb:px-3 itb:py-2">
									<div className="itb:min-w-0">
										<div className="itb:flex itb:items-center itb:gap-2">
											<span className="itb:font-medium">{ c.client }</span>
											<Badge tone={ c.level === 'adjust' ? 'amber' : 'blue' }>{ c.level === 'adjust' ? __( 'Diagnosticar y ajustar' ) : __( 'Solo diagnosticar' ) }</Badge>
										</div>
										<div className="itb:text-xs itb:text-zinc-500">
											{ c.client_host } · { c.user } · { __( 'último uso' ) } { formatDate( c.last_used_at, config.locale ) }
										</div>
									</div>
									<Button variant="outline" size="sm" disabled={ revoke.isPending } onClick={ () => revoke.mutate( c.id, { onSuccess: ( s ) => client.setQueryData( [ 'connector' ], s ) } ) }>
										{ __( 'Cortar' ) }
									</Button>
								</div>
							) ) }
						</div>

						{ data.changes.length > 0 && (
							<div className="itb:flex itb:flex-col itb:gap-2">
								<span className="itb:font-medium">{ __( 'Cambios hechos por Claude' ) }</span>
								{ data.changes.map( ( ch ) => (
									<div key={ ch.id } className="itb:flex itb:flex-col itb:gap-0.5 itb:border-b itb:border-zinc-100 itb:pb-2 itb:last:border-0">
										<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
											<Badge tone={ ch.result === 'applied' ? 'green' : 'neutral' }>{ { applied: __( 'Aplicado' ), reverted: __( 'Se deshizo solo' ), undone: __( 'Deshecho' ), pending: __( 'En curso' ), interrupted: __( 'Interrumpido y deshecho' ) }[ ch.result ] }</Badge>
											<span>{ ch.summary }</span>
											<span className="itb:text-xs itb:text-zinc-500">{ formatDate( ch.at, config.locale ) }</span>
										</div>
										{ ( ch.result === 'reverted' || ch.result === 'interrupted' ) && ch.reasons && ch.reasons.length > 0 && (
											<span className="itb:text-xs itb:text-zinc-500">
												{ ch.reasons.slice( 0, 2 ).join( ' · ' ) }
												{ ch.reasons.length > 2 && ` · ${ __( 'y' ) } ${ ch.reasons.length - 2 } ${ __( 'más' ) }` }
											</span>
										) }
									</div>
								) ) }
							</div>
						) }
					</>
				) }
				<ErrorLine error={ revoke.error } />
			</CardContent>
			<ConfirmDialog
				open={ confirm }
				onOpenChange={ setConfirm }
				title={ __( '¿Activar el conector de Claude?' ) }
				description={ __( 'Quien administre este WordPress podrá conectar Claude y dejar que ajuste Imagina Turbo. Cada acceso se autoriza con tu usuario, queda registrado y se puede cortar aquí.' ) }
				confirmLabel={ __( 'Activar' ) }
				pending={ toggle.isPending }
				onConfirm={ () => toggle.mutate( { enabled: true, confirm: true }, { onSettled: () => { setConfirm( false ); refresh(); } } ) }
			/>
			<ConfirmDialog
				open={ cutAll }
				onOpenChange={ setCutAll }
				title={ __( '¿Cortar todos los accesos?' ) }
				description={ __( 'Claude tendrá que volver a conectarse y pedir permiso.' ) }
				confirmLabel={ __( 'Cortar todos' ) }
				pending={ revoke.isPending }
				onConfirm={ () => revoke.mutate( 'all', { onSuccess: ( s ) => client.setQueryData( [ 'connector' ], s ), onSettled: () => setCutAll( false ) } ) }
			/>
		</Card>
	);
}

function ErrorLine( { error }: { error: Error | null } ) {
	return error ? <Notice level="error">{ error.message }</Notice> : null;
}
