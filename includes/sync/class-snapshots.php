<?php
/**
 * What a job changed, so it can be undone.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

use Jcore\Silta\Database;
use Jcore\Silta\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records the state of every object a job overwrites, creates or trashes, and
 * puts it all back on undo.
 */
final class Snapshots {

	/**
	 * Records an object's state before a job first changes it.
	 *
	 * @param string               $job      Job UUID.
	 * @param string               $type     post, term or menu.
	 * @param int                  $local_id Local ID.
	 * @param array<string, mixed> $data     State and mapping before the change.
	 *
	 * @return void
	 */
	public static function updated( string $job, string $type, int $local_id, array $data ): void {
		self::add( $job, $type, $local_id, 'update', $data );
	}

	/**
	 * Records that a job created an object.
	 *
	 * @param string               $job      Job UUID.
	 * @param string               $type     post, term or menu.
	 * @param int                  $local_id Local ID.
	 * @param array<string, mixed> $data     Mapping details.
	 *
	 * @return void
	 */
	public static function created( string $job, string $type, int $local_id, array $data = array() ): void {
		self::add( $job, $type, $local_id, 'create', $data );
	}

	/**
	 * Records that a job trashed a post.
	 *
	 * @param string $job      Job UUID.
	 * @param int    $local_id Post ID.
	 *
	 * @return void
	 */
	public static function trashed( string $job, int $local_id ): void {
		self::add( $job, 'post', $local_id, 'trash', array() );
	}

	/**
	 * Whether a job already recorded an object.
	 *
	 * @param string $job      Job UUID.
	 * @param string $type     post, term or menu.
	 * @param int    $local_id Local ID.
	 *
	 * @return bool
	 */
	public static function has( string $job, string $type, int $local_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'SELECT id FROM %i WHERE job_uuid = %s AND object_type = %s AND local_id = %d LIMIT 1',
				Database::table( 'snapshots' ),
				$job,
				$type,
				$local_id
			)
		);
	}

	/**
	 * Counts a job's snapshots.
	 *
	 * @param string $job Job UUID.
	 *
	 * @return int
	 */
	public static function count( string $job ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE job_uuid = %s', Database::table( 'snapshots' ), $job )
		);
	}

	/**
	 * Undoes a job: restores what it overwrote, removes what it created and
	 * untrashes what it trashed, newest change first.
	 *
	 * @param string $job Job UUID.
	 *
	 * @return array{restored: int, removed: int, untrashed: int, errors: string[]}
	 */
	public static function undo( string $job ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT * FROM %i WHERE job_uuid = %s ORDER BY id DESC', Database::table( 'snapshots' ), $job ),
			ARRAY_A
		);

		$summary = array(
			'restored'  => 0,
			'removed'   => 0,
			'untrashed' => 0,
			'errors'    => array(),
		);

		$kses = Importer::kses_off();
		foreach ( (array) $rows as $row ) {
			$data = Util::decode( $row['data'] );
			$id   = (int) $row['local_id'];

			try {
				switch ( $row['action'] ) {
					case 'create':
						self::remove( (string) $row['object_type'], $id );
						if ( ! empty( $data['site'] ) ) {
							Mapping::delete( (string) $data['site'], 'post' === $row['object_type'] ? 'post' : 'term', (int) $data['remote_id'] );
						}
						++$summary['removed'];
						break;

					case 'update':
						self::restore( (string) $row['object_type'], $id, $data );
						if ( ! empty( $data['site'] ) ) {
							Mapping::restore( (string) $data['site'], 'post' === $row['object_type'] ? 'post' : 'term', (int) $data['remote_id'], $data['mapping'] ?? null );
						}
						++$summary['restored'];
						break;

					case 'trash':
						wp_untrash_post( $id );
						++$summary['untrashed'];
						break;
				}
			} catch ( \Throwable $e ) {
				$summary['errors'][] = sprintf( '%s #%d: %s', $row['object_type'], $id, $e->getMessage() );
			}
		}
		if ( $kses ) {
			kses_init_filters();
		}

		$wpdb->delete( Database::table( 'snapshots' ), array( 'job_uuid' => $job ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

		return $summary;
	}

	/**
	 * Deletes snapshots older than the retention period.
	 *
	 * @param int $days Days to keep.
	 *
	 * @return void
	 */
	public static function prune( int $days ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				Database::table( 'snapshots' ),
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Stores a snapshot unless the job already has one for the object.
	 *
	 * @param string               $job      Job UUID.
	 * @param string               $type     Object type.
	 * @param int                  $local_id Local ID.
	 * @param string               $action   create, update or trash.
	 * @param array<string, mixed> $data     Data.
	 *
	 * @return void
	 */
	private static function add( string $job, string $type, int $local_id, string $action, array $data ): void {
		global $wpdb;

		if ( self::has( $job, $type, $local_id ) ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			Database::table( 'snapshots' ),
			array(
				'job_uuid'    => $job,
				'object_type' => $type,
				'local_id'    => $local_id,
				'action'      => $action,
				'data'        => Util::encode( $data ),
				'created_at'  => Util::now(),
			)
		);
	}

	/**
	 * Removes an object a job created.
	 *
	 * @param string $type     post, term or menu.
	 * @param int    $local_id Local ID.
	 *
	 * @return void
	 */
	private static function remove( string $type, int $local_id ): void {
		if ( 'post' === $type ) {
			$post = get_post( $local_id );
			if ( ! $post instanceof \WP_Post ) {
				return;
			}
			if ( 'attachment' === $post->post_type ) {
				wp_delete_attachment( $local_id, true );
			} else {
				wp_trash_post( $local_id );
			}
			Mapping::forget_local( 'post', $local_id );

			return;
		}

		if ( 'menu' === $type ) {
			wp_delete_nav_menu( $local_id );
			Mapping::forget_local( 'term', $local_id );

			return;
		}

		$term = get_term( $local_id );
		if ( $term instanceof \WP_Term ) {
			wp_delete_term( $local_id, $term->taxonomy );
		}
		Mapping::forget_local( 'term', $local_id );
	}

	/**
	 * Puts an object back the way a snapshot recorded it.
	 *
	 * @param string               $type     post or menu.
	 * @param int                  $local_id Local ID.
	 * @param array<string, mixed> $data     Snapshot data.
	 *
	 * @return void
	 */
	private static function restore( string $type, int $local_id, array $data ): void {
		$state = (array) ( $data['state'] ?? array() );
		if ( empty( $state ) ) {
			return;
		}

		if ( 'menu' === $type ) {
			Menus::restore( $state );

			return;
		}

		$current = State::post( $local_id, false );
		if ( null === $current ) {
			return;
		}

		Importer::write_post( $local_id, $state, $current, static fn(): bool => true, true );
	}
}
