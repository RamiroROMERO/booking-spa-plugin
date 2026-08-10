import { useState } from '@wordpress/element';

import MonthCalendar from './MonthCalendar';
import TimeSlotList from './TimeSlotList';

export default function DateTimeStep( {
	serviceId,
	staffId,
	addonIds,
	timezone,
	date,
	collisionNotice,
	onSelectSlot,
} ) {
	const [ selectedDate, setSelectedDate ] = useState( date );

	return (
		<div className="booking-plugin-widget__datetime-step">
			{ collisionNotice && (
				<p className="booking-plugin-widget__notice">{ collisionNotice }</p>
			) }
			<MonthCalendar
				serviceId={ serviceId }
				staffId={ staffId }
				timezone={ timezone }
				selectedDate={ selectedDate }
				onSelectDate={ setSelectedDate }
			/>

			<div className="booking-plugin-widget__datetime-times">
				<TimeSlotList
					serviceId={ serviceId }
					staffId={ staffId }
					addonIds={ addonIds }
					date={ selectedDate }
					timezone={ timezone }
					onSelectSlot={ ( slot ) => onSelectSlot( selectedDate, slot ) }
				/>
			</div>
		</div>
	);
}
