import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage, get, remove } from '../api';
import ErrorNotice from '../components/ErrorNotice';
import { formatDate, fromUnix } from '../words';
import IncomingKeys from './IncomingKeys';
import OutgoingConnections from './OutgoingConnections';
import ThisSite from './ThisSite';

/**
 * The Connections tab (admins only).
 *
 * @param {Object}                       props           Props.
 * @param {(...args: unknown[]) => void} props.onChanged Called after connections change.
 * @return {Element} Tab.
 */
export default function ConnectionsTab( { onChanged } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ reload, setReload ] = useState( 0 );

	useEffect( () => {
		let alive = true;
		get( '/connections' )
			.then( ( response ) => alive && setData( response ) )
			.catch( ( e ) => alive && setError( errorMessage( e ) ) );
		return () => {
			alive = false;
		};
	}, [ reload ] );

	/** Reloads the overview after a change. */
	const refresh = () => {
		setError( '' );
		setReload( ( value ) => value + 1 );
		onChanged();
	};

	if ( ! data ) {
		return error ? <ErrorNotice error={ error } /> : <Spinner />;
	}

	return (
		<div className="jcore-silta-connections">
			<ErrorNotice error={ error } onRemove={ () => setError( '' ) } />
			{ data.cloned && (
				<CloneNotice cloned={ data.cloned } onDismissed={ refresh } />
			) }
			<ThisSite data={ data } onChanged={ refresh } />
			<OutgoingConnections
				connections={ data.connections }
				onChanged={ refresh }
			/>
			<IncomingKeys
				keys={ data.keys }
				tokens={ data.tokens }
				onChanged={ refresh }
			/>
		</div>
	);
}

/**
 * Explains that this site runs on a copy of another site's database.
 *
 * @param {Object}                       props             Props.
 * @param {Object}                       props.cloned      { from, at }.
 * @param {(...args: unknown[]) => void} props.onDismissed Called after dismissing.
 * @return {Element} Notice.
 */
function CloneNotice( { cloned, onDismissed } ) {
	const [ error, setError ] = useState( '' );

	const dismiss = async () => {
		try {
			await remove( '/cloned' );
			onDismissed();
		} catch ( e ) {
			setError( errorMessage( e ) );
		}
	};

	return (
		<Notice status="warning" isDismissible={ false }>
			<p>
				{ sprintf(
					/* translators: 1: previous site address, 2: date. */
					__(
						'This site’s database was copied from %1$s (noticed %2$s). So the copy can’t act as that site, this site got a new identity, and the connections and allowed sites that came with the copy were switched off. Pair the sites you need again.',
						'jcore-silta'
					),
					cloned.from || __( 'another address', 'jcore-silta' ),
					formatDate( fromUnix( cloned.at ) )
				) }
			</p>
			<ErrorNotice error={ error } />
			<Button variant="secondary" size="compact" onClick={ dismiss }>
				{ __( 'Dismiss', 'jcore-silta' ) }
			</Button>
		</Notice>
	);
}
