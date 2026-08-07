import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function PersonalDataStep( { currentUser, personalData, onSubmit } ) {
	const [ name, setName ] = useState( personalData.name );
	const [ email, setEmail ] = useState( personalData.email );
	const [ phone, setPhone ] = useState( personalData.phone );
	const [ notes, setNotes ] = useState( personalData.notes );
	const [ error, setError ] = useState( null );

	if ( currentUser ) {
		return (
			<div className="booking-plugin-widget__personal-summary">
				<p>
					{ __( 'Vas a reservar con la cuenta:', 'booking-plugin' ) }{ ' ' }
					<strong>{ currentUser.name }</strong> ({ currentUser.email })
				</p>
				<label className="booking-plugin-widget__field">
					{ __( 'Notas (opcional)', 'booking-plugin' ) }
					<textarea value={ notes } onChange={ ( event ) => setNotes( event.target.value ) } />
				</label>
				<button
					type="button"
					className="booking-plugin-widget__primary"
					onClick={ () => onSubmit( { name: '', email: '', phone: '', notes } ) }
				>
					{ __( 'Continuar', 'booking-plugin' ) }
				</button>
			</div>
		);
	}

	const handleSubmit = () => {
		if ( '' === name.trim() ) {
			setError( __( 'El nombre es obligatorio.', 'booking-plugin' ) );
			return;
		}

		if ( '' === email.trim() || ! /\S+@\S+\.\S+/.test( email ) ) {
			setError( __( 'Ingresa un email válido.', 'booking-plugin' ) );
			return;
		}

		if ( '' === phone.trim() ) {
			setError( __( 'El teléfono es obligatorio.', 'booking-plugin' ) );
			return;
		}

		setError( null );
		onSubmit( { name: name.trim(), email: email.trim(), phone: phone.trim(), notes } );
	};

	return (
		<div className="booking-plugin-widget__personal-form">
			{ error && <p className="booking-plugin-widget__error">{ error }</p> }

			<label className="booking-plugin-widget__field">
				{ __( 'Nombre', 'booking-plugin' ) }
				<input type="text" value={ name } onChange={ ( event ) => setName( event.target.value ) } />
			</label>
			<label className="booking-plugin-widget__field">
				{ __( 'Email', 'booking-plugin' ) }
				<input
					type="email"
					value={ email }
					onChange={ ( event ) => setEmail( event.target.value ) }
				/>
			</label>
			<label className="booking-plugin-widget__field">
				{ __( 'Teléfono', 'booking-plugin' ) }
				<input type="tel" value={ phone } onChange={ ( event ) => setPhone( event.target.value ) } />
			</label>
			<label className="booking-plugin-widget__field">
				{ __( 'Notas (opcional)', 'booking-plugin' ) }
				<textarea value={ notes } onChange={ ( event ) => setNotes( event.target.value ) } />
			</label>

			<button type="button" className="booking-plugin-widget__primary" onClick={ handleSubmit }>
				{ __( 'Continuar', 'booking-plugin' ) }
			</button>
		</div>
	);
}
