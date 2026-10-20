import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	RadioControl,
	ToggleControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import ErrorNotice from '../components/ErrorNotice';
import RulesEditor from '../components/RulesEditor';
import { canManage } from '../config';

/**
 * Step 3: options for the sync.
 *
 * @param {Object} props      Props.
 * @param {Object} props.flow Sync flow.
 * @return {Element} Step.
 */
export default function StepHow( { flow } ) {
	const { options, setOptions, targetLabel } = flow;
	const set = ( changes ) =>
		setOptions( ( current ) => ( { ...current, ...changes } ) );

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'How should it be synced?', 'jcore-silta' ) }</h2>
			</CardHeader>
			<CardBody>
				<ErrorNotice
					error={ flow.error }
					onRemove={ () => flow.setError( '' ) }
				/>

				<RadioControl
					label={ sprintf(
						/* translators: %s: target site name. */
						__( 'Status on %s', 'jcore-silta' ),
						targetLabel
					) }
					selected={ options.status_mode }
					options={ [
						{
							value: 'source',
							label: __( 'Same as on the source', 'jcore-silta' ),
							description: __(
								'A published post is published on the target too.',
								'jcore-silta'
							),
						},
						{
							value: 'draft',
							label: __( 'Always as a draft', 'jcore-silta' ),
						},
						{
							value: 'keep',
							label: __(
								"Keep the target's status for existing posts",
								'jcore-silta'
							),
							description: __(
								'New posts get the status they have on the source.',
								'jcore-silta'
							),
						},
					] }
					onChange={ ( value ) => set( { status_mode: value } ) }
				/>

				<div className="jcore-silta-section">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Review each change and choose which parts to sync',
							'jcore-silta'
						) }
						help={ __(
							'Shows the differences for every post, like the revisions screen, so you can leave out the parts you do not want.',
							'jcore-silta'
						) }
						checked={ options.diff }
						onChange={ ( diff ) => set( { diff } ) }
					/>
				</div>

				<div className="jcore-silta-section">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Include dependencies', 'jcore-silta' ) }
						help={ __(
							"Also sync the media, terms and posts these refer to when the target doesn't have them.",
							'jcore-silta'
						) }
						checked={ options.dependencies }
						onChange={ ( dependencies ) => set( { dependencies } ) }
					/>
				</div>

				<div className="jcore-silta-section">
					<h3>{ __( 'Search and replace', 'jcore-silta' ) }</h3>
					<p className="description">
						{ flow.sourceHome && flow.targetHome
							? sprintf(
									/* translators: 1: source site address, 2: target site address. */
									__(
										'The site addresses are replaced automatically: %1$s → %2$s. Add rules for anything else that differs between the sites. Plain text, no patterns.',
										'jcore-silta'
									),
									flow.sourceHome,
									flow.targetHome
								)
							: __(
									'The site addresses are replaced automatically. Add rules for anything else that differs between the sites. Plain text, no patterns.',
									'jcore-silta'
								) }
					</p>
					{ canManage ? (
						<>
							<RulesEditor
								rules={ options.rules }
								onChange={ ( rules ) => set( { rules } ) }
							/>
							<CheckboxControl
								__nextHasNoMarginBottom
								label={ __(
									'Save these rules for this connection',
									'jcore-silta'
								) }
								checked={ options.save_rules }
								onChange={ ( saveRules ) =>
									set( { save_rules: saveRules } )
								}
							/>
						</>
					) : (
						<SavedRules rules={ options.rules } />
					) }
				</div>

				<Flex justify="space-between" className="jcore-silta-actions">
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ () => flow.setStep( 'what' ) }
					>
						{ __( 'Back', 'jcore-silta' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						isBusy={ flow.isBusy }
						disabled={ flow.isBusy }
						accessibleWhenDisabled
						onClick={ flow.prepare }
					>
						{ __( 'Prepare', 'jcore-silta' ) }
					</Button>
				</Flex>
				<p className="description jcore-silta-actions__note">
					{ __(
						'Preparing compares the content with the target. Nothing is written yet.',
						'jcore-silta'
					) }
				</p>
			</CardBody>
		</Card>
	);
}

/**
 * The connection's saved rules, for users who can't change them.
 *
 * @param {Object} props       Props.
 * @param {Array}  props.rules Rules.
 * @return {Element} Rules.
 */
function SavedRules( { rules } ) {
	return (
		<>
			{ rules.length > 0 && (
				<ul className="jcore-silta-saved-rules">
					{ rules.map( ( rule, index ) => (
						<li key={ index }>
							<code>{ rule[ 0 ] }</code> →{ ' ' }
							<code>{ rule[ 1 ] }</code>
						</li>
					) ) }
				</ul>
			) }
			<p className="description">
				{ rules.length > 0
					? __(
							'These rules are saved on the connection. Ask an administrator to change them.',
							'jcore-silta'
						)
					: __(
							'No other rules are saved on this connection. Ask an administrator to add them.',
							'jcore-silta'
						) }
			</p>
		</>
	);
}
