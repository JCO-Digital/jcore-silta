<?php
/**
 * Writes raw meta values.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes meta rows as the raw strings a bundle carries.
 *
 * The meta API would serialize an already serialized string a second time
 * (update_post_meta() calls maybe_serialize()), and unserializing it first
 * would mean instantiating whatever objects it contains. Writing the rows
 * directly keeps the bytes exactly as the source stored them.
 */
final class Meta_Writer {

	/**
	 * Replaces the given keys of an object with new values. Keys missing from
	 * $values are deleted.
	 *
	 * @param string                  $type   post or term.
	 * @param int                     $id     Object ID.
	 * @param string[]                $keys   Keys to replace.
	 * @param array<string, string[]> $values New raw values per key.
	 *
	 * @return void
	 */
	public static function replace( string $type, int $id, array $keys, array $values ): void {
		global $wpdb;

		if ( empty( $keys ) ) {
			return;
		}

		$table  = 'term' === $type ? $wpdb->termmeta : $wpdb->postmeta;
		$column = 'term' === $type ? 'term_id' : 'post_id';

		foreach ( array_unique( $keys ) as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Raw write, cache cleared below.
			$wpdb->delete(
				$table,
				array(
					$column    => $id,
					'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Delete one object's rows by key.
				)
			);

			foreach ( (array) ( $values[ $key ] ?? array() ) as $value ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Raw write, cache cleared below.
					$table,
					array(
						$column      => $id,
						'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Insert.
						'meta_value' => (string) $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Insert.
					)
				);
			}
		}

		wp_cache_delete( $id, 'term' === $type ? 'term_meta' : 'post_meta' );
	}
}
