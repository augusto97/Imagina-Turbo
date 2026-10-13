import { Activity, Blocks, Cloud, FastForward, Gauge, Layers, ScrollText, Settings2, Shield, ShoppingCart, SlidersHorizontal, Zap } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from './ui/badge';
import { config } from '@/lib/config';
import { __ } from '@/lib/i18n';
import { type Route, useRouter } from '@/lib/router';
import { useStatus } from '@/lib/queries';
import { cn } from '@/lib/utils';

const items: { route: Route; label: string; icon: typeof Gauge }[] = [
	{ route: 'panel', label: __( 'Panel' ), icon: Gauge },
	{ route: 'diagnostico', label: __( 'Diagnóstico' ), icon: Activity },
	{ route: 'cache', label: __( 'Caché' ), icon: Layers },
	{ route: 'cloudflare', label: __( 'Cloudflare' ), icon: Cloud },
	{ route: 'woocommerce', label: __( 'WooCommerce' ), icon: ShoppingCart },
	{ route: 'navegacion', label: __( 'Navegación' ), icon: FastForward },
	{ route: 'carga', label: __( 'Carga condicional' ), icon: Blocks },
	{ route: 'firewall', label: __( 'Firewall' ), icon: Shield },
	{ route: 'ajustes', label: __( 'Ajustes rápidos' ), icon: SlidersHorizontal },
	{ route: 'registro', label: __( 'Registro' ), icon: ScrollText },
	{ route: 'avanzado', label: __( 'Avanzado' ), icon: Settings2 },
];

export function Layout( { children }: { children: ReactNode } ) {
	const { route, go } = useRouter();
	const { data } = useStatus();
	const safeMode = data?.safety.safe_mode.active ?? config.safeMode;
	const killed = data?.safety.kill_switch.active ?? config.killed;

	return (
		<div className="itb:flex itb:min-h-[calc(100vh-32px)] itb:flex-col itb:bg-zinc-50 itb:md:flex-row">
			<aside className="itb:border-b itb:border-zinc-200 itb:bg-white itb:md:w-56 itb:md:shrink-0 itb:md:border-r itb:md:border-b-0">
				<div className="itb:flex itb:items-center itb:gap-2 itb:px-4 itb:py-4">
					<span className="itb:flex itb:size-7 itb:items-center itb:justify-center itb:rounded-md itb:bg-zinc-900 itb:text-white">
						<Zap className="itb:size-4" aria-hidden />
					</span>
					<div className="itb:leading-tight">
						<div className="itb:text-sm itb:font-semibold">Imagina Turbo</div>
						<div className="itb:text-[11px] itb:text-zinc-500">v{ config.version }</div>
					</div>
				</div>
				<nav aria-label={ __( 'Secciones' ) } className="itb:flex itb:gap-1 itb:overflow-x-auto itb:px-2 itb:pb-3 itb:md:flex-col">
					{ items.map( ( { route: target, label, icon: Icon } ) => (
						<a
							key={ target }
							href={ `#/${ target }` }
							onClick={ ( event ) => {
								event.preventDefault();
								go( target );
							} }
							aria-current={ route === target ? 'page' : undefined }
							className={ cn(
								'itb:flex itb:items-center itb:gap-2 itb:rounded-md itb:px-3 itb:py-2 itb:text-[13px] itb:font-medium itb:text-zinc-600 itb:transition-colors itb:hover:bg-zinc-100 itb:hover:text-zinc-900',
								route === target && 'itb:bg-zinc-100 itb:text-zinc-900'
							) }
						>
							<Icon className="itb:size-4" aria-hidden />
							{ label }
						</a>
					) ) }
				</nav>
				<p className="itb:hidden itb:px-4 itb:pb-4 itb:text-[11px] itb:leading-snug itb:text-zinc-400 itb:md:block">
					{ __( 'Fase 6 · Navegación instantánea y RUM. Los módulos llegan por fases.' ) }
				</p>
			</aside>
			<main className="itb:min-w-0 itb:flex-1 itb:p-5 itb:md:p-8">
				{ ( safeMode || killed ) && (
					<div className="itb:mb-5 itb:flex itb:flex-wrap itb:gap-2">
						{ safeMode && <Badge tone="red">{ __( 'Modo seguro activo' ) }</Badge> }
						{ killed && <Badge tone="amber">{ __( 'Kill switch activo' ) }</Badge> }
					</div>
				) }
				<div className="itb:mx-auto itb:max-w-6xl">{ children }</div>
			</main>
		</div>
	);
}
