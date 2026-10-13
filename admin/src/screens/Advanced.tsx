import { zodResolver } from '@hookform/resolvers/zod';
import { useQuery } from '@tanstack/react-query';
import { Download, ExternalLink, RotateCcw, ShieldOff, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { KeyValue, PageHeader } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog } from '@/components/ui/dialog';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { Select, Textarea } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import { useAction, useStatus } from '@/lib/queries';
import type { CompileResult, PublicSettings, Safety, Status } from '@/lib/types';
import { downloadFile, formatDate } from '@/lib/utils';

export function Advanced() {
	const { data, isLoading, error } = useStatus();
	return (
		<>
			<PageHeader title={ __( 'Avanzado' ) } description={ __( 'Seguridad operativa, configuración compilada, ajustes y licencia.' ) } />
			{ error && <Notice level="error">{ error.message }</Notice> }
			{ isLoading && <Skeleton className="itb:h-64" /> }
			{ data && (
				<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2">
					<KillSwitchCard safety={ data.safety } />
					<BypassCard safety={ data.safety } />
					<SafeModeCard safety={ data.safety } />
					<ConfigCard status={ data } />
					<SettingsCard />
					<ImportExportCard />
					<LicenseCard status={ data } />
					<LoaderCard status={ data } />
				</div>
			) }
		</>
	);
}

function ErrorText( { error }: { error: Error | null } ) {
	return error ? <Notice level="error" className="itb:mt-3">{ error.message }</Notice> : null;
}

