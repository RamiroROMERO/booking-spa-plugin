import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import App from './App';
import ClientPanelApp from './client-panel/ClientPanelApp';
import './style.scss';

const settings = window.BookingPluginFrontend || {};

// Igual que en el admin (SPEC 04): no se registra un createRootURLMiddleware
// propio porque WP core ya instala uno por defecto en cuanto se encola
// "wp-api-fetch" (tambien en el frontend), y correrlo junto al nuestro pisa
// nuestro namespace. Las rutas ya incluyen el namespace completo.
if ( settings.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( settings.nonce ) );
}

// El shortcode puede aparecer mas de una vez en la misma pagina: se monta
// una instancia independiente del wizard por cada contenedor.
//
// Se usa createRoot (no el `render` legado, deprecado desde WP 6.2) porque
// el root legado corre en "modo React 17": las actualizaciones de estado
// disparadas fuera de un handler de evento de React (por ejemplo, dentro de
// un .then()/.catch() de apiFetch, como en onCollision) NO se agrupan en un
// solo render. Eso permitia un render intermedio inconsistente -- p. ej.
// selection.slot ya en null pero el paso todavia en 'confirmation' -- que
// tumbaba el arbol entero al leer slot.start_datetime. createRoot habilita
// el batching automatico de React 18 tambien en esos contextos asincronos.
document.querySelectorAll( '.booking-plugin-widget-root' ).forEach( ( root ) => {
	createRoot( root ).render( <App /> );
} );

// El shortcode [booking_client_panel] (SPEC 07) reutiliza este mismo bundle:
// PHP ya resolvio el modo 'guest'/'member' (el modo 'login' no encola este
// script, ver class-booking-plugin-client-panel-shortcode.php) y lo paso via
// BookingPluginFrontend.mode / .guestContext, que ClientPanelApp lee por su
// cuenta -- igual que App lee BookingPluginFrontend arriba, sin pasarselo por
// props.
document.querySelectorAll( '.booking-plugin-client-panel-root' ).forEach( ( root ) => {
	createRoot( root ).render( <ClientPanelApp /> );
} );
