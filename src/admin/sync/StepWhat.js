import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	Notice,
	ProgressBar,
	Spinner,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { errorMessage, get } from '../api';
import ErrorNotice from '../components/ErrorNotice';
import { isOutdated } from '../words';
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

	const addPosts = ( items ) =>
		setSelection( ( current ) => {
			const known = new Set( current.posts.map( ( entry ) => entry.id ) );
			return {
				...current,
				posts: [
					...current.posts,
					...items.filter( ( item ) => ! known.has( item.id ) ),
				],
			};
		} );

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
		! selection.menus.length &&
		! selection.forms.length;

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
											onAddPosts={ addPosts }
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
								<FormPicker
									source={ source }
									targetLabel={ flow.targetLabel }
									selected={ selection.forms }
									onToggle={ ( id, checked ) =>
										toggleList( 'forms', id, checked )
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
							forms={ source?.forms || [] }
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

const SCAN_PAGE = 50;

/**
 * Finds the posts of a type that are new or changed compared with the target,
 * page by page, and adds them to the selection.
 *
 * @param {Object}                       props            Props.
 * @param {Object}                       props.flow       Sync flow.
 * @param {Object}                       props.type       Post type.
 * @param {boolean}                      props.disabled   Whether there is nothing to find.
 * @param {(...args: unknown[]) => void} props.onAddPosts Called with the posts found.
 * @return {Element} Button and outcome.
 */
function FindOutdated( { flow, type, disabled, onAddPosts } ) {
	const [ checked, setChecked ] = useState( null );
	const [ found, setFound ] = useState( null );
	const [ error, setError ] = useState( '' );
	const alive = useRef( true );

	useEffect(
		() => () => {
			alive.current = false;
		},
		[]
	);

	const find = async () => {
		setError( '' );
		setFound( null );
		setChecked( 0 );
		const outdated = [];
		try {
			for ( let page = 1; ; page++ ) {
				const response = await get( '/source/posts', {
					connection: flow.connectionId,
					direction: flow.direction,
					post_type: type.name,
					page,
					per_page: SCAN_PAGE,
					status: 1,
				} );
				if ( ! alive.current ) {
					return;
				}
				if ( response.status_error ) {
					throw new Error( response.status_error );
				}
				const items = response.items || [];
				if ( items.length && items.every( ( item ) => ! item.sync ) ) {
					throw new Error(
						__(
							'The other site can’t be compared yet. Update Silta on both sites.',
							'jcore-silta'
						)
					);
				}
				outdated.push(
					...items.filter( ( item ) =>
						isOutdated( item.sync?.state )
					)
				);
				setChecked( ( value ) => value + items.length );
				if ( ! items.length || page >= ( response.pages || 1 ) ) {
					break;
				}
			}
			onAddPosts( outdated );
			setFound( outdated.length );
		} catch ( e ) {
			if ( alive.current ) {
				setError( errorMessage( e ) );
			}
		} finally {
			if ( alive.current ) {
				setChecked( null );
			}
		}
	};

	const isBusy = checked !== null;

	return (
		<>
			<Button
				variant="tertiary"
				size="compact"
				isBusy={ isBusy }
				disabled={ disabled || isBusy }
				accessibleWhenDisabled
				onClick={ find }
			>
				{ isBusy
					? sprintf(
							/* translators: 1: posts checked so far, 2: all posts. */
							__( 'Comparing %1$d of %2$d…', 'jcore-silta' ),
							checked,
							type.count
						)
					: __( 'Select new and changed', 'jcore-silta' ) }
			</Button>
			{ isBusy && (
				<ProgressBar
					className="jcore-silta-types__progress"
					aria-label={ sprintf(
						/* translators: %s: target site name. */
						__( 'Comparing with %s', 'jcore-silta' ),
						flow.targetLabel
					) }
					value={
						type.count
							? Math.min(
									100,
									Math.round( ( checked / type.count ) * 100 )
								)
							: undefined
					}
				/>
			) }
			<span className="jcore-silta-types__found" role="status">
				{ found === 0 &&
					sprintf(
						/* translators: %s: target site name. */
						__( 'All up to date on %s.', 'jcore-silta' ),
						flow.targetLabel
					) }
				{ found > 0 &&
					sprintf(
						/* translators: %d: number of posts. */
						_n(
							'Found %d new or changed post.',
							'Found %d new or changed posts.',
							found,
							'jcore-silta'
						),
						found
					) }
			</span>
			{ error && (
				<div className="jcore-silta-types__error">
					<ErrorNotice
						error={ error }
						onRemove={ () => setError( '' ) }
					/>
				</div>
			) }
		</>
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
 * @param {(...args: unknown[]) => void} props.onAddPosts    Called with posts to add.
 * @return {Element} Row.
 */
function PostTypeRow( {
	flow,
	type,
	isOpen,
	onOpen,
	onToggleWhole,
	onTogglePost,
	onAddPosts,
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
				<FindOutdated
					flow={ flow }
					type={ type }
					disabled={ isWhole || ! type.count }
					onAddPosts={ onAddPosts }
				/>
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
 * Checkboxes for the source's Gravity Forms forms. Shown only when the source
 * has Gravity Forms; disabled with a note when the target doesn't.
 *
 * @param {Object}                       props             Props.
 * @param {Object}                       props.source      Source handshake.
 * @param {string}                       props.targetLabel Target site name.
 * @param {Array}                        props.selected    Selected form IDs.
 * @param {(...args: unknown[]) => void} props.onToggle    Called with ( id, checked ).
 * @return {Element|null} Forms.
 */
function FormPicker( { source, targetLabel, selected, onToggle } ) {
	const forms = source.forms || [];
	if ( ! source.gravity_forms || ! forms.length ) {
		return null;
	}
	const unavailable = source.target_gravity_forms === false;
	return (
		<fieldset className="jcore-silta-forms">
			<legend>
				<h3>{ __( 'Forms', 'jcore-silta' ) }</h3>
			</legend>
			{ unavailable && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: target site name. */
						__(
							'Gravity Forms isn’t active on %s, so forms can’t be synced there. Posts that embed a form keep its ID from this site.',
							'jcore-silta'
						),
						targetLabel
					) }
				</Notice>
			) }
			{ forms.map( ( form ) => (
				<CheckboxControl
					__nextHasNoMarginBottom
					key={ form.id }
					label={ form.title }
					help={ formHelp( form ) }
					checked={ selected.includes( form.id ) }
					disabled={ unavailable }
					onChange={ ( checked ) => onToggle( form.id, checked ) }
				/>
			) ) }
		</fieldset>
	);
}

/**
 * Describes a form's size and state.
 *
 * @param {Object} form Form.
 * @return {string} Description.
 */
function formHelp( form ) {
	const fields = sprintf(
		/* translators: %d: number of form fields. */
		_n( '%d field', '%d fields', form.fields, 'jcore-silta' ),
		form.fields
	);
	return form.active
		? fields
		: sprintf(
				/* translators: %s: number of fields. */
				__( '%s, inactive', 'jcore-silta' ),
				fields
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
