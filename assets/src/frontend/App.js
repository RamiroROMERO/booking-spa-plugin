import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { detectTimezone } from './utils';
import ServiceStep from './ServiceStep';
import AddonsStep from './AddonsStep';
import StaffStep from './StaffStep';
import DateTimeStep from './DateTimeStep';
import PersonalDataStep from './PersonalDataStep';
import ConfirmationStep from './ConfirmationStep';
import SuccessScreen from './SuccessScreen';

const STEP_ORDER = [ 'service', 'addons', 'staff', 'datetime', 'personal', 'confirmation' ];

const STEP_LABELS = {
	service: __( 'Servicio', 'booking-plugin' ),
	addons: __( 'Extras', 'booking-plugin' ),
	staff: __( 'Profesional', 'booking-plugin' ),
	datetime: __( 'Fecha y hora', 'booking-plugin' ),
	personal: __( 'Tus datos', 'booking-plugin' ),
	confirmation: __( 'Confirmación', 'booking-plugin' ),
};

const BRANDING_CSS_VARS = {
	accent: '--booking-accent',
	accentHover: '--booking-accent-hover',
	borderColor: '--booking-border-color',
	textMuted: '--booking-text-muted',
};

function brandingToStyle( branding ) {
	const style = {};

	Object.keys( branding || {} ).forEach( ( key ) => {
		const cssVar = BRANDING_CSS_VARS[ key ];

		if ( cssVar ) {
			style[ cssVar ] = branding[ key ];
		}
	} );

	return style;
}

