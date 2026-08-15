import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { API_NAMESPACE, detectTimezone } from '../utils';
import AppointmentListItem from './AppointmentListItem';
import GuestAppointmentView from './GuestAppointmentView';
import { getClientPanelErrorMessage } from './errorMessages';

const TABS = [
	{ id: 'upcoming', label: __( 'Próximas', 'booking-plugin' ) },
	{ id: 'history', label: __( 'Historial', 'booking-plugin' ) },
];

const BRANDING_CSS_VARS = {
	accent: '--booking-accent',
	accentHover: '--booking-accent-hover',
	borderColor: '--booking-border-color',
	textMuted: '--booking-text-muted',
};

function brandingToStyle( branding ) {
	const style = {};

	Object.keys( branding || {} ).forEach( ( key ) => {
		const cssVar = BRANDING_CSS_VARS[ key ];

		if ( cssVar ) {
			style[ cssVar ] = branding[ key ];
		}
	} );

	return style;
}

// Una cita es "proxima" solo si ademas de tener un status activo todavia no
// paso su hora de inicio; el resto (pasadas, o con status
// completed/no_show/cancelled aunque fueran futuras) cae en "Historial" --
// asi cada cita cae en exactamente una de las dos pestanas.
function isUpcoming( appointment ) {
	return (
		( 'pending' === appointment.status || 'confirmed' === appointment.status ) &&
		new Date( appointment.start_datetime ).getTime() > Date.now()
	);
}

function MyAppointmentsList( { timezone, minCancellationHours } ) {
	const [ tab, setTab ] = useState( 'upcoming' );
	const [ appointments, setAppointments ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ reschedulingId, setReschedulingId ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `${ API_NAMESPACE }/appointments/mine` } )
			.then( ( result ) => setAppointments( result ) )
			.catch( ( err ) => setError( getClientPanelErrorMessage( err, { minCancellationHours } ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ minCancellationHours ] );

	// Se actualiza el item modificado en el propio estado local en vez de
	// volver a pedir la lista entera: PATCH ya devuelve la cita completa con
	// su status/start_datetime nuevos.
	const handleUpdated = ( updated ) => {
		setAppointments( ( current ) =>
			current.map( ( appointment ) => ( appointment.id === updated.id ? updated : appointment ) )
		);
		setReschedulingId( null );
	};

	const visibleAppointments = useMemo(
		() =>
			appointments.filter( ( appointment ) =>
				'upcoming' === tab ? isUpcoming( appointment ) : ! isUpcoming( appointment )
			),
		[ appointments, tab ]
	);

	if ( isLoading ) {
		return <p>{ __( 'Cargando tus citas…', 'booking-plugin' ) }</p>;
	}

	if ( error ) {
		return <p className="booking-plugin-client-panel__error">{ error }</p>;
	}

	return (
		<div className="booking-plugin-client-panel__list">
			<div className="booking-plugin-client-panel__tabs">
				{ TABS.map( ( item ) => (
					<button
						key={ item.id }
						type="button"
						className={
							'booking-plugin-client-panel__tab' + ( item.id === tab ? ' is-active' : '' )
						}
						onClick={ () => setTab( item.id ) }
					>
						{ item.label }
					</button>
				) ) }
			</div>

			{ 0 === visibleAppointments.length && (
				<p>
					{ 'upcoming' === tab
						? __( 'No tienes citas próximas.', 'booking-plugin' )
						: __( 'Todavía no hay citas en tu historial.', 'booking-plugin' ) }
				</p>
			) }

			<ul className="booking-plugin-client-panel__items">
				{ visibleAppointments.map( ( appointment ) => (
					<AppointmentListItem
						key={ appointment.id }
						appointment={ appointment }
						timezone={ timezone }
						minCancellationHours={ minCancellationHours }
						readOnly={ 'history' === tab }
						isRescheduling={ reschedulingId === appointment.id }
						onOpenReschedule={ () => setReschedulingId( appointment.id ) }
						onCloseReschedule={ () => setReschedulingId( null ) }
						onUpdated={ handleUpdated }
					/>
				) ) }
			</ul>
		</div>
	);
}

export default function ClientPanelApp() {
	const settings = window.BookingPluginFrontend || {};
	const [ timezone ] = useState( () => detectTimezone( settings.timezone ) );
	const brandingStyle = brandingToStyle( settings.branding );

	if ( 'guest' === settings.mode ) {
		const guestContext = settings.guestContext || {};

		return (
			<div className="booking-plugin-client-panel" style={ brandingStyle }>
				<GuestAppointmentView
					appointmentId={ guestContext.appointmentId }
					token={ guestContext.token }
					timezone={ timezone }
					minCancellationHours={ settings.minCancellationHours }
				/>
			</div>
		);
	}

	return (
		<div className="booking-plugin-client-panel" style={ brandingStyle }>
			<h3>{ __( 'Tus citas', 'booking-plugin' ) }</h3>
			<MyAppointmentsList timezone={ timezone } minCancellationHours={ settings.minCancellationHours } />
		</div>
	);
}
