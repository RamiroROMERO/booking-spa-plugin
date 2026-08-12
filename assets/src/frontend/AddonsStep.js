import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { API_NAMESPACE, formatCurrency } from './utils';
import { getApiErrorMessage } from './utils/apiError';

export default function AddonsStep( { serviceId, selectedAddonIds, onContinue, onNoAddons } ) {
	const [ addons, setAddons ] = useState( [] );
	const [ selectedIds, setSelectedIds ] = useState( selectedAddonIds || [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( ! serviceId ) {
			return;
		}

		setIsLoading( true );

		apiFetch( { path: `${ API_NAMESPACE }/services/${ serviceId }/addons` } )
			.then( ( result ) => {
				setAddons( result );
				setError( null );

				// Ningun add-on activo para este servicio: el paso no tiene
				// nada que mostrar, se salta automaticamente (ver SPEC 11).
				if ( 0 === result.length ) {
					onNoAddons();
				}
			} )
			.catch( ( err ) => setError( getApiErrorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ serviceId ] );

	const toggleAddon = ( addonId ) => {
		setSelectedIds( ( current ) =>
			current.includes( addonId )
				? current.filter( ( id ) => id !== addonId )
				: [ ...current, addonId ]
		);
	};

	const handleContinue = () => {
		onContinue( addons.filter( ( addon ) => selectedIds.includes( addon.id ) ) );
	};

	if ( isLoading ) {
		return <p>{ __( 'Cargando extras…', 'booking-plugin' ) }</p>;
	}

	if ( error ) {
		return <p className="booking-plugin-widget__error">{ error }</p>;
	}

	if ( 0 === addons.length ) {
		return null;
	}

	return (
		<div className="booking-plugin-widget__addons-step">
			<div className="booking-plugin-widget__addons-grid">
				{ addons.map( ( addon ) => (
					<label key={ addon.id } className="booking-plugin-widget__addon-card">
						<input
							type="checkbox"
							checked={ selectedIds.includes( addon.id ) }
							onChange={ () => toggleAddon( addon.id ) }
						/>
						<span className="booking-plugin-widget__addon-name">{ addon.name }</span>
						<span className="booking-plugin-widget__addon-meta">
							+{ addon.extra_time_minutes } { __( 'min', 'booking-plugin' ) } —{ ' ' }
							{ formatCurrency( addon.price ) }
						</span>
					</label>
				) ) }
			</div>

			<button
				type="button"
				className="booking-plugin-widget__primary"
				onClick={ handleContinue }
			>
				{ __( 'Continuar', 'booking-plugin' ) }
			</button>
		</div>
	);
}
