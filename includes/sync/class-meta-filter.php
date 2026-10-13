<?php
/**
 * Which meta keys are synced.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

use Jcore\Silta\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether a meta key travels as plain meta.
 */
final class Meta_Filter {

	/**
	 * Keys that are local bookkeeping and never synced. A trailing * matches a prefix.
	 */
	private const EXCLUDED = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_encloseme',
		'_pingme',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_trash_meta_comments_status',
		'_wp_desired_post_slug',
		'_wp_suggested_privacy_policy_content',
		'_yoast_indexnow_last_ping',
		'_jcore_silta_*',
	);

	/**
	 * Keys that have their own section and so never travel as plain meta.
	 */
	private const HANDLED = array(
		'_thumbnail_id',
		'_wp_page_template',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
		'_menu_item_*',
	);

	/**
	 * Compiled patterns for this request.
	 *
	 * @var array{exact: array<string, true>, prefixes: string[]}|null
	 */
	private static ?array $patterns = null;

	/**
	 * Whether a post or term meta key is synced as plain meta.
	 *
	 * @param string $key Meta key.
	 *
	 * @return bool
	 */
	public static function synced( string $key ): bool {
		$patterns = self::patterns();

		if ( isset( $patterns['exact'][ $key ] ) ) {
			return false;
		}

		foreach ( $patterns['prefixes'] as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Forgets the compiled patterns, e.g. after the settings change.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$patterns = null;
	}

	/**
	 * Compiles the patterns from the built-in lists, the settings and the filter.
	 *
	 * @return array{exact: array<string, true>, prefixes: string[]}
	 */
	private static function patterns(): array {
		if ( null !== self::$patterns ) {
			return self::$patterns;
		}

		$configured = preg_split( '/\R/', (string) Settings::get( 'excluded_meta' ) );
		$excluded   = array_merge( self::EXCLUDED, array_filter( array_map( 'trim', (array) $configured ) ) );

		/**
		 * Filters the meta keys Silta never syncs. A trailing * matches a prefix.
		 *
		 * @param string[] $excluded Meta keys and prefixes.
		 */
		$excluded = (array) apply_filters( 'jcore_silta_excluded_meta', $excluded );
		$excluded = array_merge( $excluded, self::HANDLED );

		$patterns = array(
			'exact'    => array(),
			'prefixes' => array(),
		);
		foreach ( $excluded as $pattern ) {
			$pattern = (string) $pattern;
			if ( str_ends_with( $pattern, '*' ) ) {
				$patterns['prefixes'][] = substr( $pattern, 0, -1 );
			} elseif ( '' !== $pattern ) {
				$patterns['exact'][ $pattern ] = true;
			}
		}

		self::$patterns = $patterns;

		return $patterns;
	}
}
