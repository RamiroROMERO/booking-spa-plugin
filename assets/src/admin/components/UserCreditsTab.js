import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, SelectControl, Notice } from '@wordpress/components';

import { API_NAMESPACE } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

export default function UserCreditsTab() {
	const [ search, setSearch ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const [ selectedUser, setSelectedUser ] = useState( null );
	const [ credits, setCredits ] = useState( [] );
	const [ isLoadingCredits, setIsLoadingCredits ] = useState( false );
	const [ packages, setPackages ] = useState( [] );
	const [ grantPackageId, setGrantPackageId ] = useState( '' );
	const [ grantNote, setGrantNote ] = useState( '' );
	const [ isGranting, setIsGranting ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `${ API_NAMESPACE }/packages?status=active` } )
			.then( setPackages )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) );
	}, [] );

	const loadCredits = useCallback( ( userId ) => {
		setIsLoadingCredits( true );

		return apiFetch( { path: `${ API_NAMESPACE }/users/${ userId }/credits` } )
			.then( ( result ) => {
				setCredits( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoadingCredits( false ) );
	}, [] );

	const handleSearch = () => {
		if ( '' === search.trim() ) {
			return;
		}

		setIsSearching( true );

		apiFetch( { path: `/wp/v2/users?search=${ encodeURIComponent( search.trim() ) }&per_page=10` } )
			.then( ( result ) => {
				setResults( result );
				setError( null );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsSearching( false ) );
	};

	const selectUser = ( user ) => {
		setSelectedUser( user );
		setResults( [] );
		setSearch( '' );
		loadCredits( user.id );
	};

	const packageOptions = [
		{ label: __( 'Elegir paquete…', 'booking-plugin' ), value: '' },
		...packages.map( ( pkg ) => ( { label: pkg.name, value: String( pkg.id ) } ) ),
	];

	const handleGrant = () => {
		if ( ! selectedUser || '' === grantPackageId ) {
			return;
		}

		setIsGranting( true );

		apiFetch( {
			path: `${ API_NAMESPACE }/users/${ selectedUser.id }/credits`,
			method: 'POST',
			data: {
				package_id: parseInt( grantPackageId, 10 ),
				note: grantNote || null,
			},
		} )
			.then( () => {
				setGrantPackageId( '' );
				setGrantNote( '' );
				return loadCredits( selectedUser.id );
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsGranting( false ) );
	};

	return (
		<div className="booking-plugin-credits">
			{ error && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<div className="booking-plugin-credits__search">
				<TextControl
					label={ __( 'Buscar usuario (nombre o email)', 'booking-plugin' ) }
					value={ search }
					onChange={ setSearch }
				/>
				<Button
					variant="secondary"
					disabled={ isSearching || '' === search.trim() }
					onClick={ handleSearch }
				>
					{ __( 'Buscar', 'booking-plugin' ) }
				</Button>
			</div>

			{ results.length > 0 && (
				<ul className="booking-plugin-credits__results">
					{ results.map( ( user ) => (
						<li key={ user.id }>
							<Button variant="link" onClick={ () => selectUser( user ) }>
								{ user.name } ({ user.email || user.slug })
							</Button>
						</li>
					) ) }
				</ul>
			) }

			{ selectedUser && (
				<div className="booking-plugin-credits__detail">
					<h3>
						{ __( 'Créditos de', 'booking-plugin' ) } { selectedUser.name }
					</h3>

					{ isLoadingCredits && <p>{ __( 'Cargando…', 'booking-plugin' ) }</p> }

					{ ! isLoadingCredits && (
						<table className="booking-plugin-table">
							<thead>
								<tr>
									<th>{ __( 'Paquete', 'booking-plugin' ) }</th>
									<th>{ __( 'Saldo', 'booking-plugin' ) }</th>
									<th>{ __( 'Origen', 'booking-plugin' ) }</th>
									<th>{ __( 'Nota', 'booking-plugin' ) }</th>
									<th>{ __( 'Otorgado', 'booking-plugin' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ credits.map( ( credit ) => (
									<tr key={ credit.id }>
										<td>{ credit.package_name }</td>
										<td>
											{ credit.remaining_sessions } / { credit.total_sessions }
										</td>
										<td>
											{ 'manual' === credit.source
												? __( 'Manual', 'booking-plugin' )
												: 'WooCommerce' }
										</td>
										<td>{ credit.note || '—' }</td>
										<td>{ credit.created_at }</td>
									</tr>
								) ) }
								{ 0 === credits.length && (
									<tr>
										<td colSpan={ 5 }>
											{ __( 'Este usuario no tiene créditos.', 'booking-plugin' ) }
										</td>
									</tr>
								) }
							</tbody>
						</table>
					) }

					<h3>{ __( 'Otorgar crédito manual', 'booking-plugin' ) }</h3>
					<div className="booking-plugin-credits__grant">
						<SelectControl
							label={ __( 'Paquete', 'booking-plugin' ) }
							value={ grantPackageId }
							options={ packageOptions }
							onChange={ setGrantPackageId }
						/>
						<TextControl
							label={ __( 'Nota (opcional)', 'booking-plugin' ) }
							value={ grantNote }
							onChange={ setGrantNote }
						/>
						<Button
							variant="primary"
							disabled={ isGranting || '' === grantPackageId }
							onClick={ handleGrant }
						>
							{ __( 'Otorgar crédito', 'booking-plugin' ) }
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}
