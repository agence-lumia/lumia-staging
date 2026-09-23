import { createRoot } from '@wordpress/element';
import Dashboard from './screens/Dashboard';
import History from './screens/History';
import Compare from './screens/Compare';
import Log from './screens/Log';
import Settings from './screens/Settings';
import Shell from './components/Shell';
import './style.scss';

const SCREENS = {
	dashboard: Dashboard,
	history: History,
	compare: Compare,
	log: Log,
	settings: Settings,
};

const mount = document.getElementById( 'lmv-admin' );
if ( mount ) {
	const screen = SCREENS[ mount.dataset.screen ]
		? mount.dataset.screen
		: 'dashboard';
	const Screen = SCREENS[ screen ];
	createRoot( mount ).render(
		// Le comparatif est plein écran : pas de barre latérale.
		screen === 'compare' ? (
			<Screen />
		) : (
			<Shell screen={ screen }>
				<Screen />
			</Shell>
		)
	);
}
