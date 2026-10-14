<?php
/**
 * Drives a sync job.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Jobs;

use Jcore\Silta\Capabilities;
use Jcore\Silta\Database;
use Jcore\Silta\Endpoint\Endpoint;
use Jcore\Silta\Endpoint\Local;
use Jcore\Silta\Endpoint\Remote;
use Jcore\Silta\Remote\Connections;
use Jcore\Silta\Settings;
use Jcore\Silta\Site;
use Jcore\Silta\Sync\Context;
use Jcore\Silta\Util;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a job on the initiating site in time-boxed steps, so the admin screen
 * can drive it request by request (with a progress bar) and WP-CLI can drive it
 * in a loop. All state lives in the job row, so a job resumes where it stopped.
 *
 * Preparing: resolve the selection into items → export and plan them in
 * batches → add the first level of missing dependencies → find orphans → ready.
 * Running: transfer media → import in batches (terms, media, posts, menus;
 * dependencies first) → re-import items whose references were created later in
 * the job → trash the chosen orphans → done.
 */
final class Runner {

	/**
	 * Import order of object kinds.
	 */
	private const ORDER = array(
		'term'       => 0,
		'attachment' => 1,
		'post'       => 2,
		'menu'       => 3,
	);

	/**
	 * The job.
	 *
	 * @var array<string, mixed>
	 */
	private array $job;

	/**
	 * The connection the job runs over.
	 *
	 * @var array<string, mixed>
	 */
	private array $connection;

	/**
	 * Where content comes from.
	 *
	 * @var Endpoint
	 */
	private Endpoint $source;

	/**
	 * Where content goes.
	 *
	 * @var Endpoint
	 */
	private Endpoint $target;

	/**
	 * Creates a runner.
	 *
	 * @param array<string, mixed> $job        Job.
	 * @param array<string, mixed> $connection Connection.
	 */
	private function __construct( array $job, array $connection ) {
		$this->job        = $job;
		$this->connection = $connection;

		$remote = new Remote( $connection );
		$local  = new Local( (string) $connection['site'], true );

		$this->source = 'push' === $job['direction'] ? $local : $remote;
		$this->target = 'push' === $job['direction'] ? $remote : $local;
	}

