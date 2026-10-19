import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { subtypeLabel } from '../words';

/**
 * Lists everything selected so far, with remove buttons.
 *
 * @param {Object}                       props          Props.
 * @param {Object}                       props.flow     Sync flow.
 * @param {Array}                        props.types    Post types of the source.
 * @param {Array}                        props.menus    Menus of the source.
 * @param {Array}                        props.forms    Gravity Forms forms of the source.
 * @param {(...args: unknown[]) => void} props.onChange Called with the new selection.
 * @return {Element} Summary.
 */
export default function SelectedSummary( {
	flow,
	types,
	menus,
	forms,
	onChange,
} ) {
	const { selection } = flow;
	const count =
		selection.posts.length +
		selection.post_types.length +
		selection.menus.length +
		selection.forms.length;

	const without = ( key, predicate ) =>
		onChange( {
			...selection,
			[ key ]: selection[ key ].filter(
				( entry ) => ! predicate( entry )
			),
		} );

	return (
		<div className="jcore-silta-selected" aria-live="polite">
			<h3>{ __( 'Selected', 'jcore-silta' ) }</h3>
			{ count === 0 && (
				<p className="description">
					{ __(
						'Nothing yet. Tick whole post types, single posts or menus.',
						'jcore-silta'
					) }
				</p>
			) }
			<ul>
				{ selection.post_types.map( ( name ) => {
					const type = types.find( ( entry ) => entry.name === name );
					return (
						<SelectedRow
							key={ `type-${ name }` }
							title={ sprintf(
								/* translators: 1: post type name, 2: number of posts. */
								_n(
									'All %1$s (%2$d)',
									'All %1$s (%2$d)',
									type?.count || 0,
									'jcore-silta'
								),
								type?.label || name,
								type?.count || 0
							) }
							onRemove={ () =>
								without(
									'post_types',
									( entry ) => entry === name
								)
							}
						/>
					);
				} ) }
				{ selection.posts.map( ( item ) => (
					<SelectedRow
						key={ `post-${ item.id }` }
						title={ item.title ?? `#${ item.id }` }
						type={ subtypeLabel( item.post_type, flow.typeLabels ) }
						onRemove={ () =>
							without(
								'posts',
								( entry ) => entry.id === item.id
							)
						}
					/>
				) ) }
				{ selection.menus.map( ( id ) => (
					<SelectedRow
						key={ `menu-${ id }` }
						title={
							menus.find( ( menu ) => menu.id === id )?.title ||
							`#${ id }`
						}
						type={ __( 'Menu', 'jcore-silta' ) }
						onRemove={ () =>
							without( 'menus', ( entry ) => entry === id )
						}
					/>
				) ) }
				{ selection.forms.map( ( id ) => (
					<SelectedRow
						key={ `form-${ id }` }
						title={
							forms.find( ( form ) => form.id === id )?.title ||
							`#${ id }`
						}
						type={ __( 'Form', 'jcore-silta' ) }
						onRemove={ () =>
							without( 'forms', ( entry ) => entry === id )
						}
					/>
				) ) }
			</ul>
			{ count > 1 && (
				<Button
					variant="link"
					isDestructive
					onClick={ () =>
						onChange( {
							posts: [],
							post_types: [],
							menus: [],
							forms: [],
						} )
					}
				>
					{ __( 'Clear all', 'jcore-silta' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * One selected entry.
 *
 * @param {Object}                       props          Props.
 * @param {string}                       props.title    Title.
 * @param {string}                       props.type     Type label.
 * @param {(...args: unknown[]) => void} props.onRemove Remove callback.
 * @return {Element} Row.
 */
function SelectedRow( { title, type, onRemove } ) {
	return (
		<li className="jcore-silta-selected__row">
			<span className="jcore-silta-selected__title">
				{ title }
				{ type && (
					<span className="jcore-silta-selected__type">{ type }</span>
				) }
			</span>
			<Button
				size="small"
				variant="tertiary"
				isDestructive
				label={ sprintf(
					/* translators: %s: title of the selected item. */
					__( 'Remove %s', 'jcore-silta' ),
					title
				) }
				showTooltip={ false }
				onClick={ onRemove }
			>
				{ __( 'Remove', 'jcore-silta' ) }
			</Button>
		</li>
	);
}
