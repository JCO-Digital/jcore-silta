<?php
/**
 * WordPress helpers the differ's displays call, for tests that compare states.
 *
 * @package Jcore\Silta
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter -- Guarded stand-ins for core signatures.

if ( ! function_exists( 'get_post_status_object' ) ) {
	/**
	 * Returns no status object.
	 *
	 * @param string $status Status.
	 * @return object|null
	 */
	function get_post_status_object( string $status ): ?object {
		return null;
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * Returns no user.
	 *
	 * @param int $id User ID.
	 * @return object|false
	 */
	function get_userdata( int $id ): object|false {
		return false;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Returns no post.
	 *
	 * @param int $id Post ID.
	 * @return object|null
	 */
	function get_post( int $id ): ?object {
		return null;
	}
}
if ( ! function_exists( 'get_taxonomy' ) ) {
	/**
	 * Returns no taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return object|false
	 */
	function get_taxonomy( string $taxonomy ): object|false {
		return false;
	}
}
if ( ! function_exists( 'get_term' ) ) {
	/**
	 * Returns no term.
	 *
	 * @param int $id Term ID.
	 * @return mixed
	 */
	function get_term( int $id ): mixed {
		return null;
	}
}
