import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button, ToggleControl, Notice } from '@wordpress/components';

import CategoriesManager from '../CategoriesManager';
import ServiceFormModal from '../ServiceFormModal';
import { API_NAMESPACE } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

export default function ServicesPage() {
	const [ categories, setCategories ] = useState( [] );
	const [ services, setServices ] = useState( [] );
	const [ showInactive, setShowInactive ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ formTarget, setFormTarget ] = useState( null );
	const [ isFormOpen, setIsFormOpen ] = useState( false );

	const loadCategories = useCallback( () => {
		return apiFetch( { path: `${ API_NAMESPACE }/categories?per_page=100` } ).then( setCategories );
	}, [] );

	const loadServices = useCallback( () => {
		setIsLoading( true );

		const status = showInactive ? 'inactive' : 'active';

		return apiFetch( { path: `${ API_NAMESPACE }/services?status=${ status }&per_page=100` } )
			.then( ( result ) => {
				setServices( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ showInactive ] );

	useEffect( () => {
		loadCategories().catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	}, [ loadCategories ] );

	useEffect( () => {
		loadServices();
	}, [ loadServices ] );

	const toggleStatus = ( service ) => {
		const newStatus = 'active' === service.status ? 'inactive' : 'active';

		apiFetch( {
			path: `${ API_NAMESPACE }/services/${ service.id }`,
			method: 'PUT',
			data: { status: newStatus },
		} )
			.then( () => loadServices() )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	};

	const openCreateForm = () => {
		setFormTarget( null );
		setIsFormOpen( true );
	};

	const openEditForm = ( service ) => {
		setFormTarget( service );
		setIsFormOpen( true );
	};

	const categoryName = ( categoryId ) => {
		const category = categories.find( ( item ) => item.id === categoryId );
		return category ? category.name : __( '(sin categoría)', 'booking-plugin' );
	};

	return (
		<div className="booking-plugin-admin">
			<h1>{ __( 'Servicios', 'booking-plugin' ) }</h1>

			{ error && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<CategoriesManager categories={ categories } onChange={ loadCategories } />

			<hr />

			<div className="booking-plugin-services__toolbar">
				<Button variant="primary" onClick={ openCreateForm }>
					{ __( 'Nuevo servicio', 'booking-plugin' ) }
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
							<th>{ __( 'Categoría', 'booking-plugin' ) }</th>
							<th>{ __( 'Precio', 'booking-plugin' ) }</th>
							<th>{ __( 'Duración', 'booking-plugin' ) }</th>
							<th>{ __( 'Pago online', 'booking-plugin' ) }</th>
							<th>{ __( 'Estado', 'booking-plugin' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ services.map( ( service ) => (
							<tr key={ service.id }>
								<td>{ service.name }</td>
								<td>{ categoryName( service.category_id ) }</td>
								<td>{ service.price }</td>
								<td>{ service.duration_minutes } min</td>
								<td>
									{ service.requires_payment
										? __( 'Sí', 'booking-plugin' )
										: __( 'No', 'booking-plugin' ) }
								</td>
								<td>
									{ 'active' === service.status
										? __( 'Activo', 'booking-plugin' )
										: __( 'Inactivo', 'booking-plugin' ) }
								</td>
								<td>
									<Button variant="link" onClick={ () => openEditForm( service ) }>
										{ __( 'Editar', 'booking-plugin' ) }
									</Button>{ ' ' }
									<Button
										variant="link"
										isDestructive={ 'active' === service.status }
										onClick={ () => toggleStatus( service ) }
									>
										{ 'active' === service.status
											? __( 'Desactivar', 'booking-plugin' )
											: __( 'Activar', 'booking-plugin' ) }
									</Button>
								</td>
							</tr>
						) ) }
						{ 0 === services.length && (
							<tr>
								<td colSpan={ 7 }>{ __( 'No hay servicios para mostrar.', 'booking-plugin' ) }</td>
							</tr>
						) }
					</tbody>
				</table>
			) }

			{ isFormOpen && (
				<ServiceFormModal
					service={ formTarget }
					categories={ categories }
					onClose={ () => setIsFormOpen( false ) }
					onSaved={ loadServices }
				/>
			) }
		</div>
	);
}
