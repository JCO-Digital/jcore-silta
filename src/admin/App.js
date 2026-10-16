import { TabPanel } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { canManage } from './config';
import ConnectionsTab from './connections/ConnectionsTab';
import HistoryTab from './history/HistoryTab';
import SettingsTab from './settings/SettingsTab';
import SyncTab from './sync/SyncTab';
import usePageStatus from './sync/usePageStatus';
import useSyncFlow from './sync/useSyncFlow';
import { getQueryArg, setQueryArgs } from './url';

/**
 * The tabs this user may see.
 *
 * @return {Array} Tabs.
 */
const tabs = () => [
	{ name: 'sync', title: __( 'Sync', 'jcore-silta' ) },
	{ name: 'history', title: __( 'History', 'jcore-silta' ) },
	...( canManage
		? [
				{
					name: 'connections',
					title: __( 'Connections', 'jcore-silta' ),
				},
				{ name: 'settings', title: __( 'Settings', 'jcore-silta' ) },
			]
		: [] ),
];

/**
 * The JCORE Silta screen.
 *
 * @return {Element} App.
 */
export default function App() {
	const available = tabs();
	const [ tab, setTab ] = useState( () => {
		const wanted = getQueryArg( 'tab' );
		return available.some( ( entry ) => entry.name === wanted )
			? wanted
			: 'sync';
	} );
	// TabPanel only takes an initial tab, so it is re-keyed to switch tabs from code.
	const [ panelKey, setPanelKey ] = useState( 0 );
	// The flow lives here so it survives switching tabs.
	const flow = useSyncFlow();
	usePageStatus( flow );

	const select = ( name ) => {
		setTab( name );
		setQueryArgs( { tab: name === 'sync' ? null : name } );
	};

	const go = ( name ) => {
		select( name );
		setPanelKey( ( value ) => value + 1 );
	};

	const render = ( current ) => {
		switch ( current.name ) {
			case 'history':
				return (
					<HistoryTab
						onOpen={ ( id ) => {
							go( 'sync' );
							flow.openJob( id );
						} }
					/>
				);
			case 'connections':
				return <ConnectionsTab onChanged={ flow.loadSources } />;
			case 'settings':
				return <SettingsTab />;
			default:
				return (
					<SyncTab
						flow={ flow }
						onGoConnections={ () => go( 'connections' ) }
					/>
				);
		}
	};

	return (
		<div className="wrap jcore-silta-admin">
			<h1>{ __( 'JCORE Silta', 'jcore-silta' ) }</h1>
			<p className="jcore-silta-intro">
				{ __(
					'Copy posts, pages, media and menus between this site and your other WordPress sites.',
					'jcore-silta'
				) }
			</p>
			<TabPanel
				key={ panelKey }
				className="jcore-silta-tabs"
				tabs={ available }
				initialTabName={ tab }
				onSelect={ select }
			>
				{ render }
			</TabPanel>
		</div>
	);
}
