import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export function Card( { className, ...props }: HTMLAttributes<HTMLDivElement> ) {
	return <div className={ cn( 'itb:rounded-lg itb:border itb:border-zinc-200 itb:bg-white itb:shadow-xs', className ) } { ...props } />;
}

export function CardHeader( { className, ...props }: HTMLAttributes<HTMLDivElement> ) {
	return <div className={ cn( 'itb:flex itb:flex-col itb:gap-1 itb:p-5 itb:pb-3', className ) } { ...props } />;
}

export function CardTitle( { className, ...props }: HTMLAttributes<HTMLHeadingElement> ) {
	return <h3 className={ cn( 'itb:text-sm itb:font-semibold itb:tracking-tight itb:text-zinc-900', className ) } { ...props } />;
}

export function CardDescription( { className, ...props }: HTMLAttributes<HTMLParagraphElement> ) {
	return <p className={ cn( 'itb:text-[13px] itb:text-zinc-500', className ) } { ...props } />;
}

export function CardContent( { className, ...props }: HTMLAttributes<HTMLDivElement> ) {
	return <div className={ cn( 'itb:p-5 itb:pt-0', className ) } { ...props } />;
}
