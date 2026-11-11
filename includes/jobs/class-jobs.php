<?php
/**
 * Stored sync jobs and their items.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Jobs;

use Jcore\Silta\Capabilities;
use Jcore\Silta\Database;
use Jcore\Silta\Util;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the jobs and job items tables.
 *
 * A job has role `initiator` on the site it was started from and `target` on
 * the site that received a push; a pull is both on one site, so it has a single
 * initiator row.
 */
final class Jobs {

	/**
	 * Item columns that are JSON.
	 */
	private const ITEM_JSON = array( 'sections', 'override', 'plan', 'payload', 'result' );

	/**
	 * Seconds a request keeps a job to itself after its last sign of life.
	 * Longer than the slowest single step (a remote call that runs into its
	 * timeout), so a working request is never overtaken; a request that died
	 * without letting go blocks the job for this long at most.
	 */
	public const LEASE = 240;

	/**
	 * Creates a job.
	 *
	 * @param array<string, mixed> $data Columns; options and state may be arrays.
	 *
	 * @return array<string, mixed>
	 */
	public static function create( array $data ): array {
		global $wpdb;

		$row = array(
			'uuid'         => $data['uuid'] ?? wp_generate_uuid4(),
			'role'         => (string) $data['role'],
			'direction'    => (string) $data['direction'],
			'connection'   => (string) ( $data['connection'] ?? '' ),
			'remote_site'  => (string) ( $data['remote_site'] ?? '' ),
			'remote_label' => (string) ( $data['remote_label'] ?? '' ),
			'user_id'      => (int) ( $data['user_id'] ?? get_current_user_id() ),
			'status'       => (string) ( $data['status'] ?? 'draft' ),
			'options'      => Util::encode( $data['options'] ?? array() ),
			'state'        => Util::encode( $data['state'] ?? array() ),
			'summary'      => Util::encode( $data['summary'] ?? array() ),
			'created_at'   => Util::now(),
			'updated_at'   => Util::now(),
		);

		$wpdb->insert( Database::table( 'jobs' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.

		return (array) self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Returns a job by ID or UUID.
	 *
	 * @param int|string $id ID or UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int|string $id ): ?array {
		global $wpdb;

		$column = is_string( $id ) && wp_is_uuid( $id ) ? 'uuid' : 'id';
		$row    = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s', Database::table( 'jobs' ), $column, (string) $id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Updates a job.
	 *
	 * @param int                  $id      Job ID.
	 * @param array<string, mixed> $changes Columns; options, state and summary may be arrays.
	 *
	 * @return void
	 */
	public static function update( int $id, array $changes ): void {
		global $wpdb;

		foreach ( array( 'options', 'state', 'summary' ) as $json ) {
			if ( isset( $changes[ $json ] ) && is_array( $changes[ $json ] ) ) {
				$changes[ $json ] = Util::encode( $changes[ $json ] );
			}
		}
		$changes['updated_at'] = Util::now();

		$wpdb->update( Database::table( 'jobs' ), $changes, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Takes a job for one request, so two requests never work on it at once:
	 * a retry sent while the first request is still running, or a second tab,
	 * would import the same objects twice. The claim is one UPDATE, so only
	 * one of several concurrent requests gets it.
	 *
	 * @param int    $id    Job ID.
	 * @param string $token The claiming request's token.
	 *
	 * @return bool Whether this request now holds the job.
	 */
	public static function claim( int $id, string $token ): bool {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim on the custom table.
			$wpdb->prepare(
				'UPDATE %i SET lock_token = %s, locked_until = %d WHERE id = %d AND ( locked_until < %d OR lock_token = %s )',
				Database::table( 'jobs' ),
				$token,
				time() + self::LEASE,
				$id,
				time(),
				$token
			)
		);

		// Not the affected rows: they are zero when the same values are written again.
		$holder = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be current.
			$wpdb->prepare( 'SELECT lock_token FROM %i WHERE id = %d', Database::table( 'jobs' ), $id )
		);

		// No answer means the table hasn't been upgraded yet (the columns are
		// new in 1.4): carry on unguarded rather than block every sync.
		return $token === $holder || ( null === $holder && '' !== $wpdb->last_error );
	}

	/**
	 * Keeps a claimed job for another lease. Called after every step.
	 *
	 * @param int    $id    Job ID.
	 * @param string $token The holding request's token.
	 *
	 * @return void
	 */
	public static function renew( int $id, string $token ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'UPDATE %i SET locked_until = %d WHERE id = %d AND lock_token = %s', Database::table( 'jobs' ), time() + self::LEASE, $id, $token )
		);
	}

	/**
	 * Lets go of a claimed job.
	 *
	 * @param int    $id    Job ID.
	 * @param string $token The holding request's token.
	 *
	 * @return void
	 */
	public static function release( int $id, string $token ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( "UPDATE %i SET lock_token = '', locked_until = 0 WHERE id = %d AND lock_token = %s", Database::table( 'jobs' ), $id, $token )
		);
	}

