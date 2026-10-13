<?php
/**
 * REST routes for connections, trusted sites and this site's key.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Rest;

use Jcore\Silta\Crypto\Identity;
use Jcore\Silta\Remote\Connections;
use Jcore\Silta\Remote\Trusted_Keys;
use Jcore\Silta\Site;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages both directions of pairing. Everything here needs manage_options;
 * syncing users only see the connection list through Source_Controller.
 */
final class Connections_Controller extends Controller {

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/connections',
			array(
				$this->route( WP_REST_Server::READABLE, 'overview' ),
				$this->route(
					WP_REST_Server::CREATABLE,
					'add_connection',
					array(
						'pairing'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'label'     => array(
							'type'    => 'string',
							'default' => '',
						),
						'auth_user' => array(
							'type'    => 'string',
							'default' => '',
						),
						'auth_pass' => array(
							'type'    => 'string',
							'default' => '',
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/connections/(?P<id>[a-f0-9-]{36})',
			array(
				$this->route(
					WP_REST_Server::EDITABLE,
					'update_connection',
					array(
						'label'     => array( 'type' => 'string' ),
						'auth_user' => array( 'type' => 'string' ),
						'auth_pass' => array( 'type' => 'string' ),
						'rules'     => array(
							'type'  => 'array',
							'items' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					)
				),
				$this->route( WP_REST_Server::DELETABLE, 'delete_connection' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/connections/(?P<id>[a-f0-9-]{36})/verify',
			array( $this->route( WP_REST_Server::CREATABLE, 'verify_connection' ) )
		);

		register_rest_route(
			$this->namespace,
			'/keys/tokens',
			array(
				$this->route(
					WP_REST_Server::CREATABLE,
					'create_token',
					array(
						'label'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'scopes' => array(
							'type'     => 'array',
							'items'    => array(
								'type' => 'string',
								'enum' => Trusted_Keys::SCOPES,
							),
							'required' => true,
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/keys/tokens/(?P<token>[a-f0-9]{12})',
			array( $this->route( WP_REST_Server::DELETABLE, 'cancel_token' ) )
		);

		register_rest_route(
			$this->namespace,
			'/keys/(?P<fingerprint>[a-f0-9]{24})',
			array(
				$this->route(
					WP_REST_Server::EDITABLE,
					'update_key',
					array(
						'label'   => array( 'type' => 'string' ),
						'enabled' => array( 'type' => 'boolean' ),
						'scopes'  => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
								'enum' => Trusted_Keys::SCOPES,
							),
						),
					)
				),
				$this->route( WP_REST_Server::DELETABLE, 'delete_key' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/identity/export',
			array( $this->route( WP_REST_Server::CREATABLE, 'export_identity' ) )
		);

		register_rest_route(
			$this->namespace,
			'/identity/regenerate',
			array( $this->route( WP_REST_Server::CREATABLE, 'regenerate_identity' ) )
		);

		register_rest_route(
			$this->namespace,
			'/cloned',
			array( $this->route( WP_REST_Server::DELETABLE, 'dismiss_clone' ) )
		);
	}

	/**
	 * Handles GET /connections: everything the Connections screen shows.
	 *
	 * @return WP_REST_Response
	 */
	public function overview(): WP_REST_Response {
		$keys = array_map(
			static function ( array $key ): array {
				$user        = get_userdata( (int) $key['user_id'] );
				$key['user'] = $user ? $user->display_name : '';
				unset( $key['public'] );

				return $key;
			},
			Trusted_Keys::all()
		);

		return rest_ensure_response(
			array(
				'site'        => Site::info(),
				'identity'    => Identity::describe(),
				'connections' => array_values( array_map( array( Connections::class, 'public_view' ), Connections::all() ) ),
				'keys'        => $keys,
				'tokens'      => Trusted_Keys::pending(),
				'cloned'      => get_option( Site::CLONED_OPTION, null ),
			)
		);
	}

	/**
	 * Handles POST /connections: pairs with a remote.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			Connections::add(
				(string) $request['pairing'],
				(string) $request['label'],
				array(
					'user' => (string) $request['auth_user'],
					'pass' => (string) $request['auth_pass'],
				)
			)
		);
	}

	/**
	 * Handles PUT/PATCH /connections/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$changes = array();
		if ( $request->has_param( 'label' ) ) {
			$changes['label'] = (string) $request['label'];
		}
		if ( $request->has_param( 'auth_user' ) ) {
			$changes['auth'] = array(
				'user' => (string) $request['auth_user'],
				'pass' => (string) ( $request['auth_pass'] ?? '' ),
			);
		}
		if ( $request->has_param( 'rules' ) ) {
			$changes['rules'] = (array) $request['rules'];
		}

		$connection = Connections::update( (string) $request['id'], $changes );

		return $this->respond( is_wp_error( $connection ) ? $connection : Connections::public_view( $connection ) );
	}

	/**
	 * Handles DELETE /connections/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_connection( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( array( 'deleted' => Connections::delete( (string) $request['id'] ) ) );
	}

	/**
	 * Handles POST /connections/{id}/verify: a fresh handshake.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_connection( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = Connections::handshake( (string) $request['id'], true );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}

		return rest_ensure_response( Connections::public_view( (array) Connections::get( (string) $request['id'] ) ) );
	}

	/**
	 * Handles POST /keys/tokens: creates a pairing string.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_token( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$scopes = Trusted_Keys::clean_scopes( (array) $request['scopes'] );
		if ( empty( $scopes ) ) {
			return new WP_Error( 'jcore_silta_no_scope', __( 'Allow reading, writing or both.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'pairing' => Trusted_Keys::create_token( sanitize_text_field( (string) $request['label'] ), $scopes, get_current_user_id() ),
				'expires' => time() + Trusted_Keys::TOKEN_TTL,
			)
		);
	}

	/**
	 * Handles DELETE /keys/tokens/{token}.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function cancel_token( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( array( 'deleted' => Trusted_Keys::cancel_token( (string) $request['token'] ) ) );
	}

	/**
	 * Handles PUT/PATCH /keys/{fingerprint}.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_key( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$changes = array();
		foreach ( array( 'label', 'enabled', 'scopes' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$changes[ $field ] = $request[ $field ];
			}
		}

		$key = Trusted_Keys::update( (string) $request['fingerprint'], $changes );
		if ( is_array( $key ) ) {
			unset( $key['public'] );
		}

		return $this->respond( $key );
	}

	/**
	 * Handles DELETE /keys/{fingerprint}: revokes a site.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_key( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( array( 'deleted' => Trusted_Keys::delete( (string) $request['fingerprint'] ) ) );
	}

	/**
	 * Handles POST /identity/export: the wp-config.php line holding the key.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function export_identity(): WP_REST_Response|WP_Error {
		$seed = Identity::export_seed();
		if ( null === $seed ) {
			return new WP_Error( 'jcore_silta_no_key', __( 'This site has no key yet. It gets one when it first connects to another site.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'define' => sprintf( "define( '%s', '%s' );", Identity::CONSTANT, $seed ),
			)
		);
	}

	/**
	 * Handles POST /identity/regenerate.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function regenerate_identity(): WP_REST_Response|WP_Error {
		$result = Identity::regenerate();

		return $this->respond( is_wp_error( $result ) ? $result : Identity::describe() );
	}

	/**
	 * Handles DELETE /cloned: dismisses the clone notice.
	 *
	 * @return WP_REST_Response
	 */
	public function dismiss_clone(): WP_REST_Response {
		delete_option( Site::CLONED_OPTION );

		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
