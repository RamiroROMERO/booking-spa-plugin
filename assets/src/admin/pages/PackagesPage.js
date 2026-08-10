import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button, ToggleControl, Notice, TabPanel } from '@wordpress/components';

import PackageFormModal from '../PackageFormModal';
import UserCreditsTab from '../components/UserCreditsTab';
import { API_NAMESPACE } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

function PackagesTab() {
	const [ packages, setPackages ] = useState( [] );
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

	const loadPackages = useCallback( () => {
		setIsLoading( true );

		const status = showInactive ? 'inactive' : 'active';

		return apiFetch( { path: `${ API_NAMESPACE }/packages?status=${ status }` } )
			.then( ( result ) => {
				setPackages( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ showInactive ] );

	useEffect( () => {
		loadServices().catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	}, [ loadServices ] );

	useEffect( () => {
		loadPackages();
	}, [ loadPackages ] );

	const toggleStatus = ( pkg ) => {
		const newStatus = 'active' === pkg.status ? 'inactive' : 'active';

		apiFetch( {
			path: `${ API_NAMESPACE }/packages/${ pkg.id }`,
			method: 'PUT',
			data: { status: newStatus },
		} )
			.then( () => loadPackages() )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	};

	const openCreateForm = () => {
		setFormTarget( null );
		setIsFormOpen( true );
	};

	const openEditForm = ( pkg ) => {
		setFormTarget( pkg );
		setIsFormOpen( true );
	};

	return (
		<div>
			{ error && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<div className="booking-plugin-services__toolbar">
				<Button variant="primary" onClick={ openCreateForm }>
					{ __( 'Nuevo paquete', 'booking-plugin' ) }
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
							<th>{ __( 'Sesiones', 'booking-plugin' ) }</th>
							<th>{ __( 'Precio', 'booking-plugin' ) }</th>
							<th>{ __( 'Servicios incluidos', 'booking-plugin' ) }</th>
							<th>{ __( 'Estado', 'booking-plugin' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ packages.map( ( pkg ) => (
							<tr key={ pkg.id }>
								<td>{ pkg.name }</td>
								<td>{ pkg.total_sessions }</td>
								<td>{ pkg.price }</td>
								<td>{ pkg.services.length }</td>
								<td>
									{ 'active' === pkg.status
										? __( 'Activo', 'booking-plugin' )
										: __( 'Inactivo', 'booking-plugin' ) }
								</td>
								<td>
									<Button variant="link" onClick={ () => openEditForm( pkg ) }>
										{ __( 'Editar', 'booking-plugin' ) }
									</Button>{ ' ' }
									<Button
										variant="link"
										isDestructive={ 'active' === pkg.status }
										onClick={ () => toggleStatus( pkg ) }
									>
										{ 'active' === pkg.status
											? __( 'Desactivar', 'booking-plugin' )
											: __( 'Activar', 'booking-plugin' ) }
									</Button>
								</td>
							</tr>
						) ) }
						{ 0 === packages.length && (
							<tr>
								<td colSpan={ 6 }>{ __( 'No hay paquetes para mostrar.', 'booking-plugin' ) }</td>
							</tr>
						) }
					</tbody>
				</table>
			) }

			{ isFormOpen && (
				<PackageFormModal
					pkg={ formTarget }
					services={ services }
					onClose={ () => setIsFormOpen( false ) }
					onSaved={ loadPackages }
				/>
			) }
		</div>
	);
}

export default function PackagesPage() {
	return (
		<div className="booking-plugin-admin">
			<h1>{ __( 'Paquetes', 'booking-plugin' ) }</h1>

			<TabPanel
				className="booking-plugin-packages-tabs"
				tabs={ [
					{ name: 'packages', title: __( 'Paquetes', 'booking-plugin' ) },
					{ name: 'credits', title: __( 'Créditos de clientes', 'booking-plugin' ) },
				] }
			>
				{ ( tab ) => ( 'credits' === tab.name ? <UserCreditsTab /> : <PackagesTab /> ) }
			</TabPanel>
		</div>
	);
}
