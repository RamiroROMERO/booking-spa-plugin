import AppointmentCard from './AppointmentCard';

export default function CalendarColumn( { staffMember, appointments, onSelectAppointment } ) {
	return (
		<div className="booking-plugin-calendar__column">
			<div className="booking-plugin-calendar__column-header">{ staffMember.name }</div>
			<div className="booking-plugin-calendar__column-body">
				{ appointments.map( ( appointment ) => (
					<AppointmentCard
						key={ appointment.id }
						appointment={ appointment }
						onSelect={ onSelectAppointment }
					/>
				) ) }
			</div>
		</div>
	);
}
