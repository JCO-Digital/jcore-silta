<?php
/**
 * One side of a sync.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Endpoint;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The operations a site offers in a sync. The job runner works with two
 * endpoints, a source and a target; one is this site (Local) and the other is
 * reached over the signed channel (Remote), so push and pull share one code path.
 *
 * Every operation takes and returns plain arrays, which is what travels as JSON.
 */
abstract class Endpoint {

	/**
	 * Site details, content types, menus and languages.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function handshake(): array|WP_Error;

	/**
	 * Searches posts for the picker.
	 *
	 * @param array<string, mixed> $args See Content::query().
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function query( array $args ): array|WP_Error;

	/**
	 * Every ID of a post type.
	 *
	 * @param array<string, mixed> $args post_type.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function ids( array $args ): array|WP_Error;

	/**
	 * Exports objects (source).
	 *
	 * @param array<string, mixed> $args keys.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function export( array $args ): array|WP_Error;

	/**
	 * Reads a chunk of an attachment's file (source).
	 *
	 * @param array<string, mixed> $args id, path, offset, length.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function media_read( array $args ): array|WP_Error;

	/**
	 * Plans a bundle (target).
	 *
	 * @param array<string, mixed> $args bundle, options.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function plan( array $args ): array|WP_Error;

	/**
	 * Full comparison of one object (target).
	 *
	 * @param array<string, mixed> $args bundle, options, key.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function diff( array $args ): array|WP_Error;

	/**
	 * Opens a file transfer for an attachment (target).
	 *
	 * @param array<string, mixed> $args bundle, options.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function media_prepare( array $args ): array|WP_Error;

	/**
	 * Receives a chunk of a file (target).
	 *
	 * @param array<string, mixed> $args transfer, path, offset, data.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function media_write( array $args ): array|WP_Error;

	/**
	 * Imports a bundle (target).
	 *
	 * @param array<string, mixed> $args bundle, options.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function import( array $args ): array|WP_Error;

	/**
	 * Lists posts synced from the source whose originals are gone (target).
	 *
	 * @param array<string, mixed> $args post_type, ids.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function orphans( array $args ): array|WP_Error;

	/**
	 * Undoes a job (target).
	 *
	 * @param array<string, mixed> $args job.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function undo( array $args ): array|WP_Error;

	/**
	 * Reports how objects compare with their copies here (target).
	 *
	 * @param array<string, mixed> $args items: list of key and hash.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	abstract public function status( array $args ): array|WP_Error;
}
