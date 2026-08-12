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

			<table className="booking-plugin-table">
				<thead>
					<tr>
						<th>{ __( 'Nombre', 'booking-plugin' ) }</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					{ categories.map( ( category ) => (
						<tr key={ category.id }>
							{ editingId === category.id ? (
								<>
									<td>
										<TextControl
											value={ editingName }
											onChange={ setEditingName }
											hideLabelFromVision
											label={ __( 'Nombre', 'booking-plugin' ) }
										/>
									</td>
									<td>
										<Button variant="primary" disabled={ isSaving } onClick={ handleUpdate }>
											{ __( 'Guardar', 'booking-plugin' ) }
										</Button>{ ' ' }
										<Button variant="tertiary" onClick={ () => setEditingId( null ) }>
											{ __( 'Cancelar', 'booking-plugin' ) }
										</Button>
									</td>
								</>
							) : (
								<>
									<td>{ category.name }</td>
									<td>
										<Button variant="link" onClick={ () => startEditing( category ) }>
											{ __( 'Editar', 'booking-plugin' ) }
										</Button>{ ' ' }
										<Button variant="link" isDestructive onClick={ () => handleDelete( category ) }>
											{ __( 'Borrar', 'booking-plugin' ) }
										</Button>
									</td>
								</>
							) }
						</tr>
					) ) }
					{ 0 === categories.length && (
						<tr>
							<td colSpan={ 2 }>{ __( 'No hay categorías todavía.', 'booking-plugin' ) }</td>
						</tr>
					) }
				</tbody>
			</table>
		</div>
	);
}
