<?php
/**
 * This site, as one side of a sync.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Endpoint;

use Jcore\Silta\Jobs\Jobs;
use Jcore\Silta\Settings;
use Jcore\Silta\Site;
use Jcore\Silta\Sync\Content;
use Jcore\Silta\Sync\Context;
use Jcore\Silta\Sync\Exporter;
use Jcore\Silta\Sync\Gravity_Forms;
use Jcore\Silta\Sync\Importer;
use Jcore\Silta\Sync\Mapping;
use Jcore\Silta\Sync\Media;
use Jcore\Silta\Sync\Menus;
use Jcore\Silta\Sync\Planner;
use Jcore\Silta\Sync\Polylang;
use Jcore\Silta\Sync\Snapshots;
use Jcore\Silta\Sync\State;
use Jcore\Silta\Sync\Acf;
use Jcore\Silta\Util;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the operations on this site. Used directly by the job runner for the
 * local side, and by the remote routes for calls from a paired site.
 */
final class Local extends Endpoint {

	/**
	 * UUID of the other site in the sync.
	 *
	 * @var string
	 */
	private string $peer;

	/**
	 * Whether this endpoint is used by the local initiator. Never set from request data.
	 *
	 * @var bool
	 */
	private bool $initiator;

	/**
	 * Creates the endpoint.
	 *
	 * @param string $peer      UUID of the other site: the source, when this site is the target.
	 * @param bool   $initiator Whether the local runner owns this endpoint.
	 */
	public function __construct( string $peer, bool $initiator = false ) {
		$this->peer      = $peer;
		$this->initiator = $initiator;
	}

