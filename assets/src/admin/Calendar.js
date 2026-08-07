import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import CalendarColumn from './CalendarColumn';
import { addDays, startOfWeek, getLocalDate, formatTabLabel, ROW_HEIGHT, HOURS } from './utils';

export default function Calendar( { view, referenceDate, staff, appointments, onSelectAppointment } ) {
	const [ activeDate, setActiveDate ] = useState( referenceDate );

	// Al navegar (anterior/hoy/siguiente) o cambiar de vista, el dia activo
	// vuelve a coincidir con referenceDate. Cambiar de pestana dentro de la
	// misma semana solo mueve activeDate, sin pedirle nada nuevo al padre.
	useEffect( () => {
		setActiveDate( referenceDate );
	}, [ referenceDate, view ] );

	const weekDays =
		'week' === view
			? Array.from( { length: 7 }, ( _, i ) => addDays( startOfWeek( referenceDate ), i ) )
			: [];

	const dayAppointments = appointments.filter(
		( appointment ) => getLocalDate( appointment.start_datetime ) === activeDate
	);

	return (
		<div className="booking-plugin-calendar">
			{ 'week' === view && (
				<div className="booking-plugin-calendar__tabs">
					{ weekDays.map( ( day ) => (
						<button
							key={ day }
							type="button"
							className={
								'booking-plugin-calendar__tab' +
								( day === activeDate ? ' is-active' : '' )
							}
							onClick={ () => setActiveDate( day ) }
						>
							{ formatTabLabel( day ) }
						</button>
					) ) }
				</div>
			) }

			<div className="booking-plugin-calendar__grid">
				<div className="booking-plugin-calendar__gutter">
					{ HOURS.map( ( hour ) => (
						<div
							key={ hour }
							className="booking-plugin-calendar__hour-label"
							style={ { height: ROW_HEIGHT } }
						>
							{ String( hour ).padStart( 2, '0' ) }:00
						</div>
					) ) }
				</div>

				<div className="booking-plugin-calendar__columns">
					{ 0 === staff.length && (
						<p className="booking-plugin-calendar__empty">
							{ __( 'No hay staff activo para mostrar.', 'booking-plugin' ) }
						</p>
					) }
					{ staff.map( ( member ) => (
						<CalendarColumn
							key={ member.id }
							staffMember={ member }
							appointments={ dayAppointments.filter(
								( appointment ) => appointment.staff_id === member.id
							) }
							onSelectAppointment={ onSelectAppointment }
						/>
					) ) }
				</div>
			</div>
		</div>
	);
}
