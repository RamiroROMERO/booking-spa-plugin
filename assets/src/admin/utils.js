import { date as wpDate } from '@wordpress/date';

export const API_NAMESPACE = '/booking-plugin/v1';
export const ROW_HEIGHT = 48;
const MINUTES_PER_DAY = 1440;

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

export function startOfWeek( dateStr ) {
	const date = new Date( dateStr + 'T00:00:00' );
	const day = date.getDay();
	const diff = 0 === day ? -6 : 1 - day; // semana inicia en lunes
	date.setDate( date.getDate() + diff );
	return toDateString( date );
}

export function startOfMonth( dateStr ) {
	const date = new Date( dateStr + 'T00:00:00' );
	date.setDate( 1 );
	return toDateString( date );
}

export function endOfMonth( dateStr ) {
	const date = new Date( dateStr + 'T00:00:00' );
	date.setMonth( date.getMonth() + 1 );
	date.setDate( 0 );
	return toDateString( date );
}

export function getVisibleRange( view, referenceDate ) {
	if ( 'day' === view ) {
		return { from: referenceDate, to: referenceDate };
	}

	if ( 'week' === view ) {
		const from = startOfWeek( referenceDate );
		return { from, to: addDays( from, 6 ) };
	}

	return { from: startOfMonth( referenceDate ), to: endOfMonth( referenceDate ) };
}

// Las citas viajan en UTC (ISO 8601); estas funciones las convierten a la
// zona horaria del sitio WP usando @wordpress/date (que toma esa
// configuracion automaticamente del script "wp-date" que WP localiza).
export function getLocalDate( isoUtc ) {
	return wpDate( 'Y-m-d', isoUtc );
}

// Fecha en formato MM/DD/YYYY (EE.UU.) para mostrar al admin -- el plugin
// esta pensado para spas de EE.UU. sin importar el idioma/locale del sitio.
// getLocalDate() sigue devolviendo Y-m-d para usos internos (comparaciones,
// valor de <input type="date">).
export function formatDateUS( isoUtc ) {
	return wpDate( 'm/d/Y', isoUtc );
}

export function formatTime( isoUtc ) {
	return wpDate( 'H:i', isoUtc );
}

export function formatTimeRange( startIsoUtc, endIsoUtc ) {
	return `${ formatTime( startIsoUtc ) }–${ formatTime( endIsoUtc ) }`;
}

export function getMinutesSinceMidnight( isoUtc ) {
	const hour = parseInt( wpDate( 'G', isoUtc ), 10 );
	const minute = parseInt( wpDate( 'i', isoUtc ), 10 );
	return hour * 60 + minute;
}

// La duracion se calcula sobre el instante UTC real (no sobre la hora local
// ya formateada) para que no se vea afectada por transiciones de horario de verano.
export function getDurationMinutes( startIsoUtc, endIsoUtc ) {
	const start = new Date( startIsoUtc ).getTime();
	const end = new Date( endIsoUtc ).getTime();
	return Math.max( 1, Math.round( ( end - start ) / 60000 ) );
}

export function getTopPercent( isoUtc, windowStartMinutes, windowTotalMinutes ) {
	return ( ( getMinutesSinceMidnight( isoUtc ) - windowStartMinutes ) / windowTotalMinutes ) * 100;
}

export function getHeightPercent( startIsoUtc, endIsoUtc, windowTotalMinutes ) {
	return ( getDurationMinutes( startIsoUtc, endIsoUtc ) / windowTotalMinutes ) * 100;
}

function timeToMinutes( timeStr ) {
	const [ hours, minutes ] = timeStr.split( ':' ).map( Number );
	return hours * 60 + minutes;
}

/**
 * Calcula que franja de horas mostrar en el grid del calendario para un dia
 * puntual: el horario de atencion del negocio ese day_of_week, ampliado si
 * hace falta para no cortar citas/bloqueos que caigan fuera de el (ej. un
 * bloqueo manual antes de la apertura). Si el negocio esta cerrado ese dia y
 * no hay nada agendado, se muestra el dia completo en vez de un grid vacio.
 */