	/**
	 * {@inheritDoc}
	 */
	public function handshake(): array|WP_Error {
		return array(
			'site'          => Site::info(),
			'types'         => Content::types(),
			'menus'         => Menus::all(),
			'languages'     => Polylang::languages(),
			'acf'           => Acf::active(),
			'polylang'      => Polylang::active(),
			'gravity_forms' => Gravity_Forms::active(),
			'forms'         => Gravity_Forms::all(),
			'undo_days'     => (int) Settings::get( 'snapshot_days' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function query( array $args ): array|WP_Error {
		return Content::query( $args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function ids( array $args ): array|WP_Error {
		return array( 'ids' => Content::ids( (string) ( $args['post_type'] ?? '' ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function export( array $args ): array|WP_Error {
		return ( new Exporter() )->export( array_map( 'strval', (array) ( $args['keys'] ?? array() ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function media_read( array $args ): array|WP_Error {
		if ( ! is_int( $args['id'] ?? null ) || $args['id'] < 1 || ! is_string( $args['path'] ?? null ) || ! is_int( $args['offset'] ?? null ) || ! is_int( $args['length'] ?? null ) ) {
			return new WP_Error( 'jcore_silta_bad_chunk', __( 'The file chunk request is invalid.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		return Media::read( $args['id'], $args['path'], $args['offset'], $args['length'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function plan( array $args ): array|WP_Error {
		$ctx = $this->context( $args, true );

		return array( 'plans' => ( new Planner( $ctx ) )->plan() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function diff( array $args ): array|WP_Error {
		$ctx = $this->context( $args, true );

		return ( new Planner( $ctx ) )->diff( (string) ( $args['key'] ?? '' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function media_prepare( array $args ): array|WP_Error {
		$ctx = $this->context( $args, false );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$record = reset( $ctx->objects );
		if ( false === $record || 'attachment' !== ( $record['post_type'] ?? '' ) ) {
			return new WP_Error( 'jcore_silta_not_attachment', __( 'Not an attachment.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		$match = $ctx->match( $record );

		return Media::prepare( $record, (int) ( $match['id'] ?? 0 ), $this->peer );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function media_write( array $args ): array|WP_Error {
		if ( ! is_string( $args['transfer'] ?? null ) || ! is_string( $args['path'] ?? null ) || ! is_int( $args['offset'] ?? null ) || ! is_string( $args['data'] ?? null ) || ! is_int( $args['id'] ?? null ) || $args['id'] < 1 ) {
			return new WP_Error( 'jcore_silta_bad_chunk', __( 'The file chunk request is invalid.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		return Media::write( $args['transfer'], $args['path'], $args['offset'], $args['data'], $this->peer, $args['id'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function import( array $args ): array|WP_Error {
		$options = (array) ( $args['options'] ?? array() );
		$job     = (string) ( $options['job'] ?? '' );
		if ( ! wp_is_uuid( $job ) ) {
			return new WP_Error( 'jcore_silta_no_job', __( 'The import has no job ID.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		$stored = $this->initiator ? Jobs::get( $job ) : Jobs::ensure_target( $job, $this->peer, (array) ( $options['job_meta'] ?? array() ) );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( $this->initiator && ( null === $stored || 'initiator' !== $stored['role'] || $this->peer !== $stored['remote_site'] || ! Jobs::user_permission( $stored ) ) ) {
			return new WP_Error( 'jcore_silta_not_yours', __( 'That sync does not belong to you.', 'jcore-silta' ), array( 'status' => 403 ) );
		}

		// A push is guarded here: when the initiator gave up waiting and sends
		// the batch again, the first request may still be writing it. A pull is
		// guarded by its runner, which holds the same job.
		$token = $this->initiator ? null : wp_generate_uuid4();
		if ( null !== $token && ! Jobs::claim( (int) $stored['id'], $token ) ) {
			return Jobs::busy();
		}

		try {
			$ctx     = $this->context( $args, false );
			$results = ( new Importer( $ctx, $job ) )->run( $options );
			if ( ! $this->initiator && empty( $options['fixup'] ) ) {
				$recorded = Jobs::record_results(
					$job,
					array_filter( $results, static fn( array $result ): bool => 'deferred' !== ( $result['status'] ?? '' ) ),
					$this->peer
				);
				if ( is_wp_error( $recorded ) ) {
					return $recorded;
				}
			}
		} finally {
			if ( null !== $token ) {
				Jobs::release( (int) $stored['id'], $token );
			}
		}

		return array( 'results' => $results );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function orphans( array $args ): array|WP_Error {
		$present = array_flip( array_map( 'intval', (array) ( $args['ids'] ?? array() ) ) );
		$orphans = array();

		foreach ( Mapping::posts_of_type( $this->peer, sanitize_key( (string) ( $args['post_type'] ?? '' ) ) ) as $remote_id => $local_id ) {
			if ( isset( $present[ $remote_id ] ) ) {
				continue;
			}
			$post      = get_post( $local_id );
			$orphans[] = array(
				'id'        => $local_id,
				'remote_id' => $remote_id,
				'title'     => $post instanceof \WP_Post ? $post->post_title : '#' . $local_id,
				'status'    => $post instanceof \WP_Post ? $post->post_status : '',
			);
		}

		return array( 'orphans' => $orphans );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function undo( array $args ): array|WP_Error {
		$uuid = (string) ( $args['job'] ?? '' );
		$job  = wp_is_uuid( $uuid ) ? Jobs::get( $uuid ) : null;
		if ( null === $job ) {
			return new WP_Error( 'jcore_silta_no_job', __( 'This site has no record of that sync.', 'jcore-silta' ), array( 'status' => 404 ) );
		}
		if ( $this->initiator ? ( 'initiator' !== $job['role'] || $job['remote_site'] !== $this->peer || ! Jobs::user_permission( $job ) ) : ! Jobs::target_permission( $job, $this->peer ) ) {
			return new WP_Error( 'jcore_silta_not_yours', __( 'That sync came from another site.', 'jcore-silta' ), array( 'status' => 403 ) );
		}

		// A received push is this site's to guard; the runner guards its own pull.
		$token = $this->initiator ? null : wp_generate_uuid4();
		if ( null !== $token && ! Jobs::claim( (int) $job['id'], $token ) ) {
			return Jobs::busy();
		}

		try {
			// With a budget the undo is done in slices: the caller asks again
			// while snapshots remain. Without one (an older initiator) in one go.
			$budget   = is_numeric( $args['budget'] ?? null ) ? max( 1.0, min( 120.0, (float) $args['budget'] ) ) : null;
			$slice    = Snapshots::undo( $uuid, null === $budget ? null : microtime( true ) + $budget );
			$state    = (array) $job['state'];
			$previous = (array) ( $state['undo'] ?? array() );
			$summary  = array(
				'restored'  => (int) ( $previous['restored'] ?? 0 ) + $slice['restored'],
				'removed'   => (int) ( $previous['removed'] ?? 0 ) + $slice['removed'],
				'untrashed' => (int) ( $previous['untrashed'] ?? 0 ) + $slice['untrashed'],
				'errors'    => array_slice( array_merge( (array) ( $previous['errors'] ?? array() ), $slice['errors'] ), 0, 200 ),
				'remaining' => $slice['remaining'],
			);

			$state['undo'] = $summary;
			Jobs::update(
				(int) $job['id'],
				array(
					'status' => $slice['remaining'] > 0 ? 'undoing' : 'undone',
					'state'  => $state,
				)
			);
		} finally {
			if ( null !== $token ) {
				Jobs::release( (int) $job['id'], $token );
			}
		}

		return $summary;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function status( array $args ): array|WP_Error {
		$statuses = array();

		foreach ( (array) ( $args['items'] ?? array() ) as $item ) {
			list( $type, $id ) = Util::split_key( (string) ( $item['key'] ?? '' ) );
			$row               = Mapping::get( $this->peer, 'post' === $type ? 'post' : 'term', $id );
			$state             = null === $row ? null : State::read( $type, (int) $row['local_id'] );

			if ( null === $state ) {
				$statuses[ (string) $item['key'] ] = array( 'state' => 'not_synced' );
				continue;
			}

			// The source also sends the legacy hash, which matches mappings
			// stored by earlier versions; an older source sends only one.
			$hashes         = array_filter( array( (string) ( $item['hash'] ?? '' ), (string) ( $item['legacy_hash'] ?? '' ) ) );
			$source_changed = array() === $hashes ? '' !== (string) $row['remote_hash'] : ! in_array( (string) $row['remote_hash'], $hashes, true );
			$target_changed = '' !== (string) $row['local_hash'] && ! State::hash_matches( (string) $row['local_hash'], $state );

			$statuses[ (string) $item['key'] ] = array(
				'state'     => $source_changed && $target_changed ? 'both_changed' : ( $source_changed ? 'source_changed' : ( $target_changed ? 'target_changed' : 'synced' ) ),
				'synced_at' => (string) $row['synced_at'],
				'local'     => Planner::describe( $type, (int) $row['local_id'] ),
			);
		}

		return array( 'statuses' => $statuses );
	}

	/**
	 * Builds the context for a bundle.
	 *
	 * @param array<string, mixed> $args    bundle and options.
	 * @param bool                 $preview Whether this is planning rather than importing.
	 *
	 * @return Context
	 */
	private function context( array $args, bool $preview ): Context {
		$options            = (array) ( $args['options'] ?? array() );
		$options['preview'] = $preview;

		return new Context( (array) ( $args['bundle'] ?? array() ), $options, $this->peer );
	}
}
