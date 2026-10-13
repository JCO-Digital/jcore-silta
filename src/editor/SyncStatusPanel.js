import apiFetch from '@wordpress/api-fetch';
import { Button, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Describes a post's state on one site.
 *
 * @param {Object} entry Site entry from GET /status/{id}.
 * @return {string} Words.
 */
function stateText( entry ) {
	switch ( entry.state ) {
		case 'synced':
			return sprintf(
				/* translators: %s: site name. */
				__( 'Up to date on %s', 'jcore-silta' ),
				entry.label
			);
		case 'source_changed':
			return __( 'Changed here since the last push', 'jcore-silta' );
		case 'target_changed':
			return sprintf(
				/* translators: %s: site name. */
				__( 'Edited on %s since the last push', 'jcore-silta' ),
				entry.label
			);
		case 'both_changed':
			return sprintf(
				/* translators: %s: site name. */
				__( 'Changed here and on %s', 'jcore-silta' ),
				entry.label
			);
		case 'not_synced':
			/* translators: %s: site name. */
			return sprintf( __( 'Not on %s yet', 'jcore-silta' ), entry.label );
		default:
			return (
				entry.error || __( 'Could not check this site.', 'jcore-silta' )
			);
	}
}

/**
 * Document settings panel: this post's sync status on each connected site.
 *
 * @return {Element|null} Panel.
 */
export default function SyncStatusPanel() {
	const { postId, isDirty, isSaving } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postId: editor.getCurrentPostId(),
			isDirty: editor.isEditedPostDirty(),
			isSaving: editor.isSavingPost() && ! editor.isAutosavingPost(),
		};
	}, [] );
	const [ sites, setSites ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ checked, setChecked ] = useState( 0 );
	const wasSaving = useRef( false );

	// Check again once a save has finished.
	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			setChecked( ( value ) => value + 1 );
		}
		wasSaving.current = isSaving;
	}, [ isSaving ] );

	useEffect( () => {
		if ( ! postId ) {
			return;
		}
		let alive = true;
		setError( '' );
		apiFetch( { path: `/jcore-silta/v1/status/${ postId }` } )
			.then( ( response ) => alive && setSites( response.sites || [] ) )
			.catch(
				( e ) =>
					alive &&
					setError(
						e?.message ||
							__(
								'Could not load the sync status.',
								'jcore-silta'
							)
					)
			);
		return () => {
			alive = false;
		};
	}, [ postId, checked ] );

	if ( ! postId ) {
		return null;
	}

	const screen = window.jcoreSiltaEditor?.screen || '';

	return (
		<PluginDocumentSettingPanel
			name="jcore-silta"
			title={ __( 'JCORE Silta', 'jcore-silta' ) }
			className="jcore-silta-editor"
		>
			{ error && <p className="jcore-silta-editor__error">{ error }</p> }
			{ ! sites && ! error && <Spinner /> }
			{ sites && sites.length === 0 && (
				<p>{ __( 'No site to push to.', 'jcore-silta' ) }</p>
			) }
			{ sites && sites.length > 0 && (
				<ul className="jcore-silta-editor__sites">
					{ sites.map( ( entry ) => (
						<li
							key={ entry.connection }
							className={ `jcore-silta-editor__site is-${ entry.state }` }
						>
							<strong>{ entry.label }</strong>
							<span className="jcore-silta-editor__state">
								{ stateText( entry ) }
							</span>
							{ entry.state !== 'synced' &&
								entry.state !== 'error' &&
								screen && (
									<Button
										variant="secondary"
										size="compact"
										href={ addQueryArgs( screen, {
											posts: postId,
											direction: 'push',
											connection: entry.connection,
											diff: 1,
										} ) }
									>
										{ __(
											'Review and push',
											'jcore-silta'
										) }
									</Button>
								) }
						</li>
					) ) }
				</ul>
			) }
			{ isDirty && (
				<p className="description">
					{ __(
						'Save your changes first. Only saved content is pushed.',
						'jcore-silta'
					) }
				</p>
			) }
		</PluginDocumentSettingPanel>
	);
}
