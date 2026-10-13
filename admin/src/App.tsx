import { Layout } from './components/Layout';
import { useRouter } from './lib/router';
import { Advanced } from './screens/Advanced';
import { Cache } from './screens/Cache';
import { Cloudflare } from './screens/Cloudflare';
import { Woo } from './screens/Woo';
import { Firewall } from './screens/Firewall';
import { Tweaks } from './screens/Tweaks';
import { Diagnostics } from './screens/Diagnostics';
import { Log } from './screens/Log';
import { Panel } from './screens/Panel';

export function App() {
	const route = useRouter( ( state ) => state.route );
	return (
		<Layout>
			{ route === 'panel' && <Panel /> }
			{ route === 'diagnostico' && <Diagnostics /> }
			{ route === 'cache' && <Cache /> }
			{ route === 'cloudflare' && <Cloudflare /> }
			{ route === 'woocommerce' && <Woo /> }
			{ route === 'firewall' && <Firewall /> }
			{ route === 'ajustes' && <Tweaks /> }
			{ route === 'registro' && <Log /> }
			{ route === 'avanzado' && <Advanced /> }
		</Layout>
	);
}
