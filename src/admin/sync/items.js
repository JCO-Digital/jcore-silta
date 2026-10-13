/**
 * Helpers for job items.
 */

/**
 * Why an item can't be synced at all, or ''.
 *
 * @param {Object} item Item.
 * @return {string} Message.
 */
export function blockingError( item ) {
	if ( item.plan?.errors?.[ '' ] ) {
		return item.plan.errors[ '' ];
	}
	if ( item.status === 'failed' && item.result?.error ) {
		return item.result.error;
	}
	return '';
}

/**
 * Whether an item may be included in the sync.
 *
 * @param {Object} item Item.
 * @return {boolean} Includable.
 */
export const canInclude = ( item ) =>
	item.action !== 'error' && ! blockingError( item );

/**
 * Whether an included item is a conflict that will be skipped.
 *
 * @param {Object} item Item.
 * @return {boolean} Skipped conflict.
 */
export const isSkippedConflict = ( item ) =>
	item.object_type !== 'orphan' && item.conflict && ! item.confirmed;

/**
 * Whether a section is chosen. A chosen group name (fields, meta, terms)
 * covers every section below it, as on the server.
 *
 * @param {Array|null} chosen Chosen sections, or null for everything.
 * @param {string}     key    Section key, e.g. 'fields.post_title'.
 * @return {boolean} Chosen.
 */
export const isSectionChosen = ( chosen, key ) =>
	chosen === null ||
	chosen.includes( key ) ||
	chosen.includes( key.split( '.' )[ 0 ] );

/**
 * Turns a choice that may hold group names into concrete section keys.
 *
 * @param {Array|null} chosen Chosen sections, or null for everything.
 * @param {Array}      keys   All section keys of the item.
 * @return {Array} Chosen section keys.
 */
export const expandSections = ( chosen, keys ) =>
	keys.filter( ( key ) => isSectionChosen( chosen, key ) );
