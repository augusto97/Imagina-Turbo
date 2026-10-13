import type { ReactNode } from 'react';
import { Badge } from './ui/badge';
import { __ } from '@/lib/i18n';
import type { Risk } from '@/lib/types';
import { cn } from '@/lib/utils';

export function RiskBadge( { risk, label }: { risk: Risk; label: string } ) {
	const tone = risk === 'safe' ? 'green' : risk === 'moderate' ? 'amber' : 'red';
	return (
		<Badge tone={ tone } title={ __( 'Nivel de riesgo' ) }>
			{ label }
		</Badge>
	);
}

export function StatusDot( { ok, warn = false }: { ok: boolean; warn?: boolean } ) {
	return <span aria-hidden className={ cn( 'itb:inline-block itb:size-2 itb:rounded-full', ok ? 'itb:bg-emerald-500' : warn ? 'itb:bg-amber-500' : 'itb:bg-red-500' ) } />;
}

export function KeyValue( { label, children }: { label: string; children: ReactNode } ) {
	return (
		<div className="itb:flex itb:items-baseline itb:justify-between itb:gap-4 itb:border-b itb:border-zinc-100 itb:py-2 itb:last:border-b-0">
			<dt className="itb:text-[13px] itb:text-zinc-500">{ label }</dt>
			<dd className="itb:m-0 itb:text-right itb:text-[13px] itb:font-medium itb:text-zinc-900">{ children }</dd>
		</div>
	);
}

export function PageHeader( { title, description, actions }: { title: string; description?: string; actions?: ReactNode } ) {
	return (
		<div className="itb:mb-6 itb:flex itb:flex-wrap itb:items-end itb:justify-between itb:gap-4">
			<div>
				<h1 className="itb:text-xl itb:font-semibold itb:tracking-tight itb:text-zinc-900">{ title }</h1>
				{ description && <p className="itb:mt-1 itb:text-sm itb:text-zinc-500">{ description }</p> }
			</div>
			{ actions && <div className="itb:flex itb:flex-wrap itb:gap-2">{ actions }</div> }
		</div>
	);
}
