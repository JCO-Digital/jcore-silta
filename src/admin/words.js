/**
 * Plain-language labels for the values the REST API returns.
 */
import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Describes a job phase.
 *
 * @param {string} phase Phase from the job.
 * @return {string} Label.
 */
export function phaseLabel( phase ) {
	const labels = {
		resolve: __( 'Collecting', 'jcore-silta' ),
		plan: __( 'Comparing', 'jcore-silta' ),
		deps: __( 'Finding dependencies', 'jcore-silta' ),
		orphans: __( 'Checking for removed posts', 'jcore-silta' ),
		ready: __( 'Ready', 'jcore-silta' ),
		media: __( 'Copying media files', 'jcore-silta' ),
		import: __( 'Writing', 'jcore-silta' ),
		fixup: __( 'Linking references', 'jcore-silta' ),
		trash: __( 'Moving removed posts to the trash', 'jcore-silta' ),
		done: __( 'Finished', 'jcore-silta' ),
	};
	return labels[ phase ] || phase || '';
}

/**
 * Describes a job status.
 *
 * @param {string} status Job status.
 * @return {string} Label.
 */
export function jobStatusLabel( status ) {
	const labels = {
		preparing: __( 'Being prepared', 'jcore-silta' ),
		ready: __( 'Ready to run', 'jcore-silta' ),
		running: __( 'Not finished', 'jcore-silta' ),
		done: __( 'Done', 'jcore-silta' ),
		undone: __( 'Undone', 'jcore-silta' ),
		cancelled: __( 'Stopped', 'jcore-silta' ),
		received: __( 'Received', 'jcore-silta' ),
		failed: __( 'Failed', 'jcore-silta' ),
	};
	return labels[ status ] || status;
}

/**
 * Badge tone for a job status.
 *
 * @param {string} status Job status.
 * @return {string} Tone.
 */
export function jobStatusTone( status ) {
	return (
		{
			done: 'success',
			received: 'success',
			running: 'warning',
			cancelled: 'warning',
			failed: 'error',
		}[ status ] || 'neutral'
	);
}

/**
 * Describes a planned item action.
 *
 * @param {string} action Item action.
 * @return {string} Label.
 */
export function actionLabel( action ) {
	const labels = {
		create: __( 'Create', 'jcore-silta' ),
		update: __( 'Update', 'jcore-silta' ),
		unchanged: __( 'No changes', 'jcore-silta' ),
		match: __( 'Already there', 'jcore-silta' ),
		error: __( 'Problem', 'jcore-silta' ),
		trash: __( 'Trash', 'jcore-silta' ),
	};
	return labels[ action ] || __( 'Waiting', 'jcore-silta' );
}

/**
 * Badge tone for a planned item action.
 *
 * @param {string} action Item action.
 * @return {string} Tone.
 */
export function actionTone( action ) {
	return (
		{
			create: 'success',
			update: 'info',
			error: 'error',
			trash: 'warning',
		}[ action ] || 'neutral'
	);
}

/**
 * Describes how a source post compares with its copy on the target.
 *
 * @param {string} state State from GET /source/posts with status.
 * @return {string} Label.
 */
export function syncStateLabel( state ) {
	const labels = {
		not_synced: __( 'Not synced yet', 'jcore-silta' ),
		synced: __( 'Up to date', 'jcore-silta' ),
		source_changed: __( 'Changed', 'jcore-silta' ),
		target_changed: __( 'Edited on the target', 'jcore-silta' ),
		both_changed: __( 'Changed on both sites', 'jcore-silta' ),
	};
	return labels[ state ] || '';
}

/**
 * Badge tone for a sync state.
 *
 * @param {string} state Sync state.
 * @return {string} Tone.
 */
export function syncStateTone( state ) {
	return (
		{
			synced: 'success',
			source_changed: 'info',
			target_changed: 'warning',
			both_changed: 'warning',
		}[ state ] || 'neutral'
	);
}

/**
 * Whether a sync state means the source has something the target lacks.
 *
 * @param {string} state Sync state.
 * @return {boolean} Worth syncing.
 */
export const isOutdated = ( state ) =>
	[ 'not_synced', 'source_changed', 'both_changed' ].includes( state );

/**
 * Describes how a target object was matched.
 *
 * @param {string} method Match method.
 * @return {string} Words.
 */
export function matchMethodLabel( method ) {
	const labels = {
		mapping: __( 'an earlier sync', 'jcore-silta' ),
		origin: __( 'where it originally came from', 'jcore-silta' ),
		guid: __( 'the same ID (GUID)', 'jcore-silta' ),
		path: __( 'the same file', 'jcore-silta' ),
		slug: __( 'the same slug', 'jcore-silta' ),
		manual: __( 'your choice', 'jcore-silta' ),
	};
	return labels[ method ] || method;
}

/**
 * Turns a job summary ({ bucket: count }) into a sentence.
 *
 * @param {Object} summary Summary.
 * @return {string} Sentence, or '' when empty.
 */
