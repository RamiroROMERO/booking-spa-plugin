import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

import TemplateEditor from '../components/TemplateEditor';
import useEmailTemplates from '../hooks/useEmailTemplates';

const TEMPLATE_LABELS = [
	{ key: 'client_confirmation', label: __( 'Confirmación al cliente', 'booking-plugin' ) },
	{ key: 'client_reminder', label: __( 'Recordatorio al cliente', 'booking-plugin' ) },
	{ key: 'client_cancellation', label: __( 'Cancelación al cliente', 'booking-plugin' ) },
	{ key: 'admin_new_booking', label: __( 'Nueva reserva (negocio)', 'booking-plugin' ) },
	{ key: 'admin_cancellation', label: __( 'Cancelación (negocio)', 'booking-plugin' ) },
];

export default function NotificationsPage() {
	const { templates, isLoading, error, setError, saveTemplates, restoreDefault, sendTest } =
		useEmailTemplates();
	const [ editingKey, setEditingKey ] = useState( null );

	return (
		<div className="booking-plugin-admin">
			<h1>{ __( 'Notificaciones', 'booking-plugin' ) }</h1>

			{ error && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ isLoading && <p>{ __( 'Cargando…', 'booking-plugin' ) }</p> }

			{ ! isLoading && editingKey && templates[ editingKey ] && (
				<TemplateEditor
					key={ editingKey }
					templateKey={ editingKey }
					template={ templates[ editingKey ] }
					allTemplates={ templates }
					onSave={ saveTemplates }
					onRestoreDefault={ restoreDefault }
					onSendTest={ sendTest }
					onClose={ () => setEditingKey( null ) }
				/>
			) }

			{ ! isLoading && ! editingKey && (
				<table className="booking-plugin-table">
					<thead>
						<tr>
							<th>{ __( 'Plantilla', 'booking-plugin' ) }</th>
							<th>{ __( 'Estado', 'booking-plugin' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ TEMPLATE_LABELS.map( ( { key, label } ) => (
							<tr key={ key }>
								<td>{ label }</td>
								<td>
									{ templates[ key ] && templates[ key ].is_customized
										? __( 'Personalizada', 'booking-plugin' )
										: __( 'Predeterminada', 'booking-plugin' ) }
								</td>
								<td>
									<Button variant="link" onClick={ () => setEditingKey( key ) }>
										{ __( 'Editar', 'booking-plugin' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
