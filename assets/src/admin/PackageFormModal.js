import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import {
	Modal,
	Button,
	TextControl,
	SelectControl,
	CheckboxControl,
	Notice,
} from '@wordpress/components';

import { API_NAMESPACE } from './utils';
import { getApiErrorMessage } from './utils/apiError';

export default function PackageFormModal( { pkg, services, onClose, onSaved } ) {
	const isEditing = Boolean( pkg );

	const [ name, setName ] = useState( pkg ? pkg.name : '' );
	const [ totalSessions, setTotalSessions ] = useState( pkg ? String( pkg.total_sessions ) : '1' );
	const [ price, setPrice ] = useState( pkg ? String( pkg.price ) : '0' );
	const [ status, setStatus ] = useState( pkg ? pkg.status : 'active' );
	const [ packageServices, setPackageServices ] = useState( pkg ? pkg.services : [] );
	const [ newServiceId, setNewServiceId ] = useState( '' );
	const [ newCreditCost, setNewCreditCost ] = useState( '1' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ validationError, setValidationError ] = useState( null );

	const availableServices = services.filter(
		( service ) => ! packageServices.some( ( item ) => item.service_id === service.id )
	);

	const serviceOptions = [
		{ label: __( 'Elegir servicio…', 'booking-plugin' ), value: '' },
		...availableServices.map( ( service ) => ( { label: service.name, value: String( service.id ) } ) ),
	];

	const serviceName = ( serviceId ) => {
		const service = services.find( ( item ) => item.id === serviceId );
		return service ? service.name : `#${ serviceId }`;
	};

	const addService = () => {
		if ( '' === newServiceId ) {
			return;
		}

		setPackageServices( ( current ) => [
			...current,
			{ service_id: parseInt( newServiceId, 10 ), credit_cost: parseInt( newCreditCost, 10 ) || 1 },
		] );
		setNewServiceId( '' );
		setNewCreditCost( '1' );
	};

	const removeService = ( serviceId ) => {
		setPackageServices( ( current ) => current.filter( ( item ) => item.service_id !== serviceId ) );
	};

	const updateCreditCost = ( serviceId, value ) => {
		setPackageServices( ( current ) =>
			current.map( ( item ) =>
				item.service_id === serviceId
					? { ...item, credit_cost: parseInt( value, 10 ) || 1 }
					: item
			)
		);
	};

	const handleSubmit = () => {
		setValidationError( null );

		if ( '' === name.trim() ) {
			setValidationError( __( 'El nombre es obligatorio.', 'booking-plugin' ) );
			return;
		}

		const sessions = parseInt( totalSessions, 10 );

		if ( ! sessions || sessions < 1 ) {
			setValidationError( __( 'Las sesiones totales deben ser mayores a 0.', 'booking-plugin' ) );
			return;
		}

		if ( 0 === packageServices.length ) {
			setValidationError( __( 'Agregá al menos un servicio incluido.', 'booking-plugin' ) );
			return;
		}

		setIsSaving( true );
		setError( null );

		const data = {
			name: name.trim(),
			total_sessions: sessions,
			price: parseFloat( price ) || 0,
			services: packageServices,
		};

		if ( isEditing ) {
			data.status = status;
		}

		const path = isEditing ? `${ API_NAMESPACE }/packages/${ pkg.id }` : `${ API_NAMESPACE }/packages`;

		apiFetch( { path, method: isEditing ? 'PUT' : 'POST', data } )
			.then( () => {
				onSaved();
				onClose();
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSaving( false ) );
	};

	return (
		<Modal
			title={
				isEditing ? __( 'Editar paquete', 'booking-plugin' ) : __( 'Nuevo paquete', 'booking-plugin' )
			}
			onRequestClose={ onClose }
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ validationError && (
				<Notice status="error" isDismissible={ false }>
					{ validationError }
				</Notice>
			) }

			<TextControl label={ __( 'Nombre', 'booking-plugin' ) } value={ name } onChange={ setName } />
			<TextControl
				label={ __( 'Sesiones totales', 'booking-plugin' ) }
				type="number"
				min="1"
				value={ totalSessions }
				onChange={ setTotalSessions }
			/>
			<TextControl
				label={ __( 'Precio (informativo)', 'booking-plugin' ) }
				type="number"
				min="0"
				step="0.01"
				value={ price }
				onChange={ setPrice }
			/>

			{ isEditing && (
				<CheckboxControl
					label={ __( 'Activo', 'booking-plugin' ) }
					checked={ 'active' === status }
					onChange={ ( checked ) => setStatus( checked ? 'active' : 'inactive' ) }
				/>
			) }

			<h3>{ __( 'Servicios incluidos', 'booking-plugin' ) }</h3>

			<div className="booking-plugin-package-services">
				<div className="booking-plugin-package-services__list">
					{ packageServices.map( ( item ) => (
						<div key={ item.service_id } className="booking-plugin-package-services__card">
							<span>{ serviceName( item.service_id ) }</span>
							<TextControl
								label={ __( 'Costo en créditos', 'booking-plugin' ) }
								type="number"
								min="1"
								value={ String( item.credit_cost ) }
								onChange={ ( value ) => updateCreditCost( item.service_id, value ) }
							/>
							<Button variant="link" isDestructive onClick={ () => removeService( item.service_id ) }>
								{ __( 'Quitar', 'booking-plugin' ) }
							</Button>
						</div>
					) ) }
					{ 0 === packageServices.length && (
						<p>{ __( 'Todavía no agregaste servicios a este paquete.', 'booking-plugin' ) }</p>
					) }
				</div>

				<div className="booking-plugin-package-services__form">
					<SelectControl
						label={ __( 'Servicio', 'booking-plugin' ) }
						value={ newServiceId }
						options={ serviceOptions }
						onChange={ setNewServiceId }
					/>
					<TextControl
						label={ __( 'Costo en créditos', 'booking-plugin' ) }
						type="number"
						min="1"
						value={ newCreditCost }
						onChange={ setNewCreditCost }
					/>
					<Button variant="secondary" disabled={ '' === newServiceId } onClick={ addService }>
						{ __( 'Agregar servicio', 'booking-plugin' ) }
					</Button>
				</div>
			</div>

			<Button variant="primary" disabled={ isSaving } onClick={ handleSubmit }>
				{ __( 'Guardar', 'booking-plugin' ) }
			</Button>
		</Modal>
	);
}
