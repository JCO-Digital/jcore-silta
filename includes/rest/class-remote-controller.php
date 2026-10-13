<?php
/**
 * Routes paired sites call.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Rest;

use Jcore\Silta\Endpoint\Local;
use Jcore\Silta\Remote\Trusted_Keys;
use Jcore\Silta\Remote\Verifier;
use Jcore\Silta\Site;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `remote/*` routes. Every route but `pair` requires a request signed by a
 * trusted key with the right scope; `pair` requires a valid pairing token.
 */
final class Remote_Controller extends Controller {

	/**
	 * Actions and the scope each needs.
	 */
	private const ACTIONS = array(
		'handshake'     => 'any',
		'status'        => 'any',
		'query'         => 'read',
		'ids'           => 'read',
		'export'        => 'read',
		'media/read'    => 'read',
		'plan'          => 'write',
		'diff'          => 'write',
		'media/prepare' => 'write',
		'media/write'   => 'write',
		'import'        => 'write',
		'orphans'       => 'write',
		'undo'          => 'write',
	);

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Public by design: the pairing token in the body is the credential.
		register_rest_route(
			$this->namespace,
			'/remote/pair',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pair' ),
				'permission_callback' => array( Verifier::class, 'verify_pairing' ),
			)
		);

		foreach ( self::ACTIONS as $action => $scope ) {
			register_rest_route(
				$this->namespace,
				'/remote/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $action ) {
						return $this->dispatch( $action, $request );
					},
					'permission_callback' => Verifier::requires( $scope ),
				)
			);
		}
	}

	/**
	 * Handles POST /remote/pair.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function pair( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = (array) $request->get_json_params();
		$key    = Trusted_Keys::pair(
			(string) ( $params['token'] ?? '' ),
			(string) ( $params['public_key'] ?? '' ),
			(array) ( $params['site'] ?? array() )
		);

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		return rest_ensure_response(
			array(
				'site'   => Site::info(),
				'scopes' => $key['scopes'],
			)
		);
	}

	/**
	 * Runs an action for the verified caller.
	 *
	 * @param string          $action  Action.
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function dispatch( string $action, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$key = Verifier::current();
		if ( null === $key ) {
			return new WP_Error( 'jcore_silta_unauthorized', __( 'The request was not verified.', 'jcore-silta' ), array( 'status' => 401 ) );
		}

		// The caller's identity comes from the verified key, never from the body.
		$local = new Local( (string) $key['site'] );
		$args  = (array) $request->get_json_params();

		$result = match ( $action ) {
			'handshake' => $local->handshake(),
			'status' => $local->status( $args ),
			'query' => $local->query( $args ),
			'ids' => $local->ids( $args ),
			'export' => $local->export( $args ),
			'media/read' => $local->media_read( $args ),
			'plan' => $local->plan( $args ),
			'diff' => $local->diff( $args ),
			'media/prepare' => $local->media_prepare( $args ),
			'media/write' => $local->media_write( $args ),
			'import' => $local->import( $args ),
			'orphans' => $local->orphans( $args ),
			'undo' => $local->undo( $args ),
		};

		if ( 'handshake' === $action && is_array( $result ) ) {
			$result['scopes'] = $key['scopes'];
		}

		return $this->respond( $result );
	}
}
