import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage, get } from '../api';
import Badge from '../components/Badge';
import ErrorNotice from '../components/ErrorNotice';
import Pagination from '../components/Pagination';
import { UndoReport } from '../sync/Result';
import UndoButton, { UndoWindow } from '../sync/UndoButton';
import {
	formatDate,
	jobStatusLabel,
	jobStatusTone,
	parseGmt,
	relativeDate,
	summaryText,
} from '../words';

const PER_PAGE = 20;

/**
 * The History tab: past syncs, newest first.
 *
 * @param {Object}                       props        Props.
 * @param {(...args: unknown[]) => void} props.onOpen Opens a job in the Sync tab.
 * @return {Element} Tab.
 */
export default function HistoryTab( { onOpen } ) {
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ reload, setReload ] = useState( 0 );
	const [ undone, setUndone ] = useState( null );

	useEffect( () => {
		let alive = true;
		setError( '' );
		get( '/jobs', { page } )
			.then( ( response ) => alive && setData( response ) )
			.catch( ( e ) => alive && setError( errorMessage( e ) ) );
		return () => {
			alive = false;
		};
	}, [ page, reload ] );

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'History', 'jcore-silta' ) }</h2>
			</CardHeader>
			<CardBody>
				<p className="description">
					{ __(
						'Syncs started here and syncs other sites pushed here. A sync can be undone while its snapshots are kept.',
						'jcore-silta'
					) }
				</p>
				<ErrorNotice error={ error } />
				{ undone && <UndoReport result={ undone } /> }
				{ ! data && ! error && <Spinner /> }
				{ data && data.jobs.length === 0 && (
					<p>{ __( 'No syncs yet.', 'jcore-silta' ) }</p>
				) }
				{ data && data.jobs.length > 0 && (
					<table className="widefat striped jcore-silta-history">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Date', 'jcore-silta' ) }
								</th>
								<th scope="col">
									{ __( 'What', 'jcore-silta' ) }
								</th>
								<th scope="col">
									{ __( 'By', 'jcore-silta' ) }
								</th>
								<th scope="col">
									{ __( 'Status', 'jcore-silta' ) }
								</th>
								<th scope="col">
									{ __( 'Result', 'jcore-silta' ) }
								</th>
								<th scope="col">
									<span className="screen-reader-text">
										{ __( 'Actions', 'jcore-silta' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ data.jobs.map( ( job ) => (
								<HistoryRow
									key={ job.id }
									job={ job }
									onOpen={ onOpen }
									onUndone={ ( result ) => {
										setUndone( result );
										setReload( ( value ) => value + 1 );
									} }
								/>
							) ) }
						</tbody>
					</table>
				) }
				{ data && (
					<Pagination
						page={ page }
						pages={ Math.ceil( data.total / PER_PAGE ) }
						onChange={ setPage }
					/>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * One past sync.
 *
 * @param {Object}                       props          Props.
 * @param {Object}                       props.job      Job.
 * @param {(...args: unknown[]) => void} props.onOpen   Opens the job.
 * @param {(...args: unknown[]) => void} props.onUndone Called after an undo.
 * @return {Element} Row.
 */
function HistoryRow( { job, onOpen, onUndone } ) {
	const created = parseGmt( job.created_at );

	return (
		<tr>
			<td>
				<span title={ formatDate( created ) }>
					{ relativeDate( created ) }
				</span>
			</td>
			<td>{ describe( job ) }</td>
			<td>{ job.user || '—' }</td>
			<td>
				<Badge tone={ jobStatusTone( job.status ) }>
					{ jobStatusLabel( job.status ) }
				</Badge>
				<UndoWindow job={ job } />
			</td>
			<td>{ summaryText( job.summary ) || '—' }</td>
			<td className="jcore-silta-history__actions">
				<Button
					size="compact"
					variant="secondary"
					onClick={ () => onOpen( job.id ) }
				>
					{ __( 'Open', 'jcore-silta' ) }
				</Button>
				<UndoButton job={ job } size="compact" onUndone={ onUndone } />
			</td>
		</tr>
	);
}

/**
 * Describes a job in a few words.
 *
 * @param {Object} job Job.
 * @return {string} Description.
 */
function describe( job ) {
	if ( job.role === 'target' ) {
		return sprintf(
			/* translators: %s: site name. */
			__( 'Received from %s', 'jcore-silta' ),
			job.remote_label
		);
	}
	if ( job.direction === 'push' ) {
		return sprintf(
			/* translators: %s: site name. */
			__( 'Pushed to %s', 'jcore-silta' ),
			job.remote_label
		);
	}
	return sprintf(
		/* translators: %s: site name. */
		__( 'Pulled from %s', 'jcore-silta' ),
		job.remote_label
	);
}
