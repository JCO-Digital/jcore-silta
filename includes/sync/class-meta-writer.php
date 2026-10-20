<?php
/**
 * Writes raw meta values.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

use Jcore\Silta\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes meta rows as the raw strings a bundle carries.
 *
 * The meta API would serialize an already serialized string a second time
 * (update_post_meta() calls maybe_serialize()), and unserializing it first
 * would mean instantiating whatever objects it contains. Writing the rows
 * directly keeps the bytes exactly as the source stored them, except for HTML
 * the acting user may not post (see kses()).
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
	 * @param bool                    $restore Whether this restores a snapshot of this site's own values.
	 *
	 * @return string[] Warnings for rejected keys; their existing rows are kept.
	 */
	public static function replace( string $type, int $id, array $keys, array $values, bool $handled = false, bool $restore = false ): array {
		global $wpdb;

		$warnings = array();
		if ( empty( $keys ) ) {
			return $warnings;
		}

		// Values from the other site go through kses like post content, unless
		// the acting user may post unfiltered HTML. A snapshot holds what this
		// site had before, and goes back exactly as it was.
		$kses = ! $restore && ! Capabilities::unfiltered_html();

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
				if ( $kses ) {
					$value = self::kses( $value );
				}
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
	 * Filters the HTML in a raw meta value with kses: the value itself, every
	 * string inside serialized data and every string inside JSON.
	 *
	 * Only strings that contain a "<" are filtered. Without one there is no
	 * tag for kses to remove, and it would only re-encode the text ("a & b"
	 * becomes "a &amp; b"), which would break values that are not HTML. A
	 * value with nothing to filter keeps its exact bytes.
	 *
	 * @param string $value Raw meta value, already checked with valid_value().
	 *
	 * @return string
	 * @throws \UnexpectedValueException When serialized data is unsafe.
	 */
	public static function kses( string $value ): string {
		$changed = false;
		$result  = Replacer::mapping(
			static function ( string $text ) use ( &$changed ): string {
				$filtered = self::kses_text( $text );
				$changed  = $changed || $filtered !== $text;

				return $filtered;
			}
		)->string( $value );

		return $changed ? $result : $value;
	}

	/**
	 * Filters one string: a JSON array or object string by string, anything
	 * else as HTML when it contains a "<".
	 *
	 * @param string $text  Text.
	 * @param int    $depth Depth across nested JSON strings.
	 *
	 * @return string
	 */
	private static function kses_text( string $text, int $depth = 0 ): string {
		$start = substr( ltrim( $text ), 0, 1 );
		if ( $depth < 8 && ( '{' === $start || '[' === $start ) ) {
			// Objects stay stdClass so "{}" doesn't come back as "[]".
			$decoded = json_decode( $text );
			if ( is_array( $decoded ) || is_object( $decoded ) ) {
				$changed = false;
				$decoded = self::kses_json( $decoded, $depth, $changed );
				if ( ! $changed ) {
					return $text;
				}
				$encoded = wp_json_encode( $decoded );
				if ( false !== $encoded ) {
					return $encoded;
				}
			}
		}

		return str_contains( $text, '<' ) ? wp_kses_post( $text ) : $text;
	}

	/**
	 * Filters the strings of decoded JSON. Keys are left as they are.
	 *
	 * @param mixed $value   Decoded JSON.
	 * @param int   $depth   Depth across nested JSON strings.
	 * @param bool  $changed Set when a string changed.
	 *
	 * @return mixed
	 */
	private static function kses_json( mixed $value, int $depth, bool &$changed ): mixed {
		if ( is_string( $value ) ) {
			$filtered = self::kses_text( $value, $depth + 1 );
			$changed  = $changed || $filtered !== $value;

			return $filtered;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::kses_json( $item, $depth, $changed );
			}
		} elseif ( $value instanceof \stdClass ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->$key = self::kses_json( $item, $depth, $changed );
			}
		}

		return $value;
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
