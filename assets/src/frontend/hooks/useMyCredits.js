import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import { API_NAMESPACE } from '../utils';

// Solo se consulta si hay usuario logueado: GET /credits/mine exige
// autenticacion (ver SPEC 12) y un invitado nunca tiene saldo que ofrecer.
//
// `isLoading` se deriva comparando `state.serviceId` (el service_id resuelto
// por el ultimo fetch) contra el `serviceId` pedido, en vez de un booleano
// aparte: un booleano aparte queda en `false` durante el primer render
// posterior a un cambio de serviceId (el efecto que lo pone en `true` todavia
// no corrio), y el llamador lo lee como "ya termino, sin creditos" antes de
// que el fetch real haya empezado.
export default function useMyCredits( serviceId, isLoggedIn ) {
	const [ state, setState ] = useState( { serviceId: null, credits: [] } );

	useEffect( () => {
		if ( ! serviceId || ! isLoggedIn ) {
			setState( { serviceId, credits: [] } );
			return;
		}

		let cancelled = false;

		apiFetch( { path: `${ API_NAMESPACE }/credits/mine?service_id=${ serviceId }` } )
			.then( ( result ) => {
				if ( ! cancelled ) {
					setState( { serviceId, credits: result } );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setState( { serviceId, credits: [] } );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ serviceId, isLoggedIn ] );

	const isLoading = Boolean( serviceId && isLoggedIn ) && state.serviceId !== serviceId;

	return { credits: isLoading ? [] : state.credits, isLoading };
}
