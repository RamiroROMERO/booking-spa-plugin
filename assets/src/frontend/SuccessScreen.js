import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { formatTimeInZone } from './utils';

export default function SuccessScreen( { bookingResult, timezone, selection } ) {
	const [ copied, setCopied ] = useState( false );

	const service = selection && selection.service;
	const addons = ( selection && selection.addons ) || [];

	const addonsTotal = addons.reduce( ( sum, addon ) => sum + Number( addon.price ), 0 );
	const totalPrice = service ? Number( service.price ) + addonsTotal : null;
	const totalDuration = service
		? Number( service.duration_minutes ) +
		  addons.reduce( ( sum, addon ) => sum + Number( addon.extra_time_minutes ), 0 )
		: null;

	const handleCopy = () => {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( bookingResult.access_token ).then( () => {
				setCopied( true );
			} );
		}
	};

	return (
		<div className="booking-plugin-widget__success">
			<h3>{ __( '¡Reserva confirmada!', 'booking-plugin' ) }</h3>
			<p>
				{ __( 'Tu cita quedó registrada para el', 'booking-plugin' ) }{ ' ' }
				{ formatTimeInZone( bookingResult.start_datetime, timezone ) }.
			</p>

			{ addons.length > 0 && (
				<div className="booking-plugin-widget__summary">
					<p>
						<strong>{ __( 'Extras incluidos:', 'booking-plugin' ) }</strong>
					</p>
					<ul className="booking-plugin-widget__summary-addons">
						{ addons.map( ( addon ) => (
							<li key={ addon.id }>
								{ addon.name } — ${ addon.price } — +{ addon.extra_time_minutes }{ ' ' }
								{ __( 'min', 'booking-plugin' ) }
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ null !== totalPrice && (
				<p>
					{ __( 'Duración total:', 'booking-plugin' ) } { totalDuration }{ ' ' }
					{ __( 'min', 'booking-plugin' ) } — { __( 'Precio total:', 'booking-plugin' ) } $
					{ totalPrice }
				</p>
			) }

			<div className="booking-plugin-widget__token">
				<label>
					{ __(
						'Código de tu reserva (guárdalo para gestionarla más adelante):',
						'booking-plugin'
					) }
				</label>
				<div className="booking-plugin-widget__token-row">
					<code>{ bookingResult.access_token }</code>
					<button type="button" onClick={ handleCopy }>
						{ copied ? __( '¡Copiado!', 'booking-plugin' ) : __( 'Copiar', 'booking-plugin' ) }
					</button>
				</div>
			</div>

			{ bookingResult.checkout_url && (
				<div className="booking-plugin-widget__payment">
					<p>
						{ __(
							'Este servicio requiere pago online para confirmar la cita.',
							'booking-plugin'
						) }
					</p>
					<a
						href={ bookingResult.checkout_url }
						className="booking-plugin-widget__primary"
					>
						{ __( 'Pagar ahora', 'booking-plugin' ) }
					</a>
				</div>
			) }
		</div>
	);
}
