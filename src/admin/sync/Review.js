import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	Notice,
	SearchControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { errorMessage, fetchJobItems, patch } from '../api';
import ConfirmModal from '../components/ConfirmModal';
import ErrorNotice from '../components/ErrorNotice';
import BulkSections from './BulkSections';
import ReviewGroup from './ReviewGroup';
import { isSkippedConflict, itemCategory, itemTitle } from './items';

/**
 * Step 4: review what the sync will do, adjust it, then run it.
 *
 * @param {Object} props      Props.
 * @param {Object} props.flow Sync flow.
 * @return {Element} Review.
 */
export default function Review( { flow } ) {
	const { job } = flow;
	const [ items, setItems ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ confirmRun, setConfirmRun ] = useState( false );
	const [ reload, setReload ] = useState( 0 );
	const [ filter, setFilter ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ hideUnchanged, setHideUnchanged ] = useState( true );

	useEffect( () => {
		let alive = true;
		setError( '' );
		fetchJobItems( job.id )
			.then( ( list ) => alive && setItems( list ) )
			.catch( ( e ) => alive && setError( errorMessage( e ) ) );
		return () => {
			alive = false;
		};
	}, [ job.id, reload ] );

	const keys = useMemo(
		() => new Set( ( items || [] ).map( ( item ) => item.key ) ),
		[ items ]
	);

	const replace = ( updated ) =>
		setItems( ( current ) =>
			current.map( ( item ) =>
				item.id === updated.id ? { ...item, ...updated } : item
			)
		);

	/**
	 * Changes one item: optimistic for flags, re-planned for overrides.
	 *
	 * @param {Object} item    Item.
	 * @param {Object} changes Changes.
	 * @return {Promise<void>} Done.
	 */
	const patchItem = async ( item, changes ) => {
		const optimistic = { ...changes };
		delete optimistic.override;
		replace( { ...item, ...optimistic } );
		try {
			replace(
				await patch( `/jobs/${ job.id }/items/${ item.id }`, changes )
			);
		} catch ( e ) {
			replace( item );
			setError( errorMessage( e ) );
		}
	};

	/**
	 * Changes several items at once.
	 *
	 * @param {Array}  targets Items.
	 * @param {Object} changes Changes.
	 * @return {Promise<void>} Done.
	 */
	const patchMany = async ( targets, changes ) => {
		if ( ! targets.length ) {
			return;
		}
		const ids = new Set( targets.map( ( item ) => item.id ) );
		const before = items;
		setItems( ( current ) =>
			current.map( ( item ) =>
				ids.has( item.id ) ? { ...item, ...changes } : item
			)
		);
		try {
			await patch( `/jobs/${ job.id }/items`, {
				ids: [ ...ids ],
				...changes,
			} );
		} catch ( e ) {
			setItems( before );
			setError( errorMessage( e ) );
		}
	};

	if ( ! items ) {
		return (
			<Card>
				<CardBody>
					<ErrorNotice
						error={ error }
						actions={ [
							{
								label: __( 'Try again', 'jcore-silta' ),
								onClick: () =>
									setReload( ( value ) => value + 1 ),
							},
						] }
					/>
					{ ! error && <Spinner /> }
				</CardBody>
			</Card>
		);
	}

	const selected = items.filter(
		( item ) => item.object_type !== 'orphan' && item.level === 0
	);
	const dependencies = items.filter(
		( item ) => item.object_type !== 'orphan' && item.level > 0
	);
	const orphans = items.filter( ( item ) => item.object_type === 'orphan' );
	const included = items.filter( ( item ) => item.included );
	const conflicts = included.filter( isSkippedConflict );
	const groupProps = {
		flow,
		keys,
		onPatch: patchItem,
		onPatchMany: patchMany,
		isFiltered: !! filter || !! search.trim(),
		onShowHidden: () => setHideUnchanged( false ),
	};

	// Narrows a group to what the summary filter and the search let through.
	// Without either, unchanged items can be tucked away; a filter or search
	// is explicit, so it shows everything that matches.
	const query = search.trim().toLowerCase();
	const view = ( list ) => {
		const matching = list.filter(
			( item ) =>
				( ! filter || itemCategory( item ) === filter ) &&
				( ! query || itemTitle( item ).toLowerCase().includes( query ) )
		);
		const visible =
			! groupProps.isFiltered && hideUnchanged
				? matching.filter(
						( item ) => itemCategory( item ) !== 'unchanged'
					)
				: matching;
		return {
			items: list,
			visible,
			hidden: matching.length - visible.length,
		};
	};
	const views = {
		selected: view( selected ),
		dependencies: view( dependencies ),
		orphans: view( orphans ),
	};
	const noMatch =
		groupProps.isFiltered &&
		Object.values( views ).every( ( entry ) => ! entry.visible.length );
	const hasUnchanged = items.some(
		( item ) => itemCategory( item ) === 'unchanged'
	);

	return (
		<>
			<Card>
				<CardHeader>
					<h2>{ __( 'Review the changes', 'jcore-silta' ) }</h2>
				</CardHeader>
				<CardBody>
					<ErrorNotice
						error={ error || flow.error }
						onRemove={ () => {
							setError( '' );
							flow.setError( '' );
						} }
					/>
					<Summary
						items={ items }
						filter={ filter }
						onFilter={ setFilter }
					/>
					<div className="jcore-silta-review-tools">
						<SearchControl
							__nextHasNoMarginBottom
							label={ __( 'Search the items', 'jcore-silta' ) }
							value={ search }
							onChange={ setSearch }
						/>
						{ hasUnchanged && ! groupProps.isFiltered && (
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __(
									'Hide items without changes',
									'jcore-silta'
								) }
								checked={ hideUnchanged }
								onChange={ setHideUnchanged }
							/>
						) }
					</div>
					{ noMatch && (
						<p className="description">
							{ __( 'No items match.', 'jcore-silta' ) }
						</p>
					) }
					{ job.options?.diff && (
						<BulkSections
							jobId={ job.id }
							onDone={ () => setReload( ( value ) => value + 1 ) }
						/>
					) }
					{ conflicts.length > 0 && (
						<Notice
							status="warning"
							isDismissible={ false }
							actions={ [
								{
									label: __(
										'Overwrite them all',
										'jcore-silta'
									),
									onClick: () =>
										patchMany( conflicts, {
											confirmed: true,
										} ),
								},
							] }
						>
							{ sprintf(
								/* translators: 1: number of posts, 2: target site name. */
								_n(
									'%1$d post was edited on %2$s since the last sync. It is skipped unless you choose to overwrite it.',
									'%1$d posts were edited on %2$s since the last sync. They are skipped unless you choose to overwrite them.',
									conflicts.length,
									'jcore-silta'
								),
								conflicts.length,
								flow.targetLabel
							) }
						</Notice>
					) }
					{ job.next > 0 && (
						<Notice
							status="info"
							isDismissible={ false }
							actions={ [
								{
									label: __( 'Add them too', 'jcore-silta' ),
									onClick: flow.expand,
									variant: 'secondary',
								},
							] }
						>
							{ sprintf(
								/* translators: %d: number of items. */
								_n(
									'%d more item is referenced by the dependencies and is missing on the target.',
									'%d more items are referenced by the dependencies and are missing on the target.',
									job.next,
									'jcore-silta'
								),
								job.next
							) }
						</Notice>
					) }
				</CardBody>
			</Card>

			{ ( ! groupProps.isFiltered ||
				views.selected.visible.length > 0 ) && (
				<ReviewGroup
					{ ...groupProps }
					{ ...views.selected }
					title={ __( 'Selected', 'jcore-silta' ) }
				/>
			) }
			{ views.dependencies.visible.length + views.dependencies.hidden >
				0 && (
				<ReviewGroup
					{ ...groupProps }
					title={ __( 'Dependencies', 'jcore-silta' ) }
					description={ __(
						'Media, terms and posts the selected content refers to, which the target does not have yet.',
						'jcore-silta'
					) }
					{ ...views.dependencies }
				/>
			) }
			{ views.orphans.visible.length + views.orphans.hidden > 0 && (
				<ReviewGroup
					{ ...groupProps }
					title={ __( 'Removed on the source', 'jcore-silta' ) }
					description={ sprintf(
						/* translators: 1: target site name, 2: source site name. */
						__(
							'These posts on %1$s came from %2$s in an earlier sync, but the originals no longer exist. Tick the ones to move to the trash. Nothing is deleted permanently.',
							'jcore-silta'
						),
						flow.targetLabel,
						flow.sourceLabel
					) }
					{ ...views.orphans }
				/>
			) }

			<Card className="jcore-silta-footer">
				<CardBody>
					<Flex justify="space-between" wrap>
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ flow.discard }
						>
							{ __(
								'Discard and change the options',
								'jcore-silta'
							) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							disabled={ ! included.length || flow.isBusy }
							accessibleWhenDisabled
							onClick={ () => setConfirmRun( true ) }
						>
							{ __( 'Run sync', 'jcore-silta' ) }
						</Button>
					</Flex>
				</CardBody>
			</Card>

			{ confirmRun && (
				<ConfirmModal
					title={ __( 'Run the sync?', 'jcore-silta' ) }
					confirmLabel={ __( 'Run sync', 'jcore-silta' ) }
					onCancel={ () => setConfirmRun( false ) }
					onConfirm={ () => {
						setConfirmRun( false );
						flow.run();
					} }
				>
					<p>
						{ sprintf(
							/* translators: 1: number of items, 2: target site name. */
							_n(
								'Write %1$d item to %2$s? Everything changed is snapshotted and can be undone from History.',
								'Write %1$d items to %2$s? Everything changed is snapshotted and can be undone from History.',
								included.length,
								'jcore-silta'
							),
							included.length,
							flow.targetLabel
						) }
					</p>
					{ conflicts.length > 0 && (
						<p>
							{ sprintf(
								/* translators: %d: number of posts. */
								_n(
									'%d post with a conflict will be skipped.',
									'%d posts with a conflict will be skipped.',
									conflicts.length,
									'jcore-silta'
								),
								conflicts.length
							) }
						</p>
					) }
				</ConfirmModal>
			) }
		</>
	);
}

