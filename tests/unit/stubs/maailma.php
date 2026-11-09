<?php
/**
 * WordPress stand-ins for the jcore-maailma integration tests: posts by ID and
 * slug from `$GLOBALS['jcore_silta_test_posts']`. The hook registry is in bootstrap.php.
 *
 * @package Jcore\Silta
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.Files.SeparateFunctionsFromOO.Mixed -- Guarded stand-ins for core signatures.

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Lowercases a title into a slug.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	function sanitize_title( string $title ): string {
		return trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( $title ) ), '-' );
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	/**
	 * Returns a fixture post's type.
	 *
	 * @param int $id Post ID.
	 * @return string|false
	 */
	function get_post_type( int $id ): string|false {
		return $GLOBALS['jcore_silta_test_posts'][ $id ]['post_type'] ?? false;
	}
}
if ( ! function_exists( 'get_post_field' ) ) {
	/**
	 * Returns a fixture post's field.
	 *
	 * @param string $field Field.
	 * @param int    $id    Post ID.
	 * @return string
	 */
	function get_post_field( string $field, int $id ): string {
		return (string) ( $GLOBALS['jcore_silta_test_posts'][ $id ][ $field ] ?? '' );
	}
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	/**
	 * Finds a fixture post by slug and type.
	 *
	 * @param string $path      Slug.
	 * @param string $output    Output type.
	 * @param string $post_type Post type.
	 * @return object|null
	 */
	function get_page_by_path( string $path, string $output = OBJECT, string $post_type = 'page' ): ?object {
		foreach ( $GLOBALS['jcore_silta_test_posts'] ?? array() as $id => $post ) {
			if ( $post['post_type'] === $post_type && $post['post_name'] === $path ) {
				return (object) array( 'ID' => (int) $id );
			}
		}

		return null;
	}
}
