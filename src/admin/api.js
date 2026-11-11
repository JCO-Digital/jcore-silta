/**
 * Thin wrappers around apiFetch for the jcore-silta/v1 namespace.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

const NAMESPACE = '/jcore-silta/v1';

/**
 * GET a route.
 *
 * @param {string} path  Route below the namespace, e.g. '/jobs'.
 * @param {Object} query Query arguments.
 * @return {Promise<Object>} Response.
 */
export const get = ( path, query = {} ) =>
	apiFetch( { path: addQueryArgs( NAMESPACE + path, query ) } );

/**
 * POST to a route.
 *
 * @param {string} path Route below the namespace.
 * @param {Object} data Body.
 * @return {Promise<Object>} Response.
 */
export const post = ( path, data = {} ) =>
	apiFetch( { path: NAMESPACE + path, method: 'POST', data } );

/**
 * PATCH a route.
 *
 * @param {string} path Route below the namespace.
 * @param {Object} data Body.
 * @return {Promise<Object>} Response.
 */
export const patch = ( path, data = {} ) =>
	apiFetch( { path: NAMESPACE + path, method: 'PATCH', data } );

/**
 * DELETE a route.
 *
 * @param {string} path Route below the namespace.
 * @return {Promise<Object>} Response.
 */
export const remove = ( path ) =>
	apiFetch( { path: NAMESPACE + path, method: 'DELETE' } );

/**
 * Turns a rejected apiFetch call into a message to show.
 *
 * @param {Object} error Error from apiFetch.
 * @return {string} Message.
 */
export function errorMessage( error ) {
	if ( error && typeof error.message === 'string' && error.message ) {
		return error.message;
	}
	return __( 'Something went wrong. Please try again.', 'jcore-silta' );
}

/**
 * Whether a rejected call only means another request is still working on the
 * same sync (a retry sent while the first request was still running, or a
 * second tab). It is safe to ask again shortly.
 *
 * @param {Object} error Error from apiFetch.
 * @return {boolean} Busy.
 */
export const isBusy = ( error ) => error?.code === 'jcore_silta_busy';

/**
 * POSTs to a route that works on a sync, waiting while another request holds
 * the sync instead of failing.
 *
 * @param {string}        path     Route below the namespace.
 * @param {Object}        data     Body.
 * @param {() => boolean} isActive Whether the caller still wants the answer.
 * @return {Promise<Object>} Response.
 */
export async function postWhenFree( path, data = {}, isActive = () => true ) {
	for (;;) {
		try {
			return await post( path, data );
		} catch ( e ) {
			if ( ! isBusy( e ) || ! isActive() ) {
				throw e;
			}
			await new Promise( ( resolve ) => setTimeout( resolve, 2000 ) );
		}
	}
}

/**
 * Loads every page of a paginated list.
 *
 * @param {(page: number) => Promise<Object>} fetchPage Called with a page number; resolves to { items, total }.
 * @return {Promise<Array>} All items.
 */
export async function fetchAll( fetchPage ) {
	const all = [];
	for ( let page = 1; ; page++ ) {
		const { items = [], total = 0 } = await fetchPage( page );
		all.push( ...items );
		if ( ! items.length || all.length >= total ) {
			return all;
		}
	}
}

/**
 * Loads every item of a job.
 *
 * @param {number} jobId Job ID.
 * @return {Promise<Array>} Items.
 */
export const fetchJobItems = ( jobId ) =>
	fetchAll( ( page ) =>
		get( `/jobs/${ jobId }/items`, { page, per_page: 200 } )
	);
