<?php
/**
 * Which local object a remote object was synced to.
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
 * Reads and writes the map table.
 *
 * Object types are `post` (every post type, attachments and menu items
 * included) and `term` (menus included, as they are nav_menu terms).
 */
final class Mapping {

	/**
	 * Returns the row for a remote object.
	 *
	 * @param string $site      Remote site UUID.
	 * @param string $type      post or term.
	 * @param int    $remote_id Remote ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $site, string $type, int $remote_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'SELECT * FROM %i WHERE site = %s AND object_type = %s AND remote_id = %d',
				Database::table( 'map' ),
				$site,
				$type,
				$remote_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Returns every row pointing at a local object.
	 *
	 * @param string $type     post or term.
	 * @param int    $local_id Local ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_local( string $type, int $local_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'SELECT * FROM %i WHERE object_type = %s AND local_id = %d ORDER BY synced_at DESC',
				Database::table( 'map' ),
				$type,
				$local_id
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * Returns the remote ID a local object is mapped to from a given site.
	 *
	 * @param string $site     Remote site UUID.
	 * @param string $type     post or term.
	 * @param int    $local_id Local ID.
	 *
	 * @return int|null
	 */
	public static function remote_for( string $site, string $type, int $local_id ): ?int {
		global $wpdb;

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				'SELECT remote_id FROM %i WHERE site = %s AND object_type = %s AND local_id = %d LIMIT 1',
				Database::table( 'map' ),
				$site,
				$type,
				$local_id
			)
		);

		return null === $id ? null : (int) $id;
	}

	/**
	 * Creates or updates a row.
	 *
	 * @param string                    $site        Remote site UUID.
	 * @param string                    $type        post or term.
	 * @param int                       $remote_id   Remote ID.
	 * @param int                       $local_id    Local ID.
	 * @param string                    $remote_hash Hash of what was received.
	 * @param string                    $local_hash  Hash of the local object right after writing it.
	 * @param array<string, mixed>|null $extra       Extra data, e.g. renamed media files.
	 *
	 * @return void
	 */
	public static function set( string $site, string $type, int $remote_id, int $local_id, string $remote_hash = '', string $local_hash = '', ?array $extra = null ): void {
		global $wpdb;

		$existing = self::get( $site, $type, $remote_id );
		$data     = array(
			'site'        => $site,
			'object_type' => $type,
			'remote_id'   => $remote_id,
			'local_id'    => $local_id,
			'remote_hash' => $remote_hash,
			'local_hash'  => $local_hash,
			'extra'       => null === $extra ? ( null === $existing ? null : Util::encode( $existing['extra'] ) ) : Util::encode( $extra ),
			'synced_at'   => Util::now(),
		);

		if ( null === $existing ) {
			$wpdb->insert( Database::table( 'map' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.

			return;
		}

		$wpdb->update( Database::table( 'map' ), $data, array( 'id' => $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
	}

	/**
	 * Records a match found by the matcher, without hashes, unless the remote
	 * object is already mapped.
	 *
	 * @param string $site      Remote site UUID.
	 * @param string $type      post or term.
	 * @param int    $remote_id Remote ID.
	 * @param int    $local_id  Local ID.
	 *
	 * @return void
	 */
	public static function remember( string $site, string $type, int $remote_id, int $local_id ): void {
		if ( null === self::get( $site, $type, $remote_id ) ) {
			self::set( $site, $type, $remote_id, $local_id );
		}
	}

	/**
	 * Restores a row to an earlier state, or deletes it when there was none.
	 *
	 * @param string                    $site      Remote site UUID.
	 * @param string                    $type      post or term.
	 * @param int                       $remote_id Remote ID.
	 * @param array<string, mixed>|null $row       Earlier row, or null.
	 *
	 * @return void
	 */
	public static function restore( string $site, string $type, int $remote_id, ?array $row ): void {
		if ( null === $row ) {
			self::delete( $site, $type, $remote_id );

			return;
		}

		self::set( $site, $type, $remote_id, (int) $row['local_id'], (string) $row['remote_hash'], (string) $row['local_hash'], $row['extra'] ?? null );
	}

	/**
	 * Deletes a row.
	 *
	 * @param string $site      Remote site UUID.
	 * @param string $type      post or term.
	 * @param int    $remote_id Remote ID.
	 *
	 * @return void
	 */
	public static function delete( string $site, string $type, int $remote_id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			Database::table( 'map' ),
			array(
				'site'        => $site,
				'object_type' => $type,
				'remote_id'   => $remote_id,
			)
		);
	}

	/**
	 * Deletes every row pointing at a local object.
	 *
	 * @param string $type     post or term.
	 * @param int    $local_id Local ID.
	 *
	 * @return void
	 */
	public static function forget_local( string $type, int $local_id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			Database::table( 'map' ),
			array(
				'object_type' => $type,
				'local_id'    => $local_id,
			)
		);
	}

	/**
	 * Returns local posts of a post type that are mapped from a site, keyed by
	 * remote ID. Used to find orphans.
	 *
	 * @param string $site      Remote site UUID.
	 * @param string $post_type Post type.
	 *
	 * @return array<int, int> Remote ID => local ID.
	 */
	public static function posts_of_type( string $site, string $post_type ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->prepare(
				"SELECT m.remote_id, m.local_id FROM %i m INNER JOIN %i p ON p.ID = m.local_id WHERE m.site = %s AND m.object_type = 'post' AND p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')",
				Database::table( 'map' ),
				$wpdb->posts,
				$site,
				$post_type
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['remote_id'] ] = (int) $row['local_id'];
		}

		return $map;
	}

	/**
	 * Decodes a row's columns.
	 *
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['id']        = (int) $row['id'];
		$row['remote_id'] = (int) $row['remote_id'];
		$row['local_id']  = (int) $row['local_id'];
		$row['extra']     = Util::decode( $row['extra'] ?? null );

		return $row;
	}
}
