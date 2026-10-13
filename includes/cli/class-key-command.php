<?php
/**
 * `wp silta key`.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Cli;

use Jcore\Silta\Crypto\Identity;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows, exports and replaces this site's signing key.
 */
final class Key_Command {

	/**
	 * Shows where the key lives and its fingerprint.
	 *
	 * @return void
	 */
	public function info(): void {
		$identity = Identity::describe();

		WP_CLI::line( sprintf( 'Site ID:     %s', $identity['site'] ) );
		WP_CLI::line( sprintf( 'Storage:     %s%s', $identity['storage'], $identity['weak'] && 'database' === $identity['storage'] ? ' (salts are in the database, so the encryption adds nothing)' : '' ) );
		WP_CLI::line( sprintf( 'Fingerprint: %s', '' !== $identity['fingerprint'] ? $identity['fingerprint'] : '(no key yet)' ) );
	}

	/**
	 * Prints the wp-config.php line that holds the key.
	 *
	 * Once the line is in wp-config.php, the copy in the database is removed
	 * and the key survives database refreshes.
	 *
	 * @return void
	 */
	public function export(): void {
		$seed = Identity::export_seed();
		if ( null === $seed ) {
			WP_CLI::error( 'This site has no key yet. It gets one when it first connects to another site.' );
		}

		WP_CLI::line( sprintf( "define( '%s', '%s' );", Identity::CONSTANT, $seed ) );
	}

	/**
	 * Replaces the key. Every connection has to be paired again.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function regenerate( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'Every connection from this site will need a new pairing. Continue?', $assoc_args );

		$result = Identity::regenerate();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'New key created. Pair the connections again.' );
	}
}
