import { CheckboxControl, SearchControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage, get } from '../api';
import ErrorNotice from '../components/ErrorNotice';
import Pagination from '../components/Pagination';
import { formatDate, parseGmt } from '../words';

const PER_PAGE = 20;

/**
 * Browses, searches and picks posts of one post type on the source.
 *
 * @param {Object}                       props              Props.
 * @param {Object}                       props.flow         Sync flow.
 * @param {Object}                       props.type         Post type from GET /source.
 * @param {boolean}                      props.isWhole      Whether the whole type is selected.
 * @param {(...args: unknown[]) => void} props.onTogglePost Called with ( post, checked ).
 * @return {Element} Browser.
 */
export default function PostBrowser( { flow, type, isWhole, onTogglePost } ) {
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );

	// Wait for a pause in typing before searching.
	useEffect( () => {
		const timer = setTimeout( () => {
			setQuery( search );
			setPage( 1 );
		}, 350 );
		return () => clearTimeout( timer );
	}, [ search ] );

	useEffect( () => {
		let alive = true;
		setIsLoading( true );
		setError( '' );
		get( '/source/posts', {
			connection: flow.connectionId,
			direction: flow.direction,
			post_type: type.name,
			search: query,
			page,
			per_page: PER_PAGE,
		} )
			.then( ( response ) => alive && setResult( response ) )
			.catch( ( e ) => alive && setError( errorMessage( e ) ) )
			.finally( () => alive && setIsLoading( false ) );
		return () => {
			alive = false;
		};
	}, [ flow.connectionId, flow.direction, type.name, query, page ] );

	const selectedIds = new Set(
		flow.selection.posts.map( ( item ) => item.id )
	);
	const isMedia = type.name === 'attachment';

	return (
		<div className="jcore-silta-browser">
			<SearchControl
				__nextHasNoMarginBottom
				label={ sprintf(
					/* translators: %s: post type name, e.g. Pages. */
					__( 'Search %s', 'jcore-silta' ),
					type.label
				) }
				value={ search }
				onChange={ setSearch }
			/>
			{ isWhole && (
				<p className="description">
					{ __(
						'All posts of this type are already selected.',
						'jcore-silta'
					) }
				</p>
			) }
			<ErrorNotice error={ error } />
			{ isLoading && ! result && <Spinner /> }
			{ result && (
				<>
					{ result.items.length === 0 && (
						<p>{ __( 'Nothing found.', 'jcore-silta' ) }</p>
					) }
					<ul
						className={ `jcore-silta-browser__list${
							isMedia ? ' is-media' : ''
						}${ isLoading ? ' is-loading' : '' }` }
					>
						{ result.items.map( ( item ) => (
							<li
								key={ item.id }
								className="jcore-silta-browser__item"
							>
								{ isMedia && (
									<span className="jcore-silta-thumb">
										{ item.thumb && (
											<img src={ item.thumb } alt="" />
										) }
									</span>
								) }
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ item.title || `#${ item.id }` }
									help={ postMeta( item ) }
									checked={
										isWhole || selectedIds.has( item.id )
									}
									disabled={ isWhole }
									onChange={ ( checked ) =>
										onTogglePost( item, checked )
									}
								/>
							</li>
						) ) }
					</ul>
					<Pagination
						page={ page }
						pages={ result.pages || 1 }
						onChange={ setPage }
					/>
				</>
			) }
		</div>
	);
}

/**
 * One line about a post: status, language and last change.
 *
 * @param {Object} item Post from GET /source/posts.
 * @return {string} Line.
 */
function postMeta( item ) {
	const parts = [];
	if (
		item.status &&
		item.status !== 'publish' &&
		item.status !== 'inherit'
	) {
		parts.push( item.status_label || item.status );
	}
	if ( item.lang ) {
		parts.push( item.lang );
	}
	parts.push(
		sprintf(
			/* translators: %s: date. */
			__( 'changed %s', 'jcore-silta' ),
			formatDate( parseGmt( item.modified ) )
		)
	);
	return parts.join( ' · ' );
}
