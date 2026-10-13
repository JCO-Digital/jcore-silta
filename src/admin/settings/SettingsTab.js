import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { errorMessage, get, post } from '../api';
import ErrorNotice from '../components/ErrorNotice';

/**
 * Number settings with their limits.
 *
 * @return {Array} Fields.
 */
const numberFields = () => [
	{
		key: 'batch_size',
		min: 1,
		max: 100,
		label: __( 'Items per request', 'jcore-silta' ),
		help: __(
			'How many posts are compared or written in one go. Lower it if requests time out.',
			'jcore-silta'
		),
	},
	{
		key: 'chunk_kb',
		min: 256,
		max: 16384,
		label: __( 'Media chunk size (KB)', 'jcore-silta' ),
		help: __(
			'Files are sent in pieces of this size. Lower it if the server limits request sizes.',
			'jcore-silta'
		),
	},
	{
		key: 'snapshot_days',
		min: 1,
		max: 365,
		label: __( 'Keep undo snapshots for (days)', 'jcore-silta' ),
		help: __(
			'After this, a sync can no longer be undone.',
			'jcore-silta'
		),
	},
	{
		key: 'request_budget',
		min: 5,
		max: 120,
		label: __( 'Time per request (seconds)', 'jcore-silta' ),
		help: __(
			'How long the browser lets the server work before it reports progress. Keep it below the server’s time limit.',
			'jcore-silta'
		),
	},
];

/**
 * The Settings tab (admins only).
 *
 * @return {Element} Tab.
 */
export default function SettingsTab() {
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		get( '/settings' )
			.then( setSettings )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [] );

	const set = ( key, value ) => {
		setSaved( false );
		setSettings( ( current ) => ( { ...current, [ key ]: value } ) );
	};

	const save = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		setError( '' );
		setSaved( false );
		try {
			const values = { excluded_meta: settings.excluded_meta };
			numberFields().forEach( ( field ) => {
				values[ field.key ] = Number( settings[ field.key ] );
			} );
			setSettings( await post( '/settings', values ) );
			setSaved( true );
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Settings', 'jcore-silta' ) }</h2>
			</CardHeader>
			<CardBody>
				<ErrorNotice
					error={ error }
					onRemove={ () => setError( '' ) }
				/>
				{ saved && (
					<Notice
						status="success"
						onRemove={ () => setSaved( false ) }
					>
						{ __( 'Settings saved.', 'jcore-silta' ) }
					</Notice>
				) }
				{ ! settings && ! error && <Spinner /> }
				{ settings && (
					<form className="jcore-silta-form" onSubmit={ save }>
						{ numberFields().map( ( field ) => (
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								key={ field.key }
								type="number"
								min={ field.min }
								max={ field.max }
								label={ field.label }
								help={ field.help }
								value={ String( settings[ field.key ] ?? '' ) }
								onChange={ ( value ) =>
									set( field.key, value )
								}
							/>
						) ) }
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __(
								'Never sync these meta keys',
								'jcore-silta'
							) }
							help={ __(
								'One per line. End a line with * to match every key that starts with it, e.g. _my_plugin_*.',
								'jcore-silta'
							) }
							className="jcore-silta-code-input"
							rows={ 6 }
							value={ settings.excluded_meta || '' }
							onChange={ ( value ) =>
								set( 'excluded_meta', value )
							}
						/>
						<Button
							__next40pxDefaultSize
							variant="primary"
							type="submit"
							isBusy={ isSaving }
							disabled={ isSaving }
							accessibleWhenDisabled
						>
							{ __( 'Save', 'jcore-silta' ) }
						</Button>
					</form>
				) }
			</CardBody>
		</Card>
	);
}
