import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, Notice } from '@wordpress/components';

import RichTextBody from './RichTextBody';
import TemplatePreview from './TemplatePreview';
import { getApiErrorMessage } from '../utils/apiError';

// Placeholders documentados por plantilla (solo referencia de texto, sin
// botón de inserción -- ver "Out of scope" de SPEC 09). Las de negocio
// incluyen los datos de contacto del cliente; las del cliente no se
// autorreferencian con su propio email/teléfono.
const PLACEHOLDERS_BY_KEY = {
	client_confirmation: [
		'client_name',
		'service_name',
		'staff_name',
		'date',
		'time',
		'business_name',
		'manage_url',
	],
	client_reminder: [
		'client_name',
		'service_name',
		'staff_name',
		'date',
		'time',
		'business_name',
		'manage_url',
	],
	client_cancellation: [
		'client_name',
		'service_name',
		'staff_name',
		'date',
		'time',
		'business_name',
		'manage_url',
	],
	admin_new_booking: [
		'client_name',
		'client_email',
		'client_phone',
		'service_name',
		'staff_name',
		'date',
		'time',
	],
	admin_cancellation: [
		'client_name',
		'client_email',
		'client_phone',
		'service_name',
		'staff_name',
		'date',
		'time',
	],
};

function buildPutPayload( allTemplates ) {
	return Object.fromEntries(
		Object.entries( allTemplates ).map( ( [ key, tpl ] ) => [
			key,
			{ subject: tpl.subject, body: tpl.body },
		] )
	);
}

export default function TemplateEditor( {
	templateKey,
	template,
	allTemplates,
	onSave,
	onRestoreDefault,
	onSendTest,
	onClose,
} ) {
	const [ subject, setSubject ] = useState( template.subject );
	const [ body, setBody ] = useState( template.body );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isRestoring, setIsRestoring ] = useState( false );
	const [ isSendingTest, setIsSendingTest ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ successMessage, setSuccessMessage ] = useState( null );
	const [ isPreviewVisible, setIsPreviewVisible ] = useState( false );

	const placeholders = PLACEHOLDERS_BY_KEY[ templateKey ] || [];

	const handleSave = () => {
		setIsSaving( true );
		setError( null );
		setSuccessMessage( null );

		const payload = buildPutPayload( {
			...allTemplates,
			[ templateKey ]: { ...allTemplates[ templateKey ], subject, body },
		} );

		onSave( payload )
			.then( () => setSuccessMessage( __( 'Plantilla guardada.', 'booking-plugin' ) ) )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSaving( false ) );
	};

	const handleRestoreDefault = () => {
		if (
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'¿Restaurar esta plantilla al texto predeterminado? Se descartará la personalización guardada.',
					'booking-plugin'
				)
			)
		) {
			return;
		}

		setIsRestoring( true );
		setError( null );
		setSuccessMessage( null );

		onRestoreDefault( templateKey )
			.then( ( result ) => {
				setSubject( result[ templateKey ].subject );
				setBody( result[ templateKey ].body );
				setSuccessMessage( __( 'Plantilla restaurada al valor predeterminado.', 'booking-plugin' ) );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsRestoring( false ) );
	};

	const handleTestSend = () => {
		setIsSendingTest( true );
		setError( null );
		setSuccessMessage( null );

		onSendTest( templateKey, subject, body )
			.then( () => setSuccessMessage( __( 'Correo de prueba enviado.', 'booking-plugin' ) ) )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSendingTest( false ) );
	};

	const isBusy = isSaving || isRestoring || isSendingTest;

	return (
		<div className="booking-plugin-template-editor">
			<div className="booking-plugin-template-editor__header">
				<Button variant="link" onClick={ onClose }>
					{ __( '← Volver a la lista', 'booking-plugin' ) }
				</Button>
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ successMessage && (
				<Notice
					status="success"
					isDismissible={ false }
					onRemove={ () => setSuccessMessage( null ) }
				>
					{ successMessage }
				</Notice>
			) }

			<TextControl
				label={ __( 'Asunto', 'booking-plugin' ) }
				value={ subject }
				onChange={ setSubject }
			/>

			<p className="booking-plugin-template-editor__label">{ __( 'Cuerpo', 'booking-plugin' ) }</p>
			<RichTextBody value={ body } onChange={ setBody } />

			<div className="booking-plugin-template-editor__placeholders">
				<strong>{ __( 'Placeholders disponibles:', 'booking-plugin' ) }</strong>{ ' ' }
				{ placeholders.map( ( placeholder ) => (
					<code key={ placeholder }>{ `{{${ placeholder }}}` }</code>
				) ) }
			</div>

			<div className="booking-plugin-template-editor__actions">
				<Button variant="primary" disabled={ isBusy } onClick={ handleSave }>
					{ __( 'Guardar', 'booking-plugin' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => setIsPreviewVisible( ( current ) => ! current ) }
				>
					{ isPreviewVisible
						? __( 'Ocultar vista previa', 'booking-plugin' )
						: __( 'Vista previa', 'booking-plugin' ) }
				</Button>
				<Button variant="secondary" disabled={ isBusy } onClick={ handleTestSend }>
					{ __( 'Enviar correo de prueba', 'booking-plugin' ) }
				</Button>
				<Button
					variant="tertiary"
					isDestructive
					disabled={ isBusy }
					onClick={ handleRestoreDefault }
				>
					{ __( 'Restaurar predeterminado', 'booking-plugin' ) }
				</Button>
			</div>

			{ isPreviewVisible && <TemplatePreview subject={ subject } body={ body } /> }
		</div>
	);
}
