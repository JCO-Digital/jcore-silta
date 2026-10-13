import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	Modal,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage, patch, post, remove } from '../api';
import Badge from '../components/Badge';
import ConfirmModal from '../components/ConfirmModal';
import ErrorNotice from '../components/ErrorNotice';
import RulesEditor, { cleanRules } from '../components/RulesEditor';
import { connectionProblem, fromUnix, relativeDate } from '../words';

/**
 * Sites this site connects to.
 *
 * @param {Object}                       props             Props.
 * @param {Array}                        props.connections Connections.
 * @param {(...args: unknown[]) => void} props.onChanged   Called after a change.
 * @return {Element} Card.
 */
export default function OutgoingConnections( { connections, onChanged } ) {
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Sites this site connects to', 'jcore-silta' ) }</h2>
			</CardHeader>
			<CardBody>
				<p className="description">
					{ __(
						'From this site you can push to or pull from these sites, as far as they allow it.',
						'jcore-silta'
					) }
				</p>
				{ connections.length === 0 ? (
					<p>{ __( 'No connections yet.', 'jcore-silta' ) }</p>
				) : (
					<ul className="jcore-silta-list">
						{ connections.map( ( connection ) => (
							<ConnectionRow
								key={ connection.id }
								connection={ connection }
								onChanged={ onChanged }
							/>
						) ) }
					</ul>
				) }
				<AddConnection onAdded={ onChanged } />
			</CardBody>
		</Card>
	);
}

/**
 * One outgoing connection.
 *
 * @param {Object}                       props            Props.
 * @param {Object}                       props.connection Connection.
 * @param {(...args: unknown[]) => void} props.onChanged  Called after a change.
 * @return {Element} Row.
 */
