import { Button, CheckboxControl, ExternalLink } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Badge from '../components/Badge';
import {
	actionLabel,
	actionTone,
	matchMethodLabel,
	subtypeLabel,
} from '../words';
import ItemDiff from './ItemDiff';
import MatchOverride from './MatchOverride';
import { blockingError, canInclude } from './items';

/**
 * One item in the review: include checkbox, what will happen and why.
 *
 * @param {Object}                       props         Props.
 * @param {Object}                       props.item    Item.
 * @param {Object}                       props.flow    Sync flow.
 * @param {Set}                          props.keys    Keys of every item in the job.
 * @param {(...args: unknown[]) => void} props.onPatch Changes the item.
 * @return {Element} Row.
 */
export default function ReviewItem( { item, flow, keys, onPatch } ) {
	const [ showDiff, setShowDiff ] = useState( false );
	const isOrphan = item.object_type === 'orphan';
	const title = item.title || item.plan?.title || item.key;
	const blocked = blockingError( item );
	const isDiffMode = !! flow.job?.options?.diff;
	const type =
		item.object_type === 'menu'
			? __( 'Menu', 'jcore-silta' )
			: subtypeLabel( item.subtype, flow.typeLabels );

	return (
		<li
			className={ `jcore-silta-item${ item.included ? '' : ' is-excluded' }` }
		>
			<div className="jcore-silta-item__head">
				<CheckboxControl
					__nextHasNoMarginBottom
					className="jcore-silta-item__check"
					label={
						isOrphan
							? sprintf(
									/* translators: 1: post title, 2: target site name. */
									__(
										'Move “%1$s” to the trash on %2$s',
										'jcore-silta'
									),
									title,
									flow.targetLabel
								)
							: title
					}
					checked={ item.included }
					disabled={ ! canInclude( item ) }
					onChange={ ( included ) => onPatch( item, { included } ) }
				/>
				<span className="jcore-silta-item__type">
					{ type }
					{ item.level > 0 &&
						' · ' +
							sprintf(
								/* translators: %d: dependency level. */
								__( 'level %d', 'jcore-silta' ),
								item.level
							) }
				</span>
				<Badge tone={ actionTone( item.action ) }>
					{ actionLabel( item.action ) }
				</Badge>
			</div>

			<div className="jcore-silta-item__details">
				{ isOrphan && item.target_id > 0 && (
					<p>
						{ sprintf(
							/* translators: %d: post ID. */
							__( 'Post #%d on the target.', 'jcore-silta' ),
							item.target_id
						) }
					</p>
				) }
				{ blocked && (
					<p className="jcore-silta-item__error">
						{ sprintf(
							/* translators: %s: error message. */
							__(
								'This item can’t be synced: %s',
								'jcore-silta'
							),
							blocked
						) }
					</p>
				) }
				<Match item={ item } />
				<Conflict item={ item } flow={ flow } onPatch={ onPatch } />
				<SectionErrors item={ item } />
				<Missing item={ item } keys={ keys } />
				{ item.object_type === 'post' && ! blocked && (
					<MatchOverride item={ item } onPatch={ onPatch } />
				) }
				{ isDiffMode && item.action === 'create' && (
					<p className="description">
						{ __(
							'New: everything will be created.',
							'jcore-silta'
						) }
					</p>
				) }
				{ isDiffMode && item.action === 'update' && (
					<>
						<Button
							variant="link"
							aria-expanded={ showDiff }
							onClick={ () => setShowDiff( ! showDiff ) }
						>
							{ showDiff
								? __( 'Hide the changes', 'jcore-silta' )
								: __(
										'Show the changes and choose parts',
										'jcore-silta'
									) }
						</Button>
						{ item.sections !== null && ! showDiff && (
							<span className="jcore-silta-item__note">
								{ __(
									'Only the chosen parts will be synced.',
									'jcore-silta'
								) }
							</span>
						) }
						{ showDiff && (
							<ItemDiff
								jobId={ flow.job.id }
								item={ item }
								targetLabel={ flow.targetLabel }
								onPatch={ onPatch }
							/>
						) }
					</>
				) }
			</div>
		</li>
	);
}

