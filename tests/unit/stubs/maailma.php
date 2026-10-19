<?php
/**
 * WordPress stand-ins for the jcore-maailma integration tests: posts by ID and
 * slug from `$GLOBALS['jcore_silta_test_posts']`, and a small hook registry.
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
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Registers a callback.
	 *
	 * @param string $hook     Hook.
	 * @param mixed  $callback Callback.
	 * @param int    $priority Priority.
	 * @param int    $args     Accepted arguments.
	 * @return true
	 */
	function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): bool {
		$GLOBALS['jcore_silta_test_actions'][ $hook ][ $priority ][] = $callback;

		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Registers a filter callback.
	 *
	 * @param string $hook     Hook.
	 * @param mixed  $callback Callback.
	 * @param int    $priority Priority.
	 * @param int    $args     Accepted arguments.
	 * @return true
	 */
	function add_filter( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): bool {
		return add_action( $hook, $callback, $priority, $args );
	}
}
if ( ! function_exists( 'has_action' ) ) {
	/**
	 * Returns a callback's priority.
	 *
	 * @param string $hook     Hook.
	 * @param mixed  $callback Callback.
	 * @return int|false
	 */
	function has_action( string $hook, mixed $callback ): int|false {
		foreach ( $GLOBALS['jcore_silta_test_actions'][ $hook ] ?? array() as $priority => $callbacks ) {
			if ( in_array( $callback, $callbacks, true ) ) {
				return $priority;
			}
		}

		return false;
	}
}
if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * Removes a callback.
	 *
	 * @param string $hook     Hook.
	 * @param mixed  $callback Callback.
	 * @param int    $priority Priority.
	 * @return bool
	 */
	function remove_action( string $hook, mixed $callback, int $priority = 10 ): bool {
		$callbacks = $GLOBALS['jcore_silta_test_actions'][ $hook ][ $priority ] ?? array();
		$index     = array_search( $callback, $callbacks, true );
		if ( false === $index ) {
			return false;
		}
		unset( $GLOBALS['jcore_silta_test_actions'][ $hook ][ $priority ][ $index ] );

		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Runs a hook's callbacks by priority, re-reading the registry after each
	 * priority like WP_Hook does when callbacks change during a run.
	 *
	 * @param string $hook    Hook.
	 * @param mixed  ...$args Arguments.
	 * @return void
	 */
	function do_action( string $hook, mixed ...$args ): void {
		$done = -PHP_INT_MAX;
		while ( true ) {
			$next = null;
			foreach ( array_keys( $GLOBALS['jcore_silta_test_actions'][ $hook ] ?? array() ) as $priority ) {
				if ( $priority > $done && ( null === $next || $priority < $next ) && ! empty( $GLOBALS['jcore_silta_test_actions'][ $hook ][ $priority ] ) ) {
					$next = $priority;
				}
			}
			if ( null === $next ) {
				return;
			}
			foreach ( $GLOBALS['jcore_silta_test_actions'][ $hook ][ $next ] as $callback ) {
				$callback( ...$args );
			}
			$done = $next;
		}
	}
}
