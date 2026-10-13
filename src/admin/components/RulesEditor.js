import { Button, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Edits search-replace rules: rows of [ find, replace ].
 *
 * @param {Object}                       props          Props.
 * @param {Array}                        props.rules    Rules.
 * @param {(...args: unknown[]) => void} props.onChange Called with the new rules.
 * @return {Element} Editor.
 */
export default function RulesEditor( { rules, onChange } ) {
	const update = ( index, column, value ) =>
		onChange(
			rules.map( ( rule, i ) => {
				if ( i !== index ) {
					return rule;
				}
				const next = [ ...rule ];
				next[ column ] = value;
				return next;
			} )
		);

	return (
		<div className="jcore-silta-rules">
			{ rules.map( ( rule, index ) => (
				<div className="jcore-silta-rules__row" key={ index }>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: %d: rule number. */
							__( 'Find (rule %d)', 'jcore-silta' ),
							index + 1
						) }
						hideLabelFromVision={ index > 0 }
						value={ rule[ 0 ] }
						onChange={ ( value ) => update( index, 0, value ) }
					/>
					<span
						className="jcore-silta-rules__arrow"
						aria-hidden="true"
					>
						→
					</span>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ sprintf(
							/* translators: %d: rule number. */
							__( 'Replace with (rule %d)', 'jcore-silta' ),
							index + 1
						) }
						hideLabelFromVision={ index > 0 }
						value={ rule[ 1 ] }
						onChange={ ( value ) => update( index, 1, value ) }
					/>
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						isDestructive
						onClick={ () =>
							onChange( rules.filter( ( _, i ) => i !== index ) )
						}
					>
						{ __( 'Remove', 'jcore-silta' ) }
					</Button>
				</div>
			) ) }
			<Button
				variant="secondary"
				size="compact"
				onClick={ () => onChange( [ ...rules, [ '', '' ] ] ) }
			>
				{ __( 'Add a rule', 'jcore-silta' ) }
			</Button>
		</div>
	);
}

/**
 * Drops rules without a search string.
 *
 * @param {Array} rules Rules.
 * @return {Array} Clean rules.
 */
export const cleanRules = ( rules ) =>
	rules.filter( ( rule ) => rule[ 0 ] && rule[ 0 ].trim() !== '' );
