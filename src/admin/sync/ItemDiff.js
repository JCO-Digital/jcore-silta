import { CheckboxControl, Spinner, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage, get } from '../api';
import Badge from '../components/Badge';
import ErrorNotice from '../components/ErrorNotice';
import { expandSections, isSectionChosen } from './items';

/**
 * Section groups in display order.
 *
 * @return {Array} [ group, label ] pairs.
 */
const groups = () => [
	[ 'fields', __( 'Fields', 'jcore-silta' ) ],
	[ 'content', __( 'Content', 'jcore-silta' ) ],
	[ 'featured_image', __( 'Featured image', 'jcore-silta' ) ],
	[ 'meta', __( 'Custom fields', 'jcore-silta' ) ],
	[ 'terms', __( 'Taxonomies', 'jcore-silta' ) ],
	[ 'language', __( 'Language', 'jcore-silta' ) ],
	[ 'file', __( 'File', 'jcore-silta' ) ],
	[ 'items', __( 'Menu items', 'jcore-silta' ) ],
	[ 'locations', __( 'Menu locations', 'jcore-silta' ) ],
	[ 'settings', __( 'Form settings', 'jcore-silta' ) ],
	[ 'form_fields', __( 'Form fields', 'jcore-silta' ) ],
	[ 'confirmations', __( 'Confirmations', 'jcore-silta' ) ],
	[ 'notifications', __( 'Notifications', 'jcore-silta' ) ],
];

/**
 * The section-by-section diff of one item, with a checkbox per changed section.
 *
 * @param {Object}                       props             Props.
 * @param {number}                       props.jobId       Job ID.
 * @param {Object}                       props.item        Item.
 * @param {string}                       props.targetLabel Target site name.
 * @param {(...args: unknown[]) => void} props.onPatch     Changes the item.
 * @return {Element} Diff.
 */
export default function ItemDiff( { jobId, item, targetLabel, onPatch } ) {
	const [ diff, setDiff ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ showUnchanged, setShowUnchanged ] = useState( false );

	useEffect( () => {
		let alive = true;
		setDiff( null );
		setError( '' );
		get( `/jobs/${ jobId }/items/${ item.id }/diff` )
			.then( ( response ) => alive && setDiff( response ) )
			.catch( ( e ) => alive && setError( errorMessage( e ) ) );
		return () => {
			alive = false;
		};
		// Reload when the match changes, since the comparison changes with it.
	}, [ jobId, item.id, item.target_id ] );

	if ( error ) {
		return <ErrorNotice error={ error } />;
	}
	if ( ! diff ) {
		return <Spinner />;
	}

	const sections = diff.sections || {};
	const allKeys = Object.keys( sections );
	const errors = diff.errors || {};
	const isChecked = ( key ) => isSectionChosen( item.sections, key );

	const toggle = ( key, checked ) => {
		const base = expandSections( item.sections, allKeys );
		const next = checked
			? [ ...base.filter( ( entry ) => entry !== key ), key ]
			: base.filter( ( entry ) => entry !== key );
		// Everything ticked again means "sync it all".
		const all = allKeys.every( ( entry ) => next.includes( entry ) );
		const changes = { sections: all ? null : next };
		// Picking the parts of a conflicting post by hand is a decision about
		// it, so it counts as confirming the overwrite of those parts.
		if ( item.conflict && ! all ) {
			changes.confirmed = true;
		}
		onPatch( item, changes );
	};

	const visible = ( key ) => showUnchanged || sections[ key ].changed;

	return (
		<div className="jcore-silta-diff">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Show unchanged parts', 'jcore-silta' ) }
				checked={ showUnchanged }
				onChange={ setShowUnchanged }
			/>
			{ groups().map( ( [ group, label ] ) => {
				const keys = allKeys.filter(
					( key ) => sections[ key ].group === group && visible( key )
				);
				if ( ! keys.length ) {
					return null;
				}
				return (
					<fieldset key={ group } className="jcore-silta-diff__group">
						<legend>{ label }</legend>
						{ keys.map( ( key ) => (
							<DiffSection
								key={ key }
								section={ sections[ key ] }
								error={ errors[ key ] }
								targetLabel={ targetLabel }
								checked={ isChecked( key ) }
								onChange={ ( checked ) =>
									toggle( key, checked )
								}
							/>
						) ) }
					</fieldset>
				);
			} ) }
		</div>
	);
}

/**
 * One section: checkbox and its before → after.
 *
 * @param {Object}                       props             Props.
 * @param {Object}                       props.section     Section from the diff.
 * @param {string}                       props.error       Why it can't be synced, if so.
 * @param {string}                       props.targetLabel Target site name.
 * @param {boolean}                      props.checked     Whether it will be synced.
 * @param {(...args: unknown[]) => void} props.onChange    Called with checked.
 * @return {Element} Section.
 */
function DiffSection( { section, error, targetLabel, checked, onChange } ) {
	return (
		<div className="jcore-silta-diff__section">
			<div className="jcore-silta-diff__head">
				{ section.changed ? (
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ section.label }
						checked={ checked && ! error }
						disabled={ !! error }
						onChange={ onChange }
					/>
				) : (
					<span className="jcore-silta-diff__label">
						{ section.label }
					</span>
				) }
				{ ! section.changed && (
					<Badge>{ __( 'Unchanged', 'jcore-silta' ) }</Badge>
				) }
			</div>
			{ error && <p className="jcore-silta-item__error">{ error }</p> }
			<DiffBody section={ section } targetLabel={ targetLabel } />
		</div>
	);
}

/**
 * Renders what changes in a section.
 *
 * @param {Object} props             Props.
 * @param {Object} props.section     Section.
 * @param {string} props.targetLabel Target site name.
 * @return {Element} Body.
 */
function DiffBody( { section, targetLabel } ) {
	if ( ! section.changed ) {
		return <pre className="jcore-silta-diff__value">{ section.after }</pre>;
	}
	if ( section.diff ) {
		return (
			<div
				className="jcore-silta-diff__text"
				// The HTML is core's wp_text_diff() table (Differ::text_diff()).
				// On a push the other site builds it, so Endpoint\Remote::diff()
				// strips it to the table's own tags before it gets here.
				dangerouslySetInnerHTML={ { __html: section.diff } }
			/>
		);
	}
	return (
		<table className="jcore-silta-diff__values">
			<thead>
				<tr>
					<th scope="col">
						{ sprintf(
							/* translators: %s: target site name. */
							__( 'On %s now', 'jcore-silta' ),
							targetLabel
						) }
					</th>
					<th scope="col">
						{ __( 'After the sync', 'jcore-silta' ) }
					</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td className="is-before">
						<pre>{ section.before || '—' }</pre>
					</td>
					<td className="is-after">
						<pre>{ section.after || '—' }</pre>
					</td>
				</tr>
			</tbody>
		</table>
	);
}
