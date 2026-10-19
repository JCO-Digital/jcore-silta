/**
 * State and actions of the sync flow. It lives in App, so a running sync and
 * the choices made so far survive switching tabs.
 */
import { useEffect, useRef, useState } from '@wordpress/element';

import { errorMessage, get, post, remove } from '../api';
import { cleanRules } from '../components/RulesEditor';
import { preselect, site } from '../config';
import { setQueryArgs } from '../url';
import { isUsable } from '../words';

const EMPTY_SELECTION = { posts: [], post_types: [], menus: [], forms: [] };

/**
 * The scope a connection needs for a direction.
 *
 * @param {string} direction push or pull.
 * @return {string} Scope.
 */
export const scopeFor = ( direction ) =>
	direction === 'push' ? 'write' : 'read';

/**
 * Whether a connection allows a direction.
 *
 * @param {Object} connection Connection.
 * @param {string} direction  push or pull.
 * @return {boolean} Allowed.
 */
export const allows = ( connection, direction ) =>
	( connection?.scopes || [] ).includes( scopeFor( direction ) );

const defaultOptions = ( rules = [], diff = false ) => ( {
	status_mode: 'source',
	diff,
	dependencies: true,
	rules: rules.map( ( rule ) => [ ...rule ] ),
	save_rules: false,
} );

/**
 * The sync flow.
 *
 * @return {Object} Flow state and actions.
 */
