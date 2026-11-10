import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage, patch, remove } from '../api';
import Badge from '../components/Badge';
import ConfirmModal from '../components/ConfirmModal';
import ErrorNotice from '../components/ErrorNotice';
import { formatDate, fromUnix, relativeDate } from '../words';

/**
 * Sites allowed to connect to this site, and pending invitations.
 *
 * @param {Object}                       props           Props.
 * @param {Array}                        props.keys      Trusted keys.
 * @param {Array}                        props.tokens    Pending pairing tokens.
 * @param {(...args: unknown[]) => void} props.onChanged Called after a change.
 * @return {Element} Card.
 */
export default function IncomingKeys( { keys, tokens, onChanged } ) {
	return (
		<Card>
			<CardHeader>
				<h2>
					{ __( 'Sites allowed to connect here', 'jcore-silta' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p className="description">
					{ __(
						'Sites that connected using an invitation from this site. They can pull from or push to this site, depending on what you allowed, using the account of the person who created the invitation. To add one, use “Create an invitation” above.',
						'jcore-silta'
					) }
				</p>
				{ keys.length === 0 ? (
					<p>{ __( 'No sites have access yet.', 'jcore-silta' ) }</p>
				) : (
					<ul className="jcore-silta-list">
						{ keys.map( ( key ) => (
							<KeyRow
								key={ key.fingerprint }
								trusted={ key }
								onChanged={ onChanged }
							/>
						) ) }
					</ul>
				) }
				<PendingTokens tokens={ tokens } onChanged={ onChanged } />
			</CardBody>
		</Card>
	);
}

/**
 * One site allowed to connect.
 *
 * @param {Object}                       props           Props.
 * @param {Object}                       props.trusted   Trusted key.
 * @param {(...args: unknown[]) => void} props.onChanged Called after a change.
 * @return {Element} Row.
 */
function KeyRow( { trusted, onChanged } ) {
	const [ isRevoking, setIsRevoking ] = useState( false );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const scopes = trusted.scopes || [];
	const path = `/keys/${ trusted.fingerprint }`;

	const save = async ( changes ) => {
		setIsBusy( true );
		setError( '' );
		try {
			await patch( path, changes );
			onChanged();
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setIsBusy( false );
		}
	};

	const toggleScope = ( scope, checked ) => {
		const next = checked
			? [ ...scopes.filter( ( entry ) => entry !== scope ), scope ]
			: scopes.filter( ( entry ) => entry !== scope );
		if ( ! next.length ) {
			setError(
				__(
					'Allow at least one of the two, or revoke the site instead.',
					'jcore-silta'
				)
			);
			return;
		}
		save( { scopes: next } );
	};

	const revoke = async () => {
		setIsBusy( true );
		setError( '' );
		try {
			await remove( path );
			setIsRevoking( false );
			onChanged();
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setIsBusy( false );
		}
	};

	const name = trusted.label || trusted.name || trusted.home;

	return (
		<li className="jcore-silta-list__row">
			<div className="jcore-silta-list__main">
				<strong>{ name }</strong>{ ' ' }
				<span className="jcore-silta-list__address">
					{ trusted.home }
				</span>
				<div className="jcore-silta-list__badges">
					{ trusted.inherited && (
						<Badge tone="warning">
							{ __(
								'Switched off: came with a copied database',
								'jcore-silta'
							) }
						</Badge>
					) }
				</div>
				<p className="description">
					{ sprintf(
						/* translators: 1: user name, 2: relative time. */
						__( 'Acts as %1$s. Last used: %2$s', 'jcore-silta' ),
						trusted.user || __( '(a deleted user)', 'jcore-silta' ),
						trusted.last_used
							? relativeDate( fromUnix( trusted.last_used ) )
							: __( 'never', 'jcore-silta' )
					) }
				</p>
				<ErrorNotice
					error={ ! isRevoking && error }
					onRemove={ () => setError( '' ) }
				/>
				<div className="jcore-silta-list__controls">
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Can pull from this site', 'jcore-silta' ) }
						checked={ scopes.includes( 'read' ) }
						disabled={ isBusy }
						onChange={ ( checked ) =>
							toggleScope( 'read', checked )
						}
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Can push to this site', 'jcore-silta' ) }
						checked={ scopes.includes( 'write' ) }
						disabled={ isBusy }
						onChange={ ( checked ) =>
							toggleScope( 'write', checked )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Enabled', 'jcore-silta' ) }
						checked={ !! trusted.enabled }
						disabled={ isBusy }
						onChange={ ( enabled ) => save( { enabled } ) }
					/>
				</div>
			</div>
			<Flex justify="flex-end" expanded={ false }>
				<Button
					size="compact"
					variant="tertiary"
					isDestructive
					onClick={ () => setIsRevoking( true ) }
				>
					{ __( 'Revoke', 'jcore-silta' ) }
				</Button>
			</Flex>
			{ isRevoking && (
				<ConfirmModal
					title={ __( 'Revoke this site?', 'jcore-silta' ) }
					confirmLabel={ __( 'Revoke', 'jcore-silta' ) }
					isDestructive
					isBusy={ isBusy }
					error={ error }
					onCancel={ () => setIsRevoking( false ) }
					onConfirm={ revoke }
				>
					<p>
						{ sprintf(
							/* translators: %s: site name. */
							__(
								'%s can no longer connect to this site. Syncs it already made stay as they are.',
								'jcore-silta'
							),
							name
						) }
					</p>
				</ConfirmModal>
			) }
		</li>
	);
}

/**
 * Pairing strings created here and not used yet.
 *
 * @param {Object}                       props           Props.
 * @param {Array}                        props.tokens    Tokens.
 * @param {(...args: unknown[]) => void} props.onChanged Called after a change.
 * @return {Element|null} List.
 */
function PendingTokens( { tokens, onChanged } ) {
	const [ error, setError ] = useState( '' );

	if ( ! tokens.length ) {
		return null;
	}

	const cancel = async ( id ) => {
		setError( '' );
		try {
			await remove( `/keys/tokens/${ id }` );
			onChanged();
		} catch ( e ) {
			setError( errorMessage( e ) );
		}
	};

	return (
		<div className="jcore-silta-pending">
			<h3>{ __( 'Unused invitations', 'jcore-silta' ) }</h3>
			<p className="description">
				{ __(
					'Invitations created on this site that haven’t been used yet. Each one expires 30 minutes after it was created. Cancel any you no longer need.',
					'jcore-silta'
				) }
			</p>
			<ErrorNotice error={ error } onRemove={ () => setError( '' ) } />
			<ul className="jcore-silta-list">
				{ tokens.map( ( token ) => (
					<li key={ token.id } className="jcore-silta-list__row">
						<div className="jcore-silta-list__main">
							<strong>
								{ token.label ||
									__( 'Unnamed', 'jcore-silta' ) }
							</strong>{ ' ' }
							<span className="description">
								{ sprintf(
									/* translators: %s: date and time. */
									__( 'valid until %s', 'jcore-silta' ),
									formatDate( fromUnix( token.expires ) )
								) }
							</span>
						</div>
						<Button
							size="compact"
							variant="tertiary"
							isDestructive
							onClick={ () => cancel( token.id ) }
						>
							{ __( 'Cancel', 'jcore-silta' ) }
						</Button>
					</li>
				) ) }
			</ul>
		</div>
	);
}
