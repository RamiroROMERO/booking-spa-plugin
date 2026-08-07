import { __ } from '@wordpress/i18n';

import { formatTimeRange, getTopPercent, getHeightPercent } from './utils';

export default function AppointmentCard( { appointment, onSelect } ) {
	const top = getTopPercent( appointment.start_datetime );
	const height = getHeightPercent( appointment.start_datetime, appointment.end_datetime );

	const isBlocked = 'blocked' === appointment.status;
	const label = isBlocked
		? appointment.notes || __( 'Bloqueado', 'booking-plugin' )
		: appointment.guest_name || __( 'Reserva', 'booking-plugin' );

	return (
		<button
			type="button"
			className={ `booking-plugin-appointment-card is-status-${ appointment.status }` }
			style={ { top: `${ top }%`, height: `${ height }%` } }
			onClick={ () => onSelect( appointment ) }
		>
			<span className="booking-plugin-appointment-card__time">
				{ formatTimeRange( appointment.start_datetime, appointment.end_datetime ) }
			</span>
			<span className="booking-plugin-appointment-card__label">{ label }</span>
		</button>
	);
}
