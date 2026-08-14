import { __ } from '@wordpress/i18n';

// Datos de ejemplo (mock) para la vista previa -- nunca datos reales de un
// cliente, para no exponerlos accidentalmente durante la edición (ver
// "Decisions" de SPEC 09). No tienen por qué coincidir con los del envío de
// prueba en el backend (Booking_Plugin_Notifications::get_example_context());
// esta vista previa es solo para ver el formato, no para probar el envío real.
const EXAMPLE_CONTEXT = {
	client_name: __( 'Juana Pérez', 'booking-plugin' ),
	client_email: 'juana.perez@example.com',
	client_phone: '+54 9 11 1234-5678',
	service_name: __( 'Corte de cabello', 'booking-plugin' ),
	staff_name: __( 'Carlos Gómez', 'booking-plugin' ),
	date: '15 de agosto de 2026',
	time: '10:00',
	business_name: 'Mi Negocio',
	manage_url: 'https://tusitio.com/mi-cuenta/?appointment=123&token=abc123',
};

function interpolate( text ) {
	return Object.entries( EXAMPLE_CONTEXT ).reduce(
		( result, [ placeholder, value ] ) => result.split( `{{${ placeholder }}}` ).join( value ),
		text || ''
	);
}

export default function TemplatePreview( { subject, body } ) {
	return (
		<div className="booking-plugin-template-preview">
			<div className="booking-plugin-template-preview__meta">
				<div>
					<strong>{ __( 'Para:', 'booking-plugin' ) }</strong> { EXAMPLE_CONTEXT.client_email }
				</div>
				<div>
					<strong>{ __( 'Asunto:', 'booking-plugin' ) }</strong> { interpolate( subject ) }
				</div>
			</div>
			<div
				className="booking-plugin-template-preview__body"
				dangerouslySetInnerHTML={ { __html: interpolate( body ) } }
			/>
		</div>
	);
}
