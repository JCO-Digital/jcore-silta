import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage, post } from '../api';
import ConfirmModal from '../components/ConfirmModal';
import { site } from '../config';

/**
 * Whether a job can be undone.
 *
 * @param {Object} job Job.
 * @return {boolean} Undoable.
 */
export function canUndo( job ) {
	if ( job.role === 'target' ) {
		return job.status !== 'undone';
	}
	return [ 'done', 'running', 'cancelled', 'failed' ].includes( job.status );
}

/**
 * Undo button with its confirmation.
 *
 * @param {Object}                       props          Props.
 * @param {Object}                       props.job      Job.
 * @param {string}                       props.size     Button size.
 * @param {(...args: unknown[]) => void} props.onUndone Called with the undo result.
 * @return {Element|null} Button.
 */
export default function UndoButton( { job, size, onUndone } ) {
	const [ isAsking, setIsAsking ] = useState( false );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	if ( ! canUndo( job ) ) {
		return null;
	}

	// For a push and a received sync, the target is the other site or this one.
	let target = site.name;
	if ( job.role === 'initiator' && job.direction === 'push' ) {
		target = job.remote_label;
	}

	const undo = async () => {
		setIsBusy( true );
		setError( '' );
		try {
			const result = await post( `/jobs/${ job.id }/undo` );
			setIsAsking( false );
			onUndone( result );
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<>
			<Button
				__next40pxDefaultSize={ ! size }
				size={ size }
				variant="secondary"
				isDestructive
				onClick={ () => setIsAsking( true ) }
			>
				{ __( 'Undo this sync', 'jcore-silta' ) }
			</Button>
			{ isAsking && (
				<ConfirmModal
					title={ __( 'Undo this sync?', 'jcore-silta' ) }
					confirmLabel={ __( 'Undo', 'jcore-silta' ) }
					isDestructive
					isBusy={ isBusy }
					error={ error }
					onCancel={ () => setIsAsking( false ) }
					onConfirm={ undo }
				>
					<p>
						{ sprintf(
							/* translators: %s: site name. */
							__(
								'Everything this sync changed on %s is put back as it was before: updated content is restored, new content is removed and trashed posts are restored. Changes made there after the sync are lost.',
								'jcore-silta'
							),
							target
						) }
					</p>
				</ConfirmModal>
			) }
		</>
	);
}
