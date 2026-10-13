import { __ } from '@wordpress/i18n';

const STEPS = [
	{ name: 'where', steps: [ 'where' ] },
	{ name: 'what', steps: [ 'what' ] },
	{ name: 'how', steps: [ 'how' ] },
	{ name: 'review', steps: [ 'preparing', 'review' ] },
	{ name: 'sync', steps: [ 'running', 'result' ] },
];

/**
 * Labels of the steps.
 *
 * @return {Object} Label by step.
 */
const labels = () => ( {
	where: __( 'Where', 'jcore-silta' ),
	what: __( 'What', 'jcore-silta' ),
	how: __( 'How', 'jcore-silta' ),
	review: __( 'Review', 'jcore-silta' ),
	sync: __( 'Sync', 'jcore-silta' ),
} );

/**
 * Shows which step of the flow the user is on.
 *
 * @param {Object} props      Props.
 * @param {string} props.step Current flow step.
 * @return {Element} Step list.
 */
export default function StepHeader( { step } ) {
	const current = STEPS.findIndex( ( entry ) =>
		entry.steps.includes( step )
	);
	const names = labels();

	return (
		<ol className="jcore-silta-steps">
			{ STEPS.map( ( entry, index ) => {
				let state = 'is-upcoming';
				if ( index < current ) {
					state = 'is-done';
				} else if ( index === current ) {
					state = 'is-current';
				}
				return (
					<li
						key={ entry.name }
						className={ `jcore-silta-steps__step ${ state }` }
						aria-current={ index === current ? 'step' : undefined }
					>
						<span className="jcore-silta-steps__number">
							{ index + 1 }
						</span>
						<span className="jcore-silta-steps__label">
							{ names[ entry.name ] }
						</span>
						{ index < current && (
							<span className="screen-reader-text">
								{ __( '(done)', 'jcore-silta' ) }
							</span>
						) }
					</li>
				);
			} ) }
		</ol>
	);
}
