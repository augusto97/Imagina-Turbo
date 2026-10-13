import { Layout } from './components/Layout';
import { useRouter } from './lib/router';
import { Advanced } from './screens/Advanced';
import { Log } from './screens/Log';
import { Panel } from './screens/Panel';

export function App() {
	const route = useRouter( ( state ) => state.route );
	return (
		<Layout>
			{ route === 'panel' && <Panel /> }
			{ route === 'registro' && <Log /> }
			{ route === 'avanzado' && <Advanced /> }
		</Layout>
	);
}
