import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { API_NAMESPACE } from '../utils';
import AppointmentListItem from './AppointmentListItem';
import { getClientPanelErrorMessage } from './errorMessages';

export default function GuestAppointmentView( { appointmentId, token, timezone, minCancellationHours } ) {
	const [ appointment, setAppointment ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ isRescheduling, setIsRescheduling ] = useState( false );

	useEffect( () => {
		if ( ! appointmentId || ! token ) {
			setError( __( 'Este enlace no es válido.', 'booking-plugin' ) );
			setIsLoading( false );
			return;
		}

		apiFetch( {
			path: `${ API_NAMESPACE }/appointments/${ appointmentId }?token=${ encodeURIComponent( token ) }`,
		} )
			.then( ( result ) => setAppointment( result ) )
			.catch( ( err ) =>
				setError( getClientPanelErrorMessage( err, { minCancellationHours, isGuest: true } ) )
			)
			.finally( () => setIsLoading( false ) );
	}, [ appointmentId, token, minCancellationHours ] );

	const handleUpdated = ( updated ) => {
		setAppointment( updated );
		setIsRescheduling( false );
	};

	if ( isLoading ) {
		return <p>{ __( 'Cargando tu cita…', 'booking-plugin' ) }</p>;
	}

	// Sin cita cargada (token invalido/vencido, u otro error): no se renderiza
	// ningun dato de la cita, solo el mensaje de error -- ver criterio de
	// aceptacion "sin exponer ningun dato de la cita".
	if ( ! appointment ) {
		return (
			<p className="booking-plugin-client-panel__error">
				{ error || __( 'Este enlace ya no es válido.', 'booking-plugin' ) }
			</p>
		);
	}

	return (
		<div>
			<h3>{ __( 'Tu cita', 'booking-plugin' ) }</h3>
			<ul className="booking-plugin-client-panel__items">
				<AppointmentListItem
					appointment={ appointment }
					timezone={ timezone }
					token={ token }
					minCancellationHours={ minCancellationHours }
					readOnly={ false }
					isRescheduling={ isRescheduling }
					onOpenReschedule={ () => setIsRescheduling( true ) }
					onCloseReschedule={ () => setIsRescheduling( false ) }
					onUpdated={ handleUpdated }
				/>
			</ul>
		</div>
	);
}
