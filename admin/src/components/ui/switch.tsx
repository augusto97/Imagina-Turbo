import * as SwitchPrimitive from '@radix-ui/react-switch';
import { forwardRef, type ComponentPropsWithoutRef, type ElementRef } from 'react';
import { cn } from '@/lib/utils';

export const Switch = forwardRef<ElementRef<typeof SwitchPrimitive.Root>, ComponentPropsWithoutRef<typeof SwitchPrimitive.Root>>( ( { className, ...props }, ref ) => (
	<SwitchPrimitive.Root
		ref={ ref }
		className={ cn(
			'itb:peer itb:inline-flex itb:h-5 itb:w-9 itb:shrink-0 itb:cursor-pointer itb:items-center itb:rounded-full itb:border-2 itb:border-transparent itb:transition-colors itb:disabled:cursor-not-allowed itb:disabled:opacity-50 itb:data-[state=checked]:bg-zinc-900 itb:data-[state=unchecked]:bg-zinc-200',
			className
		) }
		{ ...props }
	>
		<SwitchPrimitive.Thumb className="itb:pointer-events-none itb:block itb:size-4 itb:rounded-full itb:bg-white itb:shadow itb:transition-transform itb:data-[state=checked]:translate-x-4 itb:data-[state=unchecked]:translate-x-0" />
	</SwitchPrimitive.Root>
) );
Switch.displayName = 'Switch';
