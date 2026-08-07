import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, SelectControl } from '@wordpress/components';

export default function ExceptionsEditor( { value, onChange } ) {
	const [ date, setDate ] = useState( '' );
	const [ type, setType ] = useState( 'day_off' );
	const [ startTime, setStartTime ] = useState( '09:00' );
	const [ endTime, setEndTime ] = useState( '18:00' );
	const [ reason, setReason ] = useState( '' );

	const handleAdd = () => {
		if ( '' === date ) {
			return;
		}

		const exception = {
			exception_date: date,
			is_day_off: 'day_off' === type,
			start_time: 'special_hours' === type ? startTime : null,
			end_time: 'special_hours' === type ? endTime : null,
			reason: reason || null,
		};

		onChange( [ ...value.filter( ( item ) => item.exception_date !== date ), exception ] );
		setDate( '' );
		setReason( '' );
	};

	const handleRemove = ( exceptionDate ) => {
		onChange( value.filter( ( item ) => item.exception_date !== exceptionDate ) );
	};

	return (
		<div className="booking-plugin-exceptions">
			<div className="booking-plugin-exceptions__list">
				{ value.map( ( exception ) => (
					<div key={ exception.exception_date } className="booking-plugin-exceptions__card">
						<strong>{ exception.exception_date }</strong>{ ' ' }
						{ exception.is_day_off
							? __( 'Día libre', 'booking-plugin' )
							: `${ exception.start_time }–${ exception.end_time }` }
						{ exception.reason && <span> — { exception.reason }</span> }
						<Button variant="link" isDestructive onClick={ () => handleRemove( exception.exception_date ) }>
							{ __( 'Quitar', 'booking-plugin' ) }
						</Button>
					</div>
				) ) }
				{ 0 === value.length && <p>{ __( 'No hay excepciones.', 'booking-plugin' ) }</p> }
			</div>

			<div className="booking-plugin-exceptions__form">
				<TextControl
					type="date"
					label={ __( 'Fecha', 'booking-plugin' ) }
					value={ date }
					onChange={ setDate }
				/>
				<SelectControl
					label={ __( 'Tipo', 'booking-plugin' ) }
					value={ type }
					options={ [
						{ label: __( 'Día libre', 'booking-plugin' ), value: 'day_off' },
						{ label: __( 'Horario especial', 'booking-plugin' ), value: 'special_hours' },
					] }
					onChange={ setType }
				/>
				{ 'special_hours' === type && (
					<>
						<TextControl
							type="time"
							label={ __( 'Hora inicio', 'booking-plugin' ) }
							value={ startTime }
							onChange={ setStartTime }
						/>
						<TextControl
							type="time"
							label={ __( 'Hora fin', 'booking-plugin' ) }
							value={ endTime }
							onChange={ setEndTime }
						/>
					</>
				) }
				<TextControl
					label={ __( 'Motivo', 'booking-plugin' ) }
					value={ reason }
					onChange={ setReason }
				/>
				<Button variant="primary" disabled={ '' === date } onClick={ handleAdd }>
					{ __( 'Agregar excepción', 'booking-plugin' ) }
				</Button>
			</div>
		</div>
	);
}
