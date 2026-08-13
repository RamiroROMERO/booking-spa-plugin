import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { TextControl, SelectControl, Button, Notice } from '@wordpress/components';

import { API_NAMESPACE, formatCurrency } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

export default function PayrollPage() {
	const [ dateFrom, setDateFrom ] = useState( '' );
	const [ dateTo, setDateTo ] = useState( '' );
	const [ staffId, setStaffId ] = useState( '' );
	const [ staffOptions, setStaffOptions ] = useState( [] );
	const [ rows, setRows ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ updatingStaffId, setUpdatingStaffId ] = useState( null );

	const loadStaffOptions = useCallback( () => {
		return apiFetch( { path: `${ API_NAMESPACE }/staff?status=active&per_page=100` } )
			.then( ( result ) => {
				const options = [
					{ label: __( 'Todos', 'booking-plugin' ), value: '' },
					...result.map( ( member ) => ( {
						label: member.name,
						value: String( member.id ),
					} ) ),
				];
				setStaffOptions( options );
			} );
	}, [] );

	useEffect( () => {
		loadStaffOptions().catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	}, [ loadStaffOptions ] );

	const loadPayroll = useCallback( () => {
		if ( ! dateFrom || ! dateTo ) {
			setRows( [] );
			return;
		}

		setIsLoading( true );

		const queryParams = new URLSearchParams( {
			date_from: dateFrom,
			date_to: dateTo,
		} );

		if ( staffId ) {
			queryParams.append( 'staff_id', staffId );
		}

		apiFetch( { path: `${ API_NAMESPACE }/payroll?${ queryParams.toString() }` } )
			.then( ( result ) => {
				setRows( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ dateFrom, dateTo, staffId ] );

	useEffect( () => {
		loadPayroll();
	}, [ loadPayroll ] );

	const updatePaidStatus = ( row, markAsPaid ) => {
		setUpdatingStaffId( row.staff_id );
		setError( null );

		apiFetch( {
			path: `${ API_NAMESPACE }/payroll/${ markAsPaid ? 'mark-paid' : 'unmark-paid' }`,
			method: 'PATCH',
			data: {
				staff_id: row.staff_id,
				date_from: dateFrom,
				date_to: dateTo,
			},
		} )
			.then( () => loadPayroll() )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setUpdatingStaffId( null ) );
	};

	const exportCsv = () => {
		const header = [
			__( 'Staff', 'booking-plugin' ),
			__( 'Cantidad de citas', 'booking-plugin' ),
			__( 'Comisión total', 'booking-plugin' ),
			__( 'Pagado', 'booking-plugin' ),
		];

		const csvRows = rows.map( ( row ) => [
			row.staff_name,
			row.appointments_count,
			formatCurrency( row.total_commission ),
			`${ row.paid_count }/${ row.appointments_count }`,
		] );

		const csvContent = [ header, ...csvRows ]
			.map( ( fields ) => fields.map( ( field ) => `"${ String( field ).replace( /"/g, '""' ) }"` ).join( ',' ) )
			.join( '\n' );

		const blob = new Blob( [ csvContent ], { type: 'text/csv;charset=utf-8;' } );
		const url = URL.createObjectURL( blob );
		const link = document.createElement( 'a' );
		link.href = url;
		link.download = `nomina-${ dateFrom }-${ dateTo }.csv`;
		link.click();
		URL.revokeObjectURL( url );
	};

	return (
		<div className="booking-plugin-admin">
			<h1>{ __( 'Nómina', 'booking-plugin' ) }</h1>

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

			{ ( ! dateFrom || ! dateTo ) && (
				<p>{ __( 'Seleccioná un rango de fechas (desde y hasta) para ver la nómina.', 'booking-plugin' ) }</p>
			) }

			{ ( dateFrom && dateTo && isLoading ) && (
				<p>{ __( 'Cargando…', 'booking-plugin' ) }</p>
			) }

			{ ( dateFrom && dateTo && ! isLoading ) && (
				<>
					<Button
						variant="secondary"
						disabled={ 0 === rows.length }
						onClick={ exportCsv }
						className="booking-plugin-payroll__export"
					>
						{ __( 'Exportar CSV', 'booking-plugin' ) }
					</Button>

					<table className="booking-plugin-table">
						<thead>
							<tr>
								<th>{ __( 'Staff', 'booking-plugin' ) }</th>
								<th>{ __( 'Cantidad de citas', 'booking-plugin' ) }</th>
								<th>{ __( 'Comisión total', 'booking-plugin' ) }</th>
								<th>{ __( 'Pagado', 'booking-plugin' ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.staff_id }>
									<td>{ row.staff_name }</td>
									<td>{ row.appointments_count }</td>
									<td>{ formatCurrency( row.total_commission ) }</td>
									<td>
										{ row.paid_count }/{ row.appointments_count }{ ' ' }
										{ __( 'pagadas', 'booking-plugin' ) }
									</td>
									<td>
										{ row.paid_count < row.appointments_count && (
											<Button
												variant="secondary"
												disabled={ updatingStaffId === row.staff_id }
												onClick={ () => updatePaidStatus( row, true ) }
											>
												{ __( 'Marcar pagada', 'booking-plugin' ) }
											</Button>
										) }{ ' ' }
										{ row.paid_count > 0 && (
											<Button
												variant="tertiary"
												disabled={ updatingStaffId === row.staff_id }
												onClick={ () => updatePaidStatus( row, false ) }
											>
												{ __( 'Desmarcar pagada', 'booking-plugin' ) }
											</Button>
										) }
									</td>
								</tr>
							) ) }
							{ 0 === rows.length && (
								<tr>
									<td colSpan={ 5 }>
										{ __( 'No hay comisiones para mostrar en este rango.', 'booking-plugin' ) }
									</td>
								</tr>
							) }
						</tbody>
					</table>
				</>
			) }
		</div>
	);
}
