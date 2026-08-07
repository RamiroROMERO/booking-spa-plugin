import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Modal, Button, SelectControl, TextControl, Notice } from '@wordpress/components';

import { getLocalDate, formatTime, formatTimeRange, localToUtcIso, API_NAMESPACE } from './utils';
import { getApiErrorMessage } from './utils/apiError';

export default function BlockModal( { block, staff, defaultDate, onClose, onSaved } ) {
	const isEditing = Boolean( block );

	const [ staffId, setStaffId ] = useState(
		block ? String( block.staff_id ) : staff[ 0 ] ? String( staff[ 0 ].id ) : ''
	);
	const [ dateStr, setDateStr ] = useState( block ? getLocalDate( block.start_datetime ) : defaultDate );
	const [ startTime, setStartTime ] = useState( block ? formatTime( block.start_datetime ) : '09:00' );
	const [ endTime, setEndTime ] = useState( block ? formatTime( block.end_datetime ) : '10:00' );
	const [ notes, setNotes ] = useState( block ? block.notes || '' : '' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );

	const staffMember = isEditing ? staff.find( ( member ) => member.id === block.staff_id ) : null;

	const handleCreate = () => {
		const timezone = ( window.BookingPluginAdmin || {} ).timezone;

		setIsSaving( true );
		setError( null );

		apiFetch( {
			path: `${ API_NAMESPACE }/appointments/block`,
			method: 'POST',
			data: {
				staff_id: parseInt( staffId, 10 ),
				start_datetime: localToUtcIso( dateStr, startTime, timezone ),
				end_datetime: localToUtcIso( dateStr, endTime, timezone ),
				notes,
			},
		} )
			.then( () => {
				onSaved();
				onClose();
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSaving( false ) );
	};

	const handleUnblock = () => {
		setIsSaving( true );
		setError( null );

		apiFetch( {
			path: `${ API_NAMESPACE }/appointments/${ block.id }`,
			method: 'PATCH',
			data: { status: 'cancelled' },
		} )
			.then( () => {
				onSaved();
				onClose();
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSaving( false ) );
	};

	return (
		<Modal
			title={
				isEditing
					? __( 'Bloqueo de horario', 'booking-plugin' )
					: __( 'Bloquear horario', 'booking-plugin' )
			}
			onRequestClose={ onClose }
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ isEditing ? (
				<>
					<p>
						<strong>{ __( 'Staff:', 'booking-plugin' ) }</strong>{ ' ' }
						{ staffMember ? staffMember.name : block.staff_id }
					</p>
					<p>
						<strong>{ __( 'Horario:', 'booking-plugin' ) }</strong>{ ' ' }
						{ getLocalDate( block.start_datetime ) }{ ' ' }
						{ formatTimeRange( block.start_datetime, block.end_datetime ) }
					</p>
					{ block.notes && (
						<p>
							<strong>{ __( 'Motivo:', 'booking-plugin' ) }</strong> { block.notes }
						</p>
					) }
					<Button variant="primary" isDestructive disabled={ isSaving } onClick={ handleUnblock }>
						{ __( 'Desbloquear', 'booking-plugin' ) }
					</Button>
				</>
			) : (
				<div className="booking-plugin-modal__block-form">
					<SelectControl
						label={ __( 'Staff', 'booking-plugin' ) }
						value={ staffId }
						options={ staff.map( ( member ) => ( {
							label: member.name,
							value: String( member.id ),
						} ) ) }
						onChange={ setStaffId }
					/>
					<TextControl
						type="date"
						label={ __( 'Fecha', 'booking-plugin' ) }
						value={ dateStr }
						onChange={ setDateStr }
					/>
					<TextControl
						type="time"
						label={ __( 'Hora inicio', 'booking-plugin' ) }
						value={ startTime }
						onChange={ setStartTime }
					/>
					<TextControl
						type="time"
						label={ __( 'Hora fin', 'booking-plugin' ) }
						value={ endTime }
						onChange={ setEndTime }
					/>
					<TextControl
						label={ __( 'Motivo', 'booking-plugin' ) }
						value={ notes }
						onChange={ setNotes }
					/>
					<Button variant="primary" disabled={ isSaving || ! staffId } onClick={ handleCreate }>
						{ __( 'Bloquear', 'booking-plugin' ) }
					</Button>
				</div>
			) }
		</Modal>
	);
}
