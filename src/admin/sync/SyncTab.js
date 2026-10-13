import { Spinner } from '@wordpress/components';

import ErrorNotice from '../components/ErrorNotice';
import JobProgress from './JobProgress';
import Result from './Result';
import Review from './Review';
import StepHeader from './StepHeader';
import StepHow from './StepHow';
import StepWhat from './StepWhat';
import StepWhere from './StepWhere';

/**
 * The Sync tab: one step of the flow at a time.
 *
 * @param {Object}                       props                 Props.
 * @param {Object}                       props.flow            Sync flow from useSyncFlow().
 * @param {(...args: unknown[]) => void} props.onGoConnections Opens the Connections tab.
 * @return {Element} Tab.
 */
export default function SyncTab( { flow, onGoConnections } ) {
	const { step } = flow;

	return (
		<div className="jcore-silta-sync">
			<StepHeader step={ step } />
			{ step === 'loading' && <Spinner /> }
			{ step === 'where' && (
				<>
					<ErrorNotice
						error={ flow.error }
						onRemove={ () => flow.setError( '' ) }
					/>
					<StepWhere
						flow={ flow }
						onGoConnections={ onGoConnections }
					/>
				</>
			) }
			{ step === 'what' && <StepWhat flow={ flow } /> }
			{ step === 'how' && <StepHow flow={ flow } /> }
			{ ( step === 'preparing' || step === 'running' ) && (
				<JobProgress flow={ flow } />
			) }
			{ step === 'review' && <Review flow={ flow } /> }
			{ step === 'result' && flow.job && <Result flow={ flow } /> }
		</div>
	);
}
