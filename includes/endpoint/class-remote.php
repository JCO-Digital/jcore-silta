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
 *
 * What the admin screens show from the other site (thumbnails, links, diff
 * tables) is cleaned here, since a compromised remote controls all of it.
 */
final class Remote extends Endpoint {

	/**
	 * Tags and attributes of core's wp_text_diff() table, the only HTML a diff
	 * from the other site may contain.
	 */
	private const DIFF_HTML = array(
		'table'   => array( 'class' => true ),
		'caption' => array( 'class' => true ),
		'thead'   => array(),
		'tbody'   => array(),
		'tr'      => array( 'class' => true ),
		'th'      => array(),
		'td'      => array( 'class' => true ),
		'span'    => array(
			'class'       => true,
			'aria-hidden' => true,
		),
		'del'     => array(),
		'ins'     => array(),
	);

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
		$result = $this->client->call( 'query', $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = array();
		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) ) {
				$item['thumb'] = self::url( $item['thumb'] ?? '' );
				$item['link']  = self::url( $item['link'] ?? '' );
				$items[]       = $item;
			}
		}
		$result['items'] = $items;

		return $result;
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
		$result = $this->client->call( 'plan', $args, 120 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$plans = array();
		foreach ( (array) ( $result['plans'] ?? array() ) as $key => $plan ) {
			if ( is_array( $plan ) ) {
				$plan['match'] = self::match( $plan['match'] ?? null );
				$plans[ $key ] = $plan;
			}
		}
		$result['plans'] = $plans;

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	public function diff( array $args ): array|WP_Error {
		$result = $this->client->call( 'diff', $args );
		if ( is_wp_error( $result ) || empty( $result ) ) {
			return $result;
		}

		// The screen renders the diff table as HTML, and the rest as text.
		$sections = array();
		foreach ( (array) ( $result['sections'] ?? array() ) as $key => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			foreach ( array( 'label', 'group', 'before', 'after' ) as $field ) {
				$section[ $field ] = is_scalar( $section[ $field ] ?? '' ) ? (string) ( $section[ $field ] ?? '' ) : '';
			}
			$section['changed'] = ! empty( $section['changed'] );
			$section['diff']    = is_string( $section['diff'] ?? null ) ? wp_kses( $section['diff'], self::DIFF_HTML ) : '';
			$sections[ $key ]   = $section;
		}
		$result['sections'] = $sections;
		$result['match']    = self::match( $result['match'] ?? null );

		return $result;
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
		$result = $this->client->call( 'status', $args, 30 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$statuses = array();
		foreach ( (array) ( $result['statuses'] ?? array() ) as $key => $status ) {
			if ( is_array( $status ) ) {
				if ( isset( $status['local'] ) ) {
					$status['local'] = self::match( $status['local'] );
				}
				$statuses[ $key ] = $status;
			}
		}
		$result['statuses'] = $statuses;

		return $result;
	}

	/**
	 * Cleans the other site's description of one of its objects, whose links
	 * the screens render.
	 *
	 * @param mixed $described Planner::describe() output, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function match( mixed $described ): ?array {
		if ( ! is_array( $described ) ) {
			return null;
		}

		foreach ( array( 'edit', 'view' ) as $link ) {
			if ( isset( $described[ $link ] ) ) {
				$described[ $link ] = self::url( $described[ $link ] );
			}
		}

		return $described;
	}

	/**
	 * Keeps http(s) URLs only, so a link from the other site can't run script.
	 *
	 * @param mixed $url URL from the other site.
	 *
	 * @return string URL, or an empty string.
	 */
	private static function url( mixed $url ): string {
		return is_string( $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
	}
}
