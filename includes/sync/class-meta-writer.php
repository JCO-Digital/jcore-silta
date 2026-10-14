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
	 * @param bool                    $handled Whether this is a dedicated section write.
	 *
	 * @return string[] Warnings for rejected keys; their existing rows are kept.
	 */
	public static function replace( string $type, int $id, array $keys, array $values, bool $handled = false ): array {
		global $wpdb;

		$warnings = array();
		if ( empty( $keys ) ) {
			return $warnings;
		}

		$table  = 'term' === $type ? $wpdb->termmeta : $wpdb->postmeta;
		$column = 'term' === $type ? 'term_id' : 'post_id';

		foreach ( array_unique( $keys ) as $key ) {
			if ( ! is_string( $key ) || ( ! $handled && ! Meta_Filter::synced( $key ) ) ) {
				continue;
			}
			$rows = (array) ( $values[ $key ] ?? array() );
			foreach ( $rows as $value ) {
				if ( ! is_string( $value ) || ! self::valid_value( $value ) ) {
					$warnings[] = sprintf(
						/* translators: %s: meta key. */
						__( 'Meta key "%s" was skipped: its value is invalid or contains unsafe serialized data.', 'jcore-silta' ),
						$key
					);
					continue 2;
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Raw write, cache cleared below.
			$wpdb->delete(
				$table,
				array(
					$column    => $id,
					'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Delete one object's rows by key.
				)
			);

			foreach ( $rows as $value ) {
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

		return $warnings;
	}

	/**
	 * Checks raw data without instantiating serialized classes.
	 *
	 * @param string $value Raw meta value.
	 *
	 * @return bool
	 */
	public static function valid_value( string $value ): bool {
		try {
			( new Replacer() )->string( trim( $value ) );
		} catch ( \UnexpectedValueException $e ) {
			return false;
		}

		return true;
	}
}
