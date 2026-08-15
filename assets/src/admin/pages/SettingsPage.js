import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button, ColorPicker, TextControl, ToggleControl, Notice } from '@wordpress/components';

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
	const [ paymentWindowHours, setPaymentWindowHours ] = useState( '2' );
	const [ isWooCommerceActive, setIsWooCommerceActive ] = useState( false );
	const [ autoRefundEnabled, setAutoRefundEnabled ] = useState( false );
	const [ widgetAccentColor, setWidgetAccentColor ] = useState( '' );
	const [ widgetAccentHoverColor, setWidgetAccentHoverColor ] = useState( '' );
	const [ widgetBorderColor, setWidgetBorderColor ] = useState( '' );
	const [ widgetTextMutedColor, setWidgetTextMutedColor ] = useState( '' );
	// ColorPicker de @wordpress/components no reinicia su swatch interno
	// cuando su prop `color` pasa a undefined (queda "sin controlar" con el
	// ultimo valor que tuvo, ver useControlledValue): forzar un remount con
	// `key` es la unica forma de que el picker refleje visualmente el reset.
	const [ accentColorResetKey, setAccentColorResetKey ] = useState( 0 );
	const [ accentHoverColorResetKey, setAccentHoverColorResetKey ] = useState( 0 );
	const [ borderColorResetKey, setBorderColorResetKey ] = useState( 0 );
	const [ textMutedColorResetKey, setTextMutedColorResetKey ] = useState( 0 );
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
				setPaymentWindowHours( String( settings.payment_window_hours ) );
				setIsWooCommerceActive( Boolean( settings.woocommerce_active ) );
				setAutoRefundEnabled( Boolean( settings.auto_refund_enabled ) );
				setWidgetAccentColor( settings.widget_accent_color || '' );
				setWidgetAccentHoverColor( settings.widget_accent_hover_color || '' );
				setWidgetBorderColor( settings.widget_border_color || '' );
				setWidgetTextMutedColor( settings.widget_text_muted_color || '' );
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
				payment_window_hours: parseInt( paymentWindowHours, 10 ) || 2,
				auto_refund_enabled: autoRefundEnabled,
				widget_accent_color: widgetAccentColor,
				widget_accent_hover_color: widgetAccentHoverColor,
				widget_border_color: widgetBorderColor,
				widget_text_muted_color: widgetTextMutedColor,
			},
		} )
			.then( ( result ) => {
				setMinLeadTimeHours( String( result.min_lead_time_hours ) );
				setMaxAdvanceDays( String( result.max_advance_days ) );
				setMinCancellationHours( String( result.min_cancellation_hours ) );
				setSlotIntervalMinutes( String( result.slot_interval_minutes ) );
				setNotificationEmail( result.notification_email || '' );
				setPaymentWindowHours( String( result.payment_window_hours ) );
				setAutoRefundEnabled( Boolean( result.auto_refund_enabled ) );
				setWidgetAccentColor( result.widget_accent_color || '' );
				setWidgetAccentHoverColor( result.widget_accent_hover_color || '' );
				setWidgetBorderColor( result.widget_border_color || '' );
				setWidgetTextMutedColor( result.widget_text_muted_color || '' );
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

			<Button
				variant="primary"
				disabled={ isSavingHours }
				onClick={ handleSaveHours }
				className="booking-plugin-settings-form__submit"
			>
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

			<div className="booking-plugin-settings-form">
				<div className="booking-plugin-settings-form__row">
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
				</div>
				<div className="booking-plugin-settings-form__row">
					<TextControl
						label={ __( 'Intervalo de slots (minutos)', 'booking-plugin' ) }
						type="number"
						min="1"
						value={ slotIntervalMinutes }
						onChange={ setSlotIntervalMinutes }
					/>
					<TextControl
						label={ __( 'Ventana de pago (horas)', 'booking-plugin' ) }
						help={ __(
							'Tiempo máximo para pagar una reserva con pago online antes de cancelarla automáticamente.',
							'booking-plugin'
						) }
						type="number"
						min="1"
						value={ paymentWindowHours }
						onChange={ setPaymentWindowHours }
					/>
					<TextControl
						label={ __( 'Email de notificaciones del negocio', 'booking-plugin' ) }
						type="email"
						value={ notificationEmail }
						onChange={ setNotificationEmail }
					/>
				</div>
				<div className="booking-plugin-settings-form__row">
					<ToggleControl
						label={ __( 'Reembolso automático al cancelar', 'booking-plugin' ) }
						help={
							isWooCommerceActive
								? __(
										'Al cancelar una cita con pago o depósito ya cobrado, se intenta reembolsar automáticamente el dinero real en la pasarela de pago original.',
										'booking-plugin'
								  )
								: __( 'Requiere que WooCommerce esté instalado y activo.', 'booking-plugin' )
						}
						checked={ autoRefundEnabled }
						disabled={ ! isWooCommerceActive }
						onChange={ setAutoRefundEnabled }
					/>
				</div>
			</div>

			<h2>{ __( 'Colores del widget', 'booking-plugin' ) }</h2>

			<div className="booking-plugin-settings-form__colors">
				<div className="booking-plugin-settings-form__color-field">
					<p>{ __( 'Color principal', 'booking-plugin' ) }</p>
					<ColorPicker
						key={ `accent-${ accentColorResetKey }` }
						color={ widgetAccentColor || undefined }
						onChange={ setWidgetAccentColor }
					/>
					<Button
						variant="secondary"
						onClick={ () => {
							setWidgetAccentColor( '' );
							setAccentColorResetKey( ( key ) => key + 1 );
						} }
					>
						{ __( 'Restablecer', 'booking-plugin' ) }
					</Button>
				</div>
				<div className="booking-plugin-settings-form__color-field">
					<p>{ __( 'Color hover', 'booking-plugin' ) }</p>
					<ColorPicker
						key={ `accent-hover-${ accentHoverColorResetKey }` }
						color={ widgetAccentHoverColor || undefined }
						onChange={ setWidgetAccentHoverColor }
					/>
					<Button
						variant="secondary"
						onClick={ () => {
							setWidgetAccentHoverColor( '' );
							setAccentHoverColorResetKey( ( key ) => key + 1 );
						} }
					>
						{ __( 'Restablecer', 'booking-plugin' ) }
					</Button>
				</div>
				<div className="booking-plugin-settings-form__color-field">
					<p>{ __( 'Color de borde', 'booking-plugin' ) }</p>
					<ColorPicker
						key={ `border-${ borderColorResetKey }` }
						color={ widgetBorderColor || undefined }
						onChange={ setWidgetBorderColor }
					/>
					<Button
						variant="secondary"
						onClick={ () => {
							setWidgetBorderColor( '' );
							setBorderColorResetKey( ( key ) => key + 1 );
						} }
					>
						{ __( 'Restablecer', 'booking-plugin' ) }
					</Button>
				</div>
				<div className="booking-plugin-settings-form__color-field">
					<p>{ __( 'Color de texto secundario', 'booking-plugin' ) }</p>
					<ColorPicker
						key={ `text-muted-${ textMutedColorResetKey }` }
						color={ widgetTextMutedColor || undefined }
						onChange={ setWidgetTextMutedColor }
					/>
					<Button
						variant="secondary"
						onClick={ () => {
							setWidgetTextMutedColor( '' );
							setTextMutedColorResetKey( ( key ) => key + 1 );
						} }
					>
						{ __( 'Restablecer', 'booking-plugin' ) }
					</Button>
				</div>
			</div>

			<Button variant="primary" disabled={ isSavingSettings } onClick={ handleSaveSettings }>
				{ __( 'Guardar configuración', 'booking-plugin' ) }
			</Button>

			<hr />

			<h2>{ __( 'Pagos', 'booking-plugin' ) }</h2>

			<Notice status={ isWooCommerceActive ? 'success' : 'warning' } isDismissible={ false }>
				{ isWooCommerceActive
					? __( 'WooCommerce está activo: los servicios pueden requerir pago online.', 'booking-plugin' )
					: __(
							'WooCommerce no está instalado o activo: ningún servicio puede cobrar pago online por ahora.',
							'booking-plugin'
					  ) }
			</Notice>
		</div>
	);
}
