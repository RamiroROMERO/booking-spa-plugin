import { __, sprintf } from '@wordpress/i18n';

import { getApiErrorMessage } from '../utils/apiError';

/**
 * Mapeo de errores especifico del panel de cliente (SPEC 07 paso 7):
 * - 409 por ventana de cancelacion insuficiente necesita el numero de horas
 *   configurado (min_cancellation_hours, SPEC 05), que no viaja en el error
 *   de la API -- se pasa por separado, localizado desde PHP.
 * - 403/404 en un fetch autenticado por token de invitado significa, en la
 *   practica, que el enlace ya no sirve (token invalido/vencido o de otra
 *   cita); no tiene sentido mostrarle al invitado los mensajes genericos de
 *   "no encontrado" o "sin permiso" que usa el resto del plugin.
 */
export function getClientPanelErrorMessage( error, { minCancellationHours, isGuest } = {} ) {
	const code = error && error.code;

	if ( 'booking_rest_cancellation_window_closed' === code ) {
		return sprintf(
			// translators: %d: horas minimas de anticipacion requeridas para cancelar/reprogramar.
			__( 'No se puede modificar, faltan menos de %d horas para la cita.', 'booking-plugin' ),
			minCancellationHours
		);
	}

	if ( isGuest && ( 'booking_rest_forbidden' === code || 'booking_rest_not_found' === code ) ) {
		return __( 'Este enlace ya no es válido.', 'booking-plugin' );
	}

	return getApiErrorMessage( error );
}