export function getHourRange( businessHours, dayOfWeek, dayAppointments ) {
	const today = ( businessHours || [] ).find( ( day ) => day.day_of_week === dayOfWeek );

	let startMinutes = today && today.open_time && today.close_time ? timeToMinutes( today.open_time ) : null;
	let endMinutes = today && today.open_time && today.close_time ? timeToMinutes( today.close_time ) : null;

	( dayAppointments || [] ).forEach( ( appointment ) => {
		const apptStart = getMinutesSinceMidnight( appointment.start_datetime );
		const apptEnd = apptStart + getDurationMinutes( appointment.start_datetime, appointment.end_datetime );

		startMinutes = null === startMinutes ? apptStart : Math.min( startMinutes, apptStart );
		endMinutes = null === endMinutes ? apptEnd : Math.max( endMinutes, apptEnd );
	} );

	if ( null === startMinutes || null === endMinutes ) {
		return { startHour: 0, endHour: 24 };
	}

	return {
		startHour: Math.max( 0, Math.floor( startMinutes / 60 ) ),
		endHour: Math.min( 24, Math.ceil( endMinutes / 60 ) ),
	};
}

export function formatTabLabel( dateStr ) {
	const localDate = new Date( dateStr + 'T00:00:00' );
	return localDate.toLocaleDateString( undefined, { weekday: 'short', day: 'numeric' } );
}

/**
 * Convierte una fecha/hora civil en la zona horaria del sitio WP (tal como
 * se captura en un <input type="date">/<input type="time">) a un ISO 8601
 * UTC, que es lo que espera la API (SPEC 03).
 *
 * `timezone` puede ser un identificador IANA ("America/Mexico_City") o un
 * offset fijo tipo "+02:00" (lo que devuelve wp_timezone_string() cuando el
 * sitio solo tiene gmt_offset configurado, sin timezone_string con nombre).
 */
export function localToUtcIso( dateStr, timeStr, timezone ) {
	const naive = new Date( `${ dateStr }T${ timeStr }:00` );
	const targetUtcIfNaiveWereUtc = Date.UTC(
		naive.getFullYear(),
		naive.getMonth(),
		naive.getDate(),
		naive.getHours(),
		naive.getMinutes()
	);

	const offsetMatch = /^([+-])(\d{2}):(\d{2})$/.exec( timezone || '' );

	if ( offsetMatch ) {
		const sign = '+' === offsetMatch[ 1 ] ? 1 : -1;
		const offsetMinutes =
			sign * ( parseInt( offsetMatch[ 2 ], 10 ) * 60 + parseInt( offsetMatch[ 3 ], 10 ) );

		return new Date( targetUtcIfNaiveWereUtc - offsetMinutes * 60000 ).toISOString();
	}

	try {
		const formatter = new Intl.DateTimeFormat( 'en-US', {
			timeZone: timezone,
			hourCycle: 'h23',
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
		} );

		// Refinamiento iterativo: se prueba un instante UTC, se formatea en la
		// zona destino, y se ajusta por la diferencia hasta converger (2
		// vueltas alcanzan incluso en bordes de horario de verano).
		let guess = targetUtcIfNaiveWereUtc;

		for ( let i = 0; i < 2; i++ ) {
			const parts = formatter.formatToParts( new Date( guess ) ).reduce( ( acc, part ) => {
				acc[ part.type ] = part.value;
				return acc;
			}, {} );

			const guessAsIfUtc = Date.UTC(
				parseInt( parts.year, 10 ),
				parseInt( parts.month, 10 ) - 1,
				parseInt( parts.day, 10 ),
				24 === parseInt( parts.hour, 10 ) ? 0 : parseInt( parts.hour, 10 ),
				parseInt( parts.minute, 10 )
			);

			guess += targetUtcIfNaiveWereUtc - guessAsIfUtc;
		}

		return new Date( guess ).toISOString();
	} catch ( e ) {
		// timezone no es un identificador IANA valido: se usa la hora local
		// del navegador como ultimo recurso.
		return naive.toISOString();
	}
}
