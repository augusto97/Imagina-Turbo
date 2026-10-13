import { forwardRef, type InputHTMLAttributes, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

const field = 'itb:w-full itb:rounded-md itb:border itb:border-zinc-200 itb:bg-white itb:px-3 itb:text-sm itb:shadow-xs itb:placeholder:text-zinc-400 itb:focus:border-zinc-400 itb:focus:outline-none itb:disabled:opacity-50';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>( ( { className, ...props }, ref ) => (
	<input ref={ ref } className={ cn( field, 'itb:h-9', className ) } { ...props } />
) );
Input.displayName = 'Input';

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>( ( { className, ...props }, ref ) => (
	<select ref={ ref } className={ cn( field, 'itb:h-9 itb:pr-8', className ) } { ...props } />
) );
Select.displayName = 'Select';

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>( ( { className, ...props }, ref ) => (
	<textarea ref={ ref } className={ cn( field, 'itb:min-h-24 itb:py-2 itb:font-mono itb:text-xs', className ) } { ...props } />
) );
Textarea.displayName = 'Textarea';
