<?php
/**
 * Stand-ins for the Product Bundles integration tests: product titles and
 * plural strings.
 *
 * @package Jcore\Silta
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter -- Guarded stand-ins for core signatures.

if ( ! function_exists( 'get_the_title' ) ) {
	/**
	 * Returns a test post's title.
	 *
	 * @param int $id Post ID.
	 * @return string
	 */
	function get_the_title( int $id ): string {
		return (string) ( $GLOBALS['jcore_silta_test_titles'][ $id ] ?? '' );
	}
}
if ( ! function_exists( '_n' ) ) {
	/**
	 * Picks the singular or plural test string.
	 *
	 * @param string $single Singular.
	 * @param string $plural Plural.
	 * @param int    $count  Count.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function _n( string $single, string $plural, int $count, string $domain = 'default' ): string {
		return 1 === $count ? $single : $plural;
	}
}
