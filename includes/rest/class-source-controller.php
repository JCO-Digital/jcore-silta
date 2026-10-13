<?php
/**
 * REST routes for choosing what to sync.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Rest;

use Jcore\Silta\Endpoint\Local;
use Jcore\Silta\Endpoint\Remote;
use Jcore\Silta\Remote\Connections;
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
	 * Handles GET /source: post types, menus and languages of the source.
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
			return $this->respond( ( new Local( (string) $connection['site'] ) )->handshake() );
		}

		return $this->respond( Connections::handshake( (string) $connection['id'], (bool) $request['fresh'] ) );
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

		$endpoint = 'push' === $request['direction'] ? new Local( (string) $connection['site'] ) : new Remote( $connection );

		return $this->respond(
			$endpoint->query(
				array(
					'post_type' => (string) $request['post_type'],
					'search'    => (string) $request['search'],
					'page'      => (int) $request['page'],
					'per_page'  => (int) $request['per_page'],
					'include'   => (array) ( $request['include'] ?? array() ),
				)
			)
		);
	}
}