export function summaryText( summary ) {
	if ( ! summary || typeof summary !== 'object' ) {
		return '';
	}
	const parts = Object.entries( summary )
		.filter( ( [ , count ] ) => count > 0 )
		.map( ( [ bucket, count ] ) => bucketText( bucket, count ) );
	return parts.join( ', ' );
}

/**
 * Describes one summary bucket.
 *
 * @param {string} bucket Bucket.
 * @param {number} count  Count.
 * @return {string} Words.
 */
function bucketText( bucket, count ) {
	switch ( bucket ) {
		case 'created':
			return sprintf(
				/* translators: %d: number of items. */
				_n( '%d created', '%d created', count, 'jcore-silta' ),
				count
			);
		case 'updated':
			return sprintf(
				/* translators: %d: number of items. */
				_n( '%d updated', '%d updated', count, 'jcore-silta' ),
				count
			);
		case 'unchanged':
			return sprintf(
				/* translators: %d: number of items. */
				_n( '%d unchanged', '%d unchanged', count, 'jcore-silta' ),
				count
			);
		case 'matched':
			return sprintf(
				/* translators: %d: number of items. */
				_n(
					'%d already there',
					'%d already there',
					count,
					'jcore-silta'
				),
				count
			);
		case 'trashed':
			return sprintf(
				/* translators: %d: number of items. */
				_n(
					'%d moved to the trash',
					'%d moved to the trash',
					count,
					'jcore-silta'
				),
				count
			);
		case 'skipped':
			return sprintf(
				/* translators: %d: number of items. */
				_n( '%d skipped', '%d skipped', count, 'jcore-silta' ),
				count
			);
		case 'failed':
			return sprintf(
				/* translators: %d: number of items. */
				_n( '%d failed', '%d failed', count, 'jcore-silta' ),
				count
			);
		case 'planned':
		case 'pending':
		case 'transferred':
			return sprintf(
				/* translators: %d: number of items. */
				_n( '%d not written', '%d not written', count, 'jcore-silta' ),
				count
			);
		default:
			return `${ count } ${ bucket }`;
	}
}

/**
 * Describes a problem with a connection, or returns '' when it works.
 *
 * @param {Object} connection Connection with status and error.
 * @return {string} Explanation.
 */
export function connectionProblem( connection ) {
	switch ( connection?.status ) {
		case 'inherited':
			return __(
				'This connection came with a copied database and is switched off. Pair the sites again.',
				'jcore-silta'
			);
		case 'repair':
			return __(
				"This site's key has changed, so the other site no longer recognises it. Pair the sites again.",
				'jcore-silta'
			);
		case 'error':
			return (
				connection.error ||
				__(
					'The last attempt to reach this site failed.',
					'jcore-silta'
				)
			);
		default:
			return '';
	}
}

/**
 * Whether a connection can be used at all (an error may be temporary).
 *
 * @param {Object} connection Connection.
 * @return {boolean} Usable.
 */
export const isUsable = ( connection ) =>
	! [ 'inherited', 'repair' ].includes( connection?.status );

/**
 * Parses a GMT 'Y-m-d H:i:s' string from the database.
 *
 * @param {string} value Date string.
 * @return {Date|null} Date.
 */
export function parseGmt( value ) {
	if ( ! value || value.startsWith( '0000' ) ) {
		return null;
	}
	const parsed = new Date( value.replace( ' ', 'T' ) + 'Z' );
	return isNaN( parsed.getTime() ) ? null : parsed;
}

/**
 * Formats a date in the site's date and time format.
 *
 * @param {Date|null} date Date.
 * @return {string} Formatted date, or a dash.
 */
export function formatDate( date ) {
	if ( ! date ) {
		return '—';
	}
	const formats = getSettings().formats || {};
	return dateI18n( formats.datetime || 'Y-m-d H:i', date );
}

/**
 * Formats a date relative to now ("5 minutes ago").
 *
 * @param {Date|null} date Date.
 * @return {string} Relative date, or a dash.
 */
export const relativeDate = ( date ) => ( date ? humanTimeDiff( date ) : '—' );

/**
 * Turns a Unix timestamp in seconds into a Date.
 *
 * @param {number} seconds Timestamp.
 * @return {Date|null} Date.
 */
export const fromUnix = ( seconds ) =>
	seconds > 0 ? new Date( seconds * 1000 ) : null;

/**
 * Labels object subtypes the API returns raw (post types, taxonomies).
 *
 * @param {string} subtype    Post type, taxonomy or 'nav_menu'.
 * @param {Object} typeLabels Known labels, by name.
 * @return {string} Label.
 */
export function subtypeLabel( subtype, typeLabels = {} ) {
	if ( typeLabels[ subtype ] ) {
		return typeLabels[ subtype ];
	}
	const known = {
		post: __( 'Post', 'jcore-silta' ),
		page: __( 'Page', 'jcore-silta' ),
		attachment: __( 'Media', 'jcore-silta' ),
		wp_block: __( 'Pattern', 'jcore-silta' ),
		nav_menu: __( 'Menu', 'jcore-silta' ),
		category: __( 'Category', 'jcore-silta' ),
		post_tag: __( 'Tag', 'jcore-silta' ),
	};
	return known[ subtype ] || subtype || '';
}
