import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import {
	Modal,
	Button,
	TextControl,
	SelectControl,
	TextareaControl,
	ToggleControl,
	Notice,
} from '@wordpress/components';

import { API_NAMESPACE } from './utils';
import { getApiErrorMessage } from './utils/apiError';
import AddonsEditor from './components/AddonsEditor';

export default function ServiceFormModal( { service, categories, onClose, onSaved } ) {
	const isEditing = Boolean( service );

	const [ name, setName ] = useState( service ? service.name : '' );
	const [ categoryId, setCategoryId ] = useState(
		service && service.category_id ? String( service.category_id ) : ''
	);
	const [ price, setPrice ] = useState( service ? String( service.price ) : '0' );
	const [ durationMinutes, setDurationMinutes ] = useState(
		service ? String( service.duration_minutes ) : ''
	);
	const [ bufferMinutes, setBufferMinutes ] = useState(
		service ? String( service.buffer_minutes ) : '0'
	);
	const [ description, setDescription ] = useState( service ? service.description || '' : '' );
	const [ requiresPayment, setRequiresPayment ] = useState(
		service ? Boolean( service.requires_payment ) : false
	);
	const [ requiresDeposit, setRequiresDeposit ] = useState(
		service ? Boolean( service.requires_deposit ) : false
	);
	const [ depositPercentage, setDepositPercentage ] = useState(
		service && service.deposit_percentage ? String( service.deposit_percentage ) : ''
	);
	const [ imageId, setImageId ] = useState( service && service.image_id ? service.image_id : null );
	const [ imageUrl, setImageUrl ] = useState( service && service.image_url ? service.image_url : null );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ validationError, setValidationError ] = useState( null );

	const categoryOptions = [
		{ label: __( 'Sin categoría', 'booking-plugin' ), value: '' },
		...categories.map( ( category ) => ( {
			label: category.name,
			value: String( category.id ),
		} ) ),
	];

	const handleSubmit = () => {
		setValidationError( null );

		if ( '' === name.trim() ) {
			setValidationError( __( 'El nombre es obligatorio.', 'booking-plugin' ) );
			return;
		}

		const duration = parseInt( durationMinutes, 10 );

		if ( ! duration || duration <= 0 ) {
			setValidationError( __( 'La duración debe ser mayor a 0.', 'booking-plugin' ) );
			return;
		}

		if ( requiresDeposit ) {
			const percentage = parseFloat( depositPercentage );

			if ( ! depositPercentage || isNaN( percentage ) || percentage < 1 || percentage > 99 ) {
				setValidationError(
					__( 'El porcentaje de depósito debe ser un número entre 1 y 99.', 'booking-plugin' )
				);
				return;
			}
		}

		setIsSaving( true );
		setError( null );

		const data = {
			name: name.trim(),
			category_id: categoryId ? parseInt( categoryId, 10 ) : null,
			price: parseFloat( price ) || 0,
			duration_minutes: duration,
			buffer_minutes: parseInt( bufferMinutes, 10 ) || 0,
			description: description || null,
			requires_payment: requiresPayment,
			requires_deposit: requiresDeposit,
			deposit_percentage: requiresDeposit ? parseFloat( depositPercentage ) : null,
			image_id: imageId,
		};

		const path = isEditing
			? `${ API_NAMESPACE }/services/${ service.id }`
			: `${ API_NAMESPACE }/services`;

		apiFetch( { path, method: isEditing ? 'PUT' : 'POST', data } )
			.then( () => {
				onSaved();
				onClose();
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSaving( false ) );
	};

	const openMediaLibrary = () => {
		const frame = window.wp.media( {
			title: __( 'Seleccionar imagen', 'booking-plugin' ),
			button: { text: __( 'Usar esta imagen', 'booking-plugin' ) },
			library: { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			setImageId( attachment.id );
			setImageUrl( attachment.url );
		} );

		frame.open();
	};

	const handleRemoveImage = () => {
		setImageId( null );
		setImageUrl( null );
	};

	const handleRequiresPaymentChange = ( value ) => {
		setRequiresPayment( value );

		if ( ! value ) {
			setRequiresDeposit( false );
		}
	};

	return (
		<Modal
			title={
				isEditing ? __( 'Editar servicio', 'booking-plugin' ) : __( 'Nuevo servicio', 'booking-plugin' )
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
			<SelectControl
				label={ __( 'Categoría', 'booking-plugin' ) }
				value={ categoryId }
				options={ categoryOptions }
				onChange={ setCategoryId }
			/>
			<TextControl
				label={ __( 'Precio', 'booking-plugin' ) }
				type="number"
				min="0"
				step="0.01"
				value={ price }
				onChange={ setPrice }
			/>
			<TextControl
				label={ __( 'Duración (minutos)', 'booking-plugin' ) }
				type="number"
				min="1"
				value={ durationMinutes }
				onChange={ setDurationMinutes }
			/>
			<TextControl
				label={ __( 'Buffer (minutos)', 'booking-plugin' ) }
				type="number"
				min="0"
				value={ bufferMinutes }
				onChange={ setBufferMinutes }
			/>
			<TextareaControl
				label={ __( 'Descripción', 'booking-plugin' ) }
				value={ description }
				onChange={ setDescription }
			/>
			<div className="booking-plugin-service-image-picker">
				<div className="booking-plugin-service-image-picker__preview">
					{ imageUrl ? (
						<img
							className="booking-plugin-service-image-picker__image"
							src={ imageUrl }
							alt=""
						/>
					) : (
						<div className="booking-plugin-service-image-picker__placeholder">
							<span className="dashicons dashicons-format-image" />
						</div>
					) }
				</div>
				<div className="booking-plugin-service-image-picker__actions">
					<Button variant="secondary" onClick={ openMediaLibrary }>
						{ __( 'Seleccionar imagen', 'booking-plugin' ) }
					</Button>
					{ imageId && (
						<Button variant="tertiary" isDestructive onClick={ handleRemoveImage }>
							{ __( 'Quitar imagen', 'booking-plugin' ) }
						</Button>
					) }
				</div>
			</div>
			<ToggleControl
				label={ __( 'Requiere pago online', 'booking-plugin' ) }
				help={ __(
					'Al reservar este servicio se generará un pedido de WooCommerce y se pedirá pagar antes de confirmar la cita.',
					'booking-plugin'
				) }
				checked={ requiresPayment }
				onChange={ handleRequiresPaymentChange }
			/>
			<ToggleControl
				label={ __( 'Requiere depósito', 'booking-plugin' ) }
				help={ __(
					'Al reservar, solo se cobra el porcentaje indicado del total; el resto queda como saldo pendiente que se gestiona manualmente desde la cita.',
					'booking-plugin'
				) }
				checked={ requiresDeposit }
				disabled={ ! requiresPayment }
				onChange={ setRequiresDeposit }
			/>
			{ requiresDeposit && (
				<TextControl
					label={ __( 'Porcentaje de depósito', 'booking-plugin' ) }
					type="number"
					min="1"
					max="99"
					value={ depositPercentage }
					onChange={ setDepositPercentage }
				/>
			) }

			<Button variant="primary" disabled={ isSaving } onClick={ handleSubmit }>
				{ __( 'Guardar', 'booking-plugin' ) }
			</Button>

			<hr />

			<AddonsEditor serviceId={ service ? service.id : null } />
		</Modal>
	);
}
