import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import { API_NAMESPACE } from './utils';
import { getApiErrorMessage } from './utils/apiError';
import useMyCredits from './hooks/useMyCredits';

export default function ServiceStep( { categoryId, currentUser, onSelectCategory, onSelectService } ) {
	const [ categories, setCategories ] = useState( [] );
	const [ services, setServices ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ pendingService, setPendingService ] = useState( null );

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

	const { credits: pendingCredits, isLoading: isCheckingCredits } = useMyCredits(
		pendingService && pendingService.id,
		Boolean( currentUser )
	);

	// Ya se resolvio si el servicio recien elegido tiene saldo aplicable: si
	// no hay ningun credito ofrecible (o no hay usuario logueado), se avanza
	// directo sin mostrar el aviso.
	useEffect( () => {
		if ( ! pendingService || isCheckingCredits ) {
			return;
		}

		if ( 0 === pendingCredits.length ) {
			const service = pendingService;
			setPendingService( null );
			onSelectService( service, null );
		}
	}, [ pendingService, isCheckingCredits, pendingCredits ] );

	const visibleServices = categoryId
		? services.filter( ( service ) => service.category_id === categoryId )
		: services;

	const handlePick = ( service ) => {
		if ( ! currentUser ) {
			onSelectService( service, null );
			return;
		}

		setPendingService( service );
	};

	const handleUseCredit = ( credit ) => {
		const service = pendingService;
		setPendingService( null );
		onSelectService( service, credit );
	};

	const handleSkipCredit = () => {
		const service = pendingService;
		setPendingService( null );
		onSelectService( service, null );
	};

	if ( isLoading ) {
		return <p>{ __( 'Cargando servicios…', 'booking-plugin' ) }</p>;
	}

	if ( error ) {
		return <p className="booking-plugin-widget__error">{ error }</p>;
	}

	if ( pendingService && isCheckingCredits ) {
		return <p>{ __( 'Comprobando tu saldo…', 'booking-plugin' ) }</p>;
	}

	if ( pendingService && pendingCredits.length > 0 ) {
		const credit = pendingCredits[ 0 ];

		return (
			<div className="booking-plugin-widget__credit-offer">
				<p>
					{ sprintf(
						/* translators: 1: sesiones disponibles, 2: nombre del paquete */
						__( 'Tienes %1$d sesiones disponibles de %2$s. ¿Usar una ahora?', 'booking-plugin' ),
						credit.remaining_sessions,
						credit.package_name
					) }
				</p>
				<button
					type="button"
					className="booking-plugin-widget__primary"
					onClick={ () => handleUseCredit( credit ) }
				>
					{ __( 'Usar una sesión de mi paquete', 'booking-plugin' ) }
				</button>
				<button
					type="button"
					className="booking-plugin-widget__secondary"
					onClick={ handleSkipCredit }
				>
					{ __( 'Reservar y pagar normalmente', 'booking-plugin' ) }
				</button>
			</div>
		);
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
						onClick={ () => handlePick( service ) }
					>
						{ service.image_url ? (
							<img
								className="booking-plugin-widget__service-image"
								src={ service.image_url }
								alt=""
							/>
						) : (
							<span className="booking-plugin-widget__service-image-placeholder" aria-hidden="true" />
						) }
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
