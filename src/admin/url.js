/**
 * Keeps the page URL in step with the app, so a reload lands in the same place.
 */

/**
 * Reads a query argument of the current page.
 *
 * @param {string} name Argument.
 * @return {string|null} Value.
 */
export const getQueryArg = ( name ) =>
	new URL( window.location.href ).searchParams.get( name );

/**
 * Sets or removes (null) query arguments without reloading.
 *
 * @param {Object} changes Argument => value or null.
 */
export function setQueryArgs( changes ) {
	const url = new URL( window.location.href );
	Object.entries( changes ).forEach( ( [ name, value ] ) => {
		if ( value === null || value === undefined || value === '' ) {
			url.searchParams.delete( name );
		} else {
			url.searchParams.set( name, String( value ) );
		}
	} );
	window.history.replaceState( window.history.state, '', url.toString() );
}
