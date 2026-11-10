import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage, post } from '../api';
import CopyBox from '../components/CopyBox';
import ErrorNotice from '../components/ErrorNotice';

/**
 * Guides pairing, then offers create-invite and use-invite as separate steps.
 *
 * @param {Object}                       props           Props.
 * @param {(...args: unknown[]) => void} props.onChanged Called after a change.
 * @return {Element} Setup section.
 */
export default function PairingSetup( { onChanged } ) {
	return (
		<section className="jcore-silta-pairing-setup">
			<Card>
				<CardHeader>
					<h2>{ __( 'Pair two sites', 'jcore-silta' ) }</h2>
				</CardHeader>
				<CardBody>
					<p className="description">
						{ __(
							'To pair two sites, one site creates an invitation and the other site uses it to connect. After that, each site lists the other below, and you can push or pull content.',
							'jcore-silta'
						) }
					</p>
					<ol className="jcore-silta-pairing-guide">
						<li>
							<strong>
								{ __(
									'On the site that gives access',
									'jcore-silta'
								) }
							</strong>
							{ ': ' }
							{ __(
								'use “Create an invitation” and choose what the other site may do. Copy the pairing string. It works once and expires after 30 minutes.',
								'jcore-silta'
							) }
						</li>
						<li>
							<strong>
								{ __(
									'On the site that will push or pull content',
									'jcore-silta'
								) }
							</strong>
							{ ': ' }
							{ __(
								'use “Connect with a pairing string” and paste the string.',
								'jcore-silta'
							) }
						</li>
					</ol>
					<p className="description">
						{ __(
							'This site can take either role. Use the card that matches what this site should do.',
							'jcore-silta'
						) }
					</p>
				</CardBody>
			</Card>

			<div className="jcore-silta-pairing-actions">
				<CreateInvitation onCreated={ onChanged } />
				<ConnectWithPairing onAdded={ onChanged } />
			</div>
		</section>
	);
}

/**
 * Creates an invitation / pairing string for another site (gives access).
 *
 * @param {Object}                       props           Props.
 * @param {(...args: unknown[]) => void} props.onCreated Called after creating it.
 * @return {Element} Card.
 */
