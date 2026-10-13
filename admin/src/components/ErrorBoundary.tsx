import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Button } from './ui/button';
import { Notice } from './ui/feedback';
import { __ } from '@/lib/i18n';

interface Props {
	children: ReactNode;
}

interface State {
	error: Error | null;
}

/**
 * Un error en una pantalla no deja todo el panel en blanco: se muestra el error y el resto del
 * menú sigue funcionando. La pantalla se monta con key = ruta, así que cambiar de pantalla lo reinicia.
 */
export class ErrorBoundary extends Component<Props, State> {
	state: State = { error: null };

	static getDerivedStateFromError( error: Error ): State {
		return { error };
	}

	componentDidCatch( error: Error, info: ErrorInfo ): void {
		// eslint-disable-next-line no-console
		console.error( '[Imagina Turbo]', error, info.componentStack );
	}

	render() {
		if ( this.state.error ) {
			return (
				<Notice level="error">
					<div className="itb:flex itb:flex-col itb:gap-2">
						<span className="itb:font-medium">{ __( 'Esta pantalla tuvo un error y no se pudo mostrar. El resto del plugin sigue funcionando.' ) }</span>
						<code className="itb:break-all itb:text-xs">{ this.state.error.message }</code>
						<span>{ __( 'Si se repite, copia este mensaje y envíalo a soporte.' ) }</span>
						<div>
							<Button size="sm" variant="outline" onClick={ () => window.location.reload() }>
								{ __( 'Recargar' ) }
							</Button>
						</div>
					</div>
				</Notice>
			);
		}
		return this.props.children;
	}
}
