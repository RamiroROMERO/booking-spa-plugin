import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, Notice } from '@wordpress/components';

import { API_NAMESPACE, formatCurrency } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

export default function AddonsEditor( { serviceId } ) {
	const [ addons, setAddons ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	const [ newName, setNewName ] = useState( '' );
	const [ newPrice, setNewPrice ] = useState( '0' );
	const [ newExtraTime, setNewExtraTime ] = useState( '0' );

	const [ editingId, setEditingId ] = useState( null );
	const [ editName, setEditName ] = useState( '' );
	const [ editPrice, setEditPrice ] = useState( '0' );
	const [ editExtraTime, setEditExtraTime ] = useState( '0' );

	const loadAddons = useCallback( () => {
		if ( ! serviceId ) {
			return;
		}

		setIsLoading( true );

		return apiFetch( { path: `${ API_NAMESPACE }/services/${ serviceId }/addons?status=all` } )
			.then( ( result ) => {
				setAddons( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ serviceId ] );

	useEffect( () => {
		loadAddons();
	}, [ loadAddons ] );

	if ( ! serviceId ) {
		return (
			<div className="booking-plugin-addons">
				<h3>{ __( 'Add-ons', 'booking-plugin' ) }</h3>
				<p>
					{ __(
						'Guardá el servicio primero para poder agregarle add-ons.',
						'booking-plugin'
					) }
				</p>
			</div>
		);
	}

	const handleAdd = () => {
		if ( '' === newName.trim() ) {
			return;
		}

		apiFetch( {
			path: `${ API_NAMESPACE }/services/${ serviceId }/addons`,
			method: 'POST',
			data: {
				name: newName.trim(),
				price: parseFloat( newPrice ) || 0,
				extra_time_minutes: parseInt( newExtraTime, 10 ) || 0,
			},
		} )
			.then( () => {
				setNewName( '' );
				setNewPrice( '0' );
				setNewExtraTime( '0' );
				return loadAddons();
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	};

	const startEdit = ( addon ) => {
		setEditingId( addon.id );
		setEditName( addon.name );
		setEditPrice( String( addon.price ) );
		setEditExtraTime( String( addon.extra_time_minutes ) );
	};

	const cancelEdit = () => {
		setEditingId( null );
	};

	const saveEdit = ( addon ) => {
		if ( '' === editName.trim() ) {
			return;
		}

		apiFetch( {
			path: `${ API_NAMESPACE }/addons/${ addon.id }`,
			method: 'PUT',
			data: {
				name: editName.trim(),
				price: parseFloat( editPrice ) || 0,
				extra_time_minutes: parseInt( editExtraTime, 10 ) || 0,
			},
		} )
			.then( () => {
				setEditingId( null );
				return loadAddons();
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	};

	const toggleStatus = ( addon ) => {
		if ( 'active' === addon.status ) {
			apiFetch( { path: `${ API_NAMESPACE }/addons/${ addon.id }`, method: 'DELETE' } )
				.then( () => loadAddons() )
				.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
		} else {
			apiFetch( {
				path: `${ API_NAMESPACE }/addons/${ addon.id }`,
				method: 'PUT',
				data: { status: 'active' },
			} )
				.then( () => loadAddons() )
				.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
		}
	};

	return (
		<div className="booking-plugin-addons">
			<h3>{ __( 'Add-ons', 'booking-plugin' ) }</h3>

			{ error && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ isLoading && <p>{ __( 'Cargando…', 'booking-plugin' ) }</p> }

			{ ! isLoading && (
				<div className="booking-plugin-addons__list">
					{ addons.map( ( addon ) =>
						editingId === addon.id ? (
							<div key={ addon.id } className="booking-plugin-addons__card">
								<TextControl
									label={ __( 'Nombre', 'booking-plugin' ) }
									value={ editName }
									onChange={ setEditName }
								/>
								<TextControl
									label={ __( 'Precio', 'booking-plugin' ) }
									type="number"
									min="0"
									step="0.01"
									value={ editPrice }
									onChange={ setEditPrice }
								/>
								<TextControl
									label={ __( 'Minutos extra', 'booking-plugin' ) }
									type="number"
									min="0"
									value={ editExtraTime }
									onChange={ setEditExtraTime }
								/>
								<Button variant="primary" onClick={ () => saveEdit( addon ) }>
									{ __( 'Guardar', 'booking-plugin' ) }
								</Button>
								<Button variant="tertiary" onClick={ cancelEdit }>
									{ __( 'Cancelar', 'booking-plugin' ) }
								</Button>
							</div>
						) : (
							<div key={ addon.id } className="booking-plugin-addons__card">
								<span>
									<strong>{ addon.name }</strong> — { formatCurrency( addon.price ) } —{ ' ' }
									{ addon.extra_time_minutes } { __( 'min extra', 'booking-plugin' ) }
									{ 'inactive' === addon.status && (
										<em> ({ __( 'inactivo', 'booking-plugin' ) })</em>
									) }
								</span>
								<Button variant="link" onClick={ () => startEdit( addon ) }>
									{ __( 'Editar', 'booking-plugin' ) }
								</Button>
								<Button
									variant="link"
									isDestructive={ 'active' === addon.status }
									onClick={ () => toggleStatus( addon ) }
								>
									{ 'active' === addon.status
										? __( 'Desactivar', 'booking-plugin' )
										: __( 'Activar', 'booking-plugin' ) }
								</Button>
							</div>
						)
					) }
					{ 0 === addons.length && <p>{ __( 'No hay add-ons para este servicio.', 'booking-plugin' ) }</p> }
				</div>
			) }

			<div className="booking-plugin-addons__form">
				<TextControl
					label={ __( 'Nombre', 'booking-plugin' ) }
					value={ newName }
					onChange={ setNewName }
				/>
				<TextControl
					label={ __( 'Precio', 'booking-plugin' ) }
					type="number"
					min="0"
					step="0.01"
					value={ newPrice }
					onChange={ setNewPrice }
				/>
				<TextControl
					label={ __( 'Minutos extra', 'booking-plugin' ) }
					type="number"
					min="0"
					value={ newExtraTime }
					onChange={ setNewExtraTime }
				/>
				<Button variant="primary" disabled={ '' === newName.trim() } onClick={ handleAdd }>
					{ __( 'Agregar add-on', 'booking-plugin' ) }
				</Button>
			</div>
		</div>
	);
}
