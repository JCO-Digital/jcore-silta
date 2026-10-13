import { createRoot } from '@wordpress/element';

import App from './App';
import './style.scss';

const root = document.getElementById( 'jcore-silta-app' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
