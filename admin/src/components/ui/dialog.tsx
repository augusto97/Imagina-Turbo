import * as DialogPrimitive from '@radix-ui/react-dialog';
import type { ReactNode } from 'react';
import { __ } from '@/lib/i18n';
import { Button } from './button';

interface ConfirmDialogProps {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	title: string;
	description: ReactNode;
	confirmLabel: string;
	destructive?: boolean;
	pending?: boolean;
	onConfirm: () => void;
}

/**
 * Confirmación obligatoria para acciones sensibles (docs/04: todo botón destructivo pide confirmación).
 */
export function ConfirmDialog( { open, onOpenChange, title, description, confirmLabel, destructive = false, pending = false, onConfirm }: ConfirmDialogProps ) {
	return (
		<DialogPrimitive.Root open={ open } onOpenChange={ onOpenChange }>
			<DialogPrimitive.Portal>
				<DialogPrimitive.Overlay className="itb-root itb:fixed itb:inset-0 itb:z-[100000] itb:bg-zinc-950/40" />
				<DialogPrimitive.Content className="itb-root itb:fixed itb:top-1/2 itb:left-1/2 itb:z-[100001] itb:w-[min(92vw,440px)] itb:-translate-x-1/2 itb:-translate-y-1/2 itb:rounded-lg itb:border itb:border-zinc-200 itb:bg-white itb:p-6 itb:shadow-lg">
					<DialogPrimitive.Title className="itb:text-base itb:font-semibold">{ title }</DialogPrimitive.Title>
					<DialogPrimitive.Description asChild>
						<div className="itb:mt-2 itb:text-sm itb:text-zinc-600">{ description }</div>
					</DialogPrimitive.Description>
					<div className="itb:mt-6 itb:flex itb:justify-end itb:gap-2">
						<Button variant="outline" onClick={ () => onOpenChange( false ) } disabled={ pending }>
							{ __( 'Cancelar' ) }
						</Button>
						<Button variant={ destructive ? 'destructive' : 'default' } onClick={ onConfirm } disabled={ pending }>
							{ pending ? __( 'Aplicando…' ) : confirmLabel }
						</Button>
					</div>
				</DialogPrimitive.Content>
			</DialogPrimitive.Portal>
		</DialogPrimitive.Root>
	);
}
