<?php
/**
 * The other site, over the signed channel.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Endpoint;

use Jcore\Silta\Remote\Client;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forwards every operation to the remote's `jcore-silta/v1/remote/*` routes.
 */
final class Remote extends Endpoint {

	/**
	 * Client for the connection.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Creates the endpoint for a connection.
	 *
	 * @param array<string, mixed> $connection Connection.
	 */
	public function __construct( array $connection ) {
		$this->client = new Client( $connection );
	}

	/**
	 * {@inheritDoc}
	 */
	public function handshake(): array|WP_Error {
		return $this->client->call( 'handshake' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function query( array $args ): array|WP_Error {
		return $this->client->call( 'query', $args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function ids( array $args ): array|WP_Error {
		return $this->client->call( 'ids', $args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function export( array $args ): array|WP_Error {
		return $this->client->call( 'export', $args, 120 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function media_read( array $args ): array|WP_Error {
		return $this->client->call( 'media/read', $args, 120 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function plan( array $args ): array|WP_Error {
		return $this->client->call( 'plan', $args, 120 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function diff( array $args ): array|WP_Error {
		return $this->client->call( 'diff', $args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function media_prepare( array $args ): array|WP_Error {
		return $this->client->call( 'media/prepare', $args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function media_write( array $args ): array|WP_Error {
		return $this->client->call( 'media/write', $args, 120 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function import( array $args ): array|WP_Error {
		return $this->client->call( 'import', $args, 180 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function orphans( array $args ): array|WP_Error {
		return $this->client->call( 'orphans', $args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function undo( array $args ): array|WP_Error {
		return $this->client->call( 'undo', $args, 180 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function status( array $args ): array|WP_Error {
		return $this->client->call( 'status', $args, 30 );
	}
}
