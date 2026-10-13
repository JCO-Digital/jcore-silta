<?php
/**
 * WP-CLI registration.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Cli;

use Jcore\Silta\Remote\Connections;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the `wp silta` commands.
 */
final class Commands {

	/**
	 * Registers every command.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'silta', Sync_Command::class );
		WP_CLI::add_command( 'silta connections', Connections_Command::class );
		WP_CLI::add_command( 'silta keys', Keys_Command::class );
		WP_CLI::add_command( 'silta key', Key_Command::class );
	}

	/**
	 * Finds a connection by ID, label or host.
	 *
	 * @param string $needle ID, label or host.
	 *
	 * @return array<string, mixed>
	 */
	public static function connection( string $needle ): array {
		$found = array();
		foreach ( Connections::all() as $connection ) {
			$host = (string) wp_parse_url( (string) $connection['home'], PHP_URL_HOST );
			$port = wp_parse_url( (string) $connection['home'], PHP_URL_PORT );
			if (
				$connection['id'] === $needle
				|| 0 === strcasecmp( (string) $connection['label'], $needle )
				|| 0 === strcasecmp( $host, $needle )
				|| ( null !== $port && 0 === strcasecmp( $host . ':' . $port, $needle ) )
			) {
				$found[] = $connection;
			}
		}

		if ( 1 !== count( $found ) ) {
			WP_CLI::error(
				empty( $found )
					? sprintf( 'No connection matches "%s". See `wp silta connections list`.', $needle )
					: sprintf( 'Several connections match "%s". Use the connection ID.', $needle )
			);
		}

		return $found[0];
	}
}
