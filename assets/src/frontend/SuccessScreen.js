import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { formatTimeInZone } from './utils';

export default function SuccessScreen( { bookingResult, timezone } ) {
	const [ copied, setCopied ] = useState( false );

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
		</div>
	);
}
