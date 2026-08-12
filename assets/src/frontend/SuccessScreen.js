import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { formatDateInZone, formatTimeInZone, formatCurrency } from './utils';

export default function SuccessScreen( { bookingResult, timezone, selection } ) {
	const [ copied, setCopied ] = useState( false );

	const service = selection && selection.service;
	const addons = ( selection && selection.addons ) || [];
	const credit = selection && selection.credit;
	const paidWithCredit = Boolean( bookingResult.paid_with_credit_id );

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
				{ formatDateInZone( bookingResult.start_datetime, timezone ) }{ ' ' }
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
								{ addon.name } — { formatCurrency( addon.price ) } — +{ addon.extra_time_minutes }{ ' ' }
								{ __( 'min', 'booking-plugin' ) }
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ paidWithCredit ? (
				<p>
					{ sprintf(
						/* translators: 1: nombre del paquete, 2: sesiones restantes */
						__( 'Se usó 1 sesión de tu paquete %1$s — te quedan %2$d.', 'booking-plugin' ),
						credit ? credit.package_name : '',
						credit
							? Math.max( 0, credit.remaining_sessions - bookingResult.credits_consumed )
							: 0
					) }
				</p>
			) : (
				null !== totalPrice && (
					<p>
						{ __( 'Duración total:', 'booking-plugin' ) } { totalDuration }{ ' ' }
						{ __( 'min', 'booking-plugin' ) } — { __( 'Precio total:', 'booking-plugin' ) }{ ' ' }
						{ formatCurrency( totalPrice ) }
					</p>
				)
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

			{ bookingResult.checkout_url && undefined !== bookingResult.deposit_amount && (
				<div className="booking-plugin-widget__payment">
					<p>
						{ sprintf(
							/* translators: 1: porcentaje del depósito, 2: monto del depósito, 3: saldo pendiente */
							__(
								'Este servicio requiere un depósito del %1$s%% (%2$s) para confirmar la cita. El saldo restante de %3$s queda pendiente y se cobra por separado.',
								'booking-plugin'
							),
							service ? service.deposit_percentage : '',
							formatCurrency( bookingResult.deposit_amount ),
							formatCurrency( bookingResult.balance_due )
						) }
					</p>
					<a
						href={ bookingResult.checkout_url }
						className="booking-plugin-widget__primary"
					>
						{ sprintf(
							/* translators: 1: monto del depósito, 2: porcentaje del depósito */
							__( 'Pagar depósito (%1$s — %2$s%%)', 'booking-plugin' ),
							formatCurrency( bookingResult.deposit_amount ),
							service ? service.deposit_percentage : ''
						) }
					</a>
				</div>
			) }

			{ bookingResult.checkout_url && undefined === bookingResult.deposit_amount && (
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
