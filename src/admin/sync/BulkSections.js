import { Button, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { errorMessage, patch } from '../api';
import ErrorNotice from '../components/ErrorNotice';

/**
 * Choices for the bulk control. Group names select every section below them
 * (fields.*, meta.*, terms.*); the empty value means everything.
 *
 * @return {Array} Options.
 */
const choices = () => [
	{ value: '', label: __( 'Everything', 'jcore-silta' ) },
	{ value: 'content', label: __( 'Content', 'jcore-silta' ) },
	{
		value: 'fields',
		label: __( 'Fields (title, slug, status…)', 'jcore-silta' ),
	},
	{ value: 'meta', label: __( 'Custom fields', 'jcore-silta' ) },
	{ value: 'terms', label: __( 'Taxonomies', 'jcore-silta' ) },
	{ value: 'featured_image', label: __( 'Featured image', 'jcore-silta' ) },
	{ value: 'language', label: __( 'Language', 'jcore-silta' ) },
];

/**
 * Applies one section choice to every item of the sync (diff mode).
 *
 * @param {Object}                       props        Props.
 * @param {number}                       props.jobId  Job ID.
 * @param {(...args: unknown[]) => void} props.onDone Called after applying, to reload the items.
 * @return {Element} Control.
 */
export default function BulkSections( { jobId, onDone } ) {
	const [ choice, setChoice ] = useState( '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const apply = async () => {
		setIsBusy( true );
		setError( '' );
		try {
			// No ids: the change applies to every item of the job.
			await patch( `/jobs/${ jobId }/items`, {
				sections: choice ? [ choice ] : null,
			} );
			onDone();
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<div className="jcore-silta-bulk-sections">
			<ErrorNotice error={ error } onRemove={ () => setError( '' ) } />
			<div className="jcore-silta-bulk-sections__row">
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'For every post, sync only', 'jcore-silta' ) }
					value={ choice }
					options={ choices() }
					onChange={ setChoice }
				/>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					isBusy={ isBusy }
					disabled={ isBusy }
					accessibleWhenDisabled
					onClick={ apply }
				>
					{ __( 'Apply to all', 'jcore-silta' ) }
				</Button>
			</div>
			<p className="description">
				{ __(
					'Updates existing posts only in these parts; new posts are always created in full. You can still change the parts for each post below.',
					'jcore-silta'
				) }
			</p>
		</div>
	);
}
