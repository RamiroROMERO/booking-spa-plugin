import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Modal, Button, TextControl, SelectControl, CheckboxControl, Notice } from '@wordpress/components';
import { people } from '@wordpress/icons';

import { API_NAMESPACE, formatCurrency, formatDateUS, formatTime, today } from './utils';
import { getApiErrorMessage } from './utils/apiError';

// Mismo alcance que el wizard del widget publico (SPEC 06/11), mas la
// identificacion del cliente y el credito, ver SPEC 22. "addons"/"credit" se
// saltan solos cuando no aplican (servicio sin extras, o cliente/addons que
// no ofrecen credito), igual que App.js hace con su propio STEP_ORDER.
const STEP_ORDER = [ 'service', 'addons', 'staff', 'datetime', 'client', 'credit', 'notes' ];

export default function NewAppointmentModal( { onClose, onSaved } ) {
	const timezone = ( window.BookingPluginAdmin || {} ).timezone;

	const [ step, setStep ] = useState( 'service' );
	const [ error, setError ] = useState( null );
	const [ isSaving, setIsSaving ] = useState( false );

	const [ services, setServices ] = useState( [] );
	const [ isLoadingServices, setIsLoadingServices ] = useState( true );
	const [ serviceId, setServiceId ] = useState( '' );
	const [ pendingServiceId, setPendingServiceId ] = useState( '' );

	const [ addons, setAddons ] = useState( [] );
	const [ selectedAddonIds, setSelectedAddonIds ] = useState( [] );

	const [ staffOptions, setStaffOptions ] = useState( [] );
	const [ staffId, setStaffId ] = useState( null );

	const [ date, setDate ] = useState( today() );
	const [ slots, setSlots ] = useState( [] );
	const [ isLoadingSlots, setIsLoadingSlots ] = useState( false );
	const [ selectedSlot, setSelectedSlot ] = useState( null );
	const [ collisionNotice, setCollisionNotice ] = useState( null );

	const [ clientMode, setClientMode ] = useState( 'registered' );
	const [ search, setSearch ] = useState( '' );
	const [ searchResults, setSearchResults ] = useState( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const [ clientUser, setClientUser ] = useState( null );
	const [ guestName, setGuestName ] = useState( '' );
	const [ guestEmail, setGuestEmail ] = useState( '' );
	const [ guestPhone, setGuestPhone ] = useState( '' );

	const [ credits, setCredits ] = useState( [] );
	const [ isLoadingCredits, setIsLoadingCredits ] = useState( false );
	const [ useCreditId, setUseCreditId ] = useState( null );

	const [ notes, setNotes ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: `${ API_NAMESPACE }/services?status=active&per_page=100` } )
			.then( ( result ) => {
				setServices( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoadingServices( false ) );
	}, [] );

	useEffect( () => {
		if ( 'datetime' !== step || ! serviceId || ! date ) {
			return;
		}

		setIsLoadingSlots( true );

		const params = new URLSearchParams( { service_id: serviceId, date, timezone: timezone || '' } );

		if ( staffId ) {
			params.set( 'staff_id', staffId );
		}

		if ( selectedAddonIds.length > 0 ) {
			params.set( 'addon_ids', selectedAddonIds.join( ',' ) );
		}

		apiFetch( { path: `${ API_NAMESPACE }/availability?${ params.toString() }` } )
			.then( ( result ) => {
				setSlots( result.slots );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoadingSlots( false ) );
	}, [ step, serviceId, staffId, date, selectedAddonIds, timezone ] );

	const selectedService = services.find( ( service ) => service.id === serviceId ) || null;
	const selectedAddons = addons.filter( ( addon ) => selectedAddonIds.includes( addon.id ) );

	// --- Servicio ---
	const handleContinueFromService = () => {
		if ( ! pendingServiceId ) {
			return;
		}

		const numericId = parseInt( pendingServiceId, 10 );

		setServiceId( numericId );
		setSelectedAddonIds( [] );
		setStaffId( null );
		setDate( today() );
		setSelectedSlot( null );
		setCollisionNotice( null );
		setUseCreditId( null );
		setError( null );

		Promise.all( [
			apiFetch( { path: `${ API_NAMESPACE }/services/${ numericId }/addons` } ),
			apiFetch( { path: `${ API_NAMESPACE }/staff?service_id=${ numericId }&per_page=100` } ),
		] )
			.then( ( [ addonsResult, staffResult ] ) => {
				setAddons( addonsResult );
				setStaffOptions( staffResult );
				setStep( addonsResult.length > 0 ? 'addons' : 'staff' );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	};

	// --- Extras ---
	const toggleAddon = ( addonId ) => {
		setSelectedAddonIds( ( current ) =>
			current.includes( addonId ) ? current.filter( ( id ) => id !== addonId ) : [ ...current, addonId ]
		);
	};

	// --- Profesional ---
	const handleSelectStaff = ( id ) => {
		setStaffId( id );
		setDate( today() );
		setSelectedSlot( null );
		setCollisionNotice( null );
		setStep( 'datetime' );
	};

	// --- Fecha/hora ---
	// El slot trae su propio staff_id (aunque staffId sea null, "cualquier
	// disponible"): al confirmar se usa el de ESE slot, mismo criterio que
	// App.js/handleSelectSlot en el widget publico.
	const handleSelectSlot = ( slot ) => {
		setCollisionNotice( null );
		setSelectedSlot( slot );
		setStep( 'client' );
	};

	// --- Cliente ---
	const handleSearch = () => {
		if ( '' === search.trim() ) {
			return;
		}

		setIsSearching( true );

		apiFetch( { path: `/wp/v2/users?search=${ encodeURIComponent( search.trim() ) }&per_page=10` } )
			.then( ( result ) => {
				setSearchResults( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSearching( false ) );
	};

	const selectClientUser = ( user ) => {
		setClientUser( user );
		setSearch( '' );
		setSearchResults( [] );
	};

	const canContinueFromClient =
		( 'registered' === clientMode && null !== clientUser ) ||
		( 'guest' === clientMode && '' !== guestName.trim() && guestEmail.includes( '@' ) );

	const handleContinueFromClient = () => {
		if ( ! canContinueFromClient ) {
			return;
		}

		// Un credito nunca se combina con add-ons (misma regla que el motor
		// publico, SPEC 12) y solo existe para clientes registrados.
		if ( 'registered' === clientMode && 0 === selectedAddonIds.length ) {
			setIsLoadingCredits( true );

			apiFetch( {
				path: `${ API_NAMESPACE }/users/${ clientUser.id }/credits?service_id=${ serviceId }`,
			} )
				.then( ( result ) => {
					setCredits( result );
					setError( null );
					setStep( result.length > 0 ? 'credit' : 'notes' );
				} )
				.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
				.finally( () => setIsLoadingCredits( false ) );

			return;
		}

		setCredits( [] );
		setStep( 'notes' );
	};

	// --- Credito ---
	const handleUseCredit = ( credit ) => {
		setUseCreditId( credit.id );
		setStep( 'notes' );
	};

	const handleSkipCredit = () => {
		setUseCreditId( null );
		setStep( 'notes' );
	};

	// --- Atras ---
	const handleBack = () => {
		const currentIndex = STEP_ORDER.indexOf( step );

		if ( currentIndex <= 0 ) {
			return;
		}

		let prevIndex = currentIndex - 1;

		if ( 'addons' === STEP_ORDER[ prevIndex ] && 0 === addons.length ) {
			prevIndex -= 1;
		}

		if ( 'credit' === STEP_ORDER[ prevIndex ] && ( selectedAddonIds.length > 0 || 0 === credits.length ) ) {
			prevIndex -= 1;
		}

		setError( null );
		setStep( STEP_ORDER[ prevIndex ] );
	};

	// --- Envio final ---
	const handleSubmit = () => {
		setIsSaving( true );
		setError( null );

		const data = {
			is_manual_booking: true,
			service_id: serviceId,
			staff_id: selectedSlot.staff_id,
			start_datetime: selectedSlot.start_datetime,
			addon_ids: selectedAddonIds,
		};

		if ( 'registered' === clientMode ) {
			data.client_user_id = clientUser.id;
		} else {
			data.guest_name = guestName.trim();
			data.guest_email = guestEmail.trim();
			data.guest_phone = guestPhone;
		}

		if ( useCreditId ) {
			data.use_credit_id = useCreditId;
		}

		if ( notes ) {
			data.notes = notes;
		}

		apiFetch( { path: `${ API_NAMESPACE }/appointments`, method: 'POST', data } )
			.then( () => {
				onSaved();
				onClose();
			} )
			.catch( ( err ) => {
				// Mismo criterio que ConfirmationStep.js en el widget publico:
				// un 409 en este punto significa que el slot se ocupo entre que
				// se mostro y se confirmo, no un error de formulario.
				if ( err && err.data && 409 === err.data.status ) {
					setCollisionNotice(
						__(
							'Ese horario ya no está disponible: se ocupó mientras completabas el formulario. Elige otro horario.',
							'booking-plugin'
						)
					);
					setSelectedSlot( null );
					setStep( 'datetime' );
					return;
				}

				setError( getApiErrorMessage( err ) );
			} )
			.finally( () => setIsSaving( false ) );
	};

	const canGoBack = 'service' !== step;

	const addonsTotal = selectedAddons.reduce( ( sum, addon ) => sum + Number( addon.price ), 0 );
	const totalPrice = selectedService ? Number( selectedService.price ) + addonsTotal : 0;

	return (
		<Modal title={ __( 'Nueva cita', 'booking-plugin' ) } onRequestClose={ onClose } className="booking-plugin-modal__new-appointment">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ canGoBack && (
				<Button variant="link" onClick={ handleBack }>
					{ __( '← Atrás', 'booking-plugin' ) }
				</Button>
			) }

			{ 'service' === step && (
				<div className="booking-plugin-modal__new-appointment-step">
					{ isLoadingServices && <p>{ __( 'Cargando servicios…', 'booking-plugin' ) }</p> }
					{ ! isLoadingServices && (
						<>
							<SelectControl
								label={ __( 'Servicio', 'booking-plugin' ) }
								value={ pendingServiceId ? String( pendingServiceId ) : '' }
								options={ [
									{ label: __( 'Elegir servicio…', 'booking-plugin' ), value: '' },
									...services.map( ( service ) => ( {
										label: `${ service.name } — ${ formatCurrency( service.price ) }`,
										value: String( service.id ),
									} ) ),
								] }
								onChange={ setPendingServiceId }
							/>
							<Button variant="primary" disabled={ ! pendingServiceId } onClick={ handleContinueFromService }>
								{ __( 'Continuar', 'booking-plugin' ) }
							</Button>
						</>
					) }
				</div>
			) }

			{ 'addons' === step && (
				<div className="booking-plugin-modal__new-appointment-step">
					<p>{ __( 'Extras opcionales para este servicio:', 'booking-plugin' ) }</p>
					{ addons.map( ( addon ) => (
						<CheckboxControl
							key={ addon.id }
							label={ `${ addon.name } — ${ formatCurrency( addon.price ) } (+${ addon.extra_time_minutes } min)` }
							checked={ selectedAddonIds.includes( addon.id ) }
							onChange={ () => toggleAddon( addon.id ) }
						/>
					) ) }
					<Button variant="primary" onClick={ () => setStep( 'staff' ) }>
						{ __( 'Continuar', 'booking-plugin' ) }
					</Button>
				</div>
			) }

			{ 'staff' === step && (
				<div className="booking-plugin-modal__new-appointment-step">
					<p>{ __( 'Profesional:', 'booking-plugin' ) }</p>
					<div className="booking-plugin-modal__staff-list">
						<Button variant="secondary" icon={ people } onClick={ () => handleSelectStaff( null ) }>
							{ __( 'Cualquier profesional disponible', 'booking-plugin' ) }
						</Button>
						{ staffOptions.map( ( member ) => (
							<Button
								key={ member.id }
								variant="secondary"
								icon={ people }
								onClick={ () => handleSelectStaff( member.id ) }
							>
								{ member.name }
							</Button>
						) ) }
					</div>
				</div>
			) }

			{ 'datetime' === step && (
				<div className="booking-plugin-modal__new-appointment-step">
					{ collisionNotice && (
						<Notice status="warning" isDismissible={ false }>
							{ collisionNotice }
						</Notice>
					) }
					<TextControl
						type="date"
						label={ __( 'Fecha', 'booking-plugin' ) }
						value={ date }
						onChange={ ( value ) => {
							setDate( value );
							setSelectedSlot( null );
						} }
					/>
					{ isLoadingSlots && <p>{ __( 'Cargando horarios…', 'booking-plugin' ) }</p> }
					{ ! isLoadingSlots && 0 === slots.length && (
						<p>{ __( 'No hay horarios disponibles ese día.', 'booking-plugin' ) }</p>
					) }
					{ ! isLoadingSlots && slots.length > 0 && (
						<div className="booking-plugin-modal__slot-list">
							{ slots.map( ( slot ) => (
								<Button
									key={ `${ slot.staff_id }-${ slot.start_datetime }` }
									variant="secondary"
									onClick={ () => handleSelectSlot( slot ) }
								>
									{ formatTime( slot.start_datetime ) }
								</Button>
							) ) }
						</div>
					) }
				</div>
			) }

			{ 'client' === step && (
				<div className="booking-plugin-modal__new-appointment-step">
					<div className="booking-plugin-modal__actions">
						<Button
							variant={ 'registered' === clientMode ? 'primary' : 'secondary' }
							onClick={ () => setClientMode( 'registered' ) }
						>
							{ __( 'Cliente registrado', 'booking-plugin' ) }
						</Button>
						<Button
							variant={ 'guest' === clientMode ? 'primary' : 'secondary' }
							onClick={ () => setClientMode( 'guest' ) }
						>
							{ __( 'Invitado (sin cuenta)', 'booking-plugin' ) }
						</Button>
					</div>

					{ 'registered' === clientMode && ! clientUser && (
						<>
							<div className="booking-plugin-credits__search">
								<TextControl
									label={ __( 'Buscar usuario (nombre o email)', 'booking-plugin' ) }
									value={ search }
									onChange={ setSearch }
								/>
								<Button
									variant="secondary"
									disabled={ isSearching || '' === search.trim() }
									onClick={ handleSearch }
								>
									{ __( 'Buscar', 'booking-plugin' ) }
								</Button>
							</div>
							{ searchResults.length > 0 && (
								<ul className="booking-plugin-credits__results">
									{ searchResults.map( ( user ) => (
										<li key={ user.id }>
											<Button variant="link" onClick={ () => selectClientUser( user ) }>
												{ user.name } ({ user.email || user.slug })
											</Button>
										</li>
									) ) }
								</ul>
							) }
						</>
					) }

					{ 'registered' === clientMode && clientUser && (
						<p>
							{ sprintf( __( 'Cliente: %s', 'booking-plugin' ), clientUser.name ) }{ ' ' }
							<Button variant="link" onClick={ () => setClientUser( null ) }>
								{ __( 'Cambiar', 'booking-plugin' ) }
							</Button>
						</p>
					) }

					{ 'guest' === clientMode && (
						<>
							<TextControl
								label={ __( 'Nombre', 'booking-plugin' ) }
								value={ guestName }
								onChange={ setGuestName }
							/>
							<TextControl
								type="email"
								label={ __( 'Email', 'booking-plugin' ) }
								value={ guestEmail }
								onChange={ setGuestEmail }
							/>
							<TextControl
								label={ __( 'Teléfono (opcional)', 'booking-plugin' ) }
								value={ guestPhone }
								onChange={ setGuestPhone }
							/>
						</>
					) }

					<Button
						variant="primary"
						disabled={ ! canContinueFromClient || isLoadingCredits }
						onClick={ handleContinueFromClient }
					>
						{ isLoadingCredits ? __( 'Comprobando saldo…', 'booking-plugin' ) : __( 'Continuar', 'booking-plugin' ) }
					</Button>
				</div>
			) }

			{ 'credit' === step && (
				<div className="booking-plugin-modal__new-appointment-step">
					{ credits.map( ( credit ) => (
						<div key={ credit.id }>
							<p>
								{ sprintf(
									/* translators: 1: sesiones disponibles, 2: nombre del paquete */
									__( '%1$d sesiones disponibles de %2$s.', 'booking-plugin' ),
									credit.remaining_sessions,
									credit.package_name
								) }
							</p>
							<Button variant="primary" onClick={ () => handleUseCredit( credit ) }>
								{ __( 'Usar esta sesión', 'booking-plugin' ) }
							</Button>
						</div>
					) ) }
					<Button variant="secondary" onClick={ handleSkipCredit }>
						{ __( 'No usar crédito, continuar', 'booking-plugin' ) }
					</Button>
				</div>
			) }

			{ 'notes' === step && selectedService && selectedSlot && (
				<div className="booking-plugin-modal__new-appointment-step">
					<dl>
						<dt>{ __( 'Servicio', 'booking-plugin' ) }</dt>
						<dd>{ selectedService.name }</dd>

						{ selectedAddons.length > 0 && (
							<>
								<dt>{ __( 'Extras', 'booking-plugin' ) }</dt>
								<dd>{ selectedAddons.map( ( addon ) => addon.name ).join( ', ' ) }</dd>
							</>
						) }

						<dt>{ __( 'Fecha y hora', 'booking-plugin' ) }</dt>
						<dd>
							{ formatDateUS( selectedSlot.start_datetime ) } { formatTime( selectedSlot.start_datetime ) }
						</dd>

						<dt>{ __( 'Cliente', 'booking-plugin' ) }</dt>
						<dd>{ 'registered' === clientMode ? clientUser.name : guestName }</dd>

						<dt>{ useCreditId ? __( 'Pago', 'booking-plugin' ) : __( 'Precio total', 'booking-plugin' ) }</dt>
						<dd>
							{ useCreditId
								? __( 'Se descuenta 1 sesión del paquete del cliente.', 'booking-plugin' )
								: formatCurrency( totalPrice ) }
						</dd>
					</dl>

					<TextControl
						label={ __( 'Notas (opcional)', 'booking-plugin' ) }
						value={ notes }
						onChange={ setNotes }
					/>

					<Button variant="primary" disabled={ isSaving } onClick={ handleSubmit }>
						{ isSaving ? __( 'Creando…', 'booking-plugin' ) : __( 'Crear cita', 'booking-plugin' ) }
					</Button>
				</div>
			) }
		</Modal>
	);
}
