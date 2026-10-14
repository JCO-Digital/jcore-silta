<?php
/**
 * WordPress stand-ins used by remote authorization and validation tests.
 *
 * @package Jcore\Silta\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Core stand-ins, guarded to coexist with other test stubs.
// phpcs:disable Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO.Mixed -- Core stand-ins share the requested remote stub file.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Core signatures include parameters unused by these stand-ins.
// phpcs:disable WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress is absent here.

require_once __DIR__ . '/wp-error.php';

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/** Minimal JSON request. */
	class WP_REST_Request {
		/**
		 * Route.
		 *
		 * @var string
		 */
		private string $route;
		/**
		 * Raw body.
		 *
		 * @var string
		 */
		private string $body = '';

		/**
		 * Creates the test fixture.
		 *
		 * @param string $method Method.
		 * @param string $route  Route.
		 */
		public function __construct( string $method = 'POST', string $route = '' ) {
			$this->route = $route;
		}

		/**
		 * Provides set body for the test fixture.
		 *
		 * @param string $body Body.
		 *
		 * @return void
		 */
		public function set_body( string $body ): void {
			$this->body = $body;
		}

		/**
		 * Provides get body for the test fixture.
		 *
		 * @return string
		 */
		public function get_body(): string {
			return $this->body;
		}

		/**
		 * Provides get route for the test fixture.
		 *
		 * @return string
		 */
		public function get_route(): string {
			return $this->route;
		}

		/**
		 * Provides get json params for the test fixture.
		 *
		 * @return mixed
		 */
		public function get_json_params(): mixed {
			return json_decode( $this->body, true );
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/** Minimal taxonomy term. */
	class WP_Term {
		/**
		 * Taxonomy.
		 *
		 * @var string
		 */
		public string $taxonomy = 'category';
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'DAY_IN_SECONDS'    => 86400,
	'YEAR_IN_SECONDS'   => 31536000,
) as $jcore_silta_constant => $jcore_silta_value ) {
	if ( ! defined( $jcore_silta_constant ) ) {
		define( $jcore_silta_constant, $jcore_silta_value );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stand-in for __().
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 *
	 * @return string
	 */
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stand-in for wp_parse_url().
	 *
	 * @param string $url       Url.
	 * @param int    $component Component.
	 *
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Core stand-in.
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stand-in for esc_url_raw().
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	function esc_url_raw( string $url ): string {
		return $url;
	}
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	/**
	 * Stand-in for wp_get_environment_type().
	 *
	 * @return string
	 */
	function wp_get_environment_type(): string {
		return $GLOBALS['jcore_silta_remote_environment'] ?? 'production';
	}
}
if ( ! function_exists( 'wp_is_uuid' ) ) {
	/**
	 * Stand-in for wp_is_uuid().
	 *
	 * @param string $uuid Uuid.
	 *
	 * @return bool
	 */
	function wp_is_uuid( string $uuid ): bool {
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stand-in for sanitize_text_field().
	 *
	 * @param string $value Value.
	 *
	 * @return string
	 */
	function sanitize_text_field( string $value ): string {
		return strip_tags( $value );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stand-in for get_option().
	 *
	 * @param string $name     Name.
	 * @param mixed  $fallback Fallback.
	 *
	 * @return mixed
	 */
	function get_option( string $name, mixed $fallback = false ): mixed {
		return $GLOBALS['jcore_silta_remote_options'][ $name ] ?? $fallback;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stand-in for update_option().
	 *
	 * @param string $name     Name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Autoload.
	 *
	 * @return bool
	 */
	function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['jcore_silta_remote_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Stand-in for get_transient().
	 *
	 * @param string $name Name.
	 *
	 * @return mixed
	 */
	function get_transient( string $name ): mixed {
		return $GLOBALS['jcore_silta_remote_transients'][ $name ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stand-in for set_transient().
	 *
	 * @param string $name       Name.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Expiration.
	 *
	 * @return bool
	 */
	function set_transient( string $name, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['jcore_silta_remote_transients'][ $name ] = $value;
		$callback = $GLOBALS['jcore_silta_remote_on_touch'] ?? null;
		if ( null !== $callback ) {
			$callback();
		}
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Stand-in for delete_transient().
	 *
	 * @param string $name Name.
	 *
	 * @return bool
	 */
	function delete_transient( string $name ): bool {
		unset( $GLOBALS['jcore_silta_remote_transients'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stand-in for current_user_can().
	 *
	 * @param string $capability Capability.
	 * @param mixed  ...$args    Args.
	 *
	 * @return bool
	 */
	function current_user_can( string $capability, mixed ...$args ): bool {
		$allowed = $GLOBALS['jcore_silta_remote_caps'][ $capability ] ?? false;
		return is_array( $allowed ) ? in_array( $args[0] ?? null, $allowed, true ) : $allowed;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Stand-in for get_current_user_id().
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return $GLOBALS['jcore_silta_remote_user'] ?? 7;
	}
}
if ( ! function_exists( 'get_taxonomy' ) ) {
	/**
	 * Stand-in for get_taxonomy().
	 *
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return object|false
	 */
	function get_taxonomy( string $taxonomy ): object|false {
		return $GLOBALS['jcore_silta_remote_taxonomies'][ $taxonomy ] ?? false;
	}
}
if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * Stand-in for get_post_type_object().
	 *
	 * @param string $type Type.
	 *
	 * @return ?object
	 */
	function get_post_type_object( string $type ): ?object {
		return $GLOBALS['jcore_silta_remote_types'][ $type ] ?? null;
	}
}
if ( ! function_exists( 'get_term' ) ) {
	/**
	 * Stand-in for get_term().
	 *
	 * @param int $id Id.
	 *
	 * @return mixed
	 */
	function get_term( int $id ): mixed {
		return $GLOBALS['jcore_silta_remote_terms'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Stand-in for trailingslashit().
	 *
	 * @param string $value Value.
	 *
	 * @return string
	 */
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/' ) . '/';
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * Stand-in for wp_remote_post().
	 *
	 * @param string $url  Url.
	 * @param array  $args Args.
	 *
	 * @return array
	 */
	function wp_remote_post( string $url, array $args ): array {
		$GLOBALS['jcore_silta_remote_request'] = array(
			'url'  => $url,
			'args' => $args,
		);
		return $GLOBALS['jcore_silta_remote_response'] ?? array(
			'code' => 200,
			'body' => '{}',
		);
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Stand-in for wp_remote_retrieve_body().
	 *
	 * @param array $response Response.
	 *
	 * @return string
	 */
	function wp_remote_retrieve_body( array $response ): string {
		return $response['body'];
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Stand-in for wp_remote_retrieve_response_code().
	 *
	 * @param array $response Response.
	 *
	 * @return int
	 */
	function wp_remote_retrieve_response_code( array $response ): int {
		return $response['code'];
	}
}

if ( ! function_exists( 'kses_init_filters' ) ) {
	/**
	 * Stand-in for restoring WordPress content filters.
	 *
	 * @return void
	 */
	function kses_init_filters(): void {}
}
