<?php
/**
 * REST routes for choosing what to sync.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Rest;

use Jcore\Silta\Endpoint\Endpoint;
use Jcore\Silta\Endpoint\Local;
use Jcore\Silta\Endpoint\Remote;
use Jcore\Silta\Remote\Connections;
use Jcore\Silta\Sync\Gravity_Forms;
use Jcore\Silta\Util;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists connections, and the content of the source side of a sync: this site
 * when pushing, the remote when pulling.
 */
final class Source_Controller extends Controller {

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$source_args = array(
			'connection' => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[a-f0-9-]{36}$',
			),
			'direction'  => array(
				'type'     => 'string',
				'enum'     => array( 'push', 'pull' ),
				'required' => true,
			),
		);

		register_rest_route(
			$this->namespace,
			'/sources',
			array( $this->sync_route( WP_REST_Server::READABLE, 'connections' ) )
		);

		register_rest_route(
			$this->namespace,
			'/source',
			array( $this->sync_route( WP_REST_Server::READABLE, 'source', $source_args + array( 'fresh' => array( 'type' => 'boolean' ) ) ) )
		);

		register_rest_route(
			$this->namespace,
			'/source/posts',
			array(
				$this->sync_route(
					WP_REST_Server::READABLE,
					'posts',
					$source_args + array(
						'post_type' => array(
							'type'    => 'string',
							'default' => 'post',
						),
						'search'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'page'      => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page'  => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
						'include'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'status'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				),
			)
		);
	}

	/**
	 * Handles GET /sources: the connections a syncing user can pick from.
	 *
	 * @return WP_REST_Response
	 */
	public function connections(): WP_REST_Response {
		$list = array();
		foreach ( Connections::all() as $connection ) {
			$list[] = array(
				'id'     => $connection['id'],
				'label'  => $connection['label'],
				'home'   => $connection['home'],
				'scopes' => $connection['scopes'],
				'status' => $connection['status'],
				'error'  => $connection['error'] ?? '',
				'rules'  => $connection['rules'] ?? array(),
			);
		}

		return rest_ensure_response( $list );
	}

	/**
	 * Handles GET /source: post types, menus, forms and languages of the source,
	 * and whether the target has Gravity Forms.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function source( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connection = Connections::get( (string) $request['connection'] );
		if ( null === $connection ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such connection.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		if ( 'push' === $request['direction'] ) {
			$source = ( new Local( (string) $connection['site'] ) )->handshake();
			if ( ! is_wp_error( $source ) ) {
				$source['target_gravity_forms'] = self::remote_gravity_forms( (string) $connection['id'] );
			}

			return $this->respond( $source );
		}

		$source = Connections::handshake( (string) $connection['id'], (bool) $request['fresh'] );
		if ( ! is_wp_error( $source ) ) {
			$source['target_gravity_forms'] = Gravity_Forms::active();
		}

		return $this->respond( $source );
	}

	/**
	 * Whether a remote has Gravity Forms active, from its (cached) handshake.
	 * Null when the remote can't be reached.
	 *
	 * @param string $id Connection ID.
	 *
	 * @return bool|null
	 */
	private static function remote_gravity_forms( string $id ): ?bool {
		$handshake = Connections::handshake( $id );

		return is_wp_error( $handshake ) ? null : ! empty( $handshake['gravity_forms'] );
	}

	/**
	 * Handles GET /source/posts: searches the source's posts.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function posts( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connection = Connections::get( (string) $request['connection'] );
		if ( null === $connection ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such connection.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$local  = new Local( (string) $connection['site'] );
		$remote = new Remote( $connection );
		$source = 'push' === $request['direction'] ? $local : $remote;
		$result = $source->query(
			array(
				'post_type' => (string) $request['post_type'],
				'search'    => (string) $request['search'],
				'page'      => (int) $request['page'],
				'per_page'  => (int) $request['per_page'],
				'include'   => (array) ( $request['include'] ?? array() ),
				'hashes'    => (bool) $request['status'],
			)
		);

		if ( is_wp_error( $result ) || ! $request['status'] ) {
			return $this->respond( $result );
		}

		return $this->respond( self::with_status( $result, 'push' === $request['direction'] ? $remote : $local ) );
	}

	/**
	 * Adds to each post how it compares with its copy on the target: not
	 * synced yet, up to date, or changed on one side or both since the last sync.
	 *
	 * A source that sends no hashes (an older version) gets no state, and a
	 * target that can't be reached leaves the list as it is with an error.
	 *
	 * @param array<string, mixed> $result Query result, with a hash per item.
	 * @param Endpoint             $target Target endpoint.
	 *
	 * @return array<string, mixed>
	 */
	private static function with_status( array $result, Endpoint $target ): array {
		$items = array();
		foreach ( (array) $result['items'] as $item ) {
			if ( is_string( $item['hash'] ?? null ) ) {
				$items[] = array(
					'key'  => Util::key( 'post', (int) $item['id'] ),
					'hash' => $item['hash'],
				);
			}
		}

		$statuses = array();
		if ( ! empty( $items ) ) {
			$response = $target->status( array( 'items' => $items ) );
			if ( is_wp_error( $response ) ) {
				$result['status_error'] = $response->get_error_message();
			} else {
				$statuses = (array) ( $response['statuses'] ?? array() );
			}
		}

		foreach ( $result['items'] as $index => $item ) {
			$status = $statuses[ Util::key( 'post', (int) $item['id'] ) ] ?? null;
			if ( is_array( $status ) ) {
				$result['items'][ $index ]['sync'] = array(
					'state'     => (string) ( $status['state'] ?? '' ),
					'synced_at' => (string) ( $status['synced_at'] ?? '' ),
				);
			}
			unset( $result['items'][ $index ]['hash'] );
		}

		return $result;
	}
}