export default function App() {
	const settings = window.BookingPluginFrontend || {};
	const currentUser = settings.currentUser || null;
	const brandingStyle = brandingToStyle( settings.branding );

	const [ step, setStep ] = useState( 'service' );
	const [ timezone ] = useState( () => detectTimezone( settings.timezone ) );
	const [ bookingResult, setBookingResult ] = useState( null );
	const [ collisionNotice, setCollisionNotice ] = useState( null );
	const [ selection, setSelection ] = useState( {
		category_id: null,
		service: null,
		addon_ids: [],
		addons: [],
		hasAddons: null,
		staff_id: null,
		date: null,
		slot: null,
		personalData: { name: '', email: '', phone: '', notes: '' },
		use_credit_id: null,
		credit: null,
	} );

	// Cambiar el servicio (o el profesional) limpia las selecciones que
	// dependen de el, para que el criterio de aceptacion de "Atras" se
	// cumpla sin necesitar logica especial de navegacion hacia atras: se
	// dispara igual la primera vez que se elige algo (donde ya eran null).
	//
	// `credit` llega desde ServiceStep cuando el usuario acepta gastar una
	// sesion de su paquete (SPEC 12): en ese caso se salta AddonsStep por
	// completo -- un servicio pagado con credito nunca ofrece add-ons.
	const handleSelectService = ( service, credit ) => {
		setCollisionNotice( null );
		setSelection( ( current ) => ( {
			...current,
			service,
			addon_ids: [],
			addons: [],
			hasAddons: null,
			staff_id: null,
			date: null,
			slot: null,
			use_credit_id: credit ? credit.id : null,
			credit: credit || null,
		} ) );
		setStep( credit ? 'staff' : 'addons' );
	};

	// AddonsStep se salta solo si el servicio no tiene add-ons activos (ver
	// SPEC 11): lo detecta el propio paso al hacer su fetch y avisa aca via
	// onNoAddons en vez de precargar la lista en este componente.
	const handleNoAddons = () => {
		setSelection( ( current ) => ( { ...current, addon_ids: [], addons: [], hasAddons: false } ) );
		setStep( 'staff' );
	};

	const handleSelectAddons = ( selectedAddons ) => {
		setSelection( ( current ) => ( {
			...current,
			addon_ids: selectedAddons.map( ( addon ) => addon.id ),
			addons: selectedAddons,
			hasAddons: true,
		} ) );
		setStep( 'staff' );
	};

	const handleSelectCategory = ( categoryId ) => {
		setSelection( ( current ) => ( { ...current, category_id: categoryId } ) );
	};

	const handleSelectStaff = ( staffId ) => {
		setCollisionNotice( null );
		setSelection( ( current ) => ( {
			...current,
			staff_id: staffId,
			date: null,
			slot: null,
		} ) );
		setStep( 'datetime' );
	};

	// El slot viene de GET /availability y trae su propio staff_id, aunque
	// selection.staff_id sea null ("cualquier profesional"): un slot con
	// staff_id null mezcla horarios de varios profesionales elegibles, asi
	// que hay que fijar el staff_id de ESE slot puntual al confirmar, en vez
	// de reenviar null y dejar que el servidor auto-asigne de nuevo (podria
	// elegir a otro profesional distinto del que se mostro en pantalla).
	const handleSelectSlot = ( date, slot ) => {
		setCollisionNotice( null );
		setSelection( ( current ) => ( {
			...current,
			date,
			staff_id: slot.staff_id,
			slot: { start_datetime: slot.start_datetime, end_datetime: slot.end_datetime },
		} ) );
		setStep( 'personal' );
	};

	const handlePersonalDataSubmit = ( personalData ) => {
		setSelection( ( current ) => ( { ...current, personalData } ) );
		setStep( 'confirmation' );
	};

	// El 409 de colision (SPEC 03) vuelve al paso de fecha/hora: se limpia
	// solo el slot (la fecha se mantiene) para que ese paso recargue los
	// horarios de ese mismo dia al volver a montarse, sin perder servicio
	// ni profesional ya elegidos.
	const handleCollision = () => {
		setCollisionNotice(
			__(
				'Ese horario ya no está disponible: alguien más lo reservó primero. Elige otro horario.',
				'booking-plugin'
			)
		);
		setSelection( ( current ) => ( { ...current, slot: null } ) );
		setStep( 'datetime' );
	};

	const handleBookingSuccess = ( result ) => {
		setBookingResult( result );
		setStep( 'success' );
	};

	const handleBack = () => {
		const currentIndex = STEP_ORDER.indexOf( step );

		if ( currentIndex <= 0 ) {
			return;
		}

		let prevIndex = currentIndex - 1;

		// El servicio actual no tiene add-ons, o se reserva con credito
		// (que nunca ofrece add-ons, ver SPEC 12): al volver atras desde
		// "staff" se salta "addons" tambien, para no caer en un paso vacio.
		if (
			'addons' === STEP_ORDER[ prevIndex ] &&
			( false === selection.hasAddons || selection.use_credit_id )
		) {
			prevIndex -= 1;
		}

		setStep( STEP_ORDER[ prevIndex ] );
	};

	const canGoBack = 'service' !== step && 'success' !== step;

	return (
		<div className="booking-plugin-widget" style={ brandingStyle }>
			{ 'success' !== step && (
				<div className="booking-plugin-widget__steps">
					{ STEP_ORDER.map( ( item, index ) => (
						<span
							key={ item }
							className={
								'booking-plugin-widget__step-indicator' +
								( item === step ? ' is-active' : '' )
							}
						>
							{ index + 1 }. { STEP_LABELS[ item ] }
						</span>
					) ) }
				</div>
			) }

			{ canGoBack && (
				<button type="button" className="booking-plugin-widget__back" onClick={ handleBack }>
					{ __( '← Atrás', 'booking-plugin' ) }
				</button>
			) }

			<div className="booking-plugin-widget__content">
				{ 'service' === step && (
					<ServiceStep
						categoryId={ selection.category_id }
						currentUser={ currentUser }
						onSelectCategory={ handleSelectCategory }
						onSelectService={ handleSelectService }
					/>
				) }
				{ 'addons' === step && (
					<AddonsStep
						serviceId={ selection.service && selection.service.id }
						selectedAddonIds={ selection.addon_ids }
						onContinue={ handleSelectAddons }
						onNoAddons={ handleNoAddons }
					/>
				) }
				{ 'staff' === step && (
					<StaffStep serviceId={ selection.service && selection.service.id } onSelectStaff={ handleSelectStaff } />
				) }
				{ 'datetime' === step && (
					<DateTimeStep
						serviceId={ selection.service && selection.service.id }
						staffId={ selection.staff_id }
						addonIds={ selection.addon_ids }
						timezone={ timezone }
						date={ selection.date }
						collisionNotice={ collisionNotice }
						onSelectSlot={ handleSelectSlot }
					/>
				) }
				{ 'personal' === step && (
					<PersonalDataStep
						currentUser={ currentUser }
						personalData={ selection.personalData }
						onSubmit={ handlePersonalDataSubmit }
					/>
				) }
				{ 'confirmation' === step && (
					<ConfirmationStep
						selection={ selection }
						timezone={ timezone }
						currentUser={ currentUser }
						onSuccess={ handleBookingSuccess }
						onCollision={ handleCollision }
					/>
				) }
				{ 'success' === step && (
					<SuccessScreen bookingResult={ bookingResult } timezone={ timezone } selection={ selection } />
				) }
			</div>
		</div>
	);
}
