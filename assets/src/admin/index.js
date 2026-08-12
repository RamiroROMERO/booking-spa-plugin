import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import CalendarPage from './pages/CalendarPage';
import ServicesPage from './pages/ServicesPage';
import StaffPage from './pages/StaffPage';
import SettingsPage from './pages/SettingsPage';
import NotificationsPage from './pages/NotificationsPage';
import PackagesPage from './pages/PackagesPage';
import PayrollPage from './pages/PayrollPage';
import PendingBalancesPage from './pages/PendingBalancesPage';
import './style.scss';

const settings = window.BookingPluginAdmin || {};

// No se registra un createRootURLMiddleware propio: WP core ya instala uno
// por defecto (apuntando a wpApiSettings.root) en cuanto se encola
// "wp-api-fetch", y ese middleware reescribe `url` sin condiciones cada vez
// que hay un `path`, sin importar el orden de registro -- correrlo junto al
// nuestro pisa nuestro namespace. En vez de eso, las rutas ya incluyen el
// namespace completo (`/booking-plugin/v1/...`) y se resuelven correctamente
// contra el root por defecto de WP.
apiFetch.use( apiFetch.createNonceMiddleware( settings.nonce ) );

const PAGES_BY_SECTION = {
	calendar: CalendarPage,
	services: ServicesPage,
	staff: StaffPage,
	settings: SettingsPage,
	notifications: NotificationsPage,
	packages: PackagesPage,
	payroll: PayrollPage,
	balances: PendingBalancesPage,
};

const root = document.getElementById( 'booking-plugin-admin-root' );

if ( root ) {
	const Page = PAGES_BY_SECTION[ settings.section ] || CalendarPage;

	render( <Page />, root );
}
