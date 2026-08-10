import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { API_NAMESPACE, formatTimeInZone } from './utils';
import { getApiErrorMessage } from './utils/apiError';

export default function ConfirmationStep( { selection, timezone, currentUser, onSuccess, onCollision } ) {
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	const { service, addons, addon_ids: addonIds, staff_id: staffId, slot, personalData } = selection;

	const addonsTotal = addons.reduce( ( sum, addon ) => sum + Number( addon.price ), 0 );
	const totalPrice = Number( service.price ) + addonsTotal;
	const totalDuration =
		Number( service.duration_minutes ) +
		addons.reduce( ( sum, addon ) => sum + Number( addon.extra_time_minutes ), 0 );

	const handleConfirm = () => {
		setIsSubmitting( true );
		setError( null );

		const data = {
			service_id: service.id,
			staff_id: staffId,
			start_datetime: slot.start_datetime,
			addon_ids: addonIds,
		};

		if ( currentUser ) {
			if ( personalData.notes ) {
				data.notes = personalData.notes;
			}
		} else {
			data.guest_name = personalData.name;
			data.guest_email = personalData.email;
			data.guest_phone = personalData.phone;

			if ( personalData.notes ) {
				data.notes = personalData.notes;
			}
		}

		apiFetch( { path: `${ API_NAMESPACE }/appointments`, method: 'POST', data } )
			.then( ( result ) => {
				onSuccess( result );
			} )
			.catch( ( err ) => {
				// Cualquier 409 en este punto significa que lo elegido ya no
				// esta disponible (se ocupo entre que se mostro y se
				// confirmo): se recupera volviendo a fecha/hora, en vez de
				// mostrarlo como un error mas dentro de este mismo paso.
				if ( err && err.data && 409 === err.data.status ) {
					onCollision();
					return;
				}

				setError( getApiErrorMessage( err ) );
			} )
			.finally( () => setIsSubmitting( false ) );
	};

	return (
		<div className="booking-plugin-widget__confirmation">
			<h3>{ __( 'Revisa tu reserva', 'booking-plugin' ) }</h3>

			<dl className="booking-plugin-widget__summary">
				<dt>{ __( 'Servicio', 'booking-plugin' ) }</dt>
				<dd>{ service.name }</dd>

				{ addons.length > 0 && (
					<>
						<dt>{ __( 'Extras', 'booking-plugin' ) }</dt>
						<dd>
							<ul className="booking-plugin-widget__summary-addons">
								{ addons.map( ( addon ) => (
									<li key={ addon.id }>
										{ addon.name } — ${ addon.price } — +
										{ addon.extra_time_minutes } { __( 'min', 'booking-plugin' ) }
									</li>
								) ) }
							</ul>
						</dd>
					</>
				) }

				<dt>{ __( 'Fecha y hora', 'booking-plugin' ) }</dt>
				<dd>
					{ selection.date } { formatTimeInZone( slot.start_datetime, timezone ) }
				</dd>

				<dt>{ __( 'Duración total', 'booking-plugin' ) }</dt>
				<dd>
					{ totalDuration } { __( 'min', 'booking-plugin' ) }
				</dd>

				<dt>{ __( 'Precio total', 'booking-plugin' ) }</dt>
				<dd>${ totalPrice }</dd>

				<dt>{ __( 'A nombre de', 'booking-plugin' ) }</dt>
				<dd>{ currentUser ? currentUser.name : personalData.name }</dd>
			</dl>

			{ error && <p className="booking-plugin-widget__error">{ error }</p> }

			<button
				type="button"
				className="booking-plugin-widget__primary"
				disabled={ isSubmitting }
				onClick={ handleConfirm }
			>
				{ isSubmitting
					? __( 'Confirmando…', 'booking-plugin' )
					: __( 'Confirmar reserva', 'booking-plugin' ) }
			</button>
		</div>
	);
}
