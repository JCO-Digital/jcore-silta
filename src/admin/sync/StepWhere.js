import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	Notice,
	RadioControl,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import ErrorNotice from '../components/ErrorNotice';
import { canManage } from '../config';
import { connectionProblem, isUsable } from '../words';
import { allows } from './useSyncFlow';

/**
 * Step 1: direction and connection.
 *
 * @param {Object}                       props                 Props.
 * @param {Object}                       props.flow            Sync flow.
 * @param {(...args: unknown[]) => void} props.onGoConnections Opens the Connections tab.
 * @return {Element} Step.
 */
export default function StepWhere( { flow, onGoConnections } ) {
	const { sources, direction, connectionId } = flow;

	if ( sources === null ) {
		return <Spinner />;
	}

	const eligible = sources.filter( ( item ) => allows( item, direction ) );
	const others = sources.filter( ( item ) => ! allows( item, direction ) );
	const chosen = eligible.find( ( item ) => item.id === connectionId );
	const canContinue = chosen && isUsable( chosen );

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Where do you want to sync?', 'jcore-silta' ) }</h2>
			</CardHeader>
			<CardBody>
				<ErrorNotice error={ flow.sourcesError } />
				<RadioControl
					label={ __( 'Direction', 'jcore-silta' ) }
					selected={ direction }
					options={ [
						{
							value: 'push',
							label: __( 'Push to another site', 'jcore-silta' ),
							description: __(
								'Send content from this site to the other site.',
								'jcore-silta'
							),
						},
						{
							value: 'pull',
							label: __(
								'Pull from another site',
								'jcore-silta'
							),
							description: __(
								'Bring content from the other site to this site.',
								'jcore-silta'
							),
						},
					] }
					onChange={ flow.setDirection }
				/>

				{ sources.length === 0 && (
					<NoConnections onGoConnections={ onGoConnections } />
				) }

				{ sources.length > 0 && eligible.length === 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ direction === 'push'
							? __(
									'None of the connected sites allows this site to push to it.',
									'jcore-silta'
								)
							: __(
									'None of the connected sites allows this site to pull from it.',
									'jcore-silta'
								) }
					</Notice>
				) }

				{ eligible.length > 0 && (
					<RadioControl
						className="jcore-silta-connections-choice"
						label={
							direction === 'push'
								? __( 'Push to', 'jcore-silta' )
								: __( 'Pull from', 'jcore-silta' )
						}
						selected={ connectionId }
						options={ eligible.map( ( item ) => ( {
							value: item.id,
							label: item.label,
							description: connectionProblem( item ) || item.home,
							disabled: ! isUsable( item ),
						} ) ) }
						onChange={ flow.chooseConnection }
					/>
				) }

				{ chosen && chosen.status === 'error' && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: error message. */
							__(
								'The last attempt to reach this site failed: %s You can still try.',
								'jcore-silta'
							),
							connectionProblem( chosen )
						) }
					</Notice>
				) }

				{ others.length > 0 && (
					<p className="description">
						{ notListedText(
							direction,
							others.map( ( item ) => item.label ).join( ', ' )
						) }
					</p>
				) }

				<Flex justify="flex-end">
					<Button
						__next40pxDefaultSize
						variant="primary"
						disabled={ ! canContinue }
						accessibleWhenDisabled
						onClick={ () => flow.setStep( 'what' ) }
					>
						{ __( 'Next: choose content', 'jcore-silta' ) }
					</Button>
				</Flex>
			</CardBody>
		</Card>
	);
}

/**
 * Explains what to do when there are no connections.
 *
 * @param {Object}                       props                 Props.
 * @param {(...args: unknown[]) => void} props.onGoConnections Opens the Connections tab.
 * @return {Element} Notice.
 */
function NoConnections( { onGoConnections } ) {
	return (
		<Notice status="info" isDismissible={ false }>
			<p>
				{ __(
					'This site is not connected to any other site yet. Connect the sites first, then come back here.',
					'jcore-silta'
				) }
			</p>
			{ canManage ? (
				<Button variant="link" onClick={ onGoConnections }>
					{ __( 'Go to Connections', 'jcore-silta' ) }
				</Button>
			) : (
				<p>
					{ __(
						'Ask an administrator to connect the sites.',
						'jcore-silta'
					) }
				</p>
			) }
		</Notice>
	);
}

/**
 * Names the connections that don't allow the direction.
 *
 * @param {string} direction push or pull.
 * @param {string} names     Comma-separated site names.
 * @return {string} Text.
 */
function notListedText( direction, names ) {
	if ( direction === 'push' ) {
		return sprintf(
			/* translators: %s: comma-separated site names. */
			__(
				'Not listed, because they do not allow this site to push: %s',
				'jcore-silta'
			),
			names
		);
	}
	return sprintf(
		/* translators: %s: comma-separated site names. */
		__(
			'Not listed, because they do not allow this site to pull: %s',
			'jcore-silta'
		),
		names
	);
}
