import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react';
import type { ReactNode } from 'react';
import type { Level } from '@/lib/types';
import { cn } from '@/lib/utils';

const tones: Record<Level | 'success', string> = {
	error: 'itb:border-red-200 itb:bg-red-50 itb:text-red-800',
	warning: 'itb:border-amber-200 itb:bg-amber-50 itb:text-amber-900',
	info: 'itb:border-zinc-200 itb:bg-zinc-50 itb:text-zinc-700',
	success: 'itb:border-emerald-200 itb:bg-emerald-50 itb:text-emerald-800',
};

const icons = { error: XCircle, warning: AlertTriangle, info: Info, success: CheckCircle2 };

export function Notice( { level, children, className }: { level: Level | 'success'; children: ReactNode; className?: string } ) {
	const Icon = icons[ level ];
	return (
		<div role={ level === 'error' ? 'alert' : 'status' } className={ cn( 'itb:flex itb:items-start itb:gap-2.5 itb:rounded-md itb:border itb:px-3 itb:py-2.5 itb:text-[13px]', tones[ level ], className ) }>
			<Icon className="itb:mt-0.5 itb:size-4 itb:shrink-0" aria-hidden />
			<div className="itb:min-w-0 itb:flex-1">{ children }</div>
		</div>
	);
}

export function Skeleton( { className }: { className?: string } ) {
	return <div className={ cn( 'itb:animate-pulse itb:rounded-md itb:bg-zinc-100', className ) } />;
}
