import { __ } from '@wordpress/i18n';
import { CheckboxControl, TextControl } from '@wordpress/components';

const DAY_LABELS = [
	__( 'Domingo', 'booking-plugin' ),
	__( 'Lunes', 'booking-plugin' ),
	__( 'Martes', 'booking-plugin' ),
	__( 'Miércoles', 'booking-plugin' ),
	__( 'Jueves', 'booking-plugin' ),
	__( 'Viernes', 'booking-plugin' ),
	__( 'Sábado', 'booking-plugin' ),
];

// Forma de datos interna, compartida por el horario de staff (PUT /staff/{id})
// y el horario del negocio (PUT /business-hours): 7 filas fijas por
// day_of_week, con un toggle "enabled" en vez de la ausencia/presencia de
// fila (staff) o el open_time/close_time nulo (business-hours) que usa cada
// API por separado. Los helpers de abajo convierten en ambas direcciones.
export function createEmptyWeeklySchedule() {
	return Array.from( { length: 7 }, ( _, dayOfWeek ) => ( {
		day_of_week: dayOfWeek,
		enabled: false,
		start_time: '09:00',
		end_time: '18:00',
		break_start: '',
		break_end: '',
	} ) );
}

export function scheduleRowsFromStaffSchedules( schedules ) {
	const byDay = {};

	schedules.forEach( ( row ) => {
		byDay[ row.day_of_week ] = row;
	} );

	return Array.from( { length: 7 }, ( _, dayOfWeek ) => {
		const existing = byDay[ dayOfWeek ];

		if ( ! existing ) {
			return {
				day_of_week: dayOfWeek,
				enabled: false,
				start_time: '09:00',
				end_time: '18:00',
				break_start: '',
				break_end: '',
			};
		}

		return {
			day_of_week: dayOfWeek,
			enabled: true,
			start_time: existing.start_time.slice( 0, 5 ),
			end_time: existing.end_time.slice( 0, 5 ),
			break_start: existing.break_start ? existing.break_start.slice( 0, 5 ) : '',
			break_end: existing.break_end ? existing.break_end.slice( 0, 5 ) : '',
		};
	} );
}

export function staffSchedulesFromRows( rows ) {
	return rows
		.filter( ( row ) => row.enabled )
		.map( ( row ) => ( {
			day_of_week: row.day_of_week,
			start_time: row.start_time,
			end_time: row.end_time,
			break_start: row.break_start || null,
			break_end: row.break_end || null,
		} ) );
}

export function scheduleRowsFromBusinessHours( days ) {
	return days.map( ( day ) => ( {
		day_of_week: day.day_of_week,
		enabled: Boolean( day.open_time && day.close_time ),
		start_time: day.open_time ? day.open_time.slice( 0, 5 ) : '09:00',
		end_time: day.close_time ? day.close_time.slice( 0, 5 ) : '18:00',
		break_start: '',
		break_end: '',
	} ) );
}

export function businessHoursFromRows( rows ) {
	return rows.map( ( row ) => ( {
		day_of_week: row.day_of_week,
		open_time: row.enabled ? row.start_time : null,
		close_time: row.enabled ? row.end_time : null,
	} ) );
}

export default function WeeklyScheduleEditor( { value, onChange, showBreak = true } ) {
	const updateDay = ( dayOfWeek, changes ) => {
		onChange( value.map( ( row ) => ( row.day_of_week === dayOfWeek ? { ...row, ...changes } : row ) ) );
	};

	return (
		<table className="booking-plugin-table booking-plugin-schedule">
			<thead>
				<tr>
					<th>{ __( 'Día', 'booking-plugin' ) }</th>
					<th>{ __( 'Trabaja', 'booking-plugin' ) }</th>
					<th>{ __( 'Inicio', 'booking-plugin' ) }</th>
					<th>{ __( 'Fin', 'booking-plugin' ) }</th>
					{ showBreak && <th>{ __( 'Pausa inicio', 'booking-plugin' ) }</th> }
					{ showBreak && <th>{ __( 'Pausa fin', 'booking-plugin' ) }</th> }
				</tr>
			</thead>
			<tbody>
				{ value.map( ( row ) => (
					<tr key={ row.day_of_week }>
						<td>{ DAY_LABELS[ row.day_of_week ] }</td>
						<td>
							<CheckboxControl
								checked={ row.enabled }
								onChange={ ( enabled ) => updateDay( row.day_of_week, { enabled } ) }
							/>
						</td>
						<td>
							<TextControl
								type="time"
								value={ row.start_time }
								disabled={ ! row.enabled }
								onChange={ ( start_time ) => updateDay( row.day_of_week, { start_time } ) }
							/>
						</td>
						<td>
							<TextControl
								type="time"
								value={ row.end_time }
								disabled={ ! row.enabled }
								onChange={ ( end_time ) => updateDay( row.day_of_week, { end_time } ) }
							/>
						</td>
						{ showBreak && (
							<td>
								<TextControl
									type="time"
									value={ row.break_start }
									disabled={ ! row.enabled }
									onChange={ ( break_start ) => updateDay( row.day_of_week, { break_start } ) }
								/>
							</td>
						) }
						{ showBreak && (
							<td>
								<TextControl
									type="time"
									value={ row.break_end }
									disabled={ ! row.enabled }
									onChange={ ( break_end ) => updateDay( row.day_of_week, { break_end } ) }
								/>
							</td>
						) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
