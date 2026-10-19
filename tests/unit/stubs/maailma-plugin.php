<?php
/**
 * Stand-in for jcore-maailma's slug callback, counting its calls.
 *
 * @package Jcore\Silta
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter -- Stand-in for another plugin's function.

namespace Jcore\Maailma;

/**
 * Counts the calls Maailma's update_slug() would get.
 *
 * @param int    $post_id Post ID.
 * @param object $post    Post.
 * @param bool   $update  Whether the post existed.
 *
 * @return void
 */
function update_slug( $post_id = 0, $post = null, $update = false ): void {
	$GLOBALS['jcore_silta_test_slug_syncs'] = ( $GLOBALS['jcore_silta_test_slug_syncs'] ?? 0 ) + 1;
}
