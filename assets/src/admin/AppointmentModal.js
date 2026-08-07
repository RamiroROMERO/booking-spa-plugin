import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Modal, Button, Notice } from '@wordpress/components';

import { getLocalDate, formatTime, formatTimeRange, localToUtcIso, API_NAMESPACE } from './utils';
import { getApiErrorMessage } from './utils/apiError';

const STATUS_LABELS = {
	pending: __( 'Pendiente', 'booking-plugin' ),
	confirmed: __( 'Confirmada', 'booking-plugin' ),
	completed: __( 'Completada', 'booking-plugin' ),
	no_show: __( 'No asistió', 'booking-plugin' ),
	cancelled: __( 'Cancelada', 'booking-plugin' ),
	blocked: __( 'Bloqueado', 'booking-plugin' ),
};

export default function AppointmentModal( { appointment, staff, onClose, onUpdated } ) {
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ newDate, setNewDate ] = useState( getLocalDate( appointment.start_datetime ) );
	const [ newTime, setNewTime ] = useState( formatTime( appointment.start_datetime ) );

	const staffMember = staff.find( ( member ) => member.id === appointment.staff_id );

	const patchAppointment = ( data ) => {
		setIsSaving( true );
		setError( null );

		apiFetch( {
			path: `${ API_NAMESPACE }/appointments/${ appointment.id }`,
			method: 'PATCH',
			data,
		} )
			.then( () => {
				onUpdated();
				onClose();
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSaving( false ) );
	};

	const handleReschedule = () => {
		const timezone = ( window.BookingPluginAdmin || {} ).timezone;
		patchAppointment( { start_datetime: localToUtcIso( newDate, newTime, timezone ) } );
	};

	return (
		<Modal title={ __( 'Detalle de la cita', 'booking-plugin' ) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<p>
				<strong>{ __( 'Estado:', 'booking-plugin' ) }</strong>{ ' ' }
				{ STATUS_LABELS[ appointment.status ] || appointment.status }
			</p>

			<p>
				<strong>{ __( 'Staff:', 'booking-plugin' ) }</strong>{ ' ' }
				{ staffMember ? staffMember.name : appointment.staff_id }
			</p>

			<p>
				<strong>{ __( 'Horario:', 'booking-plugin' ) }</strong>{ ' ' }
				{ getLocalDate( appointment.start_datetime ) }{ ' ' }
				{ formatTimeRange( appointment.start_datetime, appointment.end_datetime ) }
			</p>

			{ ( appointment.guest_name || appointment.guest_email || appointment.guest_phone ) && (
				<p>
					<strong>{ __( 'Cliente:', 'booking-plugin' ) }</strong>{ ' ' }
					{ appointment.guest_name }
					{ appointment.guest_email && ` — ${ appointment.guest_email }` }
					{ appointment.guest_phone && ` — ${ appointment.guest_phone }` }
				</p>
			) }

			{ appointment.notes && (
				<p>
					<strong>{ __( 'Notas:', 'booking-plugin' ) }</strong> { appointment.notes }
				</p>
			) }

			<div className="booking-plugin-modal__actions">
				<Button
					variant="secondary"
					disabled={ isSaving || 'confirmed' === appointment.status }
					onClick={ () => patchAppointment( { status: 'confirmed' } ) }
				>
					{ __( 'Confirmar', 'booking-plugin' ) }
				</Button>
				<Button
					variant="secondary"
					disabled={ isSaving || 'completed' === appointment.status }
					onClick={ () => patchAppointment( { status: 'completed' } ) }
				>
					{ __( 'Completar', 'booking-plugin' ) }
				</Button>
				<Button
					variant="secondary"
					disabled={ isSaving || 'no_show' === appointment.status }
					onClick={ () => patchAppointment( { status: 'no_show' } ) }
				>
					{ __( 'No asistió', 'booking-plugin' ) }
				</Button>
				<Button
					variant="secondary"
					isDestructive
					disabled={ isSaving || 'cancelled' === appointment.status }
					onClick={ () => patchAppointment( { status: 'cancelled' } ) }
				>
					{ __( 'Cancelar', 'booking-plugin' ) }
				</Button>
			</div>

			<hr />

			<h3>{ __( 'Reprogramar', 'booking-plugin' ) }</h3>
			<div className="booking-plugin-modal__reschedule">
				<input
					type="date"
					value={ newDate }
					onChange={ ( event ) => setNewDate( event.target.value ) }
				/>
				<input
					type="time"
					value={ newTime }
					onChange={ ( event ) => setNewTime( event.target.value ) }
				/>
				<Button variant="primary" disabled={ isSaving } onClick={ handleReschedule }>
					{ __( 'Reprogramar', 'booking-plugin' ) }
				</Button>
			</div>
		</Modal>
	);
}