	/**
	 * The error a request gets when another one holds the job.
	 *
	 * @return WP_Error
	 */
	public static function busy(): WP_Error {
		return new WP_Error( 'jcore_silta_busy', __( 'Another request is still working on this sync. It carries on as soon as that one is done.', 'jcore-silta' ), array( 'status' => 409 ) );
	}

	/**
	 * Returns a job's current status, straight from the database.
	 *
	 * @param int $id Job ID.
	 *
	 * @return string
	 */
	public static function status( int $id ): string {
		global $wpdb;

		return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be current.
			$wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', Database::table( 'jobs' ), $id )
		);
	}

	/**
	 * Lists jobs, newest first.
	 *
	 * @param int      $page     Page.
	 * @param int      $per_page Jobs per page.
	 * @param int|null $user_id  Creator; null lists all jobs.
	 *
	 * @return array{jobs: array<int, array<string, mixed>>, total: int}
	 */
	public static function list( int $page = 1, int $per_page = 20, ?int $user_id = null ): array {
		global $wpdb;

		$where = null === $user_id ? '' : $wpdb->prepare( " WHERE role = 'initiator' AND user_id = %d", $user_id );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				"SELECT * FROM %i{$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE is prepared above.
				Database::table( 'jobs' ),
				$per_page,
				( max( 1, $page ) - 1 ) * $per_page
			),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i{$where}", Database::table( 'jobs' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; WHERE is prepared above.

		return array(
			'jobs'  => array_map( array( self::class, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Makes sure the receiving side has a peer-owned target job for history and
	 * undo. Local pulls use their initiator row directly instead.
	 *
	 * @param string               $uuid Job UUID.
	 * @param string               $site Source site UUID.
	 * @param array<string, mixed> $meta direction, label, user.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function ensure_target( string $uuid, string $site, array $meta ): array|WP_Error {
		if ( ! wp_is_uuid( $uuid ) ) {
			return new WP_Error( 'jcore_silta_no_job', __( 'The import has no valid job ID.', 'jcore-silta' ), array( 'status' => 400 ) );
		}
		$job = self::get( $uuid );
		if ( null !== $job ) {
			return self::target_permission( $job, $site ) ? $job : new WP_Error( 'jcore_silta_not_yours', __( 'That sync does not belong to this site.', 'jcore-silta' ), array( 'status' => 403 ) );
		}

		self::create(
			array(
				'uuid'         => $uuid,
				'role'         => 'target',
				'direction'    => (string) ( $meta['direction'] ?? 'push' ),
				'remote_site'  => $site,
				'remote_label' => sanitize_text_field( (string) ( $meta['label'] ?? '' ) ),
				'status'       => 'received',
				'options'      => array( 'by' => sanitize_text_field( (string) ( $meta['user'] ?? '' ) ) ),
			)
		);

		$job = self::get( $uuid );

		return null !== $job && self::target_permission( $job, $site ) ? $job : new WP_Error( 'jcore_silta_not_yours', __( 'That sync does not belong to this site.', 'jcore-silta' ), array( 'status' => 403 ) );
	}

	/**
	 * Adds results to the receiving side's summary of a job.
	 *
	 * @param string                              $uuid    Job UUID.
	 * @param array<string, array<string, mixed>> $results Import results.
	 *
	 * @param string                              $site Calling peer UUID.
	 *
	 * @return true|WP_Error
	 */
	public static function record_results( string $uuid, array $results, string $site ): bool|WP_Error {
		$job = self::get( $uuid );
		if ( null === $job || ! self::target_permission( $job, $site ) ) {
			return new WP_Error( 'jcore_silta_not_yours', __( 'That sync does not belong to this site.', 'jcore-silta' ), array( 'status' => 403 ) );
		}

		$summary = $job['summary'];
		foreach ( $results as $result ) {
			$bucket             = (string) ( $result['action'] ?? $result['status'] ?? 'failed' );
			$summary[ $bucket ] = (int) ( $summary[ $bucket ] ?? 0 ) + 1;
		}

		self::update( (int) $job['id'], array( 'summary' => $summary ) );

		return true;
	}

	/**
	 * Whether a peer owns a receiving job.
	 *
	 * @param array<string, mixed> $job  Job.
	 * @param string               $site Calling peer UUID.
	 *
	 * @return bool
	 */
	public static function target_permission( array $job, string $site ): bool {
		return 'target' === $job['role'] && strtolower( $site ) === strtolower( (string) $job['remote_site'] );
	}

	/**
	 * Whether the current syncing user may see or operate a job.
	 *
	 * @param array<string, mixed> $job Job.
	 *
	 * @return bool
	 */
	public static function user_permission( array $job ): bool {
		return Capabilities::unrestricted() || ( 'initiator' === $job['role'] && get_current_user_id() > 0 && get_current_user_id() === (int) $job['user_id'] );
	}

	/**
	 * Adds items to a job, skipping ones it already has.
	 *
	 * @param int                              $job_id Job ID.
	 * @param array<int, array<string, mixed>> $items  Items: object_type, source_id, subtype, title, level, position, included, status, action, target_id,
	 *                                                 owner (the item a companion or companion orphan belongs to).
	 *
	 * @return int Items added.
	 */
	public static function add_items( int $job_id, array $items ): int {
		global $wpdb;

		$added = 0;

		// Multi-row INSERT IGNORE: the unique key on (job_id, object_type,
		// source_id) skips objects the job already has, and a whole post type
		// can mean thousands of rows.
		foreach ( array_chunk( $items, 200 ) as $chunk ) {
			$rows = array();
			foreach ( $chunk as $item ) {
				$rows[] = $wpdb->prepare(
					'(%d, %s, %d, %s, %s, %d, %d, %d, %s, %s, %d, %d)',
					$job_id,
					(string) $item['object_type'],
					(int) $item['source_id'],
					(string) ( $item['subtype'] ?? '' ),
					(string) ( $item['title'] ?? '' ),
					(int) ( $item['level'] ?? 0 ),
					(int) ( $item['position'] ?? 0 ),
					(int) ( $item['included'] ?? 1 ),
					(string) ( $item['status'] ?? 'pending' ),
					(string) ( $item['action'] ?? '' ),
					(int) ( $item['target_id'] ?? 0 ),
					(int) ( $item['owner'] ?? 0 )
				);
			}

			$added += (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; every row is prepared above.
				$wpdb->prepare( 'INSERT IGNORE INTO %i (job_id, object_type, source_id, subtype, title, level, position, included, status, action, target_id, owner) VALUES ', Database::table( 'items' ) ) . implode( ', ', $rows )
			);
		}

		return $added;
	}


	/**
	 * Returns a job's items.
	 *
	 * @param int                  $job_id Job ID.
	 * @param array<string, mixed> $where  Column filters (status, level, included, object_type, subtype); arrays mean IN.
	 * @param array<string, mixed> $args   limit, offset, order (`id` or `import`), payload (include the payload column),
	 *                                     light (leave out the plan too: for passes over a whole job that only
	 *                                     need what happened to each item), after (only items with a higher ID).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function items( int $job_id, array $where = array(), array $args = array() ): array {
		global $wpdb;

		list( $sql, $params ) = self::where( $job_id, $where );
		if ( isset( $args['after'] ) ) {
			$sql     .= ' AND id > %d';
			$params[] = (int) $args['after'];
		}
		$columns = empty( $args['payload'] ) ? 'id, job_id, object_type, source_id, subtype, title, level, position, included, status, action, target_id, owner, conflict, confirmed, sections, override, plan, result' : '*';
		if ( ! empty( $args['light'] ) ) {
			$columns = 'id, job_id, object_type, source_id, subtype, title, level, position, included, status, action, target_id, owner, conflict, confirmed, sections, result';
		}
		$order = match ( $args['order'] ?? '' ) {
			'import' => 'position ASC, id ASC',
			'id' => 'id ASC',
			default => 'level ASC, id ASC',
		};
		$limit = isset( $args['limit'] ) ? $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['limit'], (int) ( $args['offset'] ?? 0 ) ) : '';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; columns and order are constants, the WHERE clause is built from placeholders.
			$wpdb->prepare( "SELECT {$columns} FROM %i WHERE {$sql} ORDER BY {$order}", array_merge( array( Database::table( 'items' ) ), $params ) ) . $limit, // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Columns and order are constants.
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate_item' ), (array) $rows );
	}

	/**
	 * Counts a job's items.
	 *
	 * @param int                  $job_id Job ID.
	 * @param array<string, mixed> $where  Column filters.
	 *
	 * @return int
	 */
	public static function count_items( int $job_id, array $where = array() ): int {
		global $wpdb;

		list( $sql, $params ) = self::where( $job_id, $where );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; the WHERE clause is built from placeholders.
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$sql}", array_merge( array( Database::table( 'items' ) ), $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Built from placeholders.
		);
	}

	/**
	 * Counts a job's items per status.
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return array<string, int>
	 */
	public static function status_counts( int $job_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i WHERE job_id = %d AND included = 1 GROUP BY status', Database::table( 'items' ), $job_id ),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Returns one item, with its payload.
	 *
	 * @param int $job_id  Job ID.
	 * @param int $item_id Item ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function item( int $job_id, int $item_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %d AND id = %d', Database::table( 'items' ), $job_id, $item_id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate_item( $row ) : null;
	}

	/**
	 * Updates an item.
	 *
	 * @param int                  $item_id Item ID.
	 * @param array<string, mixed> $changes Columns; JSON columns may be arrays.
	 *
	 * @return void
	 */
	public static function update_item( int $item_id, array $changes ): void {
		global $wpdb;

		foreach ( self::ITEM_JSON as $json ) {
			if ( array_key_exists( $json, $changes ) && ! is_string( $changes[ $json ] ) && null !== $changes[ $json ] ) {
				$changes[ $json ] = Util::encode( $changes[ $json ] );
			}
		}
		foreach ( array( 'included', 'conflict', 'confirmed' ) as $flag ) {
			if ( isset( $changes[ $flag ] ) ) {
				$changes[ $flag ] = (int) (bool) $changes[ $flag ];
			}
		}

		$wpdb->update( Database::table( 'items' ), $changes, array( 'id' => $item_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Updates every item matching a filter.
	 *
	 * @param int                  $job_id  Job ID.
	 * @param array<string, mixed> $where   Column filters.
	 * @param array<string, mixed> $changes Columns.
	 *
	 * @return void
	 */
	public static function update_items( int $job_id, array $where, array $changes ): void {
		foreach ( self::items( $job_id, $where ) as $item ) {
			self::update_item( (int) $item['id'], $changes );
		}
	}

	/**
	 * Deletes every item matching a filter.
	 *
	 * @param int                  $job_id Job ID.
	 * @param array<string, mixed> $where  Column filters.
	 *
	 * @return void
	 */
	public static function delete_items( int $job_id, array $where ): void {
		global $wpdb;

		list( $sql, $params ) = self::where( $job_id, $where );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; the WHERE clause is built from placeholders.
			$wpdb->prepare( "DELETE FROM %i WHERE {$sql}", array_merge( array( Database::table( 'items' ) ), $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Built from placeholders.
		);
	}

	/**
	 * Gives the companions of items (a variable product's variations, and the
	 * variations it would trash) the same ticked state as the items: they are
	 * synced with their owner or not at all. Items that can't be synced stay out.
	 *
	 * @param int   $job_id   Job ID.
	 * @param int[] $owners   Owner item IDs.
	 * @param bool  $included Ticked state.
	 *
	 * @return void
	 */
	public static function include_companions( int $job_id, array $owners, bool $included ): void {
		$owners = array_values( array_filter( array_map( 'intval', $owners ) ) );
		if ( empty( $owners ) ) {
			return;
		}

		foreach ( self::items( $job_id, array( 'owner' => $owners ) ) as $item ) {
			if ( $included && ( 'error' === $item['action'] || 'failed' === $item['status'] ) ) {
				continue;
			}
			if ( (bool) $item['included'] !== $included ) {
				self::update_item( (int) $item['id'], array( 'included' => $included ) );
			}
		}
	}

	/**
	 * Returns the `type:id` keys of every object in a job.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Only objects whose item has this status, or '' for all.
	 *
	 * @return string[]
	 */
	public static function keys( int $job_id, string $status = '' ): array {
		global $wpdb;

		if ( '' === $status ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
				$wpdb->prepare( "SELECT object_type, source_id FROM %i WHERE job_id = %d AND object_type IN ('post', 'term', 'menu', 'form')", Database::table( 'items' ), $job_id ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
				$wpdb->prepare( "SELECT object_type, source_id FROM %i WHERE job_id = %d AND status = %s AND object_type IN ('post', 'term', 'menu', 'form')", Database::table( 'items' ), $job_id, $status ),
				ARRAY_A
			);
		}

		return array_map(
			static fn( array $row ): string => Util::key( (string) $row['object_type'], (int) $row['source_id'] ),
			(array) $rows
		);
	}

	/**
	 * Returns the targets chosen by hand for a job's objects, by object key:
	 * a local ID on the target, or `create`.
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return array<string, int|string>
	 */
	public static function overrides( int $job_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare( 'SELECT object_type, source_id, override FROM %i WHERE job_id = %d AND override IS NOT NULL', Database::table( 'items' ), $job_id ),
			ARRAY_A
		);

		$overrides = array();
		foreach ( (array) $rows as $row ) {
			$target = Util::decode( $row['override'] )['target'] ?? null;
			if ( null !== $target ) {
				$overrides[ Util::key( (string) $row['object_type'], (int) $row['source_id'] ) ] = $target;
			}
		}

		return $overrides;
	}

	/**
	 * Deletes a job and its items.
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return void
	 */
	public static function delete( int $job_id ): void {
		global $wpdb;

		$wpdb->delete( Database::table( 'items' ), array( 'job_id' => $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->delete( Database::table( 'jobs' ), array( 'id' => $job_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Deletes the items of finished jobs older than the given number of days.
	 * The job rows stay as history.
	 *
	 * @param int $days Days to keep items.
	 *
	 * @return void
	 */
	public static function prune( int $days ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				"DELETE i FROM %i i INNER JOIN %i j ON j.id = i.job_id WHERE j.updated_at < %s AND j.status IN ('done', 'undone', 'cancelled', 'failed')",
				Database::table( 'items' ),
				Database::table( 'jobs' ),
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Builds a WHERE clause for items.
	 *
	 * @param int                  $job_id Job ID.
	 * @param array<string, mixed> $where  Column filters.
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function where( int $job_id, array $where ): array {
		$sql    = 'job_id = %d';
		$params = array( $job_id );

		foreach ( $where as $column => $value ) {
			if ( ! in_array( $column, array( 'status', 'level', 'included', 'object_type', 'subtype', 'action', 'conflict', 'confirmed', 'id', 'owner' ), true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					$sql .= ' AND 1 = 0';
					continue;
				}
				$sql   .= " AND {$column} IN (" . implode( ', ', array_fill( 0, count( $value ), is_int( reset( $value ) ) ? '%d' : '%s' ) ) . ')';
				$params = array_merge( $params, array_values( $value ) );
			} else {
				$sql     .= " AND {$column} = " . ( is_int( $value ) ? '%d' : '%s' );
				$params[] = $value;
			}
		}

		return array( $sql, $params );
	}

	/**
	 * Decodes a job row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['id']      = (int) $row['id'];
		$row['user_id'] = (int) $row['user_id'];
		$row['options'] = Util::decode( $row['options'] ?? null );
		$row['state']   = Util::decode( $row['state'] ?? null );
		$row['summary'] = Util::decode( $row['summary'] ?? null );

		return $row;
	}

	/**
	 * Decodes an item row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	private static function hydrate_item( array $row ): array {
		foreach ( array( 'id', 'job_id', 'source_id', 'level', 'position', 'target_id', 'owner' ) as $int ) {
			$row[ $int ] = (int) ( $row[ $int ] ?? 0 );
		}
		foreach ( array( 'included', 'conflict', 'confirmed' ) as $flag ) {
			$row[ $flag ] = (bool) (int) $row[ $flag ];
		}
		foreach ( self::ITEM_JSON as $json ) {
			if ( array_key_exists( $json, $row ) ) {
				$row[ $json ] = null === $row[ $json ] ? null : Util::decode( $row[ $json ] );
			}
		}
		$row['key'] = Util::key( (string) $row['object_type'], (int) $row['source_id'] );

		return $row;
	}
}