/**
 * Which target object the item was matched with.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Item.
 * @return {Element|null} Line.
 */
function Match( { item } ) {
	const match = item.plan?.match;
	if ( ! match ) {
		return null;
	}
	const link = match.edit || match.view;
	return (
		<p className="jcore-silta-item__match">
			{ sprintf(
				/* translators: 1: title on the target, 2: ID on the target, 3: how it was matched, e.g. "the same slug". */
				__( 'Matches “%1$s” (#%2$d) by %3$s.', 'jcore-silta' ),
				match.title,
				match.id,
				matchMethodLabel( match.method )
			) }{ ' ' }
			{ link && (
				<ExternalLink href={ link }>
					{ match.edit
						? __( 'Edit on the target', 'jcore-silta' )
						: __( 'View on the target', 'jcore-silta' ) }
				</ExternalLink>
			) }
		</p>
	);
}

/**
 * Warns about a post edited on the target, with the overwrite checkbox.
 *
 * @param {Object}                       props         Props.
 * @param {Object}                       props.item    Item.
 * @param {Object}                       props.flow    Sync flow.
 * @param {(...args: unknown[]) => void} props.onPatch Changes the item.
 * @return {Element|null} Warning.
 */
function Conflict( { item, flow, onPatch } ) {
	if ( ! item.conflict || item.object_type === 'orphan' ) {
		return null;
	}
	const message =
		item.plan?.conflict === 'newer'
			? sprintf(
					/* translators: %s: target site name. */
					__(
						'Changed on %s more recently than here.',
						'jcore-silta'
					),
					flow.targetLabel
				)
			: sprintf(
					/* translators: %s: target site name. */
					__( 'Edited on %s since the last sync.', 'jcore-silta' ),
					flow.targetLabel
				);

	return (
		<div className="jcore-silta-item__conflict">
			<p>
				{ message }{ ' ' }
				{ ! item.confirmed &&
					__(
						'It is skipped unless you overwrite it.',
						'jcore-silta'
					) }
			</p>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Overwrite it', 'jcore-silta' ) }
				checked={ item.confirmed }
				onChange={ ( confirmed ) => onPatch( item, { confirmed } ) }
			/>
		</div>
	);
}

/**
 * Lists the parts of an item that can't be synced.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Item.
 * @return {Element|null} List.
 */
function SectionErrors( { item } ) {
	const errors = Object.entries( item.plan?.errors || {} ).filter(
		( [ key ] ) => key !== ''
	);
	if ( ! errors.length ) {
		return null;
	}
	return (
		<ul className="jcore-silta-item__errors">
			{ errors.map( ( [ key, message ] ) => (
				<li key={ key }>
					{ sprintf(
						/* translators: 1: part of the post, e.g. "Categories", 2: why it can't be synced. */
						__( '%1$s can’t be synced: %2$s', 'jcore-silta' ),
						item.plan.sections?.[ key ] || key,
						message
					) }
				</li>
			) ) }
		</ul>
	);
}

/**
 * Lists the references that are neither on the target nor in this sync.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Item.
 * @param {Set}    props.keys Keys of every item in the job.
 * @return {Element|null} List.
 */
function Missing( { item, keys } ) {
	const missing = ( item.plan?.missing || [] ).filter(
		( entry ) => ! keys.has( entry.key )
	);
	if ( ! missing.length ) {
		return null;
	}
	return (
		<div className="jcore-silta-item__missing">
			<p>
				{ __(
					'Refers to content the target doesn’t have and this sync doesn’t include. The references will point to nothing:',
					'jcore-silta'
				) }
			</p>
			<ul>
				{ missing.map( ( entry ) => (
					<li key={ entry.key }>
						{ entry.title || entry.key }
						{ entry.subtype &&
							` (${ subtypeLabel( entry.subtype ) })` }
						{ entry.dangling &&
							' — ' +
								__(
									'missing on the source too',
									'jcore-silta'
								) }
					</li>
				) ) }
			</ul>
		</div>
	);
}
