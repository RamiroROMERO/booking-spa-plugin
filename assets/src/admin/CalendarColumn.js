import AppointmentCard from './AppointmentCard';

export default function CalendarColumn( {
	staffMember,
	appointments,
	bodyHeight,
	windowStartMinutes,
	windowTotalMinutes,
	onSelectAppointment,
} ) {
	return (
		<div className="booking-plugin-calendar__column">
			<div className="booking-plugin-calendar__column-header">{ staffMember.name }</div>
			<div className="booking-plugin-calendar__column-body" style={ { height: bodyHeight } }>
				{ appointments.map( ( appointment ) => (
					<AppointmentCard
						key={ appointment.id }
						appointment={ appointment }
						windowStartMinutes={ windowStartMinutes }
						windowTotalMinutes={ windowTotalMinutes }
						onSelect={ onSelectAppointment }
					/>
				) ) }
			</div>
		</div>
	);
}
