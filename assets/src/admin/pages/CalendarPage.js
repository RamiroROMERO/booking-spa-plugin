import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button, CheckboxControl } from '@wordpress/components';

import Calendar from '../Calendar';
import MonthView from '../MonthView';
import AppointmentModal from '../AppointmentModal';
import BlockModal from '../BlockModal';
import { getVisibleRange, today, addDays, addMonths, API_NAMESPACE } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

const VIEWS = [
	{ id: 'day', label: __( 'Día', 'booking-plugin' ) },
	{ id: 'week', label: __( 'Semana', 'booking-plugin' ) },
	{ id: 'month', label: __( 'Mes', 'booking-plugin' ) },
];

export default function CalendarPage() {
	const [ view, setView ] = useState( 'week' );
	const [ referenceDate, setReferenceDate ] = useState( today() );
	const [ staff, setStaff ] = useState( [] );
	const [ visibleStaffIds, setVisibleStaffIds ] = useState( [] );
	const [ businessHours, setBusinessHours ] = useState( [] );
	const [ appointments, setAppointments ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ selectedAppointment, setSelectedAppointment ] = useState( null );
	const [ selectedBlock, setSelectedBlock ] = useState( null );
	const [ isBlockFormOpen, setIsBlockFormOpen ] = useState( false );

	useEffect( () => {
		apiFetch( { path: `${ API_NAMESPACE }/staff` } )
			.then( ( result ) => {
				setStaff( result );
				setVisibleStaffIds( result.map( ( member ) => member.id ) );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );

		apiFetch( { path: `${ API_NAMESPACE }/business-hours` } )
			.then( ( result ) => setBusinessHours( result.days ) )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	}, [] );

	const loadAppointments = useCallback( () => {
		const { from, to } = getVisibleRange( view, referenceDate );

		setIsLoading( true );

		apiFetch( {
			path: `${ API_NAMESPACE }/appointments?date_from=${ from }&date_to=${ to }&per_page=100`,
		} )
			.then( ( result ) => {
				setAppointments( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ view, referenceDate ] );

	useEffect( () => {
		loadAppointments();
	}, [ loadAppointments ] );

	const goToday = () => setReferenceDate( today() );

	const goPrevious = () => {
		if ( 'month' === view ) {
			setReferenceDate( ( current ) => addMonths( current, -1 ) );
		} else {
			setReferenceDate( ( current ) => addDays( current, 'week' === view ? -7 : -1 ) );
		}
	};

	const goNext = () => {
		if ( 'month' === view ) {
			setReferenceDate( ( current ) => addMonths( current, 1 ) );
		} else {
			setReferenceDate( ( current ) => addDays( current, 'week' === view ? 7 : 1 ) );
		}
	};

	const toggleStaffVisible = ( staffId ) => {
		setVisibleStaffIds( ( current ) =>
			current.includes( staffId )
				? current.filter( ( id ) => id !== staffId )
				: [ ...current, staffId ]
		);
	};

	const handleSelectAppointment = ( appointment ) => {
		if ( 'blocked' === appointment.status ) {
			setSelectedBlock( appointment );
			return;
		}

		setSelectedAppointment( appointment );
	};

	const handleSelectDay = ( day ) => {
		setView( 'day' );
		setReferenceDate( day );
	};

	const visibleStaff = staff.filter( ( member ) => visibleStaffIds.includes( member.id ) );

	return (
		<div className="booking-plugin-admin">
			<div className="booking-plugin-admin__toolbar">
				<h1>{ __( 'Reservas', 'booking-plugin' ) }</h1>

				<div className="booking-plugin-admin__nav">
					<Button variant="secondary" onClick={ goPrevious }>
						{ __( 'Anterior', 'booking-plugin' ) }
					</Button>
					<Button variant="secondary" onClick={ goToday }>
						{ __( 'Hoy', 'booking-plugin' ) }
					</Button>
					<Button variant="secondary" onClick={ goNext }>
						{ __( 'Siguiente', 'booking-plugin' ) }
					</Button>
				</div>

				<div className="booking-plugin-admin__views">
					{ VIEWS.map( ( item ) => (
						<Button
							key={ item.id }
							variant={ item.id === view ? 'primary' : 'secondary' }
							onClick={ () => setView( item.id ) }
						>
							{ item.label }
						</Button>
					) ) }
				</div>

				<Button variant="tertiary" onClick={ () => setIsBlockFormOpen( true ) }>
					{ __( 'Bloquear horario', 'booking-plugin' ) }
				</Button>
			</div>

			{ error && <p className="booking-plugin-admin__error">{ error }</p> }

			{ 'month' !== view && staff.length > 0 && (
				<div className="booking-plugin-admin__staff-filter">
					{ staff.map( ( member ) => (
						<CheckboxControl
							key={ member.id }
							label={ member.name }
							checked={ visibleStaffIds.includes( member.id ) }
							onChange={ () => toggleStaffVisible( member.id ) }
						/>
					) ) }
				</div>
			) }

			<div className="booking-plugin-admin__body">
				{ isLoading && <p>{ __( 'Actualizando…', 'booking-plugin' ) }</p> }
				{ /*
				 * Calendar/MonthView permanecen montados aun mientras isLoading
				 * es true: desmontarlos en cada recarga (por ejemplo tras
				 * confirmar una cita) perdia el estado local de "dia activo"
				 * dentro de la semana y devolvia la vista a referenceDate.
				 */ }
				{ 'month' === view && (
					<MonthView
						referenceDate={ referenceDate }
						appointments={ appointments }
						onSelectDay={ handleSelectDay }
					/>
				) }
				{ 'month' !== view && (
					<Calendar
						view={ view }
						referenceDate={ referenceDate }
						staff={ visibleStaff }
						appointments={ appointments }
						businessHours={ businessHours }
						onSelectAppointment={ handleSelectAppointment }
					/>
				) }
			</div>

			{ selectedAppointment && (
				<AppointmentModal
					appointment={ selectedAppointment }
					staff={ staff }
					onClose={ () => setSelectedAppointment( null ) }
					onUpdated={ loadAppointments }
				/>
			) }

			{ selectedBlock && (
				<BlockModal
					block={ selectedBlock }
					staff={ staff }
					onClose={ () => setSelectedBlock( null ) }
					onSaved={ loadAppointments }
				/>
			) }

			{ isBlockFormOpen && (
				<BlockModal
					staff={ staff }
					defaultDate={ referenceDate }
					onClose={ () => setIsBlockFormOpen( false ) }
					onSaved={ loadAppointments }
				/>
			) }
		</div>
	);
}
