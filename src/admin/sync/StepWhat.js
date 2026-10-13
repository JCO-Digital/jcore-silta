import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { errorMessage, get } from '../api';
import ErrorNotice from '../components/ErrorNotice';
import PostBrowser from './PostBrowser';
import SelectedSummary from './SelectedSummary';

/**
 * Step 2: what to sync.
 *
 * @param {Object} props      Props.
 * @param {Object} props.flow Sync flow.
 * @return {Element} Step.
 */
export default function StepWhat( { flow } ) {
	const [ source, setSource ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ fresh, setFresh ] = useState( 0 );
	const [ open, setOpen ] = useState( '' );
	const { connectionId, direction, selection, setSelection, setTypeLabels } =
		flow;

	useEffect( () => {
		let alive = true;
		setError( '' );
		setSource( null );
		get( '/source', {
			connection: connectionId,
			direction,
			...( fresh ? { fresh: 1 } : {} ),
		} )
			.then( ( response ) => {
				if ( ! alive ) {
					return;
				}
				setSource( response );
				setTypeLabels(
					Object.fromEntries(
						( response.types || [] ).map( ( type ) => [
							type.name,
							type.singular || type.label,
						] )
					)
				);
			} )
			.catch( ( e ) => alive && setError( errorMessage( e ) ) );
		return () => {
			alive = false;
		};
	}, [ connectionId, direction, fresh, setTypeLabels ] );

	useMissingTitles( flow );

	const togglePost = ( item, checked ) =>
		setSelection( ( current ) => ( {
			...current,
			posts: checked
				? [
						...current.posts.filter(
							( entry ) => entry.id !== item.id
						),
						item,
					]
				: current.posts.filter( ( entry ) => entry.id !== item.id ),
		} ) );

	const toggleList = ( key, value, checked ) =>
		setSelection( ( current ) => ( {
			...current,
			[ key ]: checked
				? [
						...current[ key ].filter(
							( entry ) => entry !== value
						),
						value,
					]
				: current[ key ].filter( ( entry ) => entry !== value ),
		} ) );

	// Single posts of a type synced in full would be listed twice.
	const toggleWhole = ( name, checked ) =>
		setSelection( ( current ) => ( {
			...current,
			post_types: checked
				? [
						...current.post_types.filter(
							( entry ) => entry !== name
						),
						name,
					]
				: current.post_types.filter( ( entry ) => entry !== name ),
			posts: checked
				? current.posts.filter( ( entry ) => entry.post_type !== name )
				: current.posts,
		} ) );

	const isEmpty =
		! selection.posts.length &&
		! selection.post_types.length &&
		! selection.menus.length;

	return (
		<Card>
			<CardHeader>
				<h2>
					{ sprintf(
						/* translators: %s: site name. */
						__(
							'What do you want to sync from %s?',
							'jcore-silta'
						),
						flow.sourceLabel
					) }
				</h2>
				{ direction === 'pull' && (
					<Button
						variant="tertiary"
						size="compact"
						onClick={ () => setFresh( ( value ) => value + 1 ) }
					>
						{ __( 'Reload the list', 'jcore-silta' ) }
					</Button>
				) }
			</CardHeader>
			<CardBody>
				<ErrorNotice
					error={ error }
					actions={ [
						{
							label: __( 'Try again', 'jcore-silta' ),
							onClick: () => setFresh( ( value ) => value + 1 ),
						},
					] }
				/>
				<div className="jcore-silta-what">
					<div className="jcore-silta-what__main">
						{ ! source && ! error && <Spinner /> }
						{ source && (
							<>
								<h3>{ __( 'Content', 'jcore-silta' ) }</h3>
								<ul className="jcore-silta-types">
									{ source.types.map( ( type ) => (
										<PostTypeRow
											key={ type.name }
											flow={ flow }
											type={ type }
											isOpen={ open === type.name }
											onOpen={ () =>
												setOpen(
													open === type.name
														? ''
														: type.name
												)
											}
											onToggleWhole={ ( checked ) =>
												toggleWhole(
													type.name,
													checked
												)
											}
											onTogglePost={ togglePost }
										/>
									) ) }
								</ul>
								<MenuPicker
									menus={ source.menus || [] }
									selected={ selection.menus }
									onToggle={ ( id, checked ) =>
										toggleList( 'menus', id, checked )
									}
								/>
							</>
						) }
					</div>
					<aside className="jcore-silta-what__aside">
						<SelectedSummary
							flow={ flow }
							types={ source?.types || [] }
							menus={ source?.menus || [] }
							onChange={ setSelection }
						/>
					</aside>
				</div>
				<Flex justify="space-between" className="jcore-silta-actions">
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ () => flow.setStep( 'where' ) }
					>
						{ __( 'Back', 'jcore-silta' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						disabled={ isEmpty }
						accessibleWhenDisabled
						onClick={ () => flow.setStep( 'how' ) }
					>
						{ __( 'Next: options', 'jcore-silta' ) }
					</Button>
				</Flex>
			</CardBody>
		</Card>
	);
}

/**
 * One post type: sync all of it, or open it to pick posts.
 *
 * @param {Object}                       props               Props.
 * @param {Object}                       props.flow          Sync flow.
 * @param {Object}                       props.type          Post type.
 * @param {boolean}                      props.isOpen        Whether the browser is open.
 * @param {(...args: unknown[]) => void} props.onOpen        Toggles the browser.
 * @param {(...args: unknown[]) => void} props.onToggleWhole Called with checked.
 * @param {(...args: unknown[]) => void} props.onTogglePost  Called with ( post, checked ).
 * @return {Element} Row.
 */
function PostTypeRow( {
	flow,
	type,
	isOpen,
	onOpen,
	onToggleWhole,
	onTogglePost,
} ) {
	const isWhole = flow.selection.post_types.includes( type.name );
	const picked = flow.selection.posts.filter(
		( item ) => item.post_type === type.name
	).length;
	const browserId = `jcore-silta-browser-${ type.name }`;

	return (
		<li className="jcore-silta-types__row">
			<div className="jcore-silta-types__head">
				<strong className="jcore-silta-types__label">
					{ type.label }
				</strong>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ sprintf(
						/* translators: %d: number of posts. */
						_n(
							'All %d post of this type',
							'All %d posts of this type',
							type.count,
							'jcore-silta'
						),
						type.count
					) }
					checked={ isWhole }
					disabled={ ! type.count }
					onChange={ onToggleWhole }
				/>
				<Button
					variant="secondary"
					size="compact"
					aria-expanded={ isOpen }
					aria-controls={ browserId }
					disabled={ ! type.count }
					accessibleWhenDisabled
					onClick={ onOpen }
				>
					{ isOpen
						? __( 'Close', 'jcore-silta' )
						: __( 'Choose posts', 'jcore-silta' ) }
				</Button>
				{ picked > 0 && ! isWhole && (
					<span className="jcore-silta-types__picked">
						{ sprintf(
							/* translators: %d: number of selected posts. */
							_n(
								'%d selected',
								'%d selected',
								picked,
								'jcore-silta'
							),
							picked
						) }
					</span>
				) }
			</div>
			{ isOpen && (
				<div id={ browserId }>
					<PostBrowser
						flow={ flow }
						type={ type }
						isWhole={ isWhole }
						onTogglePost={ onTogglePost }
					/>
				</div>
			) }
		</li>
	);
}

