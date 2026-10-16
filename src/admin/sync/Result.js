import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { fetchJobItems } from '../api';
import ErrorNotice from '../components/ErrorNotice';
import { jobStatusLabel, summaryText } from '../words';
import UndoButton, { UndoWindow } from './UndoButton';

/**
 * Step 5: what the sync did, with undo.
 *
 * @param {Object} props      Props.
 * @param {Object} props.flow Sync flow.
 * @return {Element} Result.
 */
export default function Result( { flow } ) {
	const { job } = flow;
	const [ problems, setProblems ] = useState( null );
	const [ undone, setUndone ] = useState( null );
	const isInitiator = job.role === 'initiator';

	useEffect( () => {
		if ( ! isInitiator ) {
			return;
		}
		let alive = true;
		fetchJobItems( job.id )
			.then(
				( items ) =>
					alive &&
					setProblems(
						items.filter(
							( item ) =>
								item.included &&
								( item.status === 'failed' ||
									item.status === 'skipped' ||
									item.result?.warnings?.length )
						)
					)
			)
			.catch( () => alive && setProblems( [] ) );
		return () => {
			alive = false;
		};
	}, [ job.id, isInitiator ] );

	const summary = summaryText( job.summary );

	return (
		<Card>
			<CardHeader>
				<h2>{ heading( job, flow.targetLabel ) }</h2>
			</CardHeader>
			<CardBody>
				<ErrorNotice error={ flow.error } />
				<p className="jcore-silta-result__summary">
					{ summary ||
						sprintf(
							/* translators: %s: status, e.g. Done. */
							__( 'Status: %s', 'jcore-silta' ),
							jobStatusLabel( job.status )
						) }
				</p>
				{ undone && <UndoReport result={ undone } /> }
				{ isInitiator && problems === null && <Spinner /> }
				{ problems?.length > 0 && <Problems items={ problems } /> }
				<UndoWindow job={ job } />
				<Flex justify="space-between" className="jcore-silta-actions">
					<UndoButton
						job={ job }
						onUndone={ ( result ) => {
							setUndone( result );
							flow.refresh();
						} }
					/>
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ flow.reset }
					>
						{ __( 'Start another sync', 'jcore-silta' ) }
					</Button>
				</Flex>
			</CardBody>
		</Card>
	);
}

/**
 * The result heading.
 *
 * @param {Object} job         Job.
 * @param {string} targetLabel Target site name.
 * @return {string} Heading.
 */
function heading( job, targetLabel ) {
	if ( job.role === 'target' ) {
		return sprintf(
			/* translators: %s: site name. */
			__( 'Received from %s', 'jcore-silta' ),
			job.remote_label
		);
	}
	switch ( job.status ) {
		case 'done':
			return sprintf(
				/* translators: %s: target site name. */
				__( 'Synced to %s', 'jcore-silta' ),
				targetLabel
			);
		case 'undone':
			return __( 'This sync was undone', 'jcore-silta' );
		case 'cancelled':
			return __( 'This sync was stopped', 'jcore-silta' );
		default:
			return jobStatusLabel( job.status );
	}
}

/**
 * Lists failed and skipped items and warnings.
 *
 * @param {Object} props       Props.
 * @param {Array}  props.items Items.
 * @return {Element} List.
 */
function Problems( { items } ) {
	return (
		<div className="jcore-silta-result__problems">
			<h3>{ __( 'Needs attention', 'jcore-silta' ) }</h3>
			<ul>
				{ items.map( ( item ) => (
					<li key={ item.id }>
						<strong>{ item.title || item.key }</strong>{ ' ' }
						{ itemProblem( item ) }
						{ ( item.result?.warnings || [] ).map(
							( warning, i ) => (
								<span
									key={ i }
									className="jcore-silta-result__warning"
								>
									{ warning }
								</span>
							)
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * Explains why an item failed or was skipped.
 *
 * @param {Object} item Item.
 * @return {string} Explanation.
 */
function itemProblem( item ) {
	const result = item.result || {};
	if ( item.status === 'failed' ) {
		return sprintf(
			/* translators: %s: error message. */
			__( 'failed: %s', 'jcore-silta' ),
			result.error || __( 'unknown error', 'jcore-silta' )
		);
	}
	if ( item.status === 'skipped' ) {
		if ( result.reason === 'conflict' ) {
			return __(
				'skipped: it was edited on the target and you did not choose to overwrite it.',
				'jcore-silta'
			);
		}
		if ( result.reason === 'gone' ) {
			return __( 'skipped: it was already gone.', 'jcore-silta' );
		}
		return __( 'skipped.', 'jcore-silta' );
	}
	return __( 'synced with warnings:', 'jcore-silta' );
}

/**
 * Reports what an undo did.
 *
 * @param {Object} props        Props.
 * @param {Object} props.result Undo result.
 * @return {Element} Notice.
 */
export function UndoReport( { result } ) {
	return (
		<Notice
			status={ result.errors?.length ? 'warning' : 'success' }
			isDismissible={ false }
		>
			<p>
				{ sprintf(
					/* translators: 1: restored items, 2: removed items, 3: items taken out of the trash. */
					__(
						'Undone: %1$d restored to how they were, %2$d created items removed, %3$d taken back out of the trash.',
						'jcore-silta'
					),
					result.restored || 0,
					result.removed || 0,
					result.untrashed || 0
				) }
			</p>
			{ result.errors?.length > 0 && (
				<ul>
					{ result.errors.map( ( message, i ) => (
						<li key={ i }>{ message }</li>
					) ) }
				</ul>
			) }
		</Notice>
	);
}