	/**
	 * Creates a job.
	 *
	 * @param string               $connection_id Connection ID.
	 * @param string               $direction     push or pull.
	 * @param array<string, mixed> $selection     posts, post_types, menus, media.
	 * @param array<string, mixed> $options       status_mode, diff, rules, save_rules, dependencies.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( string $connection_id, string $direction, array $selection, array $options ): array|WP_Error {
		if ( ! empty( $options['save_rules'] ) && ! Capabilities::unrestricted() ) {
			return new WP_Error( 'jcore_silta_forbidden', __( 'Only an administrator can save shared replacement rules.', 'jcore-silta' ), array( 'status' => 403 ) );
		}

		$connection = Connections::get( $connection_id );
		if ( null === $connection ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such connection.', 'jcore-silta' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $direction, array( 'push', 'pull' ), true ) ) {
			return new WP_Error( 'jcore_silta_direction', __( 'Choose push or pull.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		// Refreshes the granted scopes and refuses a remote running an
		// incompatible protocol version.
		$handshake = Connections::handshake( $connection_id );
		if ( is_wp_error( $handshake ) ) {
			return $handshake;
		}
		$connection = (array) Connections::get( $connection_id );

		$scope = 'push' === $direction ? 'write' : 'read';
		if ( ! in_array( $scope, (array) $connection['scopes'], true ) ) {
			return new WP_Error(
				'jcore_silta_scope',
				'push' === $direction
					? __( 'The other site does not allow this site to push to it.', 'jcore-silta' )
					: __( 'The other site does not allow this site to pull from it.', 'jcore-silta' ),
				array( 'status' => 403 )
			);
		}

		$selection = array(
			'posts'      => array_values( array_unique( array_map( 'intval', (array) ( $selection['posts'] ?? array() ) ) ) ),
			'post_types' => array_values( array_unique( array_map( 'sanitize_key', (array) ( $selection['post_types'] ?? array() ) ) ) ),
			'menus'      => array_values( array_unique( array_map( 'intval', (array) ( $selection['menus'] ?? array() ) ) ) ),
		);
		if ( empty( $selection['posts'] ) && empty( $selection['post_types'] ) && empty( $selection['menus'] ) ) {
			return new WP_Error( 'jcore_silta_empty', __( 'Select something to sync.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		$rules = Connections::clean_rules( (array) ( $options['rules'] ?? $connection['rules'] ?? array() ) );
		if ( ! empty( $options['save_rules'] ) ) {
			Connections::update( $connection_id, array( 'rules' => $rules ) );
		}

		return Jobs::create(
			array(
				'role'         => 'initiator',
				'direction'    => $direction,
				'connection'   => $connection_id,
				'remote_site'  => (string) $connection['site'],
				'remote_label' => (string) $connection['label'],
				'status'       => 'preparing',
				'options'      => array(
					'selection'    => $selection,
					'status_mode'  => in_array( $options['status_mode'] ?? '', array( 'source', 'draft', 'keep' ), true ) ? $options['status_mode'] : 'source',
					'diff'         => ! empty( $options['diff'] ),
					'rules'        => $rules,
					'dependencies' => ! isset( $options['dependencies'] ) || ! empty( $options['dependencies'] ),
				),
				'state'        => array(
					'phase' => 'resolve',
					'level' => 0,
				),
			)
		);
	}

	/**
	 * Loads the runner for a job.
	 *
	 * @param int|string $id Job ID or UUID.
	 *
	 * @return self|WP_Error
	 */
	public static function load( int|string $id ): self|WP_Error {
		$job = Jobs::get( $id );
		if ( null === $job || 'initiator' !== $job['role'] || ! Jobs::user_permission( $job ) ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such sync.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$connection = Connections::get( (string) $job['connection'] );
		if ( null === $connection ) {
			return new WP_Error( 'jcore_silta_no_connection', __( 'The connection this sync used has been removed.', 'jcore-silta' ), array( 'status' => 409 ) );
		}

		return new self( $job, $connection );
	}

	/**
	 * Returns the job.
	 *
	 * @return array<string, mixed>
	 */
	public function job(): array {
		return $this->job;
	}

	/**
	 * Prepares the job for as long as the budget allows.
	 *
	 * @param float $budget Seconds.
	 *
	 * @return array<string, mixed>|WP_Error Progress.
	 */
	public function prepare( float $budget ): array|WP_Error {
		if ( ! in_array( $this->job['status'], array( 'preparing', 'ready' ), true ) ) {
			return $this->progress();
		}

		$deadline = microtime( true ) + $budget;
		$state    = $this->job['state'];

		while ( microtime( true ) < $deadline ) {
			$phase = (string) ( $state['phase'] ?? 'resolve' );

			$result = match ( $phase ) {
				'resolve' => $this->resolve(),
				'plan' => $this->plan_batch( $state ),
				'deps' => $this->dependencies( $state ),
				'orphans' => $this->orphans( $state ),
				default => 'ready',
			};

			if ( is_wp_error( $result ) ) {
				$state['error'] = $result->get_error_message();
				$this->save( array( 'state' => $state ) );

				return $result;
			}

			unset( $state['error'] );
			if ( 'ready' === $result ) {
				$state['phase'] = 'ready';
				$this->save(
					array(
						'state'  => $state,
						'status' => 'ready',
					)
				);
				break;
			}

			$state['phase'] = $result;
			$this->save( array( 'state' => $state ) );
		}

		return $this->progress();
	}

	/**
	 * Adds the next level of missing dependencies and goes back to planning.
	 *
	 * @return array<string, mixed>|WP_Error Progress.
	 */
	public function expand(): array|WP_Error {
		$state   = $this->job['state'];
		$level   = (int) ( $state['level'] ?? 0 );
		$missing = $this->missing( $level );
		if ( empty( $missing ) ) {
			return $this->progress();
		}

		$this->add_dependencies( $missing, $level + 1 );
		$state['level'] = $level + 1;
		$state['phase'] = 'plan';
		unset( $state['next_dependencies'] );
		$this->save(
			array(
				'state'  => $state,
				'status' => 'preparing',
			)
		);

		return $this->progress();
	}

	/**
	 * Plans one item again, e.g. after its match was overridden.
	 *
	 * @param int $item_id Item ID.
	 *
	 * @return array<string, mixed>|WP_Error The item.
	 */
	public function replan( int $item_id ): array|WP_Error {
		$item = Jobs::item( (int) $this->job['id'], $item_id );
		if ( null === $item || empty( $item['payload'] ) ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such item.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$permission = $this->item_permission( $item_id );
		if ( is_wp_error( $permission ) ) {
			$this->deny_item( $item, $permission->get_error_message() );

			return $permission;
		}

		$response = $this->target->plan(
			array(
				'bundle'  => $this->bundle( array( $item ) ),
				'options' => $this->plan_options( array( $item ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->store_plan( $item, (array) ( $response['plans'][ $item['key'] ] ?? array() ) );

		return (array) Jobs::item( (int) $this->job['id'], $item_id );
	}

	/**
	 * Returns the full diff of one item.
	 *
	 * @param int $item_id Item ID.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function diff( int $item_id ): array|WP_Error {
		$item = Jobs::item( (int) $this->job['id'], $item_id );
		if ( null === $item || empty( $item['payload'] ) ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such item.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		return $this->target->diff(
			array(
				'bundle'  => $this->bundle( array( $item ) ),
				'options' => $this->plan_options( array( $item ) ),
				'key'     => $item['key'],
			)
		);
	}

	/**
	 * Runs the job for as long as the budget allows.
	 *
	 * @param float $budget Seconds.
	 *
	 * @return array<string, mixed>|WP_Error Progress.
	 */
	public function run( float $budget ): array|WP_Error {
		if ( ! in_array( $this->job['status'], array( 'ready', 'running' ), true ) ) {
			return new WP_Error( 'jcore_silta_not_ready', __( 'The sync is not ready to run.', 'jcore-silta' ), array( 'status' => 409 ) );
		}

		// Capabilities can change between planning and running.
		if ( ! Capabilities::unrestricted() ) {
			$this->permitted_items(
				Jobs::items(
					(int) $this->job['id'],
					array(
						'included'    => 1,
						'status'      => array( 'planned', 'transferred' ),
						'object_type' => array( 'post', 'term', 'menu' ),
					),
					array( 'payload' => true )
				)
			);
		}

		$deadline = microtime( true ) + $budget;
		$state    = $this->job['state'];
		if ( isset( $state['media']['item'] ) && is_wp_error( $this->item_permission( (int) $state['media']['item'] ) ) ) {
			unset( $state['media'] );
		}
		$this->save(
			array(
				'status' => 'running',
				'state'  => $state,
			)
		);

		while ( microtime( true ) < $deadline ) {
			$phase = (string) ( $state['run'] ?? 'media' );

			$result = match ( $phase ) {
				'media' => $this->media_step( $state, $deadline ),
				'import' => $this->media_import_batch( $state, $deadline ),
				'fixup' => $this->fixup_batch(),
				'trash' => $this->trash(),
				default => 'done',
			};

			if ( is_wp_error( $result ) ) {
				$state['error'] = $result->get_error_message();
				$this->save( array( 'state' => $state ) );

				return $result;
			}
			unset( $state['error'] );

			if ( true === $result ) {
				$state['run'] = match ( $phase ) {
					'media' => 'import',
					'import' => 'fixup',
					'fixup' => 'trash',
					default => 'done',
				};
			}

			if ( 'done' === ( $state['run'] ?? '' ) || 'done' === $result ) {
				$state['run'] = 'done';
				$this->save(
					array(
						'state'   => $state,
						'status'  => 'done',
						'summary' => $this->summary(),
					)
				);
				break;
			}

			$this->save( array( 'state' => $state ) );
		}

		return $this->progress();
	}

	/**
	 * Undoes the job on the target.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function undo(): array|WP_Error {
		if ( ! in_array( $this->job['status'], array( 'done', 'running', 'failed', 'cancelled' ), true ) ) {
			return new WP_Error( 'jcore_silta_not_undoable', __( 'Only syncs that have written something can be undone.', 'jcore-silta' ), array( 'status' => 409 ) );
		}

		if ( 'pull' === $this->job['direction'] && ! Capabilities::unrestricted() ) {
			$permission = $this->undo_permission();
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}
		}

		$result = $this->target->undo( array( 'job' => $this->job['uuid'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save( array( 'status' => 'undone' ) );

		return $result;
	}

	/**
	 * Checks all local snapshots before undo, which restores the job as a whole.
	 *
	 * @return true|WP_Error
	 */
	private function undo_permission(): bool|WP_Error {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Snapshot ownership and capabilities must be current.
			$wpdb->prepare( 'SELECT object_type, local_id, action FROM %i WHERE job_uuid = %s', Database::table( 'snapshots' ), $this->job['uuid'] ),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['local_id'];
			if ( 'post' === $row['object_type'] ) {
				$allowed = current_user_can( 'create' === $row['action'] ? 'delete_post' : 'edit_post', $id );
			} elseif ( 'menu' === $row['object_type'] ) {
				$allowed = current_user_can( 'edit_theme_options' );
			} else {
				$term     = get_term( $id );
				$taxonomy = $term instanceof \WP_Term ? get_taxonomy( $term->taxonomy ) : false;
				$allowed  = false !== $taxonomy && current_user_can( 'create' === $row['action'] ? $taxonomy->cap->delete_terms : $taxonomy->cap->edit_terms );
			}
			if ( ! $allowed ) {
				return new WP_Error( 'jcore_silta_forbidden', __( 'You no longer have permission to undo every object in this sync. Ask an administrator to undo it.', 'jcore-silta' ), array( 'status' => 403 ) );
			}
		}

		return true;
	}

	/**
	 * Cancels the job. What was already written stays, and can be undone.
	 *
	 * @return void
	 */
	public function cancel(): void {
		$this->save( array( 'status' => 'cancelled' ) );
	}

	/**
	 * Reports the job's progress.
	 *
	 * @return array<string, mixed>
	 */
	public function progress(): array {
		$job_id = (int) $this->job['id'];
		$counts = Jobs::status_counts( $job_id );

		return array(
			'job'    => self::public_job( $this->job ),
			'counts' => $counts,
			'total'  => Jobs::count_items(
				$job_id,
				array(
					'included'    => 1,
					'object_type' => array( 'post', 'term', 'menu', 'orphan' ),
				)
			),
		);
	}

	/**
	 * Returns a job as the admin screen sees it.
	 *
	 * @param array<string, mixed> $job Job.
	 *
	 * @return array<string, mixed>
	 */
	public static function public_job( array $job ): array {
		$user = get_userdata( (int) $job['user_id'] );

		return array(
			'id'           => (int) $job['id'],
			'uuid'         => (string) $job['uuid'],
			'role'         => (string) $job['role'],
			'direction'    => (string) $job['direction'],
			'connection'   => (string) $job['connection'],
			'remote_label' => (string) $job['remote_label'],
			'status'       => (string) $job['status'],
			'phase'        => (string) ( 'running' === $job['status'] || 'done' === $job['status'] ? ( $job['state']['run'] ?? 'media' ) : ( $job['state']['phase'] ?? '' ) ),
			'level'        => (int) ( $job['state']['level'] ?? 0 ),
			'next'         => (int) ( $job['state']['next_dependencies'] ?? 0 ),
			'error'        => (string) ( $job['state']['error'] ?? '' ),
			'options'      => $job['options'],
			'summary'      => $job['summary'],
			'user'         => $user ? $user->display_name : (string) ( $job['options']['by'] ?? '' ),
			'created_at'   => (string) $job['created_at'],
			'updated_at'   => (string) $job['updated_at'],
		);
	}

	/**
	 * Turns the selection into items.
	 *
	 * @return string|WP_Error Next phase.
	 */
	private function resolve(): string|WP_Error {
		$selection = (array) $this->job['options']['selection'];
		$items     = array();

		foreach ( (array) $selection['posts'] as $id ) {
			$items[] = array(
				'object_type' => 'post',
				'source_id'   => (int) $id,
			);
		}
		foreach ( (array) $selection['menus'] as $id ) {
			$items[] = array(
				'object_type' => 'menu',
				'source_id'   => (int) $id,
				'position'    => self::ORDER['menu'] * 100000,
			);
		}
		foreach ( (array) $selection['post_types'] as $post_type ) {
			$response = $this->source->ids( array( 'post_type' => $post_type ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			foreach ( (array) ( $response['ids'] ?? array() ) as $id ) {
				$items[] = array(
					'object_type' => 'post',
					'source_id'   => (int) $id,
					'subtype'     => $post_type,
				);
			}
		}

		Jobs::add_items( (int) $this->job['id'], $items );

		return 'plan';
	}

	/**
	 * Exports and plans the next batch of pending items at the current level.
	 *
	 * @param array<string, mixed> $state Job state.
	 *
	 * @return string|WP_Error Next phase.
	 */
	private function plan_batch( array &$state ): string|WP_Error {
		$level = (int) ( $state['level'] ?? 0 );
		$items = Jobs::items(
			(int) $this->job['id'],
			array(
				'status' => 'pending',
				'level'  => $level,
			),
			array( 'limit' => $this->batch_size() )
		);
		if ( empty( $items ) ) {
			return 'deps';
		}

		if ( 'push' === $this->job['direction'] ) {
			$items = array_values(
				array_filter(
					$items,
					function ( array $item ): bool {
						if ( self::can_export( (string) $item['object_type'], (int) $item['source_id'] ) ) {
							return true;
						}
						$this->deny_item( $item, __( 'You do not have permission to export this object.', 'jcore-silta' ) );

						return false;
					}
				)
			);
		}
		if ( empty( $items ) ) {
			return 'plan';
		}

		$bundle = $this->source->export( array( 'keys' => array_column( $items, 'key' ) ) );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		if ( empty( $state['source_site'] ) ) {
			// bundle() reads it from the job, which is only saved after this step.
			$state['source_site']              = $bundle['site'] ?? array();
			$this->job['state']['source_site'] = $state['source_site'];
		}

		if ( 'push' === $this->job['direction'] && ! Capabilities::unrestricted() ) {
			foreach ( (array) ( $bundle['stubs'] ?? array() ) as $key => $stub ) {
				list( $type, $id ) = Util::split_key( (string) $key );
				if ( ! self::can_export( $type, $id ) ) {
					unset( $bundle['stubs'][ $key ] );
				}
			}
		}

		$objects = self::requested_records( (array) ( $bundle['objects'] ?? array() ), $items );
		$stubs   = array_filter(
			(array) ( $bundle['stubs'] ?? array() ),
			static fn( $stub, $key ): bool => null === Context::record_error( $stub, true ) && $key === $stub['key'],
			ARRAY_FILTER_USE_BOTH
		);

		$planned = array();
		foreach ( $items as $item ) {
			$record = $objects[ $item['key'] ] ?? null;
			if ( null === $record ) {
				Jobs::update_item(
					(int) $item['id'],
					array(
						'status'   => 'failed',
						'included' => false,
						'result'   => array( 'error' => __( 'The source did not return a valid record for this requested object.', 'jcore-silta' ) ),
					)
				);
				continue;
			}

			$payload = array(
				'object'     => $record,
				'stubs'      => array_intersect_key( $stubs, array_flip( (array) ( $record['refs'] ?? array() ) ) ),
				'users'      => (array) ( $bundle['users'] ?? array() ),
				'acf_fields' => (array) ( $bundle['acf_fields'] ?? array() ),
			);
			Jobs::update_item(
				(int) $item['id'],
				array(
					'payload'  => $payload,
					'title'    => (string) ( $record['title'] ?? '' ),
					'subtype'  => (string) ( $record['post_type'] ?? $record['taxonomy'] ?? 'nav_menu' ),
					'position' => self::position( $record, $level ),
				)
			);
			$item['payload'] = $payload;
			$planned[]       = $item;
		}

		$planned = $this->fit_items( $this->permitted_items( $planned ) );
		if ( empty( $planned ) ) {
			return 'plan';
		}

		$response = $this->target->plan(
			array(
				'bundle'  => $this->bundle( $planned ),
				'options' => $this->plan_options( $planned ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		foreach ( $planned as $item ) {
			$this->store_plan( $item, (array) ( $response['plans'][ $item['key'] ] ?? array() ) );
		}

		return 'plan';
	}

	/**
	 * Adds the first level of missing dependencies, or counts the next level
	 * for the admin to add.
	 *
	 * @param array<string, mixed> $state Job state.
	 *
	 * @return string Next phase.
	 */
	private function dependencies( array &$state ): string {
		$level   = (int) ( $state['level'] ?? 0 );
		$missing = $this->missing( $level );

		if ( ! empty( $missing ) && 0 === $level && ! empty( $this->job['options']['dependencies'] ) ) {
			$this->add_dependencies( $missing, 1 );
			$state['level'] = 1;

			return 'plan';
		}

		$state['next_dependencies'] = count( $missing );

		return 'orphans';
	}

	/**
	 * Finds posts on the target synced from the source whose originals are gone,
	 * for every post type selected as a whole.
	 *
	 * @param array<string, mixed> $state Job state.
	 *
	 * @return string|WP_Error Next phase.
	 */
	private function orphans( array &$state ): string|WP_Error {
		if ( ! empty( $state['orphans_done'] ) ) {
			return 'ready';
		}

		foreach ( (array) $this->job['options']['selection']['post_types'] as $post_type ) {
			$ids = array_column(
				Jobs::items(
					(int) $this->job['id'],
					array(
						'object_type' => 'post',
						'subtype'     => $post_type,
					)
				),
				'source_id'
			);

			$response = $this->target->orphans(
				array(
					'post_type' => $post_type,
					'ids'       => $ids,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$items = array();
			foreach ( (array) ( $response['orphans'] ?? array() ) as $orphan ) {
				$items[] = array(
					'object_type' => 'orphan',
					'source_id'   => (int) $orphan['id'],
					'subtype'     => $post_type,
					'title'       => (string) $orphan['title'],
					'level'       => 0,
					'included'    => 0,
					'status'      => 'planned',
					'action'      => 'trash',
					'target_id'   => (int) $orphan['id'],
				);
			}
			Jobs::add_items( (int) $this->job['id'], $items );
		}

		$state['orphans_done'] = true;

		return 'ready';
	}

	/**
	 * Transfers the files of the next attachment that needs them.
	 *
	 * @param array<string, mixed> $state    Job state.
	 * @param float                $deadline Microtime to stop at.
	 *
	 * @return bool|WP_Error True when no attachment is left.
	 */
	private function media_step( array &$state, float $deadline ): bool|WP_Error {
		$cursor = $state['media'] ?? null;

		if ( null === $cursor ) {
			$item = $this->next_attachment();
			if ( null === $item ) {
				return true;
			}

			$prepared = $this->target->media_prepare(
				array(
					'bundle'  => $this->bundle( array( $item ) ),
					'options' => $this->import_options( array( $item ) ),
				)
			);
			if ( is_wp_error( $prepared ) ) {
				Jobs::update_item(
					(int) $item['id'],
					array(
						'status' => 'failed',
						'result' => array( 'error' => $prepared->get_error_message() ),
					)
				);

				return false;
			}

			$cursor = array(
				'item'     => (int) $item['id'],
				'source'   => (int) $item['source_id'],
				'transfer' => (string) $prepared['transfer'],
				'files'    => array_values(
					array_filter(
						(array) $prepared['files'],
						static fn( array $file ): bool => ! empty( $file['needed'] )
					)
				),
				'index'    => 0,
				'offset'   => 0,
			);
		}

		$chunk = \Jcore\Silta\Sync\Media::chunk_size();
		$files = count( $cursor['files'] );
		while ( $cursor['index'] < $files && microtime( true ) < $deadline ) {
			$file = $cursor['files'][ $cursor['index'] ];

			$read = $this->source->media_read(
				array(
					'id'     => $cursor['source'],
					'path'   => $file['path'],
					'offset' => $cursor['offset'],
					'length' => $chunk,
				)
			);
			if ( is_wp_error( $read ) ) {
				$state['media'] = $cursor;

				return $read;
			}

			$written = $this->target->media_write(
				array(
					'transfer' => $cursor['transfer'],
					'id'       => $cursor['source'],
					'path'     => $file['path'],
					'offset'   => $cursor['offset'],
					'data'     => (string) $read['data'],
				)
			);
			if ( is_wp_error( $written ) ) {
				if ( 'jcore_silta_transfer_expired' === $written->get_error_code() ) {
					unset( $state['media'] );
					$item = Jobs::item( (int) $this->job['id'], (int) $cursor['item'] );
					if ( null !== $item ) {
						$this->resend( $item, $written->get_error_message() );
					}

					return false;
				}
				$state['media'] = $cursor;

				return $written;
			}

			if ( ! empty( $written['complete'] ) ) {
				++$cursor['index'];
				$cursor['offset'] = 0;
			} else {
				$cursor['offset'] = (int) $written['received'];
			}
		}

		if ( $cursor['index'] >= $files ) {
			$item = Jobs::item( (int) $this->job['id'], (int) $cursor['item'] );
			Jobs::update_item(
				(int) $cursor['item'],
				array(
					'status' => 'transferred',
					'result' => array_merge( (array) ( $item['result'] ?? array() ), array( 'transfer' => $cursor['transfer'] ) ),
				)
			);
			unset( $state['media'] );

			return false;
		}

		$state['media'] = $cursor;

		return false;
	}

	/**
	 * Imports the next batch, after sending the files of any attachment the
	 * target asked to receive again (its transfer expired or was lost).
	 *
	 * @param array<string, mixed> $state    Job state.
	 * @param float                $deadline Microtime to stop at.
	 *
	 * @return bool|WP_Error True when no item is left.
	 */
	private function media_import_batch( array &$state, float $deadline ): bool|WP_Error {
		if ( isset( $state['media'] ) || null !== $this->next_attachment() ) {
			$result = $this->media_step( $state, $deadline );

			return is_wp_error( $result ) ? $result : false;
		}

		return $this->import_batch();
	}

	/**
	 * Imports the next batch of items.
	 *
	 * @return bool|WP_Error True when no item is left.
	 */
	private function import_batch(): bool|WP_Error {
		$items = Jobs::items(
			(int) $this->job['id'],
			array(
				'included'    => 1,
				'status'      => array( 'planned', 'transferred' ),
				'object_type' => array( 'post', 'term', 'menu' ),
			),
			array(
				'limit'   => $this->batch_size(),
				'order'   => 'import',
				'payload' => true,
			)
		);
		$items = array_values( array_filter( $items, static fn( array $item ): bool => ! empty( $item['payload'] ) ) );
		if ( empty( $items ) ) {
			return true;
		}

		// Attachments sort before posts. A batch ends with its last attachment,
		// so no post imports before an attachment it uses has its files, even
		// when that attachment's files have to be sent again.
		$attachments = array_keys( array_column( $items, 'subtype' ), 'attachment', true );
		if ( ! empty( $attachments ) ) {
			$items = array_slice( $items, 0, max( $attachments ) + 1 );
		}

		return $this->import( $items, false );
	}

	/**
	 * Queues an attachment's files to be sent again after the target lost
	 * them. Twice at most, then the item fails, so a host that keeps losing
	 * transfers (uploads not shared between its servers) can't loop.
	 *
	 * @param array<string, mixed> $item    Item.
	 * @param string               $message Why the files are needed again.
	 *
	 * @return bool Whether the files will be sent again.
	 */
	private function resend( array $item, string $message ): bool {
		$resends = (int) ( $item['result']['resends'] ?? 0 );
		if ( $resends >= 2 ) {
			Jobs::update_item(
				(int) $item['id'],
				array(
					'status' => 'failed',
					'result' => array(
						'status'  => 'failed',
						'error'   => $message,
						'resends' => $resends,
					),
				)
			);

			return false;
		}
		Jobs::update_item(
			(int) $item['id'],
			array(
				'status' => 'planned',
				'result' => array( 'resends' => $resends + 1 ),
			)
		);

		return true;
	}

	/**
	 * Imports again the items whose references to other items of the job could
	 * not be resolved the first time, because those were created later.
	 *
	 * @return bool|WP_Error True when no item is left.
	 */
	private function fixup_batch(): bool|WP_Error {
		$job_id   = (int) $this->job['id'];
		$imported = Jobs::items(
			$job_id,
			array(
				'status'      => 'imported',
				'object_type' => array( 'post', 'term', 'menu' ),
			)
		);
		$done     = array_flip( array_column( $imported, 'key' ) );

		$pending = array();
		foreach ( $imported as $item ) {
			$result = (array) $item['result'];
			if ( ! empty( $result['fixed'] ) || empty( $result['unresolved'] ) ) {
				continue;
			}
			if ( empty( array_intersect_key( array_flip( (array) $result['unresolved'] ), $done ) ) ) {
				$result['fixed'] = true;
				Jobs::update_item( (int) $item['id'], array( 'result' => $result ) );
				continue;
			}
			$pending[] = (int) $item['id'];
			if ( count( $pending ) >= $this->batch_size() ) {
				break;
			}
		}

		if ( empty( $pending ) ) {
			return true;
		}

		$items = Jobs::items( $job_id, array( 'id' => $pending ), array( 'payload' => true ) );

		return $this->import( $items, true );
	}

	/**
	 * Trashes the orphans the admin chose.
	 *
	 * @return bool|WP_Error True when done.
	 */
	private function trash(): bool|WP_Error {
		$items = Jobs::items(
			(int) $this->job['id'],
			array(
				'object_type' => 'orphan',
				'included'    => 1,
				'status'      => 'planned',
			),
			array( 'limit' => $this->batch_size() )
		);
		if ( empty( $items ) ) {
			return true;
		}
		if ( 'pull' === $this->job['direction'] && ! Capabilities::unrestricted() ) {
			$items = array_values(
				array_filter(
					$items,
					function ( array $item ): bool {
						if ( current_user_can( 'delete_post', (int) $item['target_id'] ) ) {
							return true;
						}
						$this->deny_item( $item, __( 'You do not have permission to trash this post.', 'jcore-silta' ) );

						return false;
					}
				)
			);
		}
		if ( empty( $items ) ) {
			return false;
		}

		$options          = $this->import_options( array() );
		$options['trash'] = array_column( $items, 'target_id' );

		$response = $this->target->import(
			array(
				'bundle'  => array(
					'site'    => $this->job['state']['source_site'] ?? array(),
					'objects' => array(),
				),
				'options' => $options,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		foreach ( $items as $item ) {
			$result = (array) ( $response['results'][ 'orphan:' . $item['target_id'] ] ?? array() );
			Jobs::update_item(
				(int) $item['id'],
				array(
					'status' => 'failed' === ( $result['status'] ?? '' ) ? 'failed' : 'imported',
					'result' => $result,
				)
			);
		}

		return false;
	}

	/**
	 * Sends items to the target and records the results.
	 *
	 * @param array<int, array<string, mixed>> $items Items with payloads.
	 * @param bool                             $fixup Whether this is the second pass.
	 *
	 * @return bool|WP_Error False: more may follow.
	 */
	private function import( array $items, bool $fixup ): bool|WP_Error {
		$items = $this->fit_items( $this->permitted_items( $items ) );
		if ( empty( $items ) ) {
			return false;
		}
		$options = $this->import_options( $items );
		if ( $fixup ) {
			$options['confirmed'] = array_fill_keys( array_column( $items, 'key' ), true );
			$options['fixup']     = true;
		}

		$response = $this->target->import(
			array(
				'bundle'  => $this->bundle( $items ),
				'options' => $options,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		foreach ( $items as $item ) {
			$result = (array) ( $response['results'][ $item['key'] ] ?? array(
				'status' => 'failed',
				'error'  => __( 'The target did not report on this item.', 'jcore-silta' ),
			) );

			if ( $fixup ) {
				$result['fixed']  = true;
				$result['action'] = $item['result']['action'] ?? ( $result['action'] ?? '' );
			}

			// A lost transfer sends the files again instead of failing the
			// attachment; media_import_batch() sends them before importing.
			if ( ! $fixup && ! empty( $result['resend'] ) && $this->resend( $item, (string) ( $result['error'] ?? '' ) ) ) {
				continue;
			}
			$result['transfer'] = $item['result']['transfer'] ?? null;

			Jobs::update_item(
				(int) $item['id'],
				array(
					'status'    => (string) ( $result['status'] ?? 'failed' ),
					'target_id' => (int) ( $result['local_id'] ?? $item['target_id'] ),
					'result'    => $result,
				)
			);
		}

		return false;
	}

	/**
	 * Returns the next attachment whose files have to be transferred.
	 *
	 * @return array<string, mixed>|null
	 */
	private function next_attachment(): ?array {
		$items = Jobs::items(
			(int) $this->job['id'],
			array(
				'included'    => 1,
				'status'      => 'planned',
				'object_type' => 'post',
				'subtype'     => 'attachment',
				'action'      => array( 'create', 'update' ),
			)
		);

		foreach ( $items as $item ) {
			$changed = (array) ( $item['plan']['changed'] ?? array() );
			$wanted  = null === $item['sections'] || ! $this->job['options']['diff'] || in_array( 'file', (array) $item['sections'], true );
			if ( 'create' === $item['action'] || ( in_array( 'file', $changed, true ) && $wanted ) ) {
				return Jobs::item( (int) $this->job['id'], (int) $item['id'] );
			}
		}

		return null;
	}

	/**
	 * Keeps only well-formed records that match the requested object key, kind
	 * and ID. The job stores and reads them (refs, titles) before the target's
	 * Context sees them, so they get the same structural check here.
	 *
	 * @param array<int, mixed>                $records Export response objects.
	 * @param array<int, array<string, mixed>> $items   Requested items.
	 *
	 * @return array<string, array<string, mixed>> Valid records keyed by object key.
	 */
	public static function requested_records( array $records, array $items ): array {
		$requested = array_column( $items, null, 'key' );
		$valid     = array();
		foreach ( $records as $record ) {
			if ( null !== Context::record_error( $record ) ) {
				continue;
			}
			$item = $requested[ $record['key'] ] ?? null;
			if ( null === $item || $record['type'] !== $item['object_type'] || $record['id'] !== (int) $item['source_id'] || Util::key( $record['type'], $record['id'] ) !== $record['key'] ) {
				continue;
			}
			$valid[ $record['key'] ] = $record;
		}

		return $valid;
	}

	/**
	 * Whether the current user may export a source object.
	 *
	 * @param string $type Object kind.
	 * @param int    $id   Source ID.
	 *
	 * @return bool
	 */
	public static function can_export( string $type, int $id ): bool {
		if ( Capabilities::unrestricted() ) {
			return true;
		}
		if ( 'post' === $type ) {
			return current_user_can( 'read_post', $id );
		}
		if ( 'menu' === $type ) {
			return current_user_can( 'edit_theme_options' );
		}
		$term     = 'term' === $type ? get_term( $id ) : null;
		$taxonomy = $term instanceof \WP_Term ? get_taxonomy( $term->taxonomy ) : null;

		return null !== $taxonomy && false !== $taxonomy && current_user_can( $taxonomy->cap->assign_terms );
	}

	/**
	 * Checks permission for a saved item before exposing its local diff.
	 *
	 * @param int $item_id Item ID.
	 *
	 * @return true|WP_Error
	 */
	public function item_permission( int $item_id ): bool|WP_Error {
		$item = Jobs::item( (int) $this->job['id'], $item_id );
		if ( null === $item || empty( $item['payload']['object'] ) ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such item.', 'jcore-silta' ), array( 'status' => 404 ) );
		}
		if ( ! $this->can_import_item( $item ) ) {
			return new WP_Error( 'jcore_silta_forbidden', __( 'You do not have permission to sync this object with its status, author or terms.', 'jcore-silta' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Checks local object capabilities using the same matching rules as the importer.
	 *
	 * @param array<string, mixed> $item Item with payload.
	 *
	 * @return bool
	 */
	private function can_import_item( array &$item ): bool {
		if ( Capabilities::unrestricted() ) {
			return true;
		}
		if ( 'push' === $this->job['direction'] ) {
			return self::can_export( (string) $item['object_type'], (int) $item['source_id'] );
		}
		// The same bundle (with its user map) and options the import sends, so
		// matching, authors and status come out as the importer sees them.
		$record = (array) ( $item['payload']['object'] ?? array() );
		$ctx    = new Context( $this->bundle( array( $item ) ), $this->plan_options( array( $item ) ), (string) $this->connection['site'] );
		if ( ! isset( $ctx->objects[ $item['key'] ] ) ) {
			return false;
		}
		$match = $ctx->match( $record );
		if ( ! self::can_import_record( $record, null === $match ? null : (int) $match['id'] ) ) {
			return false;
		}
		if ( 'post' === $record['type'] ) {
			$post     = null === $match ? null : get_post( (int) $match['id'] );
			$local    = $post instanceof \WP_Post ? array(
				'fields' => array(
					'post_status' => $post->post_status,
					'post_author' => (int) $post->post_author,
				),
			) : null;
			$incoming = $ctx->transform( $record );
			$status   = $ctx->status( $incoming, $local );

			// Fields the import leaves alone: unselected sections of an existing
			// post, and an attachment's status (new ones are always inherit).
			$selection = ! empty( $this->job['options']['diff'] ) && is_array( $item['sections'] ) ? $item['sections'] : null;
			$written   = static fn( string $field ): bool => null === $local || null === $selection || in_array( 'fields', $selection, true ) || in_array( 'fields.' . $field, $selection, true );
			if ( 'attachment' === $record['post_type'] ) {
				$status = null === $local ? 'inherit' : $local['fields']['post_status'];
			} elseif ( ! $written( 'post_status' ) ) {
				$status = $local['fields']['post_status'];
			}
			// WordPress publishes a scheduled post whose date has passed, and
			// schedules a published one dated in the future.
			$date = $written( 'post_date' ) ? (string) ( $incoming['fields']['post_date_gmt'] ?? '' ) : (string) $post?->post_date_gmt;
			$time = '' !== $date && ! str_starts_with( $date, '0000' ) ? strtotime( $date . ' UTC' ) : false;
			if ( false !== $time && 'future' === $status && $time <= time() ) {
				$status = 'publish';
			} elseif ( false !== $time && 'publish' === $status && $time > time() ) {
				$status = 'future';
			}
			if ( ! $written( 'post_author' ) ) {
				$incoming['fields']['post_author'] = 0;
			}

			if ( ! self::can_write_post( $incoming, $local, $status ) ) {
				return false;
			}
		}
		// Pin the checked match for this request, including an explicit create,
		// so earlier objects in this batch cannot change the matching decision.
		$item['override'] = array( 'target' => null === $match ? 'create' : (int) $match['id'] );

		return true;
	}

	/**
	 * Checks capabilities for a pulled record and its resolved local target.
	 *
	 * @param array<string, mixed> $record    Incoming object.
	 * @param int|null             $target_id Existing target, or null for creation.
	 *
	 * @return bool
	 */
	public static function can_import_record( array $record, ?int $target_id ): bool {
		if ( Capabilities::unrestricted() ) {
			return true;
		}
		if ( 'menu' === ( $record['type'] ?? '' ) ) {
			return current_user_can( 'edit_theme_options' );
		}
		if ( 'term' === ( $record['type'] ?? '' ) ) {
			$taxonomy = get_taxonomy( (string) ( $record['taxonomy'] ?? '' ) );

			return false !== $taxonomy && current_user_can( $taxonomy->cap->assign_terms ) && current_user_can( $taxonomy->cap->edit_terms );
		}
		if ( 'post' !== ( $record['type'] ?? '' ) ) {
			return false;
		}
		$type = get_post_type_object( (string) ( $record['post_type'] ?? '' ) );
		if ( null === $type || ( null !== $target_id ? ! current_user_can( 'edit_post', $target_id ) : ! current_user_can( $type->cap->create_posts ) ) ) {
			return false;
		}
		foreach ( array_keys( (array) ( $record['terms'] ?? array() ) ) as $name ) {
			$taxonomy = get_taxonomy( (string) $name );
			if ( false === $taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the current user may give a pulled post the status and author
	 * the import would write, checked the way the post editor checks them.
	 *
	 * @param array<string, mixed>      $incoming Transformed incoming post.
	 * @param array<string, mixed>|null $local    Current local state, or null when new.
	 * @param string                    $status   Status the import would set.
	 *
	 * @return bool
	 */
	private static function can_write_post( array $incoming, ?array $local, string $status ): bool {
		$type = get_post_type_object( (string) ( $incoming['post_type'] ?? '' ) );
		if ( null === $type ) {
			return false;
		}

		// Publishing, scheduling or making private needs publish_posts, unless
		// the post already has that status (then edit_post covered it).
		$before = (string) ( $local['fields']['post_status'] ?? '' );
		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && $status !== $before && ! current_user_can( $type->cap->publish_posts ) ) {
			return false;
		}

		// An unmatched author leaves an existing post's author alone, and makes
		// the current user the author of a new one.
		$me     = get_current_user_id();
		$author = (int) ( $incoming['fields']['post_author'] ?? 0 );
		$author = $author > 0 ? $author : ( null === $local ? $me : (int) $local['fields']['post_author'] );
		if ( $author !== $me && $author !== (int) ( $local['fields']['post_author'] ?? $me ) && ! current_user_can( $type->cap->edit_others_posts ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Excludes denied items and records a clear per-item error.
	 *
	 * @param array<int, array<string, mixed>> $items Items with payloads.
	 *
	 * @return array<int, array<string, mixed>> Allowed items.
	 */
	private function permitted_items( array $items ): array {
		$allowed = array();
		foreach ( $items as $item ) {
			if ( $this->can_import_item( $item ) ) {
				$allowed[] = $item;
			} else {
				$this->deny_item( $item, __( 'You do not have permission to sync this object with its status, author or terms.', 'jcore-silta' ) );
			}
		}

		return $allowed;
	}

	/**
	 * Records why an item was skipped.
	 *
	 * @param array<string, mixed> $item    Item.
	 * @param string               $message User-facing reason.
	 *
	 * @return void
	 */
	private function deny_item( array $item, string $message ): void {
		Jobs::update_item(
			(int) $item['id'],
			array(
				'status'   => 'failed',
				'included' => false,
				'action'   => 'error',
				'result'   => array(
					'status' => 'failed',
					'error'  => $message,
				),
				'plan'     => array(
					'action' => 'error',
					'errors' => array( '' => $message ),
				),
			)
		);
	}

	/**
	 * Limits batches by both protocol collection caps.
	 *
	 * @return int
	 */
	private function batch_size(): int {
		return max( 1, min( (int) Settings::get( 'batch_size' ), Endpoint::key_limit(), Endpoint::object_limit() ) );
	}

	/**
	 * Splits large bundles at object boundaries and rejects an individually oversized object.
	 *
	 * @param array<int, array<string, mixed>> $items Items with payloads.
	 *
	 * @return array<int, array<string, mixed>> Next request's items.
	 */
	private function fit_items( array $items ): array {
		// Most batches fit as a whole; only measure item by item when not.
		if ( empty( $items ) || $this->request_size( $items ) <= Endpoint::body_limit() ) {
			return $items;
		}

		$fitting = array();
		foreach ( $items as $item ) {
			if ( $this->request_size( array_merge( $fitting, array( $item ) ) ) > Endpoint::body_limit() ) {
				if ( ! empty( $fitting ) ) {
					break;
				}
				$this->deny_item( $item, __( 'This object exceeds the maximum sync request size.', 'jcore-silta' ) );
				continue;
			}
			$fitting[] = $item;
		}

		return $fitting;
	}

	/**
	 * Size of the largest request a batch of items makes: the import with
	 * every item confirmed.
	 *
	 * @param array<int, array<string, mixed>> $items Items with payloads.
	 *
	 * @return int Bytes.
	 */
	private function request_size( array $items ): int {
		$options              = $this->import_options( $items );
		$options['confirmed'] = array_fill_keys( array_column( $items, 'key' ), true );
		$options['fixup']     = true;

		return strlen(
			Util::encode(
				array(
					'bundle'  => $this->bundle( $items ),
					'options' => $options,
				)
			)
		);
	}

	/**
	 * Stores what the target planned for an item.
	 *
	 * @param array<string, mixed> $item Item.
	 * @param array<string, mixed> $plan Plan.
	 *
	 * @return void
	 */
	private function store_plan( array $item, array $plan ): void {
		$action = (string) ( $plan['action'] ?? 'error' );

		Jobs::update_item(
			(int) $item['id'],
			array(
				'status'    => 'planned',
				'action'    => $action,
				'conflict'  => ! empty( $plan['conflict'] ),
				'target_id' => (int) ( $plan['match']['id'] ?? 0 ),
				'included'  => 'error' === $action ? false : (bool) $item['included'],
				'plan'      => $plan,
			)
		);
	}

	/**
	 * Returns the missing dependencies of a level's items that the job doesn't
	 * have yet.
	 *
	 * @param int $level Level.
	 *
	 * @return array<string, array<string, mixed>> Keyed by object key.
	 */
	private function missing( int $level ): array {
		$job_id  = (int) $this->job['id'];
		$known   = array_flip( Jobs::keys( $job_id ) );
		$missing = array();

		$items = Jobs::items(
			$job_id,
			array(
				'level'    => $level,
				'status'   => 'planned',
				'included' => 1,
			)
		);
		foreach ( $items as $item ) {
			foreach ( (array) ( $item['plan']['missing'] ?? array() ) as $dependency ) {
				$key = (string) $dependency['key'];
				if ( ! empty( $dependency['dangling'] ) || isset( $known[ $key ] ) ) {
					continue;
				}
				$missing[ $key ] = $dependency;
			}
		}

		return $missing;
	}

	/**
	 * Adds dependencies as items.
	 *
	 * @param array<string, array<string, mixed>> $missing Dependencies by key.
	 * @param int                                 $level   Level to add them at.
	 *
	 * @return void
	 */
	private function add_dependencies( array $missing, int $level ): void {
		$items = array();
		foreach ( $missing as $key => $dependency ) {
			list( $type, $id ) = Util::split_key( $key );
			$items[]           = array(
				'object_type' => $type,
				'source_id'   => $id,
				'subtype'     => (string) ( $dependency['subtype'] ?? '' ),
				'title'       => (string) ( $dependency['title'] ?? '' ),
				'level'       => $level,
			);
		}

		Jobs::add_items( (int) $this->job['id'], $items );
	}

	/**
	 * Builds a bundle from items' payloads.
	 *
	 * @param array<int, array<string, mixed>> $items Items with payloads.
	 *
	 * @return array<string, mixed>
	 */
	private function bundle( array $items ): array {
		$objects = array();
		$stubs   = array();
		$users   = array();
		$acf     = array();

		foreach ( $items as $item ) {
			$payload   = (array) $item['payload'];
			$objects[] = $payload['object'];
			$stubs    += (array) ( $payload['stubs'] ?? array() );
			$users    += (array) ( $payload['users'] ?? array() );
			$acf      += (array) ( $payload['acf_fields'] ?? array() );
		}

		return array(
			'protocol'   => Site::PROTOCOL,
			'site'       => $this->job['state']['source_site'] ?? array(),
			'objects'    => $objects,
			'stubs'      => $stubs,
			'users'      => $users,
			'acf_fields' => $acf,
		);
	}

	/**
	 * Options for planning.
	 *
	 * @param array<int, array<string, mixed>> $items Items being planned.
	 *
	 * @return array<string, mixed>
	 */
	private function plan_options( array $items ): array {
		$overrides = array();
		foreach ( $items as $item ) {
			$override = $item['override']['target'] ?? null;
			if ( null !== $override ) {
				$overrides[ $item['key'] ] = $override;
			}
		}

		// Only the job's objects these items refer to: the target needs them to
		// tell "created later in this job" from "missing", and a whole-post-type
		// job can hold thousands of keys.
		$in_job = array_flip( Jobs::keys( (int) $this->job['id'] ) );
		$refs   = array();
		foreach ( $items as $item ) {
			foreach ( (array) ( $item['payload']['object']['refs'] ?? array() ) as $ref ) {
				if ( isset( $in_job[ $ref ] ) ) {
					$refs[ $ref ] = true;
				}
			}
		}

		return array(
			'status_mode' => (string) $this->job['options']['status_mode'],
			'rules'       => (array) $this->job['options']['rules'],
			'overrides'   => $overrides,
			'job_refs'    => array_keys( $refs ),
		);
	}

	/**
	 * Options for importing.
	 *
	 * @param array<int, array<string, mixed>> $items Items being imported.
	 *
	 * @return array<string, mixed>
	 */
	private function import_options( array $items ): array {
		$options    = $this->plan_options( $items );
		$selections = array();
		$confirmed  = array();
		$transfers  = array();

		foreach ( $items as $item ) {
			$key = (string) $item['key'];
			if ( ! empty( $this->job['options']['diff'] ) && is_array( $item['sections'] ) ) {
				$selections[ $key ] = array_values( $item['sections'] );
			}
			if ( ! empty( $item['confirmed'] ) ) {
				$confirmed[ $key ] = true;
			}
			if ( ! empty( $item['result']['transfer'] ) ) {
				$transfers[ $key ] = (string) $item['result']['transfer'];
			}
		}

		$user = wp_get_current_user();

		return array_merge(
			$options,
			array(
				'job'        => (string) $this->job['uuid'],
				'job_meta'   => array(
					'direction' => (string) $this->job['direction'],
					'label'     => get_bloginfo( 'name' ) . ' (' . Site::home() . ')',
					'user'      => $user->exists() ? $user->display_name : '',
				),
				'selections' => $selections,
				'confirmed'  => $confirmed,
				'transfers'  => $transfers,
			)
		);
	}

	/**
	 * Counts the results for the job's summary.
	 *
	 * @return array<string, int>
	 */
	private function summary(): array {
		$summary = array();
		foreach ( Jobs::items( (int) $this->job['id'], array( 'included' => 1 ) ) as $item ) {
			$bucket             = 'imported' === $item['status'] ? (string) ( $item['result']['action'] ?? 'imported' ) : (string) $item['status'];
			$summary[ $bucket ] = (int) ( $summary[ $bucket ] ?? 0 ) + 1;
		}

		return $summary;
	}

	/**
	 * Computes an item's place in the import order: terms, attachments, posts,
	 * menus; deeper dependency levels first; parents before children.
	 *
	 * @param array<string, mixed> $record Exported object.
	 * @param int                  $level  Dependency level.
	 *
	 * @return int
	 */
	private static function position( array $record, int $level ): int {
		$kind = match ( $record['type'] ) {
			'post' => 'attachment' === ( $record['post_type'] ?? '' ) ? 'attachment' : 'post',
			default => (string) $record['type'],
		};

		return self::ORDER[ $kind ] * 100000 + ( 99 - min( 99, $level ) ) * 1000 + min( 999, (int) ( $record['depth'] ?? 0 ) );
	}

	/**
	 * Saves changes to the job and keeps the local copy in sync.
	 *
	 * @param array<string, mixed> $changes Columns.
	 *
	 * @return void
	 */
	private function save( array $changes ): void {
		Jobs::update( (int) $this->job['id'], $changes );
		$this->job = array_merge( $this->job, $changes );
	}
}
