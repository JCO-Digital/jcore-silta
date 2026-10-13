/**
 * A small coloured label.
 *
 * @param {Object} props          Props.
 * @param {string} props.tone     neutral, info, success, warning or error.
 * @param {Object} props.children Content.
 * @return {Element} Badge.
 */
export default function Badge( { tone = 'neutral', children } ) {
	return (
		<span className={ `jcore-silta-badge is-${ tone }` }>{ children }</span>
	);
}
