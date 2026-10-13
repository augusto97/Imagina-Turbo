import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
	'itb:inline-flex itb:items-center itb:justify-center itb:gap-2 itb:whitespace-nowrap itb:rounded-md itb:text-sm itb:font-medium itb:transition-colors itb:disabled:pointer-events-none itb:disabled:opacity-50 itb:[&_svg]:size-4 itb:[&_svg]:shrink-0',
	{
		variants: {
			variant: {
				default: 'itb:bg-zinc-900 itb:text-white itb:shadow-sm itb:hover:bg-zinc-800',
				destructive: 'itb:bg-red-600 itb:text-white itb:shadow-sm itb:hover:bg-red-700',
				outline: 'itb:border itb:border-zinc-200 itb:bg-white itb:text-zinc-900 itb:shadow-sm itb:hover:bg-zinc-50',
				ghost: 'itb:text-zinc-700 itb:hover:bg-zinc-100 itb:hover:text-zinc-900',
			},
			size: {
				default: 'itb:h-9 itb:px-4',
				sm: 'itb:h-8 itb:px-3 itb:text-xs',
				icon: 'itb:size-8',
			},
		},
		defaultVariants: { variant: 'default', size: 'default' },
	}
);

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof buttonVariants> {
	asChild?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>( ( { className, variant, size, asChild = false, type, ...props }, ref ) => {
	const Comp = asChild ? Slot : 'button';
	return <Comp ref={ ref } type={ asChild ? undefined : type ?? 'button' } className={ cn( buttonVariants( { variant, size } ), className ) } { ...props } />;
} );
Button.displayName = 'Button';
