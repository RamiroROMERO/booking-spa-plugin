import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import { API_NAMESPACE } from '../utils';
import { getApiErrorMessage } from '../utils/apiError';

export default function useEmailTemplates() {
	const [ templates, setTemplates ] = useState( {} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const loadTemplates = useCallback( () => {
		setIsLoading( true );

		return apiFetch( { path: `${ API_NAMESPACE }/email-templates` } )
			.then( ( result ) => {
				setTemplates( result );
				setError( null );
				return result;
			} )
			.catch( ( err ) => {
				setError( getApiErrorMessage( err ) );
				throw err;
			} )
			.finally( () => setIsLoading( false ) );
	}, [] );

	useEffect( () => {
		loadTemplates();
	}, [ loadTemplates ] );

	const saveTemplates = useCallback( ( payload ) => {
		return apiFetch( {
			path: `${ API_NAMESPACE }/email-templates`,
			method: 'PUT',
			data: payload,
		} ).then( ( result ) => {
			setTemplates( result );
			return result;
		} );
	}, [] );

	const restoreDefault = useCallback( ( key ) => {
		return apiFetch( {
			path: `${ API_NAMESPACE }/email-templates/${ key }/restore-default`,
			method: 'POST',
		} ).then( ( result ) => {
			setTemplates( result );
			return result;
		} );
	}, [] );

	const sendTest = useCallback( ( key, subject, body ) => {
		return apiFetch( {
			path: `${ API_NAMESPACE }/email-templates/test-send`,
			method: 'POST',
			data: { template_key: key, subject, body },
		} );
	}, [] );

	return {
		templates,
		isLoading,
		error,
		setError,
		saveTemplates,
		restoreDefault,
		sendTest,
	};
}