function KillSwitchCard( { safety }: { safety: Safety } ) {
	const [ confirm, setConfirm ] = useState<boolean | null>( null );
	const action = useAction( ( enabled: boolean ) => api<Safety>( 'safety/kill-switch', 'POST', { enabled } ) );
	const active = safety.kill_switch.file || safety.kill_switch.constant;

	return (
		<Card>
			<CardHeader>
				<div className="itb:flex itb:items-center itb:justify-between itb:gap-3">
					<CardTitle>{ __( 'Kill switch' ) }</CardTitle>
					<Switch checked={ active } disabled={ safety.kill_switch.constant || action.isPending } onCheckedChange={ ( value ) => setConfirm( value ) } aria-label={ __( 'Kill switch' ) } />
				</div>
				<CardDescription>{ __( 'Desactiva el plugin en todo el sitio, salvo esta pantalla. Crea el archivo wp-content/imagina-turbo/disable.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				{ safety.kill_switch.constant && <Notice level="warning">{ __( 'Está activo por la constante ITB_DISABLE en wp-config.php: quítala de ahí para reactivar el plugin.' ) }</Notice> }
				{ ! safety.kill_switch.constant && <p className="itb:text-xs itb:text-zinc-500">{ __( 'También funciona sin acceso al admin: define ITB_DISABLE en wp-config.php o crea el archivo por FTP.' ) }</p> }
				<ErrorText error={ action.error } />
			</CardContent>
			<ConfirmDialog
				open={ confirm !== null }
				onOpenChange={ ( open ) => ! open && setConfirm( null ) }
				title={ confirm ? __( '¿Activar el kill switch?' ) : __( '¿Desactivar el kill switch?' ) }
				description={ confirm ? __( 'Imagina Turbo dejará de actuar en todo el sitio hasta que lo desactives.' ) : __( 'Imagina Turbo volverá a actuar con su configuración actual.' ) }
				confirmLabel={ confirm ? __( 'Activar' ) : __( 'Desactivar' ) }
				destructive={ confirm === true }
				pending={ action.isPending }
				onConfirm={ () => action.mutate( confirm === true, { onSettled: () => setConfirm( null ) } ) }
			/>
		</Card>
	);
}

function BypassCard( { safety }: { safety: Safety } ) {
	const [ hours, setHours ] = useState( '1' );
	const action = useAction( ( vars: { enabled: boolean; hours: number } ) => api<{ active: boolean; expires: number | null }>( 'safety/bypass-cookie', 'POST', vars ) );
	const cookie = safety.bypass_cookie;

	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Cookie de bypass' ) }</CardTitle>
				<CardDescription>{ __( 'Navega el sitio "como si el plugin no existiera", solo tú y por un tiempo limitado. Sirve para comparar y diagnosticar.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				{ ! cookie.available ? (
					<Notice level="warning">{ __( 'Faltan AUTH_KEY y AUTH_SALT en wp-config.php: no se puede firmar la cookie.' ) }</Notice>
				) : cookie.active ? (
					<div className="itb:flex itb:flex-wrap itb:items-center itb:justify-between itb:gap-3">
						<span className="itb:text-[13px]">
							<Badge tone="amber">{ __( 'Activa' ) }</Badge> { __( 'hasta' ) } { formatDate( cookie.expires, config.locale ) }
						</span>
						<Button variant="outline" size="sm" disabled={ action.isPending } onClick={ () => action.mutate( { enabled: false, hours: 1 } ) }>
							{ __( 'Retirar' ) }
						</Button>
					</div>
				) : (
					<div className="itb:flex itb:flex-wrap itb:items-center itb:gap-2">
						<Select className="itb:w-32" value={ hours } onChange={ ( e ) => setHours( e.target.value ) } aria-label={ __( 'Duración' ) }>
							{ [ 1, 2, 4, 8 ].map( ( h ) => (
								<option key={ h } value={ h }>
									{ h } { h === 1 ? __( 'hora' ) : __( 'horas' ) }
								</option>
							) ) }
						</Select>
						<Button size="sm" disabled={ action.isPending } onClick={ () => action.mutate( { enabled: true, hours: Number( hours ) } ) }>
							<ShieldOff aria-hidden />
							{ __( 'Emitir para mí' ) }
						</Button>
					</div>
				) }
				<ErrorText error={ action.error } />
			</CardContent>
		</Card>
	);
}

function SafeModeCard( { safety }: { safety: Safety } ) {
	const [ open, setOpen ] = useState( false );
	const action = useAction( () => api<Safety>( 'safety/safe-mode/exit', 'POST' ) );
	const details = safety.safe_mode.details;

	return (
		<Card>
			<CardHeader>
				<div className="itb:flex itb:items-center itb:justify-between">
					<CardTitle>{ __( 'Modo seguro' ) }</CardTitle>
					<Badge tone={ safety.safe_mode.active ? 'red' : 'green' }>{ safety.safe_mode.active ? __( 'Activo' ) : __( 'Inactivo' ) }</Badge>
				</div>
				<CardDescription>{ __( 'Se activa solo tras 3 errores fatales atribuibles al plugin en 5 minutos. Apaga todos los módulos.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				{ safety.safe_mode.active && details && (
					<dl className="itb:m-0 itb:mb-3">
						<KeyValue label={ __( 'Desde' ) }>{ formatDate( details.at, config.locale ) }</KeyValue>
						<KeyValue label={ __( 'Fatales' ) }>{ details.context?.count ?? '—' }</KeyValue>
						<KeyValue label={ __( 'Archivo' ) }>
							<code className="itb:text-xs">{ details.context?.file ? `${ details.context.file }:${ details.context.line ?? '' }` : '—' }</code>
						</KeyValue>
						<div className="itb:mt-2 itb:rounded-md itb:bg-zinc-50 itb:p-2 itb:font-mono itb:text-xs itb:break-words itb:text-zinc-700">{ details.context?.message ?? '—' }</div>
					</dl>
				) }
				<Button variant="outline" size="sm" disabled={ ! safety.safe_mode.active || action.isPending } onClick={ () => setOpen( true ) }>
					<RotateCcw aria-hidden />
					{ __( 'Salir del modo seguro' ) }
				</Button>
				<ErrorText error={ action.error } />
			</CardContent>
			<ConfirmDialog
				open={ open }
				onOpenChange={ setOpen }
				title={ __( '¿Salir del modo seguro?' ) }
				description={ __( 'Confirma que resolviste la causa del error. Se recompila la configuración y los módulos vuelven a actuar. Si los fatales se repiten, el modo seguro se activará de nuevo.' ) }
				confirmLabel={ __( 'Salir del modo seguro' ) }
				pending={ action.isPending }
				onConfirm={ () => action.mutate( undefined, { onSettled: () => setOpen( false ) } ) }
			/>
		</Card>
	);
}

function ConfigCard( { status }: { status: Status } ) {
	const action = useAction( () => api<{ result: CompileResult }>( 'config/recompile', 'POST' ) );
	const result = action.data?.result;

	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Configuración compilada' ) }</CardTitle>
				<CardDescription>{ __( 'Archivo PHP que lee el frontend desde OPcache. Se regenera solo al cambiar ajustes, plugins, tema o páginas de WooCommerce.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				<dl className="itb:m-0 itb:mb-3">
					<KeyValue label={ __( 'Estado' ) }>{ status.config.exists ? <Badge tone="green">{ __( 'Vigente' ) }</Badge> : <Badge tone="amber">{ __( 'Falta' ) }</Badge> }</KeyValue>
					<KeyValue label="Hash">
						<code className="itb:text-xs">{ status.config.hash || '—' }</code>
					</KeyValue>
					<KeyValue label={ __( 'Generada' ) }>{ formatDate( status.config.generated, config.locale ) }</KeyValue>
					<KeyValue label={ __( 'Archivo' ) }>
						<code className="itb:text-xs">{ status.config.file }</code>
					</KeyValue>
				</dl>
				<Button variant="outline" size="sm" disabled={ action.isPending || status.safety.safe_mode.active } onClick={ () => action.mutate( undefined ) }>
					<RotateCcw aria-hidden />
					{ action.isPending ? __( 'Recompilando…' ) : __( 'Recompilar configuración' ) }
				</Button>
				{ result?.ok && <Notice level="success" className="itb:mt-3">{ __( 'Configuración recompilada.' ) }</Notice> }
				{ result && result.warnings.length > 0 && (
					<Notice level="warning" className="itb:mt-3">
						{ result.warnings.map( ( w ) => (
							<div key={ w }>{ w }</div>
						) ) }
					</Notice>
				) }
				<ErrorText error={ action.error } />
			</CardContent>
		</Card>
	);
}

const domainPattern = /^[a-z0-9.-]{2,253}$/;

const settingsSchema = z.object( {
	debug: z.boolean(),
	stagingDomains: z.string().refine(
		( value ) => value.split( /\s+/ ).filter( Boolean ).every( ( d ) => domainPattern.test( d.toLowerCase() ) ),
		{ message: __( 'Cada línea debe ser un dominio o sufijo válido (por ejemplo, .staging.misitio.com).' ) }
	),
} );

type SettingsForm = z.infer<typeof settingsSchema>;

function SettingsCard() {
	const { data, isLoading } = useQuery( { queryKey: [ 'settings' ], queryFn: () => api<PublicSettings>( 'settings' ) } );
	const action = useAction( ( vars: { debug: boolean; staging_domains: string[] } ) => api<PublicSettings>( 'settings', 'PUT', vars ) );
	const form = useForm<SettingsForm>( { resolver: zodResolver( settingsSchema ), defaultValues: { debug: false, stagingDomains: '' } } );

	useEffect( () => {
		if ( data ) {
			form.reset( { debug: data.debug, stagingDomains: data.staging_domains.join( '\n' ) } );
		}
	}, [ data, form ] );

	const onSubmit = form.handleSubmit( ( values ) =>
		action.mutate( {
			debug: values.debug,
			staging_domains: values.stagingDomains.split( /\s+/ ).map( ( d ) => d.trim().toLowerCase() ).filter( Boolean ),
		} )
	);

	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Ajustes generales' ) }</CardTitle>
				<CardDescription>{ __( 'En dominios de staging no se aplicarán reglas de Cloudflare ni purgas (fase 2). Se suman a los 61 de la matriz.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				{ isLoading ? (
					<Skeleton className="itb:h-32" />
				) : (
					<form onSubmit={ ( e ) => void onSubmit( e ) } className="itb:flex itb:flex-col itb:gap-3" noValidate>
						<label className="itb:flex itb:flex-col itb:gap-1.5 itb:text-[13px]">
							<span className="itb:font-medium">{ __( 'Dominios de staging propios (uno por línea)' ) }</span>
							<Textarea rows={ 3 } placeholder=".staging.misitio.com" { ...form.register( 'stagingDomains' ) } aria-invalid={ !! form.formState.errors.stagingDomains } />
							{ form.formState.errors.stagingDomains && <span className="itb:text-xs itb:text-red-600">{ form.formState.errors.stagingDomains.message }</span> }
						</label>
						<label className="itb:flex itb:items-center itb:justify-between itb:gap-3 itb:text-[13px]">
							<span>
								<span className="itb:font-medium">{ __( 'Modo depuración' ) }</span>
								<span className="itb:block itb:text-xs itb:text-zinc-500">{ __( 'Reservado para diagnóstico: el perfilador (fase 1) lo usa para mostrar Server-Timing.' ) }</span>
							</span>
							<Switch checked={ form.watch( 'debug' ) } onCheckedChange={ ( value ) => form.setValue( 'debug', value, { shouldDirty: true } ) } />
						</label>
						<div>
							<Button type="submit" size="sm" disabled={ action.isPending || ! form.formState.isDirty }>
								{ action.isPending ? __( 'Guardando…' ) : __( 'Guardar' ) }
							</Button>
						</div>
						{ action.isSuccess && ! form.formState.isDirty && <Notice level="success">{ __( 'Ajustes guardados.' ) }</Notice> }
						<ErrorText error={ action.error } />
					</form>
				) }
			</CardContent>
		</Card>
	);
}

const importSchema = z.object( {
	plugin: z.literal( 'imagina-turbo' ),
	version: z.number().int().positive(),
	settings: z.record( z.string(), z.unknown() ),
} );

function ImportExportCard() {
	const [ pending, setPending ] = useState<z.infer<typeof importSchema> | null>( null );
	const [ parseError, setParseError ] = useState( '' );
	const [ exportError, setExportError ] = useState( '' );
	const action = useAction( ( data: unknown ) => api<PublicSettings>( 'settings/import', 'POST', { data } ) );

	const exportSettings = async () => {
		setExportError( '' );
		try {
			const data = await api<unknown>( 'settings/export' );
			downloadFile( `imagina-turbo-ajustes-${ new Date().toISOString().slice( 0, 10 ) }.json`, JSON.stringify( data, null, 2 ), 'application/json' );
		} catch ( e ) {
			setExportError( e instanceof Error ? e.message : String( e ) );
		}
	};

	const onFile = async ( file: File | undefined ) => {
		setParseError( '' );
		if ( ! file ) {
			return;
		}
		if ( file.size > 1_000_000 ) {
			setParseError( __( 'El archivo es demasiado grande.' ) );
			return;
		}
		try {
			const parsed = importSchema.safeParse( JSON.parse( await file.text() ) );
			if ( ! parsed.success ) {
				setParseError( __( 'El archivo no es una exportación de Imagina Turbo.' ) );
				return;
			}
			setPending( parsed.data );
		} catch {
			setParseError( __( 'El archivo no es JSON válido.' ) );
		}
	};

	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Exportar e importar ajustes' ) }</CardTitle>
				<CardDescription>{ __( 'La exportación nunca incluye el token de Cloudflare ni datos de clientes.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				<div className="itb:flex itb:flex-wrap itb:gap-2">
					<Button variant="outline" size="sm" onClick={ () => void exportSettings() }>
						<Download aria-hidden />
						{ __( 'Exportar' ) }
					</Button>
					<Button variant="outline" size="sm" asChild>
						<label className="itb:cursor-pointer">
							<Upload aria-hidden />
							{ __( 'Importar…' ) }
							<input
								type="file"
								accept="application/json,.json"
								className="itb:sr-only"
								onChange={ ( e ) => {
									void onFile( e.target.files?.[ 0 ] );
									e.target.value = '';
								} }
							/>
						</label>
					</Button>
				</div>
				{ parseError && <Notice level="error" className="itb:mt-3">{ parseError }</Notice> }
				{ exportError && <Notice level="error" className="itb:mt-3">{ exportError }</Notice> }
				{ action.isSuccess && <Notice level="success" className="itb:mt-3">{ __( 'Ajustes importados.' ) }</Notice> }
				<ErrorText error={ action.error } />
			</CardContent>
			<ConfirmDialog
				open={ pending !== null }
				onOpenChange={ ( open ) => ! open && setPending( null ) }
				title={ __( '¿Importar estos ajustes?' ) }
				description={ __( 'Se reemplazan los ajustes actuales y se recompila la configuración. Queda registrado en el registro de eventos.' ) }
				confirmLabel={ __( 'Importar' ) }
				pending={ action.isPending }
				onConfirm={ () => pending && action.mutate( pending, { onSettled: () => setPending( null ) } ) }
			/>
		</Card>
	);
}

function LicenseCard( { status }: { status: Status } ) {
	const license = status.license;
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Licencia y actualizaciones' ) }</CardTitle>
				<CardDescription>{ __( 'Gestionadas por Imagina Updater.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				<dl className="itb:m-0 itb:mb-3">
					<KeyValue label={ __( 'Licencia' ) }>
						{ ! license.protected ? <Badge>{ __( 'Copia sin protección (desarrollo)' ) }</Badge> : license.licensed ? <Badge tone="green">{ __( 'Activa' ) }</Badge> : <Badge tone="red">{ __( 'No activa' ) }</Badge> }
					</KeyValue>
					{ license.masked_key && <KeyValue label={ __( 'Clave' ) }>{ license.masked_key }</KeyValue> }
					<KeyValue label="Imagina Updater Client">{ license.client_active ? __( 'Activo' ) : __( 'No instalado' ) }</KeyValue>
				</dl>
				{ license.license_page && (
					<Button variant="outline" size="sm" asChild>
						<a href={ license.license_page }>
							<ExternalLink aria-hidden />
							{ __( 'Gestionar licencia' ) }
						</a>
					</Button>
				) }
			</CardContent>
		</Card>
	);
}

function LoaderCard( { status }: { status: Status } ) {
	const loader = status.loader;
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Loader MU y conexión con el hub' ) }</CardTitle>
				<CardDescription>{ __( 'El loader corre antes que los demás plugins. El hub llega en la fase 1; el sitio nunca depende de él.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				<dl className="itb:m-0">
					<KeyValue label={ __( 'Instalado' ) }>{ loader.installed ? ( loader.current ? __( 'Sí, vigente' ) : __( 'Sí, desactualizado' ) ) : __( 'No' ) }</KeyValue>
					<KeyValue label={ __( 'Corrió en este request' ) }>{ loader.ran_this_request ? __( 'Sí' ) : __( 'No' ) }</KeyValue>
					<KeyValue label={ __( 'mu-plugins escribible' ) }>{ loader.writable ? __( 'Sí' ) : __( 'No' ) }</KeyValue>
					<KeyValue label={ __( 'Ruta' ) }>
						<code className="itb:text-xs">{ loader.path }</code>
					</KeyValue>
					<KeyValue label={ __( 'Directorio de datos' ) }>
						<code className="itb:text-xs">{ status.safety.data_dir.path }</code>
					</KeyValue>
				</dl>
			</CardContent>
		</Card>
	);
}