/**
 * Counts what the sync will do. Each count also filters the items.
 *
 * @param {Object}                       props          Props.
 * @param {Array}                        props.items    All items.
 * @param {string}                       props.filter   Category shown, or '' for all.
 * @param {(...args: unknown[]) => void} props.onFilter Called with a category, or ''.
 * @return {Element} Summary.
 */
function Summary( { items, filter, onFilter } ) {
	const counts = {
		create: 0,
		update: 0,
		unchanged: 0,
		conflicts: 0,
		problems: 0,
		trash: 0,
	};
	const present = new Set();
	items.forEach( ( item ) => {
		const category = itemCategory( item );
		present.add( category );
		// Problems can't be included, so they count either way.
		if ( item.included || category === 'problems' ) {
			counts[ category ]++;
		}
	} );

	const labels = {
		create: __( 'to create', 'jcore-silta' ),
		update: __( 'to update', 'jcore-silta' ),
		unchanged: __( 'unchanged', 'jcore-silta' ),
		conflicts: __( 'conflicts', 'jcore-silta' ),
		problems: __( 'problems', 'jcore-silta' ),
		trash: __( 'to trash', 'jcore-silta' ),
	};

	return (
		<ul
			className="jcore-silta-summary"
			aria-label={ __( 'Show only', 'jcore-silta' ) }
		>
			{ Object.entries( counts ).map( ( [ key, count ] ) => (
				<li key={ key }>
					<button
						type="button"
						className={ `jcore-silta-summary__item is-${ key }${
							count ? '' : ' is-zero'
						}${ filter === key ? ' is-active' : '' }` }
						aria-pressed={ filter === key }
						disabled={ ! present.has( key ) && filter !== key }
						onClick={ () => onFilter( filter === key ? '' : key ) }
					>
						<span className="jcore-silta-summary__count">
							{ count }
						</span>
						<span className="jcore-silta-summary__label">
							{ labels[ key ] }
						</span>
					</button>
				</li>
			) ) }
		</ul>
	);
}