function ConnectionRow( { connection, onChanged } ) {
	const [ isEditing, setIsEditing ] = useState( false );
	const [ isRemoving, setIsRemoving ] = useState( false );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const problem = connectionProblem( connection );
	const scopes = connection.scopes || [];

	const act = async ( request ) => {
		setIsBusy( true );
		setError( '' );
		try {
			await request();
			setIsRemoving( false );
			onChanged();
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<li className="jcore-silta-list__row">
			<div className="jcore-silta-list__main">
				<strong>{ connection.label }</strong>{ ' ' }
				<span className="jcore-silta-list__address">
					{ connection.home }
				</span>
				<div className="jcore-silta-list__badges">
					{ scopes.includes( 'write' ) && (
						<Badge tone="info">
							{ __( 'Can push', 'jcore-silta' ) }
						</Badge>
					) }
					{ scopes.includes( 'read' ) && (
						<Badge tone="info">
							{ __( 'Can pull', 'jcore-silta' ) }
						</Badge>
					) }
					{ problem ? (
						<Badge
							tone={
								connection.status === 'error'
									? 'warning'
									: 'error'
							}
						>
							{ __( 'Needs attention', 'jcore-silta' ) }
						</Badge>
					) : (
						<Badge tone="success">
							{ __( 'Working', 'jcore-silta' ) }
						</Badge>
					) }
					{ connection.has_auth && (
						<Badge>{ __( 'Basic auth', 'jcore-silta' ) }</Badge>
					) }
				</div>
				{ problem && (
					<p className="jcore-silta-item__error">{ problem }</p>
				) }
				<p className="description">
					{ sprintf(
						/* translators: %s: relative time, e.g. "5 mins". */
						__( 'Last checked: %s', 'jcore-silta' ),
						relativeDate( fromUnix( connection.verified_at ) )
					) }
				</p>
				<ErrorNotice
					error={ ! isRemoving && error }
					onRemove={ () => setError( '' ) }
				/>
			</div>
			<Flex justify="flex-end" gap={ 2 } expanded={ false }>
				<Button
					size="compact"
					variant="secondary"
					isBusy={ isBusy && ! isRemoving }
					onClick={ () =>
						act( () =>
							post( `/connections/${ connection.id }/verify` )
						)
					}
				>
					{ __( 'Check', 'jcore-silta' ) }
				</Button>
				<Button
					size="compact"
					variant="secondary"
					onClick={ () => setIsEditing( true ) }
				>
					{ __( 'Edit', 'jcore-silta' ) }
				</Button>
				<Button
					size="compact"
					variant="tertiary"
					isDestructive
					onClick={ () => setIsRemoving( true ) }
				>
					{ __( 'Remove', 'jcore-silta' ) }
				</Button>
			</Flex>
			{ isEditing && (
				<EditConnection
					connection={ connection }
					onClose={ () => setIsEditing( false ) }
					onSaved={ () => {
						setIsEditing( false );
						onChanged();
					} }
				/>
			) }
			{ isRemoving && (
				<ConfirmModal
					title={ __( 'Remove this connection?', 'jcore-silta' ) }
					confirmLabel={ __( 'Remove', 'jcore-silta' ) }
					isDestructive
					isBusy={ isBusy }
					error={ error }
					onCancel={ () => setIsRemoving( false ) }
					onConfirm={ () =>
						act( () => remove( `/connections/${ connection.id }` ) )
					}
				>
					<p>
						{ sprintf(
							/* translators: %s: site name. */
							__(
								'This site can no longer sync with %s. To connect again, you need a new pairing string from that site. Consider revoking this site there as well.',
								'jcore-silta'
							),
							connection.label
						) }
					</p>
				</ConfirmModal>
			) }
		</li>
	);
}

/**
 * Edits a connection's label, basic auth and saved rules.
 *
 * @param {Object}                       props            Props.
 * @param {Object}                       props.connection Connection.
 * @param {(...args: unknown[]) => void} props.onClose    Close callback.
 * @param {(...args: unknown[]) => void} props.onSaved    Called after saving.
 * @return {Element} Modal.
 */
function EditConnection( { connection, onClose, onSaved } ) {
	const [ label, setLabel ] = useState( connection.label );
	const [ authUser, setAuthUser ] = useState( connection.auth_user || '' );
	const [ authPass, setAuthPass ] = useState( '' );
	const [ rules, setRules ] = useState( connection.rules || [] );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const save = async ( event ) => {
		event.preventDefault();
		setIsBusy( true );
		setError( '' );
		try {
			await patch( `/connections/${ connection.id }`, {
				label,
				auth_user: authUser,
				auth_pass: authPass,
				rules: cleanRules( rules ),
			} );
			onSaved();
		} catch ( e ) {
			setError( errorMessage( e ) );
			setIsBusy( false );
		}
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: site name. */
				__( 'Edit %s', 'jcore-silta' ),
				connection.label
			) }
			onRequestClose={ onClose }
			size="large"
		>
			<form onSubmit={ save } className="jcore-silta-form">
				<ErrorNotice error={ error } />
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Name', 'jcore-silta' ) }
					value={ label }
					onChange={ setLabel }
				/>
				<h3>{ __( 'Basic auth', 'jcore-silta' ) }</h3>
				<p className="description">
					{ __(
						'Only needed when the other site is behind a password. Leave the user empty to remove it.',
						'jcore-silta'
					) }
				</p>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'User', 'jcore-silta' ) }
					value={ authUser }
					autoComplete="off"
					onChange={ setAuthUser }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="password"
					label={ __( 'Password', 'jcore-silta' ) }
					help={
						connection.has_auth
							? __(
									'Leave empty to keep the saved password.',
									'jcore-silta'
								)
							: ''
					}
					value={ authPass }
					autoComplete="new-password"
					onChange={ setAuthPass }
				/>
				<h3>
					{ __( 'Saved search and replace rules', 'jcore-silta' ) }
				</h3>
				<p className="description">
					{ __(
						'Used for every sync with this site, on top of the automatic address replacement. You can still change them for each sync.',
						'jcore-silta'
					) }
				</p>
				<RulesEditor rules={ rules } onChange={ setRules } />
				<Flex justify="flex-end" className="jcore-silta-modal__actions">
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ onClose }
					>
						{ __( 'Cancel', 'jcore-silta' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						type="submit"
						isBusy={ isBusy }
						disabled={ isBusy }
						accessibleWhenDisabled
					>
						{ __( 'Save', 'jcore-silta' ) }
					</Button>
				</Flex>
			</form>
		</Modal>
	);
}

/**
 * Pairs with another site using its pairing string.
 *
 * @param {Object}                       props         Props.
 * @param {(...args: unknown[]) => void} props.onAdded Called after pairing.
 * @return {Element} Form.
 */
function AddConnection( { onAdded } ) {
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
		<form className="jcore-silta-form jcore-silta-add" onSubmit={ connect }>
			<h3>{ __( 'Add a connection', 'jcore-silta' ) }</h3>
			<p className="description">
				{ __(
					'On the other site, open Tools → JCORE Silta → Connections and choose “Allow a site to connect”. Paste the pairing string it shows here.',
					'jcore-silta'
				) }
			</p>
			<ErrorNotice error={ error } onRemove={ () => setError( '' ) } />
			{ added && (
				<p className="jcore-silta-success" role="status">
					{ sprintf(
						/* translators: %s: site name. */
						__( 'Connected to %s.', 'jcore-silta' ),
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
					'Defaults to the other site’s name.',
					'jcore-silta'
				) }
				value={ label }
				onChange={ setLabel }
			/>
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __(
					'The other site is behind a password (basic auth)',
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
	);
}
