<?php
/**
 * `wp silta connections`.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Cli;

use Jcore\Silta\Remote\Connections;
use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the sites this site connects to.
 */
final class Connections_Command {

	/**
	 * Lists the connections.
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
	 *   - ids
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
		foreach ( Connections::all() as $connection ) {
			$rows[] = array(
				'id'     => $connection['id'],
				'label'  => $connection['label'],
				'home'   => $connection['home'],
				'scopes' => implode( ',', (array) $connection['scopes'] ),
				'status' => $connection['status'],
			);
		}

		Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'label', 'home', 'scopes', 'status' ) );
	}

	/**
	 * Pairs with another site using the string it gave.
	 *
	 * ## OPTIONS
	 *
	 * <pairing>
	 * : Pairing string from `wp silta keys create-token` (or the admin screen) on the other site.
	 *
	 * [--label=<label>]
	 * : What to call the other site. Defaults to its name.
	 *
	 * [--auth-user=<user>]
	 * : Basic auth user, if the other site is behind basic auth.
	 *
	 * [--auth-pass=<pass>]
	 * : Basic auth password.
	 *
	 * [--porcelain]
	 * : Print only the connection ID.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function add( array $args, array $assoc_args ): void {
		$connection = Connections::add(
			(string) $args[0],
			(string) ( $assoc_args['label'] ?? '' ),
			array(
				'user' => (string) ( $assoc_args['auth-user'] ?? '' ),
				'pass' => (string) ( $assoc_args['auth-pass'] ?? '' ),
			)
		);

		if ( is_wp_error( $connection ) ) {
			WP_CLI::error( $connection->get_error_message() );
		}

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $connection['id'] );

			return;
		}

		WP_CLI::success( sprintf( 'Connected to %s (%s), allowed to %s.', $connection['label'], $connection['home'], implode( ' and ', $connection['scopes'] ) ) );
	}

	/**
	 * Runs a fresh handshake with a connected site.
	 *
	 * ## OPTIONS
	 *
	 * <connection>
	 * : Connection ID, label or host.
	 *
	 * @param string[] $args Positional arguments.
	 *
	 * @return void
	 */
	public function verify( array $args ): void {
		$connection = Commands::connection( (string) $args[0] );
		$result     = Connections::handshake( (string) $connection['id'], true );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'%s answered: Silta %s, allowed to %s.',
				(string) ( $result['site']['name'] ?? $connection['label'] ),
				(string) ( $result['site']['version'] ?? '?' ),
				implode( ' and ', (array) ( $result['scopes'] ?? array() ) )
			)
		);
	}

	/**
	 * Removes a connection. The other site still trusts this one until revoked there.
	 *
	 * ## OPTIONS
	 *
	 * <connection>
	 * : Connection ID, label or host.
	 *
	 * @param string[] $args Positional arguments.
	 *
	 * @return void
	 */
	public function remove( array $args ): void {
		$connection = Commands::connection( (string) $args[0] );
		Connections::delete( (string) $connection['id'] );

		WP_CLI::success( sprintf( 'Removed the connection to %s.', $connection['label'] ) );
	}
}
