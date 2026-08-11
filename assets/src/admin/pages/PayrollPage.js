import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { TextControl, SelectControl, Notice } from '@wordpress/components';

import { API_NAMESPACE } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

export default function PayrollPage() {
	const [ dateFrom, setDateFrom ] = useState( '' );
	const [ dateTo, setDateTo ] = useState( '' );
	const [ staffId, setStaffId ] = useState( '' );
	const [ staffOptions, setStaffOptions ] = useState( [] );
	const [ rows, setRows ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );

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

	useEffect( () => {
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

	return (
		<div className="booking-plugin-admin">
			<h1>{ __( 'Nómina', 'booking-plugin' ) }</h1>

			{ error && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<div className="booking-plugin-payroll__filters">
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
				<table className="booking-plugin-table">
					<thead>
						<tr>
							<th>{ __( 'Staff', 'booking-plugin' ) }</th>
							<th>{ __( 'Cantidad de citas', 'booking-plugin' ) }</th>
							<th>{ __( 'Comisión total', 'booking-plugin' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.staff_id }>
								<td>{ row.staff_name }</td>
								<td>{ row.appointments_count }</td>
								<td>{ Number( row.total_commission ).toFixed( 2 ) }</td>
							</tr>
						) ) }
						{ 0 === rows.length && (
							<tr>
								<td colSpan={ 3 }>
									{ __( 'No hay comisiones para mostrar en este rango.', 'booking-plugin' ) }
								</td>
							</tr>
						) }
					</tbody>
				</table>
			) }
		</div>
	);
}
