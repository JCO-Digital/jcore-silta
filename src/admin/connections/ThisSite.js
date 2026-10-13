import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	Modal,
	Notice,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { errorMessage, post } from '../api';
import ConfirmModal from '../components/ConfirmModal';
import CopyBox from '../components/CopyBox';
import ErrorNotice from '../components/ErrorNotice';

/**
 * Where this site's key is kept, in words.
 *
 * @param {string} storage Storage.
 * @return {string} Description.
 */
function storageText( storage ) {
	switch ( storage ) {
		case 'database':
			return __(
				'Stored in the database, encrypted with this site’s salts.',
				'jcore-silta'
			);
		case 'constant':
			return __(
				'Defined in wp-config.php. To change it, edit it there.',
				'jcore-silta'
			);
		case 'unreadable':
			return '';
		default:
			return __(
				'No key yet. It is created when this site first connects to another site.',
				'jcore-silta'
			);
	}
}

/**
 * This site's identity and key.
 *
 * @param {Object}                       props           Props.
 * @param {Object}                       props.data      GET /connections response.
 * @param {(...args: unknown[]) => void} props.onChanged Called after a change.
 * @return {Element} Card.
 */
export default function ThisSite( { data, onChanged } ) {
	const { site, identity } = data;
	const [ define, setDefine ] = useState( '' );
	const [ isRegenerating, setIsRegenerating ] = useState( false );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const exportKey = async () => {
		setError( '' );
		try {
			setDefine( ( await post( '/identity/export' ) ).define );
		} catch ( e ) {
			setError( errorMessage( e ) );
		}
	};

	const regenerate = async () => {
		setIsBusy( true );
		setError( '' );
		try {
			await post( '/identity/regenerate' );
			setIsRegenerating( false );
			onChanged();
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'This site', 'jcore-silta' ) }</h2>
			</CardHeader>
			<CardBody>
				<ErrorNotice error={ ! isRegenerating && error } />
				<dl className="jcore-silta-facts">
					<dt>{ __( 'Name', 'jcore-silta' ) }</dt>
					<dd>{ site.name }</dd>
					<dt>{ __( 'Address', 'jcore-silta' ) }</dt>
					<dd>{ site.home }</dd>
					<dt>{ __( 'Site ID', 'jcore-silta' ) }</dt>
					<dd>
						<code>{ site.uuid }</code>
					</dd>
					<dt>{ __( 'Key', 'jcore-silta' ) }</dt>
					<dd>
						{ identity.fingerprint && (
							<code>{ identity.fingerprint }</code>
						) }{ ' ' }
						{ storageText( identity.storage ) }
					</dd>
				</dl>
				{ identity.storage === 'unreadable' && (
					<Notice status="error" isDismissible={ false }>
						{ __(
							'This site’s key can no longer be read (the salts in wp-config.php changed). Create a new key and pair your connections again.',
							'jcore-silta'
						) }
					</Notice>
				) }
				{ identity.weak && identity.storage === 'database' && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'This site’s salts are stored in the database instead of wp-config.php, so encrypting the key with them adds no protection. Move the key to wp-config.php.',
							'jcore-silta'
						) }
					</Notice>
				) }
				<p className="description">
					{ __(
						'This site signs its requests to other sites with its key. The other sites only store the matching public key, so a copy of their database can’t be used to sync.',
						'jcore-silta'
					) }
				</p>
				<Flex justify="flex-start" gap={ 2 }>
					{ identity.storage === 'database' && (
						<Button variant="secondary" onClick={ exportKey }>
							{ __(
								'Move the key to wp-config.php',
								'jcore-silta'
							) }
						</Button>
					) }
					{ [ 'database', 'unreadable' ].includes(
						identity.storage
					) && (
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => setIsRegenerating( true ) }
						>
							{ __( 'Create a new key', 'jcore-silta' ) }
						</Button>
					) }
				</Flex>
			</CardBody>

			{ define && (
				<Modal
					title={ __(
						'Move the key to wp-config.php',
						'jcore-silta'
					) }
					onRequestClose={ () => {
						setDefine( '' );
						onChanged();
					} }
					size="large"
				>
					<ol>
						<li>
							{ __(
								'Copy this line. It is secret: anyone who has it can sync as this site.',
								'jcore-silta'
							) }
						</li>
						<li>
							{ __(
								'Add it to wp-config.php, above the line “That’s all, stop editing!”.',
								'jcore-silta'
							) }
						</li>
						<li>
							{ __(
								'Reload this page. Once the key is found in wp-config.php, the copy in the database is removed automatically.',
								'jcore-silta'
							) }
						</li>
					</ol>
					<CopyBox
						value={ define }
						label={ __( 'Line for wp-config.php', 'jcore-silta' ) }
					/>
				</Modal>
			) }

			{ isRegenerating && (
				<ConfirmModal
					title={ __( 'Create a new key?', 'jcore-silta' ) }
					confirmLabel={ __( 'Create a new key', 'jcore-silta' ) }
					isDestructive
					isBusy={ isBusy }
					error={ error }
					onCancel={ () => setIsRegenerating( false ) }
					onConfirm={ regenerate }
				>
					<p>
						{ __(
							'The other sites won’t recognise this site any more. Every connection on this site needs to be paired again.',
							'jcore-silta'
						) }
					</p>
				</ConfirmModal>
			) }
		</Card>
	);
}
