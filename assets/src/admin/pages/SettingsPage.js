import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, Notice } from '@wordpress/components';

import WeeklyScheduleEditor, {
	scheduleRowsFromBusinessHours,
	businessHoursFromRows,
} from '../components/WeeklyScheduleEditor';
import { API_NAMESPACE } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

export default function SettingsPage() {
	const [ isLoading, setIsLoading ] = useState( true );
	const [ loadError, setLoadError ] = useState( null );
	const [ scheduleRows, setScheduleRows ] = useState( [] );
	const [ minLeadTimeHours, setMinLeadTimeHours ] = useState( '0' );
	const [ maxAdvanceDays, setMaxAdvanceDays ] = useState( '0' );
	const [ minCancellationHours, setMinCancellationHours ] = useState( '0' );
	const [ slotIntervalMinutes, setSlotIntervalMinutes ] = useState( '15' );
	const [ notificationEmail, setNotificationEmail ] = useState( '' );
	const [ isSavingHours, setIsSavingHours ] = useState( false );
	const [ isSavingSettings, setIsSavingSettings ] = useState( false );
	const [ hoursError, setHoursError ] = useState( null );
	const [ hoursSaved, setHoursSaved ] = useState( false );
	const [ settingsError, setSettingsError ] = useState( null );
	const [ settingsSaved, setSettingsSaved ] = useState( false );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: `${ API_NAMESPACE }/business-hours` } ),
			apiFetch( { path: `${ API_NAMESPACE }/settings` } ),
		] )
			.then( ( [ businessHours, settings ] ) => {
				setScheduleRows( scheduleRowsFromBusinessHours( businessHours.days ) );
				setMinLeadTimeHours( String( settings.min_lead_time_hours ) );
				setMaxAdvanceDays( String( settings.max_advance_days ) );
				setMinCancellationHours( String( settings.min_cancellation_hours ) );
				setSlotIntervalMinutes( String( settings.slot_interval_minutes ) );
				setNotificationEmail( settings.notification_email || '' );
			} )
			.catch( ( err ) => setLoadError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [] );

	const handleSaveHours = () => {
		setIsSavingHours( true );
		setHoursError( null );
		setHoursSaved( false );

		apiFetch( {
			path: `${ API_NAMESPACE }/business-hours`,
			method: 'PUT',
			data: { days: businessHoursFromRows( scheduleRows ) },
		} )
			.then( ( result ) => {
				setScheduleRows( scheduleRowsFromBusinessHours( result.days ) );
				setHoursSaved( true );
			} )
			.catch( ( err ) => setHoursError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSavingHours( false ) );
	};

	const handleSaveSettings = () => {
		setIsSavingSettings( true );
		setSettingsError( null );
		setSettingsSaved( false );

		apiFetch( {
			path: `${ API_NAMESPACE }/settings`,
			method: 'PUT',
			data: {
				min_lead_time_hours: parseInt( minLeadTimeHours, 10 ) || 0,
				max_advance_days: parseInt( maxAdvanceDays, 10 ) || 0,
				min_cancellation_hours: parseInt( minCancellationHours, 10 ) || 0,
				slot_interval_minutes: parseInt( slotIntervalMinutes, 10 ) || 15,
				notification_email: notificationEmail,
			},
		} )
			.then( ( result ) => {
				setMinLeadTimeHours( String( result.min_lead_time_hours ) );
				setMaxAdvanceDays( String( result.max_advance_days ) );
				setMinCancellationHours( String( result.min_cancellation_hours ) );
				setSlotIntervalMinutes( String( result.slot_interval_minutes ) );
				setNotificationEmail( result.notification_email || '' );
				setSettingsSaved( true );
			} )
			.catch( ( err ) => setSettingsError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSavingSettings( false ) );
	};

	if ( isLoading ) {
		return (
			<div className="booking-plugin-admin">
				<h1>{ __( 'Configuración', 'booking-plugin' ) }</h1>
				<p>{ __( 'Cargando…', 'booking-plugin' ) }</p>
			</div>
		);
	}

	return (
		<div className="booking-plugin-admin">
			<h1>{ __( 'Configuración', 'booking-plugin' ) }</h1>

			{ loadError && (
				<Notice status="error" isDismissible={ false }>
					{ loadError }
				</Notice>
			) }

			<h2>{ __( 'Horario del negocio', 'booking-plugin' ) }</h2>

			{ hoursError && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setHoursError( null ) }>
					{ hoursError }
				</Notice>
			) }
			{ hoursSaved && (
				<Notice status="success" isDismissible onRemove={ () => setHoursSaved( false ) }>
					{ __( 'Horario del negocio guardado.', 'booking-plugin' ) }
				</Notice>
			) }

			<WeeklyScheduleEditor value={ scheduleRows } onChange={ setScheduleRows } showBreak={ false } />

			<Button variant="primary" disabled={ isSavingHours } onClick={ handleSaveHours }>
				{ __( 'Guardar horario', 'booking-plugin' ) }
			</Button>

			<hr />

			<h2>{ __( 'Ventanas de tiempo', 'booking-plugin' ) }</h2>

			{ settingsError && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setSettingsError( null ) }>
					{ settingsError }
				</Notice>
			) }
			{ settingsSaved && (
				<Notice status="success" isDismissible onRemove={ () => setSettingsSaved( false ) }>
					{ __( 'Configuración guardada.', 'booking-plugin' ) }
				</Notice>
			) }

			<TextControl
				label={ __( 'Antelación mínima (horas)', 'booking-plugin' ) }
				type="number"
				min="0"
				value={ minLeadTimeHours }
				onChange={ setMinLeadTimeHours }
			/>
			<TextControl
				label={ __( 'Máximo de días a futuro', 'booking-plugin' ) }
				type="number"
				min="0"
				value={ maxAdvanceDays }
				onChange={ setMaxAdvanceDays }
			/>
			<TextControl
				label={ __( 'Ventana mínima de cancelación (horas)', 'booking-plugin' ) }
				type="number"
				min="0"
				value={ minCancellationHours }
				onChange={ setMinCancellationHours }
			/>
			<TextControl
				label={ __( 'Intervalo de slots (minutos)', 'booking-plugin' ) }
				type="number"
				min="1"
				value={ slotIntervalMinutes }
				onChange={ setSlotIntervalMinutes }
			/>
			<TextControl
				label={ __( 'Email de notificaciones del negocio', 'booking-plugin' ) }
				type="email"
				value={ notificationEmail }
				onChange={ setNotificationEmail }
			/>

			<Button variant="primary" disabled={ isSavingSettings } onClick={ handleSaveSettings }>
				{ __( 'Guardar configuración', 'booking-plugin' ) }
			</Button>
		</div>
	);
}
