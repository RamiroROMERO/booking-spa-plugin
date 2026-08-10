import { __ } from '@wordpress/i18n';

// Mismo mapeo que assets/src/admin/utils/apiError.js: son bundles de webpack
// separados (admin.js / frontend.js), asi que se duplica en vez de que el
// bundle publico dependa de un archivo pensado para el admin.
const MESSAGES_BY_CODE = {
	booking_rest_invalid_service: __(
		'Este servicio ya no está disponible.',
		'booking-plugin'
	),
	booking_rest_invalid_staff: __( 'Ese profesional ya no está disponible.', 'booking-plugin' ),
	booking_rest_invalid_datetime: __( 'La fecha u hora no es válida.', 'booking-plugin' ),
	booking_rest_invalid_addons: __(
		'Uno de los extras elegidos ya no está disponible para este servicio. Vuelve a elegirlos.',
		'booking-plugin'
	),
	booking_rest_invalid_guest_name: __( 'El nombre es obligatorio.', 'booking-plugin' ),
	booking_rest_invalid_guest_email: __( 'El email no es válido.', 'booking-plugin' ),
	booking_rest_slot_unavailable: __(
		'Ese horario ya no está disponible. Elige otro horario.',
		'booking-plugin'
	),
	booking_rest_no_availability: __(
		'No hay profesionales disponibles para ese servicio en este momento.',
		'booking-plugin'
	),
	booking_rest_cancellation_window_closed: __(
		'Ya no se puede modificar esta reserva sin contactar directamente al negocio.',
		'booking-plugin'
	),
	booking_rest_forbidden: __( 'No tienes permiso para realizar esta acción.', 'booking-plugin' ),
	booking_rest_not_found: __( 'No se encontró el registro solicitado.', 'booking-plugin' ),
};

export function getApiErrorMessage( error ) {
	if ( ! error ) {
		return __( 'Ocurrió un error inesperado.', 'booking-plugin' );
	}

	if ( error.code && MESSAGES_BY_CODE[ error.code ] ) {
		return MESSAGES_BY_CODE[ error.code ];
	}

	if ( error.message ) {
		return error.message;
	}

	return String( error );
}
