import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { API_NAMESPACE } from './utils';
import { getApiErrorMessage } from './utils/apiError';

export default function ServiceStep( { categoryId, onSelectCategory, onSelectService } ) {
	const [ categories, setCategories ] = useState( [] );
	const [ services, setServices ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setIsLoading( true );

		Promise.all( [
			apiFetch( { path: `${ API_NAMESPACE }/categories?per_page=100` } ),
			apiFetch( { path: `${ API_NAMESPACE }/services?per_page=100` } ),
		] )
			.then( ( [ categoriesResult, servicesResult ] ) => {
				setCategories( categoriesResult );
				setServices( servicesResult );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [] );

	const visibleServices = categoryId
		? services.filter( ( service ) => service.category_id === categoryId )
		: services;

	if ( isLoading ) {
		return <p>{ __( 'Cargando servicios…', 'booking-plugin' ) }</p>;
	}

	if ( error ) {
		return <p className="booking-plugin-widget__error">{ error }</p>;
	}

	return (
		<div className="booking-plugin-widget__service-step">
			{ categories.length > 0 && (
				<div className="booking-plugin-widget__categories">
					<button
						type="button"
						className={
							'booking-plugin-widget__category' + ( ! categoryId ? ' is-active' : '' )
						}
						onClick={ () => onSelectCategory( null ) }
					>
						{ __( 'Todas', 'booking-plugin' ) }
					</button>
					{ categories.map( ( category ) => (
						<button
							type="button"
							key={ category.id }
							className={
								'booking-plugin-widget__category' +
								( categoryId === category.id ? ' is-active' : '' )
							}
							onClick={ () => onSelectCategory( category.id ) }
						>
							{ category.name }
						</button>
					) ) }
				</div>
			) }

			<div className="booking-plugin-widget__service-grid">
				{ visibleServices.map( ( service ) => (
					<button
						type="button"
						key={ service.id }
						className="booking-plugin-widget__service-card"
						onClick={ () => onSelectService( service ) }
					>
						<span className="booking-plugin-widget__service-name">{ service.name }</span>
						<span className="booking-plugin-widget__service-meta">
							{ service.duration_minutes } { __( 'min', 'booking-plugin' ) } — $
							{ service.price }
						</span>
						{ service.description && (
							<span className="booking-plugin-widget__service-description">
								{ service.description }
							</span>
						) }
					</button>
				) ) }
				{ 0 === visibleServices.length && (
					<p>{ __( 'No hay servicios disponibles en esta categoría.', 'booking-plugin' ) }</p>
				) }
			</div>
		</div>
	);
}
