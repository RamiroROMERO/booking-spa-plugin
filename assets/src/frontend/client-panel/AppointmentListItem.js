import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { API_NAMESPACE, formatDateInZone, formatTimeInZone } from '../utils';
import MonthCalendar from '../MonthCalendar';
import TimeSlotList from '../TimeSlotList';
import { getClientPanelErrorMessage } from './errorMessages';

const STATUS_LABELS = {
	pending: __( 'Pendiente de confirmación', 'booking-plugin' ),
	confirmed: __( 'Confirmada', 'booking-plugin' ),
	completed: __( 'Completada', 'booking-plugin' ),
	no_show: __( 'No se presentó', 'booking-plugin' ),
	cancelled: __( 'Cancelada', 'booking-plugin' ),
};

/**
 * Un item se usa tanto en la lista del socio (MyAppointmentsList) como en la
 * vista puntual del invitado (GuestAppointmentView, SPEC 07 paso 6): el
 * `token` es opcional y, cuando esta presente, autentica el PATCH igual que
 * ya hace GET/PATCH /appointments/{id}?token= en SPEC 03.
 */
export default function AppointmentListItem( {
	appointment,
	timezone,
	token,
	minCancellationHours,
	readOnly,
	isRescheduling,
	onOpenReschedule,
	onCloseReschedule,
	onUpdated,
} ) {
	const [ isConfirmingCancel, setIsConfirmingCancel ] = useState( false );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ rescheduleDate, setRescheduleDate ] = useState( null );

	const describeError = ( err ) =>
		getClientPanelErrorMessage( err, { minCancellationHours, isGuest: !! token } );

	const patch = ( data ) => {
		const path = token
			? `${ API_NAMESPACE }/appointments/${ appointment.id }?token=${ encodeURIComponent( token ) }`
			: `${ API_NAMESPACE }/appointments/${ appointment.id }`;

		return apiFetch( { path, method: 'PATCH', data } );
	};

	const handleCancel = () => {
		setIsSubmitting( true );
		setError( null );

		patch( { status: 'cancelled' } )
			.then( ( updated ) => {
				setIsConfirmingCancel( false );
				onUpdated( updated );
			} )
			.catch( ( err ) => setError( describeError( err ) ) )
			.finally( () => setIsSubmitting( false ) );
	};

	const handleSelectSlot = ( slot ) => {
		setIsSubmitting( true );
		setError( null );

		patch( { start_datetime: slot.start_datetime } )
			.then( ( updated ) => {
				onUpdated( updated );
			} )
			.catch( ( err ) => setError( describeError( err ) ) )
			.finally( () => setIsSubmitting( false ) );
	};

	return (
		<li className="booking-plugin-client-panel__item">
			<div className="booking-plugin-client-panel__item-summary">
				<strong>{ formatDateInZone( appointment.start_datetime, timezone ) }</strong>
				<span>{ formatTimeInZone( appointment.start_datetime, timezone ) }</span>
				<span
					className={
						'booking-plugin-client-panel__status booking-plugin-client-panel__status--' +
						appointment.status
					}
				>
					{ STATUS_LABELS[ appointment.status ] || appointment.status }
				</span>
			</div>

			{ appointment.notes && (
				<p className="booking-plugin-client-panel__item-notes">{ appointment.notes }</p>
			) }

			{ error && <p className="booking-plugin-client-panel__error">{ error }</p> }

			{ ! readOnly && (
				<div className="booking-plugin-client-panel__item-actions">
					{ isConfirmingCancel ? (
						<>
							<span>{ __( '¿Cancelar esta cita?', 'booking-plugin' ) }</span>
							<button
								type="button"
								className="booking-plugin-client-panel__link is-destructive"
								disabled={ isSubmitting }
								onClick={ handleCancel }
							>
								{ __( 'Sí, cancelar', 'booking-plugin' ) }
							</button>
							<button
								type="button"
								className="booking-plugin-client-panel__link"
								disabled={ isSubmitting }
								onClick={ () => setIsConfirmingCancel( false ) }
							>
								{ __( 'No', 'booking-plugin' ) }
							</button>
						</>
					) : (
						<>
							<button
								type="button"
								className="booking-plugin-client-panel__link is-destructive"
								onClick={ () => setIsConfirmingCancel( true ) }
							>
								{ __( 'Cancelar', 'booking-plugin' ) }
							</button>
							<button
								type="button"
								className="booking-plugin-client-panel__link"
								onClick={ () => ( isRescheduling ? onCloseReschedule() : onOpenReschedule() ) }
							>
								{ isRescheduling
									? __( 'Cerrar', 'booking-plugin' )
									: __( 'Reprogramar', 'booking-plugin' ) }
							</button>
						</>
					) }
				</div>
			) }

			{ isRescheduling && (
				<div className="booking-plugin-client-panel__reschedule">
					<MonthCalendar
						serviceId={ appointment.service_id }
						staffId={ appointment.staff_id }
						timezone={ timezone }
						selectedDate={ rescheduleDate }
						onSelectDate={ setRescheduleDate }
					/>
					<TimeSlotList
						serviceId={ appointment.service_id }
						staffId={ appointment.staff_id }
						date={ rescheduleDate }
						timezone={ timezone }
						onSelectSlot={ handleSelectSlot }
					/>
					{ isSubmitting && <p>{ __( 'Reprogramando…', 'booking-plugin' ) }</p> }
				</div>
			) }
		</li>
	);
}
