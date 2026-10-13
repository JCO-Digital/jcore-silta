import {
	Button,
	Dropdown,
	MenuGroup,
	MenuItem,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Lets the user override which target post an item is written to.
 *
 * @param {Object}                       props         Props.
 * @param {Object}                       props.item    Item.
 * @param {(...args: unknown[]) => void} props.onPatch Changes the item.
 * @return {Element} Control.
 */
export default function MatchOverride( { item, onPatch } ) {
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
							{ __(
								'Use another target post ID…',
								'jcore-silta'
							) }
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
						label={ __( 'Post ID on the target', 'jcore-silta' ) }
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
