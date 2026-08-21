import { __ } from '@wordpress/i18n';

// Traduce los codigos de WP_Error que devuelven los controladores REST de
// SPEC 02/03 a mensajes legibles en espanol. Cualquier codigo no mapeado
// cae de vuelta al message que ya trae la respuesta de la API.
const MESSAGES_BY_CODE = {
	booking_rest_category_in_use: __(
		'No se puede borrar: hay servicios en esta categoría.',
		'booking-plugin'
	),
	booking_rest_duplicate_name: __( 'Ya existe un registro con ese nombre.', 'booking-plugin' ),
	booking_rest_invalid_duration: __( 'La duración debe ser mayor a 0.', 'booking-plugin' ),
	booking_rest_invalid_price: __( 'El precio no puede ser negativo.', 'booking-plugin' ),
	booking_rest_invalid_buffer: __( 'El buffer no puede ser negativo.', 'booking-plugin' ),
	booking_rest_invalid_extra_time: __(
		'Los minutos extra no pueden ser negativos.',
		'booking-plugin'
	),
	booking_rest_invalid_addons: __(
		'Uno de los add-ons seleccionados no es válido para este servicio.',
		'booking-plugin'
	),
	booking_rest_addons_not_editable: __(
		'Los add-ons de esta cita ya no se pueden editar.',
		'booking-plugin'
	),
	booking_rest_invalid_name: __( 'El nombre es obligatorio.', 'booking-plugin' ),
	booking_rest_invalid_email: __( 'El email no es válido.', 'booking-plugin' ),
	booking_rest_invalid_guest_name: __( 'El nombre del cliente es obligatorio.', 'booking-plugin' ),
	booking_rest_invalid_guest_email: __( 'El email del cliente no es válido.', 'booking-plugin' ),
	booking_rest_invalid_category: __( 'La categoría seleccionada no existe.', 'booking-plugin' ),
	booking_rest_invalid_service_ids: __(
		'Uno de los servicios seleccionados no existe.',
		'booking-plugin'
	),
	booking_rest_invalid_schedules: __( 'El horario contiene datos inválidos.', 'booking-plugin' ),
	booking_rest_invalid_exceptions: __( 'Una de las excepciones contiene datos inválidos.', 'booking-plugin' ),
	booking_rest_invalid_status: __( 'El estado no es válido.', 'booking-plugin' ),
	booking_rest_invalid_staff: __( 'El staff seleccionado no existe.', 'booking-plugin' ),
	booking_rest_invalid_service: __(
		'El servicio seleccionado no existe o está inactivo.',
		'booking-plugin'
	),
	booking_rest_invalid_datetime: __( 'La fecha u hora no es válida.', 'booking-plugin' ),
	booking_rest_invalid_days: __( 'El horario debe cubrir los 7 días de la semana.', 'booking-plugin' ),
	booking_rest_slot_unavailable: __( 'Ese horario ya no está disponible.', 'booking-plugin' ),
	booking_rest_no_availability: __( 'No hay staff disponible para ese servicio.', 'booking-plugin' ),
	booking_rest_cancellation_window_closed: __(
		'Ya no se puede cancelar ni reprogramar esta cita sin permisos de administrador.',
		'booking-plugin'
	),
	booking_rest_forbidden: __( 'No tienes permiso para realizar esta acción.', 'booking-plugin' ),
	booking_rest_not_found: __( 'El registro no fue encontrado.', 'booking-plugin' ),
	booking_rest_invalid_google_credentials: __(
		'El Client ID y el Client Secret son obligatorios.',
		'booking-plugin'
	),
	booking_rest_google_missing_credentials: __(
		'Guardá el Client ID y el Client Secret antes de conectar la cuenta.',
		'booking-plugin'
	),
	booking_rest_invalid_calendar: __( 'Elegí un calendario para continuar.', 'booking-plugin' ),
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
