import { ErrorBoundary } from './components/ErrorBoundary';
import { Layout } from './components/Layout';
import { useRouter } from './lib/router';
import { Advanced } from './screens/Advanced';
import { Cache } from './screens/Cache';
import { Cloudflare } from './screens/Cloudflare';
import { Woo } from './screens/Woo';
import { Firewall } from './screens/Firewall';
import { PluginFilter } from './screens/PluginFilter';
import { Navigation } from './screens/Navigation';
import { Tweaks } from './screens/Tweaks';
import { Assets } from './screens/Assets';
import { Database } from './screens/Database';
import { Fleet } from './screens/Fleet';
import { Diagnostics } from './screens/Diagnostics';
import { Log } from './screens/Log';
import { Panel } from './screens/Panel';

export function App() {
	const route = useRouter( ( state ) => state.route );
	return (
		<Layout>
			<ErrorBoundary key={ route }>
				{ route === 'panel' && <Panel /> }
				{ route === 'diagnostico' && <Diagnostics /> }
				{ route === 'cache' && <Cache /> }
				{ route === 'cloudflare' && <Cloudflare /> }
				{ route === 'woocommerce' && <Woo /> }
				{ route === 'navegacion' && <Navigation /> }
				{ route === 'carga' && <PluginFilter /> }
				{ route === 'firewall' && <Firewall /> }
				{ route === 'ajustes' && <Tweaks /> }
				{ route === 'assets' && <Assets /> }
				{ route === 'base-de-datos' && <Database /> }
				{ route === 'flota' && <Fleet /> }
				{ route === 'registro' && <Log /> }
				{ route === 'avanzado' && <Advanced /> }
			</ErrorBoundary>
		</Layout>
	);
}
