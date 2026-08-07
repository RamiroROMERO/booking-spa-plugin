import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Modal, Button, TextControl, CheckboxControl, Notice } from '@wordpress/components';

import WeeklyScheduleEditor, {
	createEmptyWeeklySchedule,
	scheduleRowsFromStaffSchedules,
	staffSchedulesFromRows,
} from './components/WeeklyScheduleEditor';
import ExceptionsEditor from './components/ExceptionsEditor';
import { API_NAMESPACE } from './utils';
import { getApiErrorMessage } from './utils/apiError';

export default function StaffFormModal( { staffId, services, onClose, onSaved } ) {
	const isEditing = Boolean( staffId );

	const [ isLoading, setIsLoading ] = useState( isEditing );
	const [ loadError, setLoadError ] = useState( null );
	const [ name, setName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ phone, setPhone ] = useState( '' );
	const [ status, setStatus ] = useState( 'active' );
	const [ serviceIds, setServiceIds ] = useState( [] );
	const [ scheduleRows, setScheduleRows ] = useState( createEmptyWeeklySchedule() );
	const [ exceptions, setExceptions ] = useState( [] );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ validationError, setValidationError ] = useState( null );

	// Aunque el listado de staff ya trae schedules/exceptions para un admin
	// autenticado, esta pantalla vuelve a pedir el registro individual al
	// abrir el formulario: es la mitigacion explicita del riesgo de que
	// "Guardar" se habilite antes de tener los datos mas recientes y termine
	// reemplazando schedules/exceptions/service_ids con un payload vacio o
	// desactualizado (PUT /staff/{id} reemplaza esos campos por completo).
	useEffect( () => {
		if ( ! isEditing ) {
			return;
		}

		apiFetch( { path: `${ API_NAMESPACE }/staff/${ staffId }` } )
			.then( ( result ) => {
				setName( result.name );
				setEmail( result.email );
				setPhone( result.phone || '' );
				setStatus( result.status );
				setServiceIds( result.service_ids || [] );
				setScheduleRows( scheduleRowsFromStaffSchedules( result.schedules || [] ) );
				setExceptions( result.exceptions || [] );
			} )
			.catch( ( err ) => setLoadError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ staffId, isEditing ] );

	const toggleService = ( serviceId ) => {
		setServiceIds( ( current ) =>
			current.includes( serviceId )
				? current.filter( ( id ) => id !== serviceId )
				: [ ...current, serviceId ]
		);
	};

	const handleSubmit = () => {
		setValidationError( null );

		if ( '' === name.trim() ) {
			setValidationError( __( 'El nombre es obligatorio.', 'booking-plugin' ) );
			return;
		}

		if ( '' === email.trim() ) {
			setValidationError( __( 'El email es obligatorio.', 'booking-plugin' ) );
			return;
		}

		setIsSaving( true );
		setError( null );

		const data = {
			name: name.trim(),
			email: email.trim(),
			phone: phone || null,
			service_ids: serviceIds,
			schedules: staffSchedulesFromRows( scheduleRows ),
			exceptions,
		};

		if ( isEditing ) {
			data.status = status;
		}

		const path = isEditing ? `${ API_NAMESPACE }/staff/${ staffId }` : `${ API_NAMESPACE }/staff`;

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
			title={ isEditing ? __( 'Editar staff', 'booking-plugin' ) : __( 'Nuevo staff', 'booking-plugin' ) }
			onRequestClose={ onClose }
			className="booking-plugin-staff-modal"
		>
			{ loadError && (
				<Notice status="error" isDismissible={ false }>
					{ loadError }
				</Notice>
			) }
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

			{ isLoading && <p>{ __( 'Cargando…', 'booking-plugin' ) }</p> }

			{ ! isLoading && (
				<>
					<TextControl label={ __( 'Nombre', 'booking-plugin' ) } value={ name } onChange={ setName } />
					<TextControl
						label={ __( 'Email', 'booking-plugin' ) }
						type="email"
						value={ email }
						onChange={ setEmail }
					/>
					<TextControl
						label={ __( 'Teléfono', 'booking-plugin' ) }
						value={ phone }
						onChange={ setPhone }
					/>

					{ isEditing && (
						<CheckboxControl
							label={ __( 'Activo', 'booking-plugin' ) }
							checked={ 'active' === status }
							onChange={ ( checked ) => setStatus( checked ? 'active' : 'inactive' ) }
						/>
					) }

					<h3>{ __( 'Servicios que puede realizar', 'booking-plugin' ) }</h3>
					<div className="booking-plugin-staff-modal__services">
						{ services.map( ( service ) => (
							<CheckboxControl
								key={ service.id }
								label={ service.name }
								checked={ serviceIds.includes( service.id ) }
								onChange={ () => toggleService( service.id ) }
							/>
						) ) }
						{ 0 === services.length && (
							<p>{ __( 'No hay servicios activos todavía.', 'booking-plugin' ) }</p>
						) }
					</div>

					<h3>{ __( 'Horario semanal', 'booking-plugin' ) }</h3>
					<WeeklyScheduleEditor value={ scheduleRows } onChange={ setScheduleRows } />

					<h3>{ __( 'Excepciones', 'booking-plugin' ) }</h3>
					<ExceptionsEditor value={ exceptions } onChange={ setExceptions } />

					<Button
						variant="primary"
						disabled={ isSaving || Boolean( loadError ) }
						onClick={ handleSubmit }
					>
						{ __( 'Guardar', 'booking-plugin' ) }
					</Button>
				</>
			) }
		</Modal>
	);
}
