import { __ } from '@wordpress/i18n';

import { addDays, startOfMonth, startOfWeek, getLocalDate } from './utils';

const WEEKDAY_LABELS = [
	__( 'Lun', 'booking-plugin' ),
	__( 'Mar', 'booking-plugin' ),
	__( 'Mié', 'booking-plugin' ),
	__( 'Jue', 'booking-plugin' ),
	__( 'Vie', 'booking-plugin' ),
	__( 'Sáb', 'booking-plugin' ),
	__( 'Dom', 'booking-plugin' ),
];

export default function MonthView( { referenceDate, appointments, onSelectDay } ) {
	const gridStart = startOfWeek( startOfMonth( referenceDate ) );
	const days = Array.from( { length: 42 }, ( _, i ) => addDays( gridStart, i ) );
	const currentMonth = referenceDate.slice( 0, 7 );

	const countsByDay = appointments.reduce( ( acc, appointment ) => {
		const day = getLocalDate( appointment.start_datetime );
		acc[ day ] = ( acc[ day ] || 0 ) + 1;
		return acc;
	}, {} );

	return (
		<div className="booking-plugin-month">
			<div className="booking-plugin-month__weekdays">
				{ WEEKDAY_LABELS.map( ( label ) => (
					<div key={ label } className="booking-plugin-month__weekday">
						{ label }
					</div>
				) ) }
			</div>
			<div className="booking-plugin-month__grid">
				{ days.map( ( day ) => (
					<button
						type="button"
						key={ day }
						className={
							'booking-plugin-month__day' +
							( day.slice( 0, 7 ) === currentMonth ? '' : ' is-outside' )
						}
						onClick={ () => onSelectDay( day ) }
					>
						<span className="booking-plugin-month__day-number">
							{ Number( day.slice( 8, 10 ) ) }
						</span>
						{ countsByDay[ day ] > 0 && (
							<span className="booking-plugin-month__day-count">
								{ countsByDay[ day ] } { __( 'citas', 'booking-plugin' ) }
							</span>
						) }
					</button>
				) ) }
			</div>
		</div>
	);
}
