import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	ProgressBar,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import ConfirmModal from '../components/ConfirmModal';
import ErrorNotice from '../components/ErrorNotice';
import { phaseLabel } from '../words';

/**
 * Counts the items a phase has finished.
 *
 * @param {Object}  progress  Progress: counts and total.
 * @param {boolean} isRunning Whether the job is being written.
 * @return {number} Finished items.
 */
export function finished( progress, isRunning ) {
	const counts = progress?.counts || {};
	if ( isRunning ) {
		return (
			( counts.imported || 0 ) +
			( counts.skipped || 0 ) +
			( counts.failed || 0 )
		);
	}
	return Math.max( 0, ( progress?.total || 0 ) - ( counts.pending || 0 ) );
}

/**
 * Says how far the phase is.
 *
 * @param {boolean} isRunning Whether the job is being written.
 * @param {number}  done      Finished items.
 * @param {number}  total     All items.
 * @return {string} Text.
 */
function countText( isRunning, done, total ) {
	if ( isRunning ) {
		return sprintf(
			/* translators: 1: finished items, 2: all items. */
			__( '%1$d of %2$d written', 'jcore-silta' ),
			done,
			total
		);
	}
	return sprintf(
		/* translators: 1: finished items, 2: all items. */
		__( '%1$d of %2$d compared', 'jcore-silta' ),
		done,
		total
	);
}

/**
 * Progress of preparing (step 4) or running (step 5) a sync.
 *
 * @param {Object} props      Props.
 * @param {Object} props.flow Sync flow.
 * @return {Element} Progress card.
 */
export default function JobProgress( { flow } ) {
	const [ confirmStop, setConfirmStop ] = useState( false );
	const { progress, job, error, step, targetLabel } = flow;
	const isRunning = step === 'running';
	const total = progress?.total || 0;
	const done = Math.min( total, finished( progress, isRunning ) );
	const value = total > 0 ? Math.round( ( done / total ) * 100 ) : undefined;

	return (
		<Card>
			<CardHeader>
				<h2>
					{ isRunning
						? sprintf(
								/* translators: %s: target site name. */
								__( 'Syncing to %s', 'jcore-silta' ),
								targetLabel
							)
						: __( 'Preparing the sync', 'jcore-silta' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<ErrorNotice error={ error } />
				<p className="jcore-silta-progress__phase" aria-live="polite">
					{ job && phaseLabel( job.phase ) }
					{ total > 0 && ' · ' + countText( isRunning, done, total ) }
				</p>
				<ProgressBar
					className="jcore-silta-progress__bar"
					value={ error ? value || 0 : value }
				/>
				<p className="description">
					{ isRunning
						? __(
								'Keep this page open. If it closes, you can continue from History.',
								'jcore-silta'
							)
						: __(
								'Nothing is written yet. This compares every item with the target.',
								'jcore-silta'
							) }
				</p>
				<Flex justify="flex-end" className="jcore-silta-actions">
					{ isRunning ? (
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							isDestructive
							onClick={ () => setConfirmStop( true ) }
						>
							{ __( 'Stop', 'jcore-silta' ) }
						</Button>
					) : (
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ flow.discard }
						>
							{ __( 'Cancel', 'jcore-silta' ) }
						</Button>
					) }
					{ error && (
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ flow.retry }
						>
							{ __( 'Retry', 'jcore-silta' ) }
						</Button>
					) }
				</Flex>
			</CardBody>
			{ confirmStop && (
				<ConfirmModal
					title={ __( 'Stop the sync?', 'jcore-silta' ) }
					confirmLabel={ __( 'Stop', 'jcore-silta' ) }
					isDestructive
					onCancel={ () => setConfirmStop( false ) }
					onConfirm={ () => {
						setConfirmStop( false );
						flow.stop();
					} }
				>
					<p>
						{ __(
							'What has been written so far stays on the target. You can undo it from History.',
							'jcore-silta'
						) }
					</p>
				</ConfirmModal>
			) }
		</Card>
	);
}
