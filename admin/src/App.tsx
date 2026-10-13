import { Layout } from './components/Layout';
import { useRouter } from './lib/router';
import { Advanced } from './screens/Advanced';
import { Diagnostics } from './screens/Diagnostics';
import { Log } from './screens/Log';
import { Panel } from './screens/Panel';

export function App() {
	const route = useRouter( ( state ) => state.route );
	return (
		<Layout>
			{ route === 'panel' && <Panel /> }
			{ route === 'diagnostico' && <Diagnostics /> }
			{ route === 'registro' && <Log /> }
			{ route === 'avanzado' && <Advanced /> }
		</Layout>
	);
}
