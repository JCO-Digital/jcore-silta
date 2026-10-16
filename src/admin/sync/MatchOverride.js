import {
	Button,
	Dropdown,
	MenuGroup,
	MenuItem,
	SearchControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage, get } from '../api';
import ErrorNotice from '../components/ErrorNotice';

/**
 * Lets the user override which target post an item is written to.
 *
 * @param {Object}                       props         Props.
 * @param {Object}                       props.item    Item.
 * @param {Object}                       props.flow    Sync flow.
 * @param {(...args: unknown[]) => void} props.onPatch Changes the item.
 * @return {Element} Control.
 */
export default function MatchOverride( { item, flow, onPatch } ) {
	const [ isAsking, setIsAsking ] = useState( false );
	const [ targetId, setTargetId ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );
	const current = item.override?.target ?? null;

	const save = async ( override ) => {
		setIsSaving( true );
		await onPatch( item, { override } );
		setIsSaving( false );
		setIsAsking( false );
	};

	let chosen = '';
	if ( current === 'create' ) {
		chosen = __( 'You chose to create a new copy.', 'jcore-silta' );
	} else if ( current ) {
		chosen = sprintf(
			/* translators: %d: post ID on the target. */
			__( 'You chose target post #%d.', 'jcore-silta' ),
			current
		);
	}

	return (
		<div className="jcore-silta-override">
			{ chosen && (
				<span className="jcore-silta-item__note">{ chosen }</span>
			) }
			<Dropdown
				popoverProps={ { placement: 'bottom-start' } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button
						variant="link"
						aria-expanded={ isOpen }
						isBusy={ isSaving }
						onClick={ onToggle }
					>
						{ __( 'Change the match', 'jcore-silta' ) }
					</Button>
				) }
				renderContent={ ( { onClose } ) => (
					<MenuGroup>
						{ current !== 'create' && (
							<MenuItem
								onClick={ () => {
									onClose();
									save( 'create' );
								} }
							>
								{ __(
									'Create a new copy instead',
									'jcore-silta'
								) }
							</MenuItem>
						) }
						<MenuItem
							onClick={ () => {
								onClose();
								setIsAsking( true );
							} }
						>
							{ __( 'Pick another target post…', 'jcore-silta' ) }
						</MenuItem>
						{ current !== null && (
							<MenuItem
								onClick={ () => {
									onClose();
									save( null );
								} }
							>
								{ __(
									'Reset to the automatic match',
									'jcore-silta'
								) }
							</MenuItem>
						) }
					</MenuGroup>
				) }
			/>
			{ isAsking && (
				<TargetSearch
					flow={ flow }
					postType={ item.subtype }
					isSaving={ isSaving }
					onPick={ save }
				/>
			) }
			{ isAsking && (
				<form
					className="jcore-silta-override__form"
					onSubmit={ ( event ) => {
						event.preventDefault();
						if ( Number( targetId ) > 0 ) {
							save( Number( targetId ) );
						}
					} }
				>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="number"
						min={ 1 }
						label={ __(
							'Or enter its ID on the target',
							'jcore-silta'
						) }
						value={ targetId }
						onChange={ setTargetId }
					/>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						type="submit"
						isBusy={ isSaving }
						disabled={ ! ( Number( targetId ) > 0 ) || isSaving }
						accessibleWhenDisabled
					>
						{ __( 'Use this post', 'jcore-silta' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ () => setIsAsking( false ) }
					>
						{ __( 'Cancel', 'jcore-silta' ) }
					</Button>
				</form>
			) }
		</div>
	);
}

/**
 * Searches the target for posts of the same type to match with.
 *
 * The target is the other site on a push, so searching it needs permission
 * to read from it; without that only the ID field is offered.
 *
 * @param {Object}                       props          Props.
 * @param {Object}                       props.flow     Sync flow.
 * @param {string}                       props.postType Post type of the item.
 * @param {boolean}                      props.isSaving Whether a choice is being saved.
 * @param {(...args: unknown[]) => void} props.onPick   Called with the chosen post ID.
 * @return {Element|null} Search.
 */
function TargetSearch( { flow, postType, isSaving, onPick } ) {
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );
	const isPush = flow.direction === 'push';
	const canSearch =
		! isPush || ( flow.connection?.scopes || [] ).includes( 'read' );

	useEffect( () => {
		const timer = setTimeout( () => setQuery( search ), 350 );
		return () => clearTimeout( timer );
	}, [ search ] );

	useEffect( () => {
		if ( ! canSearch ) {
			return;
		}
		let alive = true;
		setError( '' );
		// The source routes read the other side when the direction is flipped.
		get( '/source/posts', {
			connection: flow.connectionId,
			direction: isPush ? 'pull' : 'push',
			post_type: postType,
			search: query,
			per_page: 10,
		} )
			.then( ( response ) => alive && setResult( response ) )
			.catch( ( e ) => alive && setError( errorMessage( e ) ) );
		return () => {
			alive = false;
		};
	}, [ canSearch, flow.connectionId, isPush, postType, query ] );

	if ( ! canSearch ) {
		return null;
	}

	return (
		<div className="jcore-silta-override__search">
			<SearchControl
				__nextHasNoMarginBottom
				label={ sprintf(
					/* translators: %s: target site name. */
					__( 'Search on %s', 'jcore-silta' ),
					flow.targetLabel
				) }
				value={ search }
				onChange={ setSearch }
			/>
			<ErrorNotice error={ error } />
			{ ! result && ! error && <Spinner /> }
			{ result && result.items.length === 0 && (
				<p className="description">
					{ __( 'Nothing found.', 'jcore-silta' ) }
				</p>
			) }
			{ result && result.items.length > 0 && (
				<ul className="jcore-silta-override__results">
					{ result.items.map( ( post ) => (
						<li key={ post.id }>
							<Button
								variant="tertiary"
								size="compact"
								disabled={ isSaving }
								accessibleWhenDisabled
								onClick={ () => onPick( post.id ) }
							>
								{ sprintf(
									/* translators: 1: post title, 2: post ID. */
									__( '%1$s (#%2$d)', 'jcore-silta' ),
									post.title ||
										__( '(no title)', 'jcore-silta' ),
									post.id
								) }
							</Button>
							{ post.status !== 'publish' &&
								post.status !== 'inherit' && (
									<span className="description">
										{ post.status_label || post.status }
									</span>
								) }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
