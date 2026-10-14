<?php
/**
 * WordPress helpers for incoming validation tests.
 *
 * @package Jcore\Silta
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter -- Guarded stand-ins for core signatures.

if ( ! function_exists( '__' ) ) {
	/**
	 * Returns the untranslated test string.
	 *
	 * @param string $text   Message.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Returns test options.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $fallback Default value.
	 * @return mixed
	 */
	function get_option( string $name, mixed $fallback = false ): mixed {
		return $GLOBALS['jcore_silta_test_options'][ $name ] ?? $fallback;
	}
}
if ( ! function_exists( 'post_type_exists' ) ) {
	/**
	 * Checks test post types.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	function post_type_exists( string $type ): bool {
		return in_array( $type, array( 'post', 'page', 'attachment', 'nav_menu_item', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'custom_css', 'user_request', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' ), true );
	}
}
if ( ! function_exists( 'taxonomy_exists' ) ) {
	/**
	 * Checks test taxonomies.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	function taxonomy_exists( string $taxonomy ): bool {
		return in_array( $taxonomy, array( 'category', 'post_tag', 'nav_menu', 'post_format', 'language', 'term_language', 'post_translations', 'term_translations', 'wp_theme' ), true );
	}
}
if ( ! function_exists( 'get_post_stati' ) ) {
	/**
	 * Returns noninternal statuses.
	 *
	 * @param array $args Query arguments.
	 * @return string[]
	 */
	function get_post_stati( array $args = array() ): array {
		return array( 'publish', 'draft', 'pending', 'private', 'future' );
	}
}
if ( ! function_exists( 'is_object_in_taxonomy' ) ) {
	/**
	 * Tests whether a taxonomy belongs to a type.
	 *
	 * @param string $type     Post type.
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	function is_object_in_taxonomy( string $type, string $taxonomy ): bool {
		return 'post' === $type && in_array( $taxonomy, array( 'category', 'post_tag' ), true );
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * No cache is used by the test database.
	 *
	 * @param int    $id    Object ID.
	 * @param string $group Cache group.
	 * @return bool
	 */
	function wp_cache_delete( int $id, string $group ): bool {
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Checks the acting test user's capability.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	function current_user_can( string $capability ): bool {
		return ! empty( $GLOBALS['jcore_silta_test_unfiltered'] );
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	/**
	 * Returns whether test KSES is installed.
	 *
	 * @param string $hook     Hook.
	 * @param string $callback Callback.
	 * @return int|false
	 */
	function has_filter( string $hook, string $callback ): int|false {
		return ! empty( $GLOBALS['jcore_silta_test_kses'] ) ? 10 : false;
	}
}
if ( ! function_exists( 'kses_remove_filters' ) ) {
	/**
	 * Removes test KSES filters.
	 *
	 * @return void
	 */
	function kses_remove_filters(): void {
		$GLOBALS['jcore_silta_test_kses'] = false;
	}
}
if ( ! function_exists( 'kses_init_filters' ) ) {
	/**
	 * Installs test KSES filters.
	 *
	 * @return void
	 */
	function kses_init_filters(): void {
		$GLOBALS['jcore_silta_test_kses'] = true;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Returns the test site's URL.
	 *
	 * @return string
	 */
	function home_url(): string {
		return 'https://local.example';
	}
}
if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	/**
	 * Returns the test uploads URL.
	 *
	 * @return array<string, string>
	 */
	function wp_get_upload_dir(): array {
		return array( 'baseurl' => 'https://local.example/uploads' );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Sanitizes a test key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Strips tags for test strings.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Minimal stand-in without WordPress.
	}
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	/**
	 * Sanitizes a test HTML class.
	 *
	 * @param string $value Class.
	 * @return string
	 */
	function sanitize_html_class( string $value ): string {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', $value );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Rejects script URLs in tests.
	 *
	 * @param string $value URL.
	 * @return string
	 */
	function esc_url_raw( string $value ): string {
		return str_starts_with( strtolower( $value ), 'javascript:' ) ? '' : $value;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Escapes a translated test error.
	 *
	 * @param string $text   Message.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}
