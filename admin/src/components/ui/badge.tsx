import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

const badgeVariants = cva( 'itb:inline-flex itb:items-center itb:gap-1 itb:rounded-full itb:border itb:px-2 itb:py-0.5 itb:text-[11px] itb:font-medium itb:leading-4', {
	variants: {
		tone: {
			neutral: 'itb:border-zinc-200 itb:bg-zinc-50 itb:text-zinc-700',
			green: 'itb:border-emerald-200 itb:bg-emerald-50 itb:text-emerald-700',
			amber: 'itb:border-amber-200 itb:bg-amber-50 itb:text-amber-800',
			red: 'itb:border-red-200 itb:bg-red-50 itb:text-red-700',
			blue: 'itb:border-sky-200 itb:bg-sky-50 itb:text-sky-700',
		},
	},
	defaultVariants: { tone: 'neutral' },
} );

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement>, VariantProps<typeof badgeVariants> {}

export function Badge( { className, tone, ...props }: BadgeProps ) {
	return <span className={ cn( badgeVariants( { tone } ), className ) } { ...props } />;
}
