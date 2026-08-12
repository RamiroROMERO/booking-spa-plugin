import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { TextControl, SelectControl, ToggleControl, Button, Notice } from '@wordpress/components';

import { API_NAMESPACE, formatDateUS, formatTimeRange, formatCurrency } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

export default function PendingBalancesPage() {
	const [ dateFrom, setDateFrom ] = useState( '' );
	const [ dateTo, setDateTo ] = useState( '' );
	const [ staffId, setStaffId ] = useState( '' );
	const [ showCollected, setShowCollected ] = useState( false );
	const [ staff, setStaff ] = useState( [] );
	const [ staffOptions, setStaffOptions ] = useState( [] );
	const [ services, setServices ] = useState( [] );
	const [ appointments, setAppointments ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ collectingId, setCollectingId ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `${ API_NAMESPACE }/staff?status=active&per_page=100` } )
			.then( ( result ) => {
				setStaff( result );
				setStaffOptions( [
					{ label: __( 'Todos', 'booking-plugin' ), value: '' },
					...result.map( ( member ) => ( { label: member.name, value: String( member.id ) } ) ),
				] );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );

		apiFetch( { path: `${ API_NAMESPACE }/services?status=active&per_page=100` } )
			.then( setServices )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	}, [] );

	const loadAppointments = useCallback( () => {
		setIsLoading( true );

		const queryParams = new URLSearchParams( { per_page: '100' } );
		queryParams.set( showCollected ? 'has_deposit' : 'has_pending_balance', 'true' );

		if ( dateFrom ) {
			queryParams.set( 'date_from', dateFrom );
		}

		if ( dateTo ) {
			queryParams.set( 'date_to', dateTo );
		}

		if ( staffId ) {
			queryParams.set( 'staff_id', staffId );
		}

		apiFetch( { path: `${ API_NAMESPACE }/appointments?${ queryParams.toString() }` } )
			.then( ( result ) => {
				setAppointments( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ dateFrom, dateTo, staffId, showCollected ] );

	useEffect( () => {
		loadAppointments();
	}, [ loadAppointments ] );

	const staffName = ( staffIdValue ) => {
		const member = staff.find( ( item ) => item.id === staffIdValue );
		return member ? member.name : staffIdValue;
	};

	const serviceName = ( serviceIdValue ) => {
		const service = services.find( ( item ) => item.id === serviceIdValue );
		return service ? service.name : __( '(servicio no disponible)', 'booking-plugin' );
	};

	const clientName = ( appointment ) => appointment.guest_name || __( 'Reserva', 'booking-plugin' );

	const handleMarkCollected = ( appointmentId ) => {
		setCollectingId( appointmentId );
		setError( null );

		apiFetch( {
			path: `${ API_NAMESPACE }/appointments/${ appointmentId }`,
			method: 'PATCH',
			data: { balance_collected: true },
		} )
			.then( () => loadAppointments() )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setCollectingId( null ) );
	};

	return (
		<div className="booking-plugin-admin">
			<h1>{ __( 'Saldos pendientes', 'booking-plugin' ) }</h1>

			{ error && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<div className="booking-plugin-filters-grid">
				<TextControl
					type="date"
					label={ __( 'Desde', 'booking-plugin' ) }
					value={ dateFrom }
					onChange={ setDateFrom }
				/>
				<TextControl
					type="date"
					label={ __( 'Hasta', 'booking-plugin' ) }
					value={ dateTo }
					onChange={ setDateTo }
				/>
				<SelectControl
					label={ __( 'Staff', 'booking-plugin' ) }
					value={ staffId }
					options={ staffOptions }
					onChange={ setStaffId }
				/>
			</div>

			<ToggleControl
				label={ __( 'Mostrar cobradas', 'booking-plugin' ) }
				checked={ showCollected }
				onChange={ setShowCollected }
			/>

			{ isLoading && <p>{ __( 'Cargando…', 'booking-plugin' ) }</p> }

			{ ! isLoading && (
				<table className="booking-plugin-table">
					<thead>
						<tr>
							<th>{ __( 'Cliente', 'booking-plugin' ) }</th>
							<th>{ __( 'Servicio', 'booking-plugin' ) }</th>
							<th>{ __( 'Staff', 'booking-plugin' ) }</th>
							<th>{ __( 'Fecha', 'booking-plugin' ) }</th>
							<th>{ __( 'Depósito pagado', 'booking-plugin' ) }</th>
							<th>{ __( 'Saldo pendiente', 'booking-plugin' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ appointments.map( ( appointment ) => (
							<tr key={ appointment.id }>
								<td>{ clientName( appointment ) }</td>
								<td>{ serviceName( appointment.service_id ) }</td>
								<td>{ staffName( appointment.staff_id ) }</td>
								<td>
									{ formatDateUS( appointment.start_datetime ) }{ ' ' }
									{ formatTimeRange( appointment.start_datetime, appointment.end_datetime ) }
								</td>
								<td>{ formatCurrency( appointment.deposit_amount ) }</td>
								<td>{ formatCurrency( appointment.balance_due ) }</td>
								<td>
									{ null === appointment.balance_collected_at ? (
										<Button
											variant="secondary"
											disabled={ collectingId === appointment.id }
											onClick={ () => handleMarkCollected( appointment.id ) }
										>
											{ __( 'Marcar cobrado', 'booking-plugin' ) }
										</Button>
									) : (
										__( 'Cobrado', 'booking-plugin' )
									) }
								</td>
							</tr>
						) ) }
						{ 0 === appointments.length && (
							<tr>
								<td colSpan={ 7 }>
									{ __( 'No hay saldos pendientes para mostrar.', 'booking-plugin' ) }
								</td>
							</tr>
						) }
					</tbody>
				</table>
			) }
		</div>
	);
}
