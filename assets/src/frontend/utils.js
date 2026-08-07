export const API_NAMESPACE = '/booking-plugin/v1';

/**
 * Detecta la zona horaria del navegador. Si Intl.DateTimeFormat no esta
 * disponible (navegadores muy antiguos), cae a la zona horaria del sitio
 * (localizada por PHP) en vez de bloquear el widget.
 */
export function detectTimezone( fallback ) {
	try {
		const resolved = Intl.DateTimeFormat().resolvedOptions().timeZone;

		if ( resolved ) {
			return resolved;
		}
	} catch ( e ) {
		// Intl no disponible: se usa el fallback.
	}

	return fallback || 'UTC';
}

export function toDateString( date ) {
	const year = date.getFullYear();
	const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( date.getDate() ).padStart( 2, '0' );
	return `${ year }-${ month }-${ day }`;
}

export function today() {
	return toDateString( new Date() );
}

export function addDays( dateStr, days ) {
	const date = new Date( dateStr + 'T00:00:00' );
	date.setDate( date.getDate() + days );
	return toDateString( date );
}

export function addMonths( dateStr, months ) {
	const date = new Date( dateStr + 'T00:00:00' );
	date.setMonth( date.getMonth() + months );
	return toDateString( date );
}

export function startOfMonth( dateStr ) {
	const date = new Date( dateStr + 'T00:00:00' );
	date.setDate( 1 );
	return toDateString( date );
}

export function startOfWeek( dateStr ) {
	const date = new Date( dateStr + 'T00:00:00' );
	const day = date.getDay();
	const diff = 0 === day ? -6 : 1 - day; // semana inicia en lunes
	date.setDate( date.getDate() + diff );
	return toDateString( date );
}

export function formatMonth( dateStr ) {
	return dateStr.slice( 0, 7 );
}

export function formatMonthLabel( monthStr ) {
	const date = new Date( monthStr + '-01T00:00:00' );
	return date.toLocaleDateString( undefined, { month: 'long', year: 'numeric' } );
}

/**
 * Formatea un instante UTC (ISO 8601) en la hora civil de una zona horaria
 * arbitraria (la detectada del navegador del cliente, que puede ser
 * distinta a la del sitio). A diferencia del admin (SPEC 04), aqui no se
 * usa @wordpress/date porque ese paquete solo formatea contra la zona
 * horaria del SITIO -- Intl.DateTimeFormat si acepta una zona arbitraria.
 */
export function formatTimeInZone( isoUtc, timezone ) {
	const options = { hour: '2-digit', minute: '2-digit', hour12: false };

	try {
		return new Intl.DateTimeFormat( undefined, { ...options, timeZone: timezone } ).format(
			new Date( isoUtc )
		);
	} catch ( e ) {
		return new Intl.DateTimeFormat( undefined, options ).format( new Date( isoUtc ) );
	}
}

// Misma logica que formatTimeInZone, para la fecha (SPEC 07: panel de
// cliente). Formatear en la zona del cliente (no solo tomar el string UTC
// crudo) evita mostrar el dia equivocado cerca de la medianoche.
export function formatDateInZone( isoUtc, timezone ) {
	const options = { dateStyle: 'long' };

	try {
		return new Intl.DateTimeFormat( undefined, { ...options, timeZone: timezone } ).format(
			new Date( isoUtc )
		);
	} catch ( e ) {
		return new Intl.DateTimeFormat( undefined, options ).format( new Date( isoUtc ) );
	}
}
