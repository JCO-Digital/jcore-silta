<?php
/**
 * This site, as one side of a sync.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Endpoint;

use Jcore\Silta\Jobs\Jobs;
use Jcore\Silta\Site;
use Jcore\Silta\Sync\Content;
use Jcore\Silta\Sync\Context;
use Jcore\Silta\Sync\Exporter;
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
	 * Creates the endpoint.
	 *
	 * @param string $peer UUID of the other site: the source, when this site is the target.
	 */
	public function __construct( string $peer ) {
		$this->peer = $peer;
	}

	/**
	 * {@inheritDoc}
	 */
	public function handshake(): array|WP_Error {
		return array(
			'site'      => Site::info(),
			'types'     => Content::types(),
			'menus'     => Menus::all(),
			'languages' => Polylang::languages(),
			'acf'       => Acf::active(),
			'polylang'  => Polylang::active(),
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
		return Media::read( (int) ( $args['id'] ?? 0 ), (string) ( $args['path'] ?? '' ), (int) ( $args['offset'] ?? 0 ), (int) ( $args['length'] ?? 0 ) );
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
		$ctx    = $this->context( $args, false );
		$record = reset( $ctx->objects );
		if ( false === $record || 'attachment' !== ( $record['post_type'] ?? '' ) ) {
			return new WP_Error( 'jcore_silta_not_attachment', __( 'Not an attachment.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		$match = $ctx->match( $record );

		return Media::prepare( $record, (int) ( $match['id'] ?? 0 ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function media_write( array $args ): array|WP_Error {
		return Media::write( (string) ( $args['transfer'] ?? '' ), (string) ( $args['path'] ?? '' ), (int) ( $args['offset'] ?? 0 ), (string) ( $args['data'] ?? '' ) );
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

		Jobs::ensure_target( $job, $this->peer, (array) ( $options['job_meta'] ?? array() ) );

		$ctx     = $this->context( $args, false );
		$results = ( new Importer( $ctx, $job ) )->run( $options );
		if ( empty( $options['fixup'] ) ) {
			Jobs::record_results( $job, $results );
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
		if ( 'initiator' !== $job['role'] && $job['remote_site'] !== $this->peer ) {
			return new WP_Error( 'jcore_silta_not_yours', __( 'That sync came from another site.', 'jcore-silta' ), array( 'status' => 403 ) );
		}

		$summary = Snapshots::undo( $uuid );
		Jobs::update( (int) $job['id'], array( 'status' => 'undone' ) );

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

			$source_changed = (string) ( $item['hash'] ?? '' ) !== (string) $row['remote_hash'];
			$target_changed = '' !== (string) $row['local_hash'] && $row['local_hash'] !== State::hash( $state );

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