function CreateInvitation( { onCreated } ) {
	const [ label, setLabel ] = useState( '' );
	const [ canRead, setCanRead ] = useState( false );
	const [ canWrite, setCanWrite ] = useState( false );
	const [ pairing, setPairing ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const create = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( '' );
		try {
			const scopes = [];
			if ( canRead ) {
				scopes.push( 'read' );
			}
			if ( canWrite ) {
				scopes.push( 'write' );
			}
			setPairing( await post( '/keys/tokens', { label, scopes } ) );
			setLabel( '' );
			setCanRead( false );
			setCanWrite( false );
			onCreated();
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<Card className="jcore-silta-pairing-actions__card">
			<CardHeader>
				<span className="jcore-silta-pairing-step">1</span>
				<h2>{ __( 'Create an invitation', 'jcore-silta' ) }</h2>
			</CardHeader>
			<CardBody>
				<p className="description">
					{ __(
						'Use this on the site that gives access. It creates a pairing string to paste on the other site.',
						'jcore-silta'
					) }
				</p>
				<form className="jcore-silta-form" onSubmit={ create }>
					<ErrorNotice
						error={ error }
						onRemove={ () => setError( '' ) }
					/>
					{ pairing && (
						<div className="jcore-silta-pairing" role="status">
							<CopyBox
								value={ pairing.pairing }
								label={ __( 'Pairing string', 'jcore-silta' ) }
							/>
							<p>
								{ __(
									'Paste this on the other site under “Connect with a pairing string” within 30 minutes. It works only once.',
									'jcore-silta'
								) }
							</p>
							<Button
								variant="link"
								onClick={ () => setPairing( null ) }
							>
								{ __(
									'Create another invitation',
									'jcore-silta'
								) }
							</Button>
						</div>
					) }
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Name of the other site', 'jcore-silta' ) }
						help={ __(
							'Only shown on this site, for example “Staging”.',
							'jcore-silta'
						) }
						value={ label }
						onChange={ setLabel }
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Can pull from this site', 'jcore-silta' ) }
						help={ __(
							'The other site can read content from here.',
							'jcore-silta'
						) }
						checked={ canRead }
						onChange={ setCanRead }
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Can push to this site', 'jcore-silta' ) }
						help={ __(
							'The other site can create and change content here, using your user account.',
							'jcore-silta'
						) }
						checked={ canWrite }
						onChange={ setCanWrite }
					/>
					<Button
						__next40pxDefaultSize
						variant="primary"
						type="submit"
						isBusy={ isBusy }
						disabled={ ( ! canRead && ! canWrite ) || isBusy }
						accessibleWhenDisabled
					>
						{ __( 'Create invitation', 'jcore-silta' ) }
					</Button>
					{ ! canRead && ! canWrite && (
						<p className="description">
							{ __(
								'Choose at least one option above to create the invitation.',
								'jcore-silta'
							) }
						</p>
					) }
				</form>
			</CardBody>
		</Card>
	);
}

/**
 * Connects using a pairing string from the other site.
 *
 * @param {Object}                       props         Props.
 * @param {(...args: unknown[]) => void} props.onAdded Called after pairing.
 * @return {Element} Card.
 */
function ConnectWithPairing( { onAdded } ) {
	const [ pairing, setPairing ] = useState( '' );
	const [ label, setLabel ] = useState( '' );
	const [ hasAuth, setHasAuth ] = useState( false );
	const [ authUser, setAuthUser ] = useState( '' );
	const [ authPass, setAuthPass ] = useState( '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ added, setAdded ] = useState( '' );

	const connect = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( '' );
		setAdded( '' );
		try {
			const connection = await post( '/connections', {
				pairing: pairing.trim(),
				label,
				auth_user: hasAuth ? authUser : '',
				auth_pass: hasAuth ? authPass : '',
			} );
			setPairing( '' );
			setLabel( '' );
			setAuthUser( '' );
			setAuthPass( '' );
			setHasAuth( false );
			setAdded( connection.label );
			onAdded();
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<Card className="jcore-silta-pairing-actions__card">
			<CardHeader>
				<span className="jcore-silta-pairing-step">2</span>
				<h2>
					{ __( 'Connect with a pairing string', 'jcore-silta' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p className="description">
					{ __(
						'Use this on the site that will push or pull content. Paste the pairing string from the other site’s “Create an invitation” card.',
						'jcore-silta'
					) }
				</p>
				<form className="jcore-silta-form" onSubmit={ connect }>
					<ErrorNotice
						error={ error }
						onRemove={ () => setError( '' ) }
					/>
					{ added && (
						<p className="jcore-silta-success" role="status">
							{ sprintf(
								/* translators: %s: site name. */
								__(
									'Connected to %s. It’s now listed below.',
									'jcore-silta'
								),
								added
							) }
						</p>
					) }
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Pairing string', 'jcore-silta' ) }
						className="jcore-silta-code-input"
						rows={ 3 }
						value={ pairing }
						onChange={ setPairing }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Name (optional)', 'jcore-silta' ) }
						help={ __(
							'Only shown on this site. Leave empty to use the other site’s name.',
							'jcore-silta'
						) }
						value={ label }
						onChange={ setLabel }
					/>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'The other site asks for a username and password before it loads (HTTP basic auth)',
							'jcore-silta'
						) }
						checked={ hasAuth }
						onChange={ setHasAuth }
					/>
					{ hasAuth && (
						<div className="jcore-silta-form__indent">
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'User', 'jcore-silta' ) }
								autoComplete="off"
								value={ authUser }
								onChange={ setAuthUser }
							/>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								type="password"
								label={ __( 'Password', 'jcore-silta' ) }
								autoComplete="new-password"
								value={ authPass }
								onChange={ setAuthPass }
							/>
						</div>
					) }
					<Button
						__next40pxDefaultSize
						variant="primary"
						type="submit"
						isBusy={ isBusy }
						disabled={ ! pairing.trim() || isBusy }
						accessibleWhenDisabled
					>
						{ __( 'Connect', 'jcore-silta' ) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}