/**
 * Checkboxes for the source's menus.
 *
 * @param {Object}                       props          Props.
 * @param {Array}                        props.menus    Menus.
 * @param {Array}                        props.selected Selected menu IDs.
 * @param {(...args: unknown[]) => void} props.onToggle Called with ( id, checked ).
 * @return {Element|null} Menus.
 */
function MenuPicker( { menus, selected, onToggle } ) {
	if ( ! menus.length ) {
		return null;
	}
	return (
		<fieldset className="jcore-silta-menus">
			<legend>
				<h3>{ __( 'Menus', 'jcore-silta' ) }</h3>
			</legend>
			{ menus.map( ( menu ) => (
				<CheckboxControl
					__nextHasNoMarginBottom
					key={ menu.id }
					label={ menu.title }
					help={ menuHelp( menu ) }
					checked={ selected.includes( menu.id ) }
					onChange={ ( checked ) => onToggle( menu.id, checked ) }
				/>
			) ) }
		</fieldset>
	);
}

/**
 * Describes a menu's size and locations.
 *
 * @param {Object} menu Menu.
 * @return {string} Description.
 */
function menuHelp( menu ) {
	const items = sprintf(
		/* translators: %d: number of menu items. */
		_n( '%d item', '%d items', menu.count, 'jcore-silta' ),
		menu.count
	);
	if ( ! menu.locations?.length ) {
		return items;
	}
	return sprintf(
		/* translators: 1: number of items, 2: comma-separated theme locations. */
		__( '%1$s, shown in: %2$s', 'jcore-silta' ),
		items,
		menu.locations.join( ', ' )
	);
}

/**
 * Loads titles for posts selected by ID only (from a list table link or a
 * resumed sync).
 *
 * @param {Object} flow Sync flow.
 */
function useMissingTitles( flow ) {
	const { connectionId, direction, selection, setSelection } = flow;
	const missing = selection.posts
		.filter( ( item ) => item.title === null )
		.map( ( item ) => item.id );
	const key = missing.join( ',' );

	useEffect( () => {
		if ( ! key ) {
			return;
		}
		const ids = key.split( ',' ).map( Number );
		get( '/source/posts', {
			connection: connectionId,
			direction,
			include: ids,
			per_page: 100,
		} )
			.then( ( response ) => {
				const found = Object.fromEntries(
					response.items.map( ( item ) => [ item.id, item ] )
				);
				setSelection( ( current ) => ( {
					...current,
					posts: current.posts.map( ( item ) => {
						if ( item.title !== null ) {
							return item;
						}
						return (
							found[ item.id ] || {
								...item,
								title: sprintf(
									/* translators: %d: post ID. */
									__( '#%d (not found)', 'jcore-silta' ),
									item.id
								),
							}
						);
					} ),
				} ) );
			} )
			.catch( () => {
				// Leave the IDs as they are; the sync itself will report problems.
			} );
	}, [ key, connectionId, direction, setSelection ] );
}
