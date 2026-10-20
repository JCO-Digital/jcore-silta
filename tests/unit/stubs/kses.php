<?php
/**
 * Stand-in for kses, for the HTML filtering tests.
 *
 * @package Jcore\Silta
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Guarded stand-in for core.

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Stand-in for wp_kses_post(): drops script and style elements, tags other
	 * than a few inline ones and event handler attributes, and records the call.
	 *
	 * @param string $data Text.
	 * @return string
	 */
	function wp_kses_post( string $data ): string {
		$GLOBALS['jcore_silta_test_kses_calls'][] = $data;

		$data = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $data );
		$data = strip_tags( $data, '<p><a><strong><em><b>' );

		return (string) preg_replace( '/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|\S+)/i', '', $data );
	}
}
