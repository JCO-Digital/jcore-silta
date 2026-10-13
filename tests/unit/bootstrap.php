<?php
/**
 * Bootstrap for the unit tests: the pure parts of the sync engine (replacing,
 * remapping, signing) run without WordPress, with the few core helpers they
 * call stubbed below. Everything that touches the database is covered by the
 * two-site integration tests in tests/integration/.
 *
 * @package Jcore\Silta
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Stand-ins for core functions.

define( 'ABSPATH', __DIR__ . '/' );
define( 'JCORE_SILTA_PATH', dirname( __DIR__, 2 ) . '/' );
define( 'JCORE_SILTA_VERSION', '0.0.0' );

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
