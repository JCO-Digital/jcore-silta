<?php
/**
 * Drives a sync job.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Jobs;

use Jcore\Silta\Endpoint\Endpoint;
use Jcore\Silta\Endpoint\Local;
use Jcore\Silta\Endpoint\Remote;
use Jcore\Silta\Remote\Connections;
use Jcore\Silta\Settings;
use Jcore\Silta\Site;
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
		$local  = new Local( (string) $connection['site'] );

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
		if ( null === $job || 'initiator' !== $job['role'] ) {
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

		$deadline = microtime( true ) + $budget;
		$state    = $this->job['state'];
		$this->save( array( 'status' => 'running' ) );

		while ( microtime( true ) < $deadline ) {
			$phase = (string) ( $state['run'] ?? 'media' );

			$result = match ( $phase ) {
				'media' => $this->media_step( $state, $deadline ),
				'import' => $this->import_batch(),
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

		$result = $this->target->undo( array( 'job' => $this->job['uuid'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save( array( 'status' => 'undone' ) );

		return $result;
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
			array( 'limit' => (int) Settings::get( 'batch_size' ) )
		);
		if ( empty( $items ) ) {
			return 'deps';
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

		$objects = array();
		foreach ( (array) ( $bundle['objects'] ?? array() ) as $record ) {
			$objects[ (string) $record['key'] ] = $record;
		}

		$planned = array();
		foreach ( $items as $item ) {
			$record = $objects[ $item['key'] ] ?? null;
			if ( null === $record ) {
				Jobs::update_item(
					(int) $item['id'],
					array(
						'status'   => 'failed',
						'included' => false,
						'result'   => array( 'error' => __( 'It no longer exists on the source site.', 'jcore-silta' ) ),
					)
				);
				continue;
			}

			$payload = array(
				'object'     => $record,
				'stubs'      => array_intersect_key( (array) ( $bundle['stubs'] ?? array() ), array_flip( (array) $record['refs'] ) ),
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

		$chunk = (int) Settings::get( 'chunk_kb' ) * 1024;
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
					'path'     => $file['path'],
					'offset'   => $cursor['offset'],
					'data'     => (string) $read['data'],
				)
			);
			if ( is_wp_error( $written ) ) {
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
				'limit'   => (int) Settings::get( 'batch_size' ),
				'order'   => 'import',
				'payload' => true,
			)
		);
		$items = array_values( array_filter( $items, static fn( array $item ): bool => ! empty( $item['payload'] ) ) );
		if ( empty( $items ) ) {
			return true;
		}

		return $this->import( $items, false );
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
			if ( count( $pending ) >= (int) Settings::get( 'batch_size' ) ) {
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
			)
		);
		if ( empty( $items ) ) {
			return true;
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

		return true;
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
