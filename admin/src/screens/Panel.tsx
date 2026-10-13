import { RefreshCw } from 'lucide-react';
import { KeyValue, PageHeader, RiskBadge, StatusDot } from '@/components/common';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Notice, Skeleton } from '@/components/ui/feedback';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import { useCompat, useStatus } from '@/lib/queries';
import type { Status } from '@/lib/types';
import { formatDate } from '@/lib/utils';

const cacheNames: Record<string, string> = {
	'litespeed-cache': 'LiteSpeed Cache',
	'wp-rocket': 'WP Rocket',
};

export function Panel() {
	const { data, isLoading, error, refetch, isFetching } = useStatus();

	return (
		<>
			<PageHeader
				title={ __( 'Panel' ) }
				description={ __( 'Estado general del plugin y del entorno detectado.' ) }
				actions={
					<Button variant="outline" size="sm" onClick={ () => void refetch() } disabled={ isFetching }>
						<RefreshCw className={ isFetching ? 'itb:animate-spin' : '' } aria-hidden />
						{ __( 'Actualizar' ) }
					</Button>
				}
			/>
			{ error && <Notice level="error">{ error.message }</Notice> }
			{ isLoading && <PanelSkeleton /> }
			{ data && <PanelContent status={ data } /> }
		</>
	);
}

function PanelSkeleton() {
	return (
		<div className="itb:grid itb:gap-4 itb:md:grid-cols-2 itb:xl:grid-cols-4">
			{ [ 1, 2, 3, 4 ].map( ( i ) => (
				<Skeleton key={ i } className="itb:h-28" />
			) ) }
		</div>
	);
}

function PanelContent( { status }: { status: Status } ) {
	const env = status.environment;
	const safe = status.safety;
	const conflicts = status.overlap.conflicts.filter( ( c ) => c.modules.length > 0 );

	return (
		<div className="itb:flex itb:flex-col itb:gap-6">
			<div className="itb:grid itb:gap-4 itb:md:grid-cols-2 itb:xl:grid-cols-4">
				<SummaryCard
					title={ __( 'Plugin de caché' ) }
					value={ env.cache_plugin ? cacheNames[ env.cache_plugin ] ?? env.cache_plugin : __( 'Ninguno' ) }
					ok={ env.cache_plugin !== null }
					warn
					hint={ env.cache_plugin ? __( 'Se coordina con él: no se duplica la caché de página.' ) : __( 'Solo el edge de Cloudflare.' ) }
				/>
				<SummaryCard
					title={ __( 'Cloudflare' ) }
					value={ env.cloudflare.proxied_request ? __( 'Detectado' ) : __( 'No detectado' ) }
					ok={ env.cloudflare.proxied_request }
					warn
					hint={ __( 'La conexión por API llega en la fase 2.' ) }
				/>
				<SummaryCard
					title={ __( 'Seguridad operativa' ) }
					value={ safe.safe_mode.active ? __( 'Modo seguro' ) : safe.kill_switch.active ? __( 'Kill switch' ) : __( 'Normal' ) }
					ok={ ! safe.safe_mode.active && ! safe.kill_switch.active }
					warn={ ! safe.safe_mode.active }
					hint={ safe.safe_mode.active ? __( 'Todos los módulos están apagados.' ) : __( 'Sin incidentes.' ) }
				/>
				<SummaryCard
					title={ __( 'Etapa temprana' ) }
					value={ status.loader.installed && status.config.exists ? __( 'Activa' ) : __( 'Incompleta' ) }
					ok={ status.loader.installed && status.config.exists }
					warn
					hint={ status.config.exists ? `${ __( 'Config' ) } ${ status.config.hash }` : __( 'Falta la configuración compilada.' ) }
				/>
			</div>

			<Card>
				<CardHeader>
					<CardTitle>{ __( 'Alertas' ) }</CardTitle>
					<CardDescription>{ __( 'Lo que requiere atención, de más a menos grave.' ) }</CardDescription>
				</CardHeader>
				<CardContent className="itb:flex itb:flex-col itb:gap-2">
					{ status.alerts.length === 0 ? (
						<Notice level="success">{ __( 'Todo en orden.' ) }</Notice>
					) : (
						status.alerts.map( ( alert ) => (
							<Notice key={ alert.code + alert.message } level={ alert.level }>
								{ alert.message }
							</Notice>
						) )
					) }
				</CardContent>
			</Card>

			<Card>
				<CardHeader>
					<CardTitle>{ __( 'Métricas' ) }</CardTitle>
					<CardDescription>{ __( 'TTFB, LCP, aciertos del edge, memoria por request y bloqueos del firewall.' ) }</CardDescription>
				</CardHeader>
				<CardContent>
					<Notice level="info">{ __( 'Aún no hay datos: el perfilador (fase 1), Cloudflare (fase 2) y el RUM (fase 6) los irán llenando. Cada métrica indicará su fuente y su periodo.' ) }</Notice>
				</CardContent>
			</Card>

			<div className="itb:grid itb:gap-4 itb:lg:grid-cols-2">
				<Card>
					<CardHeader>
						<CardTitle>{ __( 'Entorno' ) }</CardTitle>
					</CardHeader>
					<CardContent>
						<dl className="itb:m-0">
							<KeyValue label={ __( 'Servidor' ) }>
								{ env.server.family === 'unknown' ? env.server.software || '—' : env.server.family }
							</KeyValue>
							<KeyValue label="PHP">{ env.php }</KeyValue>
							<KeyValue label="WordPress">{ env.wordpress }</KeyValue>
							<KeyValue label="WooCommerce">{ env.woocommerce.active ? env.woocommerce.version ?? __( 'Activo' ) : __( 'No' ) }</KeyValue>
							<KeyValue label={ __( 'Idiomas' ) }>{ env.multilingual ?? __( 'Un idioma' ) }</KeyValue>
							<KeyValue label={ __( 'Caché de objetos' ) }>{ env.object_cache ? __( 'Sí' ) : __( 'No' ) }</KeyValue>
							<KeyValue label="OPcache">{ env.opcache ? __( 'Sí' ) : __( 'No' ) }</KeyValue>
							<KeyValue label="WP-Cron">{ env.wp_cron.disabled ? __( 'Cron del sistema' ) : __( 'Por visitas' ) }</KeyValue>
							<KeyValue label={ __( 'Tema' ) }>{ env.theme.stylesheet }</KeyValue>
						</dl>
					</CardContent>
				</Card>

				<CompatCard status={ status } conflicts={ conflicts.length } />
			</div>

			<Card>
				<CardHeader>
					<CardTitle>{ __( 'Módulos' ) }</CardTitle>
					<CardDescription>{ __( 'Cada módulo muestra su nivel de riesgo. Solo los seguros vienen activos por defecto.' ) }</CardDescription>
				</CardHeader>
				<CardContent>
					<ul className="itb:grid itb:gap-3 itb:md:grid-cols-2 itb:xl:grid-cols-3">
						{ status.modules.map( ( module ) => (
							<li key={ module.id } className="itb:rounded-md itb:border itb:border-zinc-200 itb:p-3">
								<div className="itb:flex itb:items-start itb:justify-between itb:gap-2">
									<span className="itb:text-[13px] itb:font-medium">{ module.label }</span>
									<RiskBadge risk={ module.risk } label={ module.riskLabel } />
								</div>
								<p className="itb:mt-1 itb:text-xs itb:text-zinc-500">{ module.description }</p>
								<div className="itb:mt-2 itb:flex itb:flex-wrap itb:gap-1.5">
									{ module.enabled && <Badge tone="green">{ __( 'Activo' ) }</Badge> }
									{ ! module.available && <Badge>{ `${ __( 'Fase' ) } ${ module.phase }` }</Badge> }
									{ module.forcedOff.length > 0 && <Badge tone="amber" title={ module.forcedOff.join( ' · ' ) }>{ __( 'Apagado por solapamiento' ) }</Badge> }
								</div>
							</li>
						) ) }
					</ul>
				</CardContent>
			</Card>
		</div>
	);
}

