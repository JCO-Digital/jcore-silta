<?php
/**
 * Bootstrap for the unit tests: the pure parts of the sync engine (replacing,
 * remapping, signing) run without WordPress, with the few core helpers they
 * call stubbed below. Everything that touches the database is covered by the
 * two-site integration tests in tests/integration/.
 *
 * @package Jcore\Silta
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter -- Stand-ins for core functions and their signatures.

define( 'ABSPATH', __DIR__ . '/' );
define( 'JCORE_SILTA_PATH', dirname( __DIR__, 2 ) . '/' );
define( 'JCORE_SILTA_VERSION', '0.0.0' );
define( 'KB_IN_BYTES', 1024 );

require JCORE_SILTA_PATH . 'vendor/autoload.php';

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Jcore\\Silta\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$parts = array_map(
			static fn( string $part ): string => strtolower( str_replace( '_', '-', $part ) ),
			explode( '\\', substr( $class_name, strlen( $prefix ) ) )
		);

		$parts[ array_key_last( $parts ) ] = 'class-' . end( $parts );

		$file = JCORE_SILTA_PATH . 'includes/' . implode( '/', $parts ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/**
 * Filters registered by tests: hook => callback.
 *
 * @var array<string, callable>
 */
$GLOBALS['jcore_silta_test_filters'] = array();

/**
 * Stand-in for apply_filters(): runs a callback a test registered.
 *
 * @param string $hook  Hook.
 * @param mixed  $value Value.
 * @param mixed  ...$args Extra arguments.
 *
 * @return mixed
 */
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$callback = $GLOBALS['jcore_silta_test_filters'][ $hook ] ?? null;

	return null === $callback ? $value : $callback( $value, ...$args );
}

/**
 * Stand-in for untrailingslashit().
 *
 * @param string $value Value.
 *
 * @return string
 */
function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

/**
 * Stand-in for wp_json_encode().
 *
 * @param mixed $data  Data.
 * @param int   $flags Flags.
 *
 * @return string|false
 */
function wp_json_encode( mixed $data, int $flags = 0 ): string|false {
	return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stand-in.
}

/**
 * Copy of core's serialize_block_attributes().
 *
 * @param array<string, mixed> $block_attributes Attributes.
 *
 * @return string
 */
function serialize_block_attributes( array $block_attributes ): string {
	$encoded_attributes = wp_json_encode( $block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	return strtr(
		$encoded_attributes,
		array(
			'\\\\' => '\\u005c',
			'--'   => '\\u002d\\u002d',
			'<'    => '\\u003c',
			'>'    => '\\u003e',
			'&'    => '\\u0026',
			'\\"'  => '\\u0022',
		)
	);
}

// A small hook registry for add_action() / do_action(); apply_filters() above
// stays the single-callback stand-in the tests register directly.
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
