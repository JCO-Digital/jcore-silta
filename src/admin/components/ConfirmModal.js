import { Button, Flex, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import ErrorNotice from './ErrorNotice';

/**
 * Asks before doing something that matters.
 *
 * @param {Object}                       props               Props.
 * @param {string}                       props.title         Modal title.
 * @param {Object}                       props.children      Explanation.
 * @param {string}                       props.confirmLabel  Label of the confirm button.
 * @param {boolean}                      props.isDestructive Whether the action removes something.
 * @param {boolean}                      props.isBusy        Whether the action is running.
 * @param {string}                       props.error         Error from the action.
 * @param {(...args: unknown[]) => void} props.onConfirm     Confirm callback.
 * @param {(...args: unknown[]) => void} props.onCancel      Cancel callback.
 * @return {Element} Modal.
 */
export default function ConfirmModal( {
	title,
	children,
	confirmLabel,
	isDestructive = false,
	isBusy = false,
	error = '',
	onConfirm,
	onCancel,
} ) {
	return (
		<Modal
			title={ title }
			onRequestClose={ isBusy ? () => {} : onCancel }
			size="medium"
			className="jcore-silta-modal"
		>
			<ErrorNotice error={ error } />
			<div className="jcore-silta-modal__body">{ children }</div>
			<Flex justify="flex-end" className="jcore-silta-modal__actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onCancel }
					disabled={ isBusy }
					accessibleWhenDisabled
				>
					{ __( 'Cancel', 'jcore-silta' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive={ isDestructive }
					onClick={ onConfirm }
					isBusy={ isBusy }
					disabled={ isBusy }
					accessibleWhenDisabled
				>
					{ confirmLabel }
				</Button>
			</Flex>
		</Modal>
	);
}
