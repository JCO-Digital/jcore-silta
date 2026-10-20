<?php
/**
 * REST routes for sync jobs.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Rest;

use Jcore\Silta\Capabilities;
use Jcore\Silta\Endpoint\Local;
use Jcore\Silta\Jobs\Jobs;
use Jcore\Silta\Jobs\Runner;
use Jcore\Silta\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, prepares, reviews, runs and undoes jobs.
 */
final class Jobs_Controller extends Controller {

	/**
	 * Item fields the admin can change.
	 */
	private const ITEM_ARGS = array(
		'included'  => array( 'type' => 'boolean' ),
		'confirmed' => array( 'type' => 'boolean' ),
		'sections'  => array(
			'type'  => array( 'array', 'null' ),
			'items' => array( 'type' => 'string' ),
		),
		'override'  => array(
			'type' => array( 'string', 'integer', 'null' ),
		),
	);

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$job = '/jobs/(?P<id>\d+)';

		register_rest_route(
			$this->namespace,
			'/jobs',
			array(
				$this->sync_route(
					WP_REST_Server::READABLE,
					'list_jobs',
					array(
						'page' => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					)
				),
				$this->sync_route(
					WP_REST_Server::CREATABLE,
					'create_job',
					array(
						'connection' => array(
							'type'     => 'string',
							'required' => true,
						),
						'direction'  => array(
							'type'     => 'string',
							'enum'     => array( 'push', 'pull' ),
							'required' => true,
						),
						'selection'  => array(
							'type'       => 'object',
							'required'   => true,
							'properties' => array(
								'posts'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'integer' ),
								),
								'post_types' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'menus'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'integer' ),
								),
								'forms'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'integer' ),
								),
							),
						),
						'options'    => array(
							'type'       => 'object',
							'default'    => array(),
							'properties' => array(
								'status_mode'  => array(
									'type' => 'string',
									'enum' => array( 'source', 'draft', 'keep' ),
								),
								'diff'         => array( 'type' => 'boolean' ),
								'dependencies' => array( 'type' => 'boolean' ),
								'save_rules'   => array( 'type' => 'boolean' ),
								'rules'        => array(
									'type'  => 'array',
									'items' => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
								),
							),
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$job,
			array(
				$this->sync_route( WP_REST_Server::READABLE, 'get_job' ),
				$this->sync_route( WP_REST_Server::DELETABLE, 'delete_job' ),
			)
		);

		foreach ( array( 'prepare', 'expand', 'run', 'undo', 'cancel' ) as $action ) {
			register_rest_route(
				$this->namespace,
				$job . '/' . $action,
				array( $this->sync_route( WP_REST_Server::CREATABLE, $action . '_job' ) )
			);
		}

		register_rest_route(
			$this->namespace,
			$job . '/items',
			array(
				$this->sync_route(
					WP_REST_Server::READABLE,
					'list_items',
					array(
						'level'    => array( 'type' => 'integer' ),
						'type'     => array(
							'type' => 'string',
							'enum' => array( 'post', 'term', 'menu', 'form', 'orphan' ),
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 200,
						),
					)
				),
				$this->sync_route(
					WP_REST_Server::EDITABLE,
					'patch_items',
					array(
						'ids'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'included'  => array( 'type' => 'boolean' ),
						'confirmed' => array( 'type' => 'boolean' ),
						'sections'  => self::ITEM_ARGS['sections'],
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$job . '/items/(?P<item>\d+)',
			array( $this->sync_route( WP_REST_Server::EDITABLE, 'patch_item', self::ITEM_ARGS ) )
		);

		register_rest_route(
			$this->namespace,
			$job . '/items/(?P<item>\d+)/diff',
			array( $this->sync_route( WP_REST_Server::READABLE, 'diff_item' ) )
		);
	}

	/**
	 * Handles GET /jobs: the history.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function list_jobs( WP_REST_Request $request ): WP_REST_Response {
		$list = Jobs::list( (int) $request['page'], 20, Capabilities::unrestricted() ? null : get_current_user_id() );

		return rest_ensure_response(
			array(
				'jobs'  => array_map( array( Runner::class, 'public_job' ), $list['jobs'] ),
				'total' => $list['total'],
				'pages' => (int) ceil( $list['total'] / 20 ),
			)
		);
	}

	/**
	 * Handles POST /jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = Runner::create(
			(string) $request['connection'],
			(string) $request['direction'],
			(array) $request['selection'],
			(array) $request['options']
		);

		return $this->respond( is_wp_error( $job ) ? $job : Runner::public_job( $job ) );
	}

	/**
	 * Handles GET /jobs/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = Jobs::get( (int) $request['id'] );
		if ( null === $job || ! Jobs::user_permission( $job ) ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such sync.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		if ( 'target' === $job['role'] ) {
			return rest_ensure_response( array( 'job' => Runner::public_job( $job ) ) );
		}

		$runner = Runner::load( (int) $request['id'] );

		return $this->respond( is_wp_error( $runner ) ? $runner : $runner->progress() );
	}

	/**
	 * Handles DELETE /jobs/{id}: drops a job that never ran.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = Jobs::get( (int) $request['id'] );
		if ( null === $job || ! Jobs::user_permission( $job ) ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such sync.', 'jcore-silta' ), array( 'status' => 404 ) );
		}
		$never_ran = in_array( $job['status'], array( 'preparing', 'ready' ), true )
			|| ( 'cancelled' === $job['status'] && in_array( $job['state']['cancelled_from'] ?? '', array( 'preparing', 'ready' ), true ) );
		if ( ! $never_ran ) {
			return new WP_Error( 'jcore_silta_keep', __( 'A sync that has written something stays in the history.', 'jcore-silta' ), array( 'status' => 409 ) );
		}

		Jobs::delete( (int) $job['id'] );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Handles POST /jobs/{id}/prepare.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function prepare_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$runner = Runner::load( (int) $request['id'] );

		return $this->respond( is_wp_error( $runner ) ? $runner : $runner->prepare( self::budget() ) );
	}

	/**
	 * Handles POST /jobs/{id}/expand.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function expand_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$runner = Runner::load( (int) $request['id'] );

		return $this->respond( is_wp_error( $runner ) ? $runner : $runner->expand() );
	}

	/**
	 * Handles POST /jobs/{id}/run.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$runner = Runner::load( (int) $request['id'] );

		return $this->respond( is_wp_error( $runner ) ? $runner : $runner->run( self::budget() ) );
	}

	/**
	 * Handles POST /jobs/{id}/undo. Works for syncs started here and for syncs
	 * other sites pushed here.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = Jobs::get( (int) $request['id'] );
		if ( null === $job || ! Jobs::user_permission( $job ) ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such sync.', 'jcore-silta' ), array( 'status' => 404 ) );
		}
		if ( 'undone' === $job['status'] ) {
			return new WP_Error( 'jcore_silta_undone', __( 'This sync has already been undone.', 'jcore-silta' ), array( 'status' => 409 ) );
		}

		if ( 'target' === $job['role'] ) {
			return $this->respond( ( new Local( (string) $job['remote_site'] ) )->undo( array( 'job' => $job['uuid'] ) ) );
		}

		$runner = Runner::load( (int) $job['id'] );

		return $this->respond( is_wp_error( $runner ) ? $runner : $runner->undo() );
	}

	/**
	 * Handles POST /jobs/{id}/cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$runner = Runner::load( (int) $request['id'] );
		if ( is_wp_error( $runner ) ) {
			return $this->respond( $runner );
		}

		$cancelled = $runner->cancel();
		if ( is_wp_error( $cancelled ) ) {
			return $this->respond( $cancelled );
		}

		return rest_ensure_response( $runner->progress() );
	}

	/**
	 * Handles GET /jobs/{id}/items.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = Jobs::get( (int) $request['id'] );
		if ( null === $job || ! Jobs::user_permission( $job ) ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such sync.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$where = array();
		if ( $request->has_param( 'level' ) ) {
			$where['level'] = (int) $request['level'];
		}
		if ( $request->has_param( 'type' ) ) {
			$where['object_type'] = (string) $request['type'];
		}

		$per_page = (int) $request['per_page'];

		return rest_ensure_response(
			array(
				'items' => Jobs::items(
					(int) $job['id'],
					$where,
					array(
						'limit'  => $per_page,
						'offset' => ( (int) $request['page'] - 1 ) * $per_page,
					)
				),
				'total' => Jobs::count_items( (int) $job['id'], $where ),
			)
		);
	}

	/**
	 * Handles PATCH /jobs/{id}/items: changes several items at once.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function patch_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = $this->editable_job( (int) $request['id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$changes = $this->item_changes( $request );
		$where   = array();
		if ( $request->has_param( 'ids' ) ) {
			$where['id'] = array_map( 'intval', (array) $request['ids'] );
		}
		if ( ! empty( $changes ) ) {
			Jobs::update_items( (int) $job['id'], $where, $changes );
		}

		return rest_ensure_response( array( 'updated' => true ) );
	}

	/**
	 * Handles PATCH /jobs/{id}/items/{item}.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function patch_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = $this->editable_job( (int) $request['id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$item = Jobs::item( (int) $job['id'], (int) $request['item'] );
		if ( null === $item ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such item.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$changes = $this->item_changes( $request );
		if ( $request->has_param( 'override' ) ) {
			$override            = $request['override'];
			$changes['override'] = null === $override || '' === $override ? null : array( 'target' => 'create' === $override ? 'create' : (int) $override );

			// On a push the target post is on the other site, which writes it as its
			// pairing admin and shows its current content in the diff. Restricted
			// users get the automatic match or a new copy, never a post they pick.
			if ( 'push' === $job['direction'] && is_int( $changes['override']['target'] ?? null ) && ! Capabilities::unrestricted() ) {
				return new WP_Error( 'jcore_silta_forbidden', __( 'Only an administrator can choose which post on the other site a push overwrites.', 'jcore-silta' ), array( 'status' => 403 ) );
			}
		}
		if ( ! empty( $changes ) ) {
			Jobs::update_item( (int) $item['id'], $changes );
		}

		if ( $request->has_param( 'override' ) ) {
			$runner = Runner::load( (int) $job['id'] );
			if ( is_wp_error( $runner ) ) {
				return $this->respond( $runner );
			}

			$replanned = $runner->replan( (int) $item['id'] );
			if ( is_array( $replanned ) ) {
				unset( $replanned['payload'] );
			}

			return $this->respond( $replanned );
		}

		$item = Jobs::item( (int) $job['id'], (int) $item['id'] );
		unset( $item['payload'] );

		return rest_ensure_response( $item );
	}

	/**
	 * Handles GET /jobs/{id}/items/{item}/diff.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function diff_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$runner = Runner::load( (int) $request['id'] );

		if ( is_wp_error( $runner ) ) {
			return $runner;
		}
		$permission = $runner->item_permission( (int) $request['item'] );

		return $this->respond( is_wp_error( $permission ) ? $permission : $runner->diff( (int) $request['item'] ) );
	}

	/**
	 * Returns a job whose items may still change.
	 *
	 * @param int $id Job ID.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function editable_job( int $id ): array|WP_Error {
		$job = Jobs::get( $id );
		if ( null === $job || 'initiator' !== $job['role'] || ! Jobs::user_permission( $job ) ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such sync.', 'jcore-silta' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $job['status'], array( 'preparing', 'ready' ), true ) ) {
			return new WP_Error( 'jcore_silta_locked', __( 'This sync has already started; its selection can no longer change.', 'jcore-silta' ), array( 'status' => 409 ) );
		}

		return $job;
	}

	/**
	 * Collects the item changes in a request.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array<string, mixed>
	 */
	private function item_changes( WP_REST_Request $request ): array {
		$changes = array();
		foreach ( array( 'included', 'confirmed' ) as $flag ) {
			if ( $request->has_param( $flag ) ) {
				$changes[ $flag ] = (bool) $request[ $flag ];
			}
		}
		if ( $request->has_param( 'sections' ) ) {
			$sections            = $request['sections'];
			$changes['sections'] = null === $sections ? null : array_values( array_map( 'strval', (array) $sections ) );
		}

		return $changes;
	}

	/**
	 * Seconds one admin request may spend on a job.
	 *
	 * @return float
	 */
	private static function budget(): float {
		return (float) Settings::get( 'request_budget' );
	}
}
