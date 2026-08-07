import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import App from './App';
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

const root = document.getElementById( 'booking-plugin-admin-root' );

if ( root ) {
	render( <App />, root );
}
