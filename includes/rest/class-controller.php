<?php
/**
 * Shared base for the plugin's REST controllers.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Rest;

use Jcore\Silta\Capabilities;
use WP_Error;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the namespace and the capability checks every route shares.
 */
abstract class Controller extends \WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'jcore-silta/v1';

	/**
	 * Permission callback for the administrator-only routes.
	 *
	 * @return bool
	 */
	public function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for the routes that run syncs.
	 *
	 * @return bool
	 */
	public function sync_permission(): bool {
		return current_user_can( Capabilities::SYNC );
	}

	/**
	 * Builds a route definition with the administrator permission already set.
	 *
	 * @param string               $method   One of the `WP_REST_Server` method constants.
	 * @param string               $callback Method name on this controller.
	 * @param array<string, mixed> $args     Argument schema.
	 *
	 * @return array<string, mixed>
	 */
	protected function route( string $method, string $callback, array $args = array() ): array {
		return array(
			'methods'             => $method,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => $args,
		);
	}

	/**
	 * Builds a route definition for syncing users.
	 *
	 * @param string               $method   One of the `WP_REST_Server` method constants.
	 * @param string               $callback Method name on this controller.
	 * @param array<string, mixed> $args     Argument schema.
	 *
	 * @return array<string, mixed>
	 */
	protected function sync_route( string $method, string $callback, array $args = array() ): array {
		$route                        = $this->route( $method, $callback, $args );
		$route['permission_callback'] = array( $this, 'sync_permission' );

		return $route;
	}

	/**
	 * Wraps a result or error as a response.
	 *
	 * @param mixed $result Array result or WP_Error.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	protected function respond( mixed $result ): WP_REST_Response|WP_Error {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$result->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => 400 ) ) );
			}

			return $result;
		}

		return rest_ensure_response( $result );
	}
}
