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
 * @param {Object}                       props             Props.
 * @param {string}                       props.title       Group title.
 * @param {string}                       props.description Explanation.
 * @param {Array}                        props.items       Items in the group.
 * @param {Object}                       props.flow        Sync flow.
 * @param {Set}                          props.keys        Keys of every item in the job.
 * @param {(...args: unknown[]) => void} props.onPatch     Changes one item.
 * @param {(...args: unknown[]) => void} props.onPatchMany Changes several items.
 * @return {Element} Group.
 */
export default function ReviewGroup( {
	title,
	description,
	items,
	flow,
	keys,
	onPatch,
	onPatchMany,
} ) {
	const [ shown, setShown ] = useState( PAGE );
	const includable = items.filter( canInclude );
	const includedCount = items.filter( ( item ) => item.included ).length;
	const left = items.length - shown;

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
						{ __( 'Include all', 'jcore-silta' ) }
					</Button>
					<Button
						size="compact"
						variant="secondary"
						onClick={ () =>
							onPatchMany(
								items.filter( ( item ) => item.included ),
								{ included: false }
							)
						}
					>
						{ __( 'Include none', 'jcore-silta' ) }
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
					{ items.slice( 0, shown ).map( ( item ) => (
						<ReviewItem
							key={ item.id }
							item={ item }
							flow={ flow }
							keys={ keys }
							onPatch={ onPatch }
						/>
					) ) }
				</ul>
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
