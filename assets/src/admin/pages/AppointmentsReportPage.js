import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { TextControl, SelectControl, Button, Notice } from '@wordpress/components';

import { API_NAMESPACE, formatDateUS, formatTimeRange } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

const STATUS_LABELS = {
	pending: __( 'Pendiente', 'booking-plugin' ),
	confirmed: __( 'Confirmada', 'booking-plugin' ),
	completed: __( 'Completada', 'booking-plugin' ),
	no_show: __( 'No asistió', 'booking-plugin' ),
	cancelled: __( 'Cancelada', 'booking-plugin' ),
};

const PER_PAGE = 50;

export default function AppointmentsReportPage() {
	const [ dateFrom, setDateFrom ] = useState( '' );
	const [ dateTo, setDateTo ] = useState( '' );
	const [ staffId, setStaffId ] = useState( '' );
	const [ serviceId, setServiceId ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );

	const [ staff, setStaff ] = useState( [] );
	const [ staffOptions, setStaffOptions ] = useState( [] );
	const [ services, setServices ] = useState( [] );
	const [ serviceOptions, setServiceOptions ] = useState( [] );

	const [ appointments, setAppointments ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isExporting, setIsExporting ] = useState( false );
	const [ error, setError ] = useState( null );

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
			.then( ( result ) => {
				setServices( result );
				setServiceOptions( [
					{ label: __( 'Todos', 'booking-plugin' ), value: '' },
					...result.map( ( service ) => ( { label: service.name, value: String( service.id ) } ) ),
				] );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	}, [] );

	const statusOptions = [
		{ label: __( 'Todos', 'booking-plugin' ), value: '' },
		...Object.entries( STATUS_LABELS ).map( ( [ value, label ] ) => ( { label, value } ) ),
	];

	const updateDateFrom = ( value ) => {
		setPage( 1 );
		setDateFrom( value );
	};

	const updateDateTo = ( value ) => {
		setPage( 1 );
		setDateTo( value );
	};

	const updateStaffId = ( value ) => {
		setPage( 1 );
		setStaffId( value );
	};

	const updateServiceId = ( value ) => {
		setPage( 1 );
		setServiceId( value );
	};

	const updateStatus = ( value ) => {
		setPage( 1 );
		setStatus( value );
	};

	const updateSearch = ( value ) => {
		setPage( 1 );
		setSearch( value );
	};

	const buildQueryParams = useCallback( ( overridePage, overridePerPage ) => {
		const queryParams = new URLSearchParams( {
			date_from: dateFrom,
			date_to: dateTo,
			exclude_blocked: 'true',
			per_page: String( overridePerPage || PER_PAGE ),
			page: String( overridePage || page ),
		} );

		if ( staffId ) {
			queryParams.set( 'staff_id', staffId );
		}

		if ( serviceId ) {
			queryParams.set( 'service_id', serviceId );
		}

		if ( status ) {
			queryParams.set( 'status', status );
		}

		if ( search ) {
			queryParams.set( 'search', search );
		}

		return queryParams;
	}, [ dateFrom, dateTo, staffId, serviceId, status, search, page ] );

	const loadAppointments = useCallback( () => {
		if ( ! dateFrom || ! dateTo ) {
			setAppointments( [] );
			setTotal( 0 );
			setTotalPages( 1 );
			return;
		}

		setIsLoading( true );

		const queryParams = buildQueryParams();

		apiFetch( { path: `${ API_NAMESPACE }/appointments?${ queryParams.toString() }`, parse: false } )
			.then( ( response ) =>
				response.json().then( ( result ) => ( {
					result,
					total: parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 ),
					totalPages: parseInt( response.headers.get( 'X-WP-TotalPages' ) || '1', 10 ),
				} ) )
			)
			.then( ( { result, total: newTotal, totalPages: newTotalPages } ) => {
				setAppointments( result );
				setTotal( newTotal );
				setTotalPages( newTotalPages );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ dateFrom, dateTo, buildQueryParams ] );

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

	const clientName = ( appointment ) => appointment.client_name || __( 'Reserva', 'booking-plugin' );

	const hasDateRange = dateFrom && dateTo;

	const EXPORT_PER_PAGE = 100;

	const fetchExportPage = ( pageNumber, accumulated ) => {
		const queryParams = buildQueryParams( pageNumber, EXPORT_PER_PAGE );

		return apiFetch( { path: `${ API_NAMESPACE }/appointments?${ queryParams.toString() }`, parse: false } )
			.then( ( response ) =>
				response.json().then( ( result ) => ( {
					result,
					totalPages: parseInt( response.headers.get( 'X-WP-TotalPages' ) || '1', 10 ),
				} ) )
			)
			.then( ( { result, totalPages: pagesCount } ) => {
				const combined = accumulated.concat( result );
				return pageNumber < pagesCount ? fetchExportPage( pageNumber + 1, combined ) : combined;
			} );
	};

	const exportCsv = () => {
		setIsExporting( true );
		setError( null );

		fetchExportPage( 1, [] )
			.then( ( allAppointments ) => {
				const header = [
					__( 'Cliente', 'booking-plugin' ),
					__( 'Servicio', 'booking-plugin' ),
					__( 'Staff', 'booking-plugin' ),
					__( 'Fecha', 'booking-plugin' ),
					__( 'Estado', 'booking-plugin' ),
				];

				const csvRows = allAppointments.map( ( appointment ) => [
					clientName( appointment ),
					serviceName( appointment.service_id ),
					staffName( appointment.staff_id ),
					`${ formatDateUS( appointment.start_datetime ) } ${ formatTimeRange( appointment.start_datetime, appointment.end_datetime ) }`,
					STATUS_LABELS[ appointment.status ] || appointment.status,
				] );

				const csvContent = [ header, ...csvRows ]
					.map( ( fields ) => fields.map( ( field ) => `"${ String( field ).replace( /"/g, '""' ) }"` ).join( ',' ) )
					.join( '\n' );

				const blob = new Blob( [ csvContent ], { type: 'text/csv;charset=utf-8;' } );
				const url = URL.createObjectURL( blob );
				const link = document.createElement( 'a' );
				link.href = url;
				link.download = `reporte-citas-${ dateFrom }-${ dateTo }.csv`;
				link.click();
				URL.revokeObjectURL( url );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsExporting( false ) );
	};

	return (
		<div className="booking-plugin-admin">
			<h1>{ __( 'Reporte de citas', 'booking-plugin' ) }</h1>

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
					onChange={ updateDateFrom }
				/>
				<TextControl
					type="date"
					label={ __( 'Hasta', 'booking-plugin' ) }
					value={ dateTo }
					onChange={ updateDateTo }
				/>
				<SelectControl
					label={ __( 'Staff', 'booking-plugin' ) }
					value={ staffId }
					options={ staffOptions }
					onChange={ updateStaffId }
				/>
				<SelectControl
					label={ __( 'Servicio', 'booking-plugin' ) }
					value={ serviceId }
					options={ serviceOptions }
					onChange={ updateServiceId }
				/>
				<SelectControl
					label={ __( 'Estado', 'booking-plugin' ) }
					value={ status }
					options={ statusOptions }
					onChange={ updateStatus }
				/>
				<TextControl
					label={ __( 'Buscar cliente', 'booking-plugin' ) }
					value={ search }
					onChange={ updateSearch }
					placeholder={ __( 'Nombre o email…', 'booking-plugin' ) }
				/>
			</div>

			{ ! hasDateRange && (
				<p>{ __( 'Seleccioná un rango de fechas (desde y hasta) para ver el reporte.', 'booking-plugin' ) }</p>
			) }

			{ ( hasDateRange && isLoading ) && <p>{ __( 'Cargando…', 'booking-plugin' ) }</p> }

			{ ( hasDateRange && ! isLoading ) && (
				<>
					<Button
						variant="secondary"
						disabled={ 0 === total || isExporting }
						onClick={ exportCsv }
						className="booking-plugin-report__export"
					>
						{ isExporting ? __( 'Exportando…', 'booking-plugin' ) : __( 'Exportar CSV', 'booking-plugin' ) }
					</Button>

					<table className="booking-plugin-table">
						<thead>
							<tr>
								<th>{ __( 'Cliente', 'booking-plugin' ) }</th>
								<th>{ __( 'Servicio', 'booking-plugin' ) }</th>
								<th>{ __( 'Staff', 'booking-plugin' ) }</th>
								<th>{ __( 'Fecha', 'booking-plugin' ) }</th>
								<th>{ __( 'Estado', 'booking-plugin' ) }</th>
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
									<td>{ STATUS_LABELS[ appointment.status ] || appointment.status }</td>
								</tr>
							) ) }
							{ 0 === appointments.length && (
								<tr>
									<td colSpan={ 5 }>
										{ __( 'No hay citas para mostrar con estos filtros.', 'booking-plugin' ) }
									</td>
								</tr>
							) }
						</tbody>
					</table>
				</>
			) }

			{ ( hasDateRange && ! isLoading && total > 0 ) && (
				<div className="booking-plugin-report__pagination">
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Anterior', 'booking-plugin' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: total pages, 3: total results */
							__( 'Página %1$d de %2$d (%3$d resultados)', 'booking-plugin' ),
							page,
							totalPages,
							total
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= totalPages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Siguiente', 'booking-plugin' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
