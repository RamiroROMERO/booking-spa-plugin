import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Modal, Button, Notice, CheckboxControl, Icon } from '@wordpress/components';
import { info, people, time } from '@wordpress/icons';

import {
	getLocalDate,
	formatDateUS,
	formatTime,
	formatTimeRange,
	localToUtcIso,
	formatCurrency,
	API_NAMESPACE,
} from './utils';
import { getApiErrorMessage } from './utils/apiError';

const STATUS_LABELS = {
	pending: __( 'Pendiente', 'booking-plugin' ),
	confirmed: __( 'Confirmada', 'booking-plugin' ),
	completed: __( 'Completada', 'booking-plugin' ),
	no_show: __( 'No asistió', 'booking-plugin' ),
	cancelled: __( 'Cancelada', 'booking-plugin' ),
	blocked: __( 'Bloqueado', 'booking-plugin' ),
};

const STATUS_DOT_CLASSES = {
	pending: 'is-pending',
	confirmed: 'is-confirmed',
	completed: 'is-completed',
	no_show: 'is-no-show',
	cancelled: 'is-cancelled',
	blocked: 'is-blocked',
};

export default function AppointmentModal( { appointment, staff, onClose, onUpdated } ) {
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ newDate, setNewDate ] = useState( getLocalDate( appointment.start_datetime ) );
	const [ newTime, setNewTime ] = useState( formatTime( appointment.start_datetime ) );

	const [ editingAddons, setEditingAddons ] = useState( false );
	const [ availableAddons, setAvailableAddons ] = useState( null );
	const [ isLoadingAddons, setIsLoadingAddons ] = useState( false );
	const [ selectedAddonIds, setSelectedAddonIds ] = useState( [] );
	const [ creditPackageName, setCreditPackageName ] = useState( null );

	const staffMember = staff.find( ( member ) => member.id === appointment.staff_id );

	// El endpoint de citas no trae el nombre del paquete (solo el id del
	// credito usado, ver SPEC 12): se busca aparte en los creditos del
	// cliente para mostrarlo en el modal.
	useEffect( () => {
		if ( ! appointment.paid_with_credit_id || ! appointment.user_id ) {
			return;
		}

		apiFetch( { path: `${ API_NAMESPACE }/users/${ appointment.user_id }/credits` } )
			.then( ( credits ) => {
				const credit = credits.find( ( item ) => item.id === appointment.paid_with_credit_id );
				setCreditPackageName( credit ? credit.package_name : null );
			} )
			.catch( () => setCreditPackageName( null ) );
	}, [ appointment.paid_with_credit_id, appointment.user_id ] );

	const canEditAddons =
		! appointment.wc_order_id && [ 'pending', 'confirmed' ].includes( appointment.status );

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

	const startEditAddons = () => {
		setEditingAddons( true );
		setSelectedAddonIds( ( appointment.addons || [] ).map( ( addon ) => addon.addon_id ) );

		if ( ! appointment.service_id ) {
			return;
		}

		setIsLoadingAddons( true );

		apiFetch( { path: `${ API_NAMESPACE }/services/${ appointment.service_id }/addons` } )
			.then( setAvailableAddons )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoadingAddons( false ) );
	};

	const toggleAddon = ( addonId ) => {
		setSelectedAddonIds( ( current ) =>
			current.includes( addonId )
				? current.filter( ( id ) => id !== addonId )
				: [ ...current, addonId ]
		);
	};

	const saveAddons = () => {
		patchAppointment( { addon_ids: selectedAddonIds } );
	};

	return (
		<Modal title={ __( 'Detalle de la cita', 'booking-plugin' ) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<div className="booking-plugin-modal__details">
				<div className="booking-plugin-modal__detail-row">
					<span className="booking-plugin-modal__detail-label">
						<Icon icon={ info } size={ 18 } />
						{ __( 'Estado', 'booking-plugin' ) }
					</span>
					<span className="booking-plugin-modal__detail-value">
						<span
							className={ `booking-plugin-modal__status-dot ${ STATUS_DOT_CLASSES[ appointment.status ] || '' }` }
						/>
						{ STATUS_LABELS[ appointment.status ] || appointment.status }
					</span>
				</div>

				<div className="booking-plugin-modal__detail-row">
					<span className="booking-plugin-modal__detail-label">
						<Icon icon={ people } size={ 18 } />
						{ __( 'Staff', 'booking-plugin' ) }
					</span>
					<span className="booking-plugin-modal__detail-value">
						{ staffMember ? staffMember.name : appointment.staff_id }
					</span>
				</div>

				<div className="booking-plugin-modal__detail-row">
					<span className="booking-plugin-modal__detail-label">
						<Icon icon={ time } size={ 18 } />
						{ __( 'Horario', 'booking-plugin' ) }
					</span>
					<span className="booking-plugin-modal__detail-value">
						{ formatDateUS( appointment.start_datetime ) }{ ' ' }
						{ formatTimeRange( appointment.start_datetime, appointment.end_datetime ) }
					</span>
				</div>
			</div>

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

			{ appointment.wc_order_id && (
				<p>
					<strong>{ __( 'Pago:', 'booking-plugin' ) }</strong>{ ' ' }
					{ appointment.payment_status || __( 'Desconocido', 'booking-plugin' ) }
					{ appointment.wc_order_edit_url && (
						<>
							{ ' — ' }
							<a href={ appointment.wc_order_edit_url } target="_blank" rel="noreferrer">
								{ __( 'Ver pedido', 'booking-plugin' ) }
							</a>
						</>
					) }
					{ appointment.refunded_amount > 0 && (
						<>
							<br />
							<strong>{ __( 'Reembolsado:', 'booking-plugin' ) }</strong> { formatCurrency( appointment.refunded_amount ) }
						</>
					) }
				</p>
			) }

			{ null !== appointment.deposit_amount && (
				<p>
					<strong>{ __( 'Depósito pagado:', 'booking-plugin' ) }</strong> { formatCurrency( appointment.deposit_amount ) }
					<br />
					<strong>{ __( 'Saldo pendiente:', 'booking-plugin' ) }</strong> { formatCurrency( appointment.balance_due ) }
					<br />
					{ appointment.balance_collected_at ? (
						sprintf(
							/* translators: %s: fecha en que se marco el saldo como cobrado */
							__( 'Saldo cobrado el %s', 'booking-plugin' ),
							formatDateUS( appointment.balance_collected_at )
						)
					) : (
						<Button
							variant="secondary"
							disabled={ isSaving }
							onClick={ () => patchAppointment( { balance_collected: true } ) }
						>
							{ __( 'Marcar saldo como cobrado', 'booking-plugin' ) }
						</Button>
					) }
				</p>
			) }

			{ appointment.paid_with_credit_id && (
				<p>
					<strong>{ __( 'Pago:', 'booking-plugin' ) }</strong>{ ' ' }
					{ creditPackageName
						? sprintf(
								/* translators: %s: nombre del paquete */
								__( 'Pagada con crédito (%s)', 'booking-plugin' ),
								creditPackageName
						  )
						: __( 'Pagada con crédito', 'booking-plugin' ) }
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

			<h3>{ __( 'Add-ons', 'booking-plugin' ) }</h3>

			{ appointment.addons && appointment.addons.length > 0 ? (
				<ul>
					{ appointment.addons.map( ( addon ) => (
						<li key={ addon.addon_id }>
							{ addon.name } — { formatCurrency( addon.price ) } — { addon.extra_time_minutes }{ ' ' }
							{ __( 'min extra', 'booking-plugin' ) }
						</li>
					) ) }
				</ul>
			) : (
				<p>{ __( 'Esta cita no tiene add-ons.', 'booking-plugin' ) }</p>
			) }

			{ canEditAddons && ! editingAddons && (
				<Button variant="link" onClick={ startEditAddons }>
					{ __( 'Editar add-ons', 'booking-plugin' ) }
				</Button>
			) }

			{ canEditAddons && editingAddons && (
				<div className="booking-plugin-modal__addons-editor">
					{ isLoadingAddons && <p>{ __( 'Cargando…', 'booking-plugin' ) }</p> }
					{ ! isLoadingAddons &&
						availableAddons &&
						availableAddons.map( ( addon ) => (
							<CheckboxControl
								key={ addon.id }
								label={ `${ addon.name } — ${ formatCurrency( addon.price ) } — ${ addon.extra_time_minutes } min` }
								checked={ selectedAddonIds.includes( addon.id ) }
								onChange={ () => toggleAddon( addon.id ) }
							/>
						) ) }
					{ ! isLoadingAddons && availableAddons && 0 === availableAddons.length && (
						<p>{ __( 'Este servicio no tiene add-ons activos.', 'booking-plugin' ) }</p>
					) }
					<Button variant="primary" disabled={ isSaving } onClick={ saveAddons }>
						{ __( 'Guardar add-ons', 'booking-plugin' ) }
					</Button>
					<Button variant="tertiary" onClick={ () => setEditingAddons( false ) }>
						{ __( 'Cancelar', 'booking-plugin' ) }
					</Button>
				</div>
			) }

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