export default function useSyncFlow() {
	const [ step, setStep ] = useState( preselect.job ? 'loading' : 'where' );
	const [ direction, setDirectionState ] = useState( preselect.direction );
	const [ connectionId, setConnectionId ] = useState( '' );
	const [ sources, setSources ] = useState( null );
	const [ sourcesError, setSourcesError ] = useState( '' );
	const [ selection, setSelection ] = useState( {
		...EMPTY_SELECTION,
		posts: preselect.posts.map( ( id ) => ( { id, title: null } ) ),
	} );
	const [ options, setOptions ] = useState(
		defaultOptions( [], preselect.diff )
	);
	const [ progress, setProgress ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ typeLabels, setTypeLabels ] = useState( {} );

	// Each loop remembers the token it started with and stops when it changes.
	const token = useRef( 0 );

	const connection = ( sources || [] ).find(
		( item ) => item.id === connectionId
	);
	const job = progress?.job || null;

	const loop = async ( id, action ) => {
		const mine = ++token.current;
		setError( '' );
		setStep( action === 'run' ? 'running' : 'preparing' );
		try {
			for (;;) {
				const next = await post( `/jobs/${ id }/${ action }` );
				if ( token.current !== mine ) {
					return;
				}
				setProgress( next );
				const status = next.job.status;
				if ( action === 'prepare' && status !== 'preparing' ) {
					setStep( status === 'ready' ? 'review' : 'result' );
					return;
				}
				if ( action === 'run' && status !== 'running' ) {
					setStep( 'result' );
					return;
				}
			}
		} catch ( e ) {
			if ( token.current === mine ) {
				setError( errorMessage( e ) );
			}
		}
	};

	const loadSources = async () => {
		setSourcesError( '' );
		try {
			const list = await get( '/sources' );
			setSources( list );
			return list;
		} catch ( e ) {
			setSourcesError( errorMessage( e ) );
			setSources( [] );
			return [];
		}
	};

	const openJob = async ( id ) => {
		++token.current;
		setStep( 'loading' );
		setError( '' );
		try {
			const loaded = await get( `/jobs/${ id }` );
			const loadedJob = loaded.job;
			setQueryArgs( { job: id } );
			setProgress( loaded );
			if ( loadedJob.role === 'initiator' ) {
				const chosen = loadedJob.options?.selection || {};
				setDirectionState( loadedJob.direction );
				setConnectionId( loadedJob.connection );
				setSelection( {
					posts: ( chosen.posts || [] ).map( ( postId ) => ( {
						id: postId,
						title: null,
					} ) ),
					post_types: chosen.post_types || [],
					menus: chosen.menus || [],
					forms: chosen.forms || [],
				} );
				setOptions( {
					...defaultOptions( loadedJob.options?.rules || [] ),
					status_mode: loadedJob.options?.status_mode || 'source',
					diff: !! loadedJob.options?.diff,
					dependencies: loadedJob.options?.dependencies !== false,
				} );
			}
			if ( loadedJob.role === 'initiator' ) {
				if ( loadedJob.status === 'preparing' ) {
					loop( id, 'prepare' );
					return;
				}
				if ( loadedJob.status === 'running' ) {
					loop( id, 'run' );
					return;
				}
				if ( loadedJob.status === 'ready' ) {
					setStep( 'review' );
					return;
				}
			}
			setStep( 'result' );
		} catch ( e ) {
			setQueryArgs( { job: null } );
			setError( errorMessage( e ) );
			setStep( 'where' );
		}
	};

	// Load the connections once, then apply the preselection.
	useEffect( () => {
		loadSources().then( ( list ) => {
			if ( preselect.job ) {
				openJob( preselect.job );
				return;
			}
			const usable = list.filter(
				( item ) => isUsable( item ) && allows( item, direction )
			);
			const wanted =
				usable.find( ( item ) => item.id === preselect.connection ) ||
				( usable.length === 1 ? usable[ 0 ] : null );
			if ( wanted ) {
				setConnectionId( wanted.id );
				setOptions( ( current ) => ( {
					...current,
					rules: ( wanted.rules || [] ).map( ( rule ) => [
						...rule,
					] ),
				} ) );
				if ( preselect.connection === wanted.id ) {
					setStep( 'what' );
				}
			}
		} );
		// Runs once on mount; the preselection never changes.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const setDirection = ( value ) => {
		if ( value === direction ) {
			return;
		}
		setDirectionState( value );
		setSelection( EMPTY_SELECTION );
		if ( connection && ! allows( connection, value ) ) {
			setConnectionId( '' );
		}
	};

	const chooseConnection = ( id ) => {
		if ( id === connectionId ) {
			return;
		}
		const chosen = ( sources || [] ).find( ( item ) => item.id === id );
		setConnectionId( id );
		setOptions( ( current ) => ( {
			...current,
			rules: ( chosen?.rules || [] ).map( ( rule ) => [ ...rule ] ),
		} ) );
		// When pulling, the content comes from the remote, so IDs change meaning.
		if ( direction === 'pull' ) {
			setSelection( EMPTY_SELECTION );
		}
	};

	const prepare = async () => {
		setIsBusy( true );
		setError( '' );
		try {
			const created = await post( '/jobs', {
				connection: connectionId,
				direction,
				selection: {
					posts: selection.posts.map( ( item ) => item.id ),
					post_types: selection.post_types,
					menus: selection.menus,
					forms: selection.forms,
				},
				options: { ...options, rules: cleanRules( options.rules ) },
			} );
			setQueryArgs( {
				job: created.id,
				posts: null,
				direction: null,
				connection: null,
				diff: null,
			} );
			setProgress( { job: created, counts: {}, total: 0 } );
			if ( options.save_rules ) {
				loadSources();
			}
			loop( created.id, 'prepare' );
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setIsBusy( false );
		}
	};

	/** Drops a job that never ran and goes back to the options. */
	const discard = async () => {
		++token.current;
		const id = job?.id;
		setError( '' );
		setProgress( null );
		setQueryArgs( { job: null } );
		setStep( 'how' );
		if ( id ) {
			try {
				await remove( `/jobs/${ id }` );
			} catch ( e ) {
				setError( errorMessage( e ) );
			}
		}
	};

	const expand = async () => {
		setIsBusy( true );
		setError( '' );
		try {
			setProgress( await post( `/jobs/${ job.id }/expand` ) );
			loop( job.id, 'prepare' );
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setIsBusy( false );
		}
	};

	const retry = () => loop( job.id, step === 'running' ? 'run' : 'prepare' );

	const run = () => loop( job.id, 'run' );

	/** Stops a running sync. What was written stays and can be undone. */
	const stop = async () => {
		++token.current;
		setError( '' );
		try {
			setProgress( await post( `/jobs/${ job.id }/cancel` ) );
			setStep( 'result' );
		} catch ( e ) {
			setError( errorMessage( e ) );
		}
	};

	/** Reloads the job, e.g. after an undo. */
	const refresh = async () => {
		if ( ! job ) {
			return;
		}
		try {
			setProgress( await get( `/jobs/${ job.id }` ) );
		} catch ( e ) {
			setError( errorMessage( e ) );
		}
	};

	const reset = () => {
		++token.current;
		setError( '' );
		setProgress( null );
		setSelection( EMPTY_SELECTION );
		setOptions( defaultOptions( connection?.rules || [] ) );
		setQueryArgs( { job: null } );
		setStep( connection ? 'what' : 'where' );
	};

	// The two sites, from the point of view of this sync.
	const remoteLabel = job?.remote_label || connection?.label || '';
	const remoteHome = connection?.home || '';
	const isPush = direction === 'push';

	return {
		step,
		setStep,
		direction,
		setDirection,
		connectionId,
		connection,
		chooseConnection,
		sources,
		sourcesError,
		loadSources,
		selection,
		setSelection,
		options,
		setOptions,
		typeLabels,
		setTypeLabels,
		progress,
		job,
		error,
		setError,
		isBusy,
		prepare,
		discard,
		expand,
		retry,
		run,
		stop,
		refresh,
		reset,
		openJob,
		sourceLabel: isPush ? site.name : remoteLabel,
		targetLabel: isPush ? remoteLabel : site.name,
		sourceHome: isPush ? site.home : remoteHome,
		targetHome: isPush ? remoteHome : site.home,
	};
}
