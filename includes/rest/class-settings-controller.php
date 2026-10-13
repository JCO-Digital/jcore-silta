<?php
/**
 * REST routes for the general settings.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Rest;

use Jcore\Silta\Settings;
use Jcore\Silta\Sync\Meta_Filter;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the `jcore_silta_settings` option.
 */
final class Settings_Controller extends Controller {

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				$this->route( WP_REST_Server::READABLE, 'get_settings' ),
				$this->route(
					WP_REST_Server::EDITABLE,
					'update_settings',
					array(
						'batch_size'     => array( 'type' => 'integer' ),
						'chunk_kb'       => array( 'type' => 'integer' ),
						'snapshot_days'  => array( 'type' => 'integer' ),
						'excluded_meta'  => array( 'type' => 'string' ),
						'request_budget' => array( 'type' => 'integer' ),
					)
				),
			)
		);
	}

	/**
	 * Handles GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return rest_ensure_response( Settings::all() );
	}

	/**
	 * Handles POST /settings. Only keys present in the request change.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$values = array();
		foreach ( array_keys( Settings::DEFAULTS ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$values[ $key ] = $request[ $key ];
			}
		}

		$settings = Settings::update( $values );
		Meta_Filter::reset();

		return rest_ensure_response( $settings );
	}
}
