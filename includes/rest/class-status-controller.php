<?php
/**
 * REST route for a post's sync status, shown in the block editor.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Rest;

use Jcore\Silta\Endpoint\Remote;
use Jcore\Silta\Remote\Connections;
use Jcore\Silta\Sync\State;
use Jcore\Silta\Util;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks every connection this site may push to how its copy of a post compares.
 */
final class Status_Controller extends Controller {

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/status/(?P<post>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => function ( WP_REST_Request $request ): bool {
						return $this->sync_permission() && current_user_can( 'edit_post', (int) $request['post'] );
					},
				),
			)
		);
	}

	/**
	 * Handles GET /status/{post}.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = (int) $request['post'];
		$state = State::post( $id );
		if ( null === $state ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'This post cannot be synced.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$key   = Util::key( 'post', $id );
		$hash  = State::hash( $state );
		$sites = array();

		foreach ( Connections::all() as $connection ) {
			if ( ! in_array( 'write', (array) $connection['scopes'], true ) ) {
				continue;
			}

			$entry = array(
				'connection' => $connection['id'],
				'label'      => $connection['label'],
				'home'       => $connection['home'],
			);

			$response = ( new Remote( $connection ) )->status(
				array(
					'items' => array(
						array(
							'key'  => $key,
							'hash' => $hash,
						),
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$entry['state'] = 'error';
				$entry['error'] = $response->get_error_message();
			} else {
				$entry = array_merge( $entry, (array) ( $response['statuses'][ $key ] ?? array( 'state' => 'not_synced' ) ) );
			}

			$sites[] = $entry;
		}

		return rest_ensure_response( array( 'sites' => $sites ) );
	}
}
