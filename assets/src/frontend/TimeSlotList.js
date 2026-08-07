import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { API_NAMESPACE, formatTimeInZone } from './utils';
import { getApiErrorMessage } from './utils/apiError';

export default function TimeSlotList( { serviceId, staffId, date, timezone, onSelectSlot } ) {
	const [ slots, setSlots ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( ! date ) {
			return;
		}

		setIsLoading( true );

		const params = new URLSearchParams( {
			service_id: serviceId,
			date,
			timezone,
		} );

		if ( staffId ) {
			params.set( 'staff_id', staffId );
		}

		apiFetch( { path: `${ API_NAMESPACE }/availability?${ params.toString() }` } )
			.then( ( result ) => {
				setSlots( result.slots );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ serviceId, staffId, date, timezone ] );

	if ( ! date ) {
		return <p>{ __( 'Elige un día en el calendario.', 'booking-plugin' ) }</p>;
	}

	if ( isLoading ) {
		return <p>{ __( 'Cargando horarios…', 'booking-plugin' ) }</p>;
	}

	if ( error ) {
		return <p className="booking-plugin-widget__error">{ error }</p>;
	}

	if ( 0 === slots.length ) {
		return <p>{ __( 'No hay horarios disponibles ese día.', 'booking-plugin' ) }</p>;
	}

	return (
		<div className="booking-plugin-widget__slot-list">
			{ slots.map( ( slot ) => (
				<button
					type="button"
					key={ `${ slot.staff_id }-${ slot.start_datetime }` }
					className="booking-plugin-widget__slot"
					onClick={ () => onSelectSlot( slot ) }
				>
					{ formatTimeInZone( slot.start_datetime, timezone ) }
				</button>
			) ) }
		</div>
	);
}
