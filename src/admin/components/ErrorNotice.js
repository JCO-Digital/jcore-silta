import { Notice } from '@wordpress/components';

/**
 * An error notice, shown only when there is an error.
 *
 * @param {Object}                       props          Props.
 * @param {string}                       props.error    Message.
 * @param {(...args: unknown[]) => void} props.onRemove Dismiss callback; not dismissible without.
 * @param {Array}                        props.actions  Notice actions.
 * @return {Element|null} Notice.
 */
export default function ErrorNotice( { error, onRemove, actions } ) {
	if ( ! error ) {
		return null;
	}
	return (
		<Notice
			status="error"
			isDismissible={ !! onRemove }
			onRemove={ onRemove }
			actions={ actions }
			className="jcore-silta-notice"
		>
			{ error }
		</Notice>
	);
}
