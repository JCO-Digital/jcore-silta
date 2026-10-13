import { registerPlugin } from '@wordpress/plugins';

import SyncStatusPanel from './SyncStatusPanel';
import './style.scss';

registerPlugin( 'jcore-silta', { render: SyncStatusPanel } );
