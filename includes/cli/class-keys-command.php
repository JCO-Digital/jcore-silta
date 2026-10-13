<?php
/**
 * `wp silta keys`.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Cli;

use Jcore\Silta\Remote\Trusted_Keys;
use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the sites allowed to connect to this one.
 */
final class Keys_Command {

	/**
	 * Lists the sites allowed to connect.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( Trusted_Keys::all() as $key ) {
			$rows[] = array(
				'fingerprint' => $key['fingerprint'],
				'label'       => $key['label'],
				'home'        => $key['home'],
				'scopes'      => implode( ',', (array) $key['scopes'] ),
				'enabled'     => $key['enabled'] ? 'yes' : ( $key['inherited'] ? 'no (inherited)' : 'no' ),
				'last_used'   => $key['last_used'] ? gmdate( 'Y-m-d H:i', (int) $key['last_used'] ) : '',
			);
		}

		Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'fingerprint', 'label', 'home', 'scopes', 'enabled', 'last_used' ) );
	}

	/**
	 * Creates a one-time pairing string for another site to connect with.
	 *
	 * ## OPTIONS
	 *
	 * [--label=<label>]
	 * : What to call the other site.
	 *
	 * [--scopes=<scopes>]
	 * : What the other site may do: read (pull from here), write (push here), or both.
	 * ---
	 * default: read,write
	 * ---
	 *
	 * [--porcelain]
	 * : Print only the pairing string.
	 *
	 * ## EXAMPLES
	 *
	 *     # On production: let staging push and pull.
	 *     wp silta keys create-token --label=Staging --scopes=read,write --user=admin
	 *
	 * @subcommand create-token
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function create_token( array $args, array $assoc_args ): void {
		$user = get_current_user_id();
		if ( ! $user || ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run this as an administrator (--user=<admin>): the paired site will act as that user here.' );
		}

		$scopes = Trusted_Keys::clean_scopes( explode( ',', (string) ( $assoc_args['scopes'] ?? 'read,write' ) ) );
		if ( empty( $scopes ) ) {
			WP_CLI::error( 'Scopes must be read, write or both.' );
		}

		$pairing = Trusted_Keys::create_token( (string) ( $assoc_args['label'] ?? '' ), $scopes, $user );

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( $pairing );

			return;
		}

		WP_CLI::line( 'Paste this on the other site within 30 minutes (Tools → JCORE Silta → Connections, or `wp silta connections add`):' );
		WP_CLI::line( '' );
		WP_CLI::line( $pairing );
	}

	/**
	 * Revokes a site's access.
	 *
	 * ## OPTIONS
	 *
	 * <fingerprint>
	 * : Key fingerprint, from `wp silta keys list`.
	 *
	 * @param string[] $args Positional arguments.
	 *
	 * @return void
	 */
	public function revoke( array $args ): void {
		if ( ! Trusted_Keys::delete( (string) $args[0] ) ) {
			WP_CLI::error( 'No such key.' );
		}

		WP_CLI::success( 'Revoked.' );
	}

	/**
	 * Turns a site's access back on, e.g. after a clone switched it off.
	 *
	 * ## OPTIONS
	 *
	 * <fingerprint>
	 * : Key fingerprint, from `wp silta keys list`.
	 *
	 * @param string[] $args Positional arguments.
	 *
	 * @return void
	 */
	public function enable( array $args ): void {
		$key = Trusted_Keys::update( (string) $args[0], array( 'enabled' => true ) );
		if ( is_wp_error( $key ) ) {
			WP_CLI::error( $key->get_error_message() );
		}

		WP_CLI::success( 'Enabled.' );
	}
}
