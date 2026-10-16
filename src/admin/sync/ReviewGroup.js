import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import ReviewItem from './ReviewItem';
import { canInclude } from './items';

const PAGE = 50;

/**
 * One group of review rows, with bulk include buttons.
 *
 * @param {Object}                       props              Props.
 * @param {string}                       props.title        Group title.
 * @param {string}                       props.description  Explanation.
 * @param {Array}                        props.items        Items in the group.
 * @param {Array}                        props.visible      Items the filters let through.
 * @param {number}                       props.hidden       Unchanged items left out.
 * @param {boolean}                      props.isFiltered   Whether a filter or search is on.
 * @param {(...args: unknown[]) => void} props.onShowHidden Shows the unchanged items.
 * @param {Object}                       props.flow         Sync flow.
 * @param {Set}                          props.keys         Keys of every item in the job.
 * @param {(...args: unknown[]) => void} props.onPatch      Changes one item.
 * @param {(...args: unknown[]) => void} props.onPatchMany  Changes several items.
 * @return {Element} Group.
 */
export default function ReviewGroup( {
	title,
	description,
	items,
	visible,
	hidden,
	isFiltered,
	onShowHidden,
	flow,
	keys,
	onPatch,
	onPatchMany,
} ) {
	const [ shown, setShown ] = useState( PAGE );
	// With a filter on, the bulk buttons act on what is shown.
	const targets = isFiltered ? visible : items;
	const includable = targets.filter( canInclude );
	const includedCount = items.filter( ( item ) => item.included ).length;
	const left = visible.length - shown;

	return (
		<Card className="jcore-silta-group">
			<CardHeader>
				<div>
					<h3>
						{ title }{ ' ' }
						<span className="jcore-silta-group__count">
							{ sprintf(
								/* translators: 1: included items, 2: all items. */
								__( '%1$d of %2$d included', 'jcore-silta' ),
								includedCount,
								items.length
							) }
						</span>
					</h3>
					{ description && (
						<p className="description">{ description }</p>
					) }
				</div>
				<Flex justify="flex-end" gap={ 2 } expanded={ false }>
					<Button
						size="compact"
						variant="secondary"
						onClick={ () =>
							onPatchMany(
								includable.filter(
									( item ) => ! item.included
								),
								{ included: true }
							)
						}
					>
						{ isFiltered
							? __( 'Include these', 'jcore-silta' )
							: __( 'Include all', 'jcore-silta' ) }
					</Button>
					<Button
						size="compact"
						variant="secondary"
						onClick={ () =>
							onPatchMany(
								targets.filter( ( item ) => item.included ),
								{ included: false }
							)
						}
					>
						{ isFiltered
							? __( 'Exclude these', 'jcore-silta' )
							: __( 'Include none', 'jcore-silta' ) }
					</Button>
				</Flex>
			</CardHeader>
			<CardBody>
				{ items.length === 0 && (
					<p className="description">
						{ __( 'Nothing here.', 'jcore-silta' ) }
					</p>
				) }
				<ul className="jcore-silta-items">
					{ visible.slice( 0, shown ).map( ( item ) => (
						<ReviewItem
							key={ item.id }
							item={ item }
							flow={ flow }
							keys={ keys }
							onPatch={ onPatch }
						/>
					) ) }
				</ul>
				{ hidden > 0 && (
					<p className="description">
						{ sprintf(
							/* translators: %d: number of items. */
							_n(
								'%d item without changes is hidden.',
								'%d items without changes are hidden.',
								hidden,
								'jcore-silta'
							),
							hidden
						) }{ ' ' }
						<Button variant="link" onClick={ onShowHidden }>
							{ __( 'Show them', 'jcore-silta' ) }
						</Button>
					</p>
				) }
				{ left > 0 && (
					<Button
						variant="secondary"
						onClick={ () => setShown( shown + PAGE ) }
					>
						{ sprintf(
							/* translators: 1: items to show, 2: items not shown yet. */
							_n(
								'Show %1$d more (%2$d left)',
								'Show %1$d more (%2$d left)',
								Math.min( PAGE, left ),
								'jcore-silta'
							),
							Math.min( PAGE, left ),
							left
						) }
					</Button>
				) }
			</CardBody>
		</Card>
	);
}
