import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, Notice } from '@wordpress/components';

import { API_NAMESPACE } from './utils';
import { getApiErrorMessage } from './utils/apiError';

export default function CategoriesManager( { categories, onChange } ) {
	const [ newName, setNewName ] = useState( '' );
	const [ editingId, setEditingId ] = useState( null );
	const [ editingName, setEditingName ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ isSaving, setIsSaving ] = useState( false );

	const handleCreate = () => {
		if ( '' === newName.trim() ) {
			return;
		}

		setIsSaving( true );
		setError( null );

		apiFetch( {
			path: `${ API_NAMESPACE }/categories`,
			method: 'POST',
			data: { name: newName.trim() },
		} )
			.then( () => {
				setNewName( '' );
				onChange();
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSaving( false ) );
	};

	const startEditing = ( category ) => {
		setEditingId( category.id );
		setEditingName( category.name );
	};

	const handleUpdate = () => {
		if ( '' === editingName.trim() ) {
			return;
		}

		setIsSaving( true );
		setError( null );

		apiFetch( {
			path: `${ API_NAMESPACE }/categories/${ editingId }`,
			method: 'PUT',
			data: { name: editingName.trim() },
		} )
			.then( () => {
				setEditingId( null );
				onChange();
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSaving( false ) );
	};

	const handleDelete = ( category ) => {
		setError( null );

		apiFetch( {
			path: `${ API_NAMESPACE }/categories/${ category.id }`,
			method: 'DELETE',
		} )
			.then( () => onChange() )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	};

	return (
		<div className="booking-plugin-categories">
			<h2>{ __( 'Categorías', 'booking-plugin' ) }</h2>

			{ error && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<ul className="booking-plugin-categories__list">
				{ categories.map( ( category ) => (
					<li key={ category.id } className="booking-plugin-categories__item">
						{ editingId === category.id ? (
							<>
								<TextControl
									value={ editingName }
									onChange={ setEditingName }
									hideLabelFromVision
									label={ __( 'Nombre', 'booking-plugin' ) }
								/>
								<Button variant="primary" disabled={ isSaving } onClick={ handleUpdate }>
									{ __( 'Guardar', 'booking-plugin' ) }
								</Button>
								<Button variant="tertiary" onClick={ () => setEditingId( null ) }>
									{ __( 'Cancelar', 'booking-plugin' ) }
								</Button>
							</>
						) : (
							<>
								<span>{ category.name }</span>
								<Button variant="link" onClick={ () => startEditing( category ) }>
									{ __( 'Editar', 'booking-plugin' ) }
								</Button>
								<Button variant="link" isDestructive onClick={ () => handleDelete( category ) }>
									{ __( 'Borrar', 'booking-plugin' ) }
								</Button>
							</>
						) }
					</li>
				) ) }
				{ 0 === categories.length && (
					<li>{ __( 'No hay categorías todavía.', 'booking-plugin' ) }</li>
				) }
			</ul>

			<div className="booking-plugin-categories__new">
				<TextControl
					value={ newName }
					onChange={ setNewName }
					placeholder={ __( 'Nueva categoría', 'booking-plugin' ) }
					hideLabelFromVision
					label={ __( 'Nueva categoría', 'booking-plugin' ) }
				/>
				<Button
					variant="primary"
					disabled={ isSaving || '' === newName.trim() }
					onClick={ handleCreate }
				>
					{ __( 'Agregar', 'booking-plugin' ) }
				</Button>
			</div>
		</div>
	);
}
