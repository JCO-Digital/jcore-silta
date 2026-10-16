/**
 * Keeps the browser tab in step with the sync: warns before leaving while a
 * sync is being prepared or written, shows its progress in the tab title, and
 * says so there when it finished while the tab was in the background.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { jobStatusLabel } from '../words';
import { finished } from './JobProgress';

/**
 * Words for the tab title while a sync is busy.
 *
 * @param {Object} flow Sync flow.
 * @return {string} Title prefix.
 */
function busyText( flow ) {
	const isRunning = flow.step === 'running';
	const total = flow.progress?.total || 0;
	if ( ! total ) {
		return isRunning
			? sprintf(
					/* translators: %s: target site name. */
					__( 'Syncing to %s', 'jcore-silta' ),
					flow.targetLabel
				)
			: __( 'Preparing the sync', 'jcore-silta' );
	}
	const percent = Math.round(
		( Math.min( total, finished( flow.progress, isRunning ) ) / total ) *
			100
	);
	return isRunning
		? sprintf(
				/* translators: 1: percentage done, 2: target site name. */
				__( '%1$d%% · Syncing to %2$s', 'jcore-silta' ),
				percent,
				flow.targetLabel
			)
		: sprintf(
				/* translators: %d: percentage done. */
				__( '%d%% · Preparing the sync', 'jcore-silta' ),
				percent
			);
}

/**
 * Words for the tab title when a busy step ended out of sight.
 *
 * @param {Object} flow Sync flow.
 * @return {string} Title prefix.
 */
function endText( flow ) {
	if ( flow.step === 'review' ) {
		return __( 'Ready to review', 'jcore-silta' );
	}
	return flow.job?.status === 'done'
		? __( 'Sync finished', 'jcore-silta' )
		: jobStatusLabel( flow.job?.status || '' );
}

/**
 * Ties the tab to the sync flow.
 *
 * @param {Object} flow Sync flow from useSyncFlow().
 */
export default function usePageStatus( flow ) {
	const { step } = flow;
	const isBusy = step === 'preparing' || step === 'running';
	const original = useRef( document.title );
	const wasBusy = useRef( false );
	const [ ended, setEnded ] = useState( '' );

	// Closing the tab pauses the sync; it continues from History, but a
	// warning saves the surprise.
	useEffect( () => {
		if ( ! isBusy ) {
			return;
		}
		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ isBusy ] );

	// Remember a sync that ended while nobody was looking, until they look.
	useEffect( () => {
		if ( wasBusy.current && ! isBusy && document.hidden ) {
			setEnded( endText( flow ) );
		}
		wasBusy.current = isBusy;
		// Only the step change matters; the text is read at that moment.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ isBusy ] );

	useEffect( () => {
		if ( ! ended ) {
			return;
		}
		const clear = () => ! document.hidden && setEnded( '' );
		document.addEventListener( 'visibilitychange', clear );
		return () => document.removeEventListener( 'visibilitychange', clear );
	}, [ ended ] );

	let prefix = ended;
	if ( isBusy ) {
		prefix = busyText( flow );
	}

	useEffect( () => {
		document.title = prefix
			? `${ prefix } · ${ original.current }`
			: original.current;
	}, [ prefix ] );

	useEffect(
		() => () => {
			document.title = original.current;
		},
		[]
	);
}
