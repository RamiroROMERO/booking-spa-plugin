import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import {
	API_NAMESPACE,
	today,
	addMonths,
	startOfMonth,
	startOfWeek,
	addDays,
	formatMonth,
	formatMonthLabel,
} from './utils';
import { getApiErrorMessage } from './utils/apiError';

const WEEKDAY_LABELS = [
	__( 'Lun', 'booking-plugin' ),
	__( 'Mar', 'booking-plugin' ),
	__( 'Mié', 'booking-plugin' ),
	__( 'Jue', 'booking-plugin' ),
	__( 'Vie', 'booking-plugin' ),
	__( 'Sáb', 'booking-plugin' ),
	__( 'Dom', 'booking-plugin' ),
];

export default function MonthCalendar( { serviceId, staffId, timezone, selectedDate, onSelectDate } ) {
	const [ viewMonth, setViewMonth ] = useState( () => formatMonth( selectedDate || today() ) );
	const [ availableDates, setAvailableDates ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setIsLoading( true );

		const params = new URLSearchParams( {
			service_id: serviceId,
			month: viewMonth,
			timezone,
		} );

		if ( staffId ) {
			params.set( 'staff_id', staffId );
		}

		apiFetch( { path: `${ API_NAMESPACE }/availability/days?${ params.toString() }` } )
			.then( ( result ) => {
				setAvailableDates( result.available_dates );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ serviceId, staffId, viewMonth, timezone ] );

	const gridStart = startOfWeek( startOfMonth( viewMonth + '-01' ) );
	const days = Array.from( { length: 42 }, ( _, i ) => addDays( gridStart, i ) );

	return (
		<div className="booking-plugin-widget__calendar">
			<div className="booking-plugin-widget__calendar-nav">
				<button
					type="button"
					onClick={ () => setViewMonth( formatMonth( addMonths( viewMonth + '-01', -1 ) ) ) }
				>
					‹
				</button>
				<span>{ formatMonthLabel( viewMonth ) }</span>
				<button
					type="button"
					onClick={ () => setViewMonth( formatMonth( addMonths( viewMonth + '-01', 1 ) ) ) }
				>
					›
				</button>
			</div>

			{ error && <p className="booking-plugin-widget__error">{ error }</p> }
			{ isLoading && <p>{ __( 'Cargando disponibilidad…', 'booking-plugin' ) }</p> }

			<div className="booking-plugin-widget__calendar-weekdays">
				{ WEEKDAY_LABELS.map( ( label ) => (
					<span key={ label }>{ label }</span>
				) ) }
			</div>

			<div className="booking-plugin-widget__calendar-grid">
				{ days.map( ( day ) => {
					const inMonth = day.slice( 0, 7 ) === viewMonth;
					const isAvailable = ! isLoading && inMonth && availableDates.includes( day );

					return (
						<button
							type="button"
							key={ day }
							disabled={ ! isAvailable }
							className={
								'booking-plugin-widget__calendar-day' +
								( ! inMonth ? ' is-outside' : '' ) +
								( isAvailable ? ' is-available' : '' ) +
								( day === selectedDate ? ' is-selected' : '' )
							}
							onClick={ () => onSelectDate( day ) }
						>
							{ Number( day.slice( 8, 10 ) ) }
						</button>
					);
				} ) }
			</div>
		</div>
	);
}
