import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button, ToggleControl, Notice } from '@wordpress/components';

import StaffFormModal from '../StaffFormModal';
import { API_NAMESPACE } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

export default function StaffPage() {
	const [ staff, setStaff ] = useState( [] );
	const [ services, setServices ] = useState( [] );
	const [ showInactive, setShowInactive ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ formTarget, setFormTarget ] = useState( null );
	const [ isFormOpen, setIsFormOpen ] = useState( false );

	const loadServices = useCallback( () => {
		return apiFetch( { path: `${ API_NAMESPACE }/services?status=active&per_page=100` } ).then(
			setServices
		);
	}, [] );

	const loadStaff = useCallback( () => {
		setIsLoading( true );

		const status = showInactive ? 'inactive' : 'active';

		return apiFetch( { path: `${ API_NAMESPACE }/staff?status=${ status }&per_page=100` } )
			.then( ( result ) => {
				setStaff( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ showInactive ] );

	useEffect( () => {
		loadServices().catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	}, [ loadServices ] );

	useEffect( () => {
		loadStaff();
	}, [ loadStaff ] );

	const openCreateForm = () => {
		setFormTarget( null );
		setIsFormOpen( true );
	};

	const openEditForm = ( member ) => {
		setFormTarget( member.id );
		setIsFormOpen( true );
	};

	const toggleStatus = ( member ) => {
		const newStatus = 'active' === member.status ? 'inactive' : 'active';

		apiFetch( {
			path: `${ API_NAMESPACE }/staff/${ member.id }`,
			method: 'PUT',
			data: { status: newStatus },
		} )
			.then( () => loadStaff() )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	};

	return (
		<div className="booking-plugin-admin">
			<h1>{ __( 'Staff', 'booking-plugin' ) }</h1>

			{ error && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<div className="booking-plugin-services__toolbar">
				<Button variant="primary" onClick={ openCreateForm }>
					{ __( 'Nuevo staff', 'booking-plugin' ) }
				</Button>
				<ToggleControl
					label={ __( 'Mostrar inactivos', 'booking-plugin' ) }
					checked={ showInactive }
					onChange={ setShowInactive }
				/>
			</div>

			{ isLoading && <p>{ __( 'Cargando…', 'booking-plugin' ) }</p> }

			{ ! isLoading && (
				<table className="booking-plugin-table">
					<thead>
						<tr>
							<th>{ __( 'Nombre', 'booking-plugin' ) }</th>
							<th>{ __( 'Servicios', 'booking-plugin' ) }</th>
							<th>{ __( 'Estado', 'booking-plugin' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ staff.map( ( member ) => (
							<tr key={ member.id }>
								<td>{ member.name }</td>
								<td>{ member.service_ids.length }</td>
								<td>
									{ 'active' === member.status
										? __( 'Activo', 'booking-plugin' )
										: __( 'Inactivo', 'booking-plugin' ) }
								</td>
								<td>
									<Button variant="link" onClick={ () => openEditForm( member ) }>
										{ __( 'Editar', 'booking-plugin' ) }
									</Button>{ ' ' }
									<Button
										variant="link"
										isDestructive={ 'active' === member.status }
										onClick={ () => toggleStatus( member ) }
									>
										{ 'active' === member.status
											? __( 'Desactivar', 'booking-plugin' )
											: __( 'Activar', 'booking-plugin' ) }
									</Button>
								</td>
							</tr>
						) ) }
						{ 0 === staff.length && (
							<tr>
								<td colSpan={ 4 }>{ __( 'No hay staff para mostrar.', 'booking-plugin' ) }</td>
							</tr>
						) }
					</tbody>
				</table>
			) }

			{ isFormOpen && (
				<StaffFormModal
					staffId={ formTarget }
					services={ services }
					onClose={ () => setIsFormOpen( false ) }
					onSaved={ loadStaff }
				/>
			) }
		</div>
	);
}