function SummaryCard( { title, value, ok, warn = false, hint }: { title: string; value: string; ok: boolean; warn?: boolean; hint: string } ) {
	return (
		<Card>
			<CardContent className="itb:pt-5">
				<div className="itb:flex itb:items-center itb:gap-2 itb:text-xs itb:font-medium itb:text-zinc-500">
					<StatusDot ok={ ok } warn={ warn } />
					{ title }
				</div>
				<div className="itb:mt-2 itb:text-lg itb:font-semibold itb:tracking-tight">{ value }</div>
				<div className="itb:mt-1 itb:truncate itb:text-xs itb:text-zinc-500" title={ hint }>
					{ hint }
				</div>
			</CardContent>
		</Card>
	);
}

function CompatCard( { status, conflicts }: { status: Status; conflicts: number } ) {
	const { data } = useCompat();
	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Compatibilidad' ) }</CardTitle>
				<CardDescription>{ __( 'Reglas de la matriz que aplican a los plugins y tema activos.' ) }</CardDescription>
			</CardHeader>
			<CardContent>
				<dl className="itb:m-0">
					<KeyValue label={ __( 'Versión de la matriz' ) }>{ status.matrix.version || '—' }</KeyValue>
					<KeyValue label={ __( 'Integraciones aplicadas' ) }>{ data ? data.integrations.length : '…' }</KeyValue>
					<KeyValue label={ __( 'Coordinados' ) }>{ status.overlap.coordinated.join( ', ' ) || '—' }</KeyValue>
					<KeyValue label={ __( 'Solapamientos' ) }>{ conflicts }</KeyValue>
					<KeyValue label={ __( 'Config compilada' ) }>{ formatDate( status.config.generated, config.locale ) }</KeyValue>
				</dl>
				{ data && data.integrations.length > 0 && (
					<div className="itb:mt-3 itb:flex itb:flex-wrap itb:gap-1.5">
						{ data.integrations.slice( 0, 30 ).map( ( item ) => (
							<Badge key={ item.id } title={ item.notes || item.id }>
								{ item.title }
							</Badge>
						) ) }
					</div>
				) }
			</CardContent>
		</Card>
	);
}
