import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { API_NAMESPACE } from './utils';
import { getApiErrorMessage } from './utils/apiError';

export default function StaffStep( { serviceId, onSelectStaff } ) {
	const [ staff, setStaff ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( ! serviceId ) {
			return;
		}

		setIsLoading( true );

		apiFetch( { path: `${ API_NAMESPACE }/staff?service_id=${ serviceId }&per_page=100` } )
			.then( ( result ) => {
				setStaff( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ serviceId ] );

	if ( isLoading ) {
		return <p>{ __( 'Cargando profesionales…', 'booking-plugin' ) }</p>;
	}

	if ( error ) {
		return <p className="booking-plugin-widget__error">{ error }</p>;
	}

	return (
		<div className="booking-plugin-widget__staff-grid">
			<button
				type="button"
				className="booking-plugin-widget__staff-card booking-plugin-widget__staff-card--any"
				onClick={ () => onSelectStaff( null ) }
			>
				{ __( 'Cualquier profesional disponible', 'booking-plugin' ) }
			</button>
			{ staff.map( ( member ) => (
				<button
					type="button"
					key={ member.id }
					className="booking-plugin-widget__staff-card"
					onClick={ () => onSelectStaff( member.id ) }
				>
					{ member.name }
				</button>
			) ) }
			{ 0 === staff.length && (
				<p>
					{ __(
						'No hay profesionales específicos para este servicio; puedes continuar con "Cualquier profesional disponible".',
						'booking-plugin'
					) }
				</p>
			) }
		</div>
	);
}
