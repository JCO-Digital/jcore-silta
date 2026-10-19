<?php
/**
 * `wp silta push`, `pull`, `diff`, `jobs`, `resume` and `undo`.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Cli;

use Jcore\Silta\Endpoint\Local;
use Jcore\Silta\Jobs\Jobs;
use Jcore\Silta\Jobs\Runner;
use Jcore\Silta\Remote\Connections;
use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs content between connected sites.
 */
final class Sync_Command {

	/**
	 * Seconds per step; CLI has no request timeout, the steps only report progress.
	 */
	private const STEP = 30.0;

	/**
	 * Pushes content from this site to a connected site.
	 *
	 * ## OPTIONS
	 *
	 * <connection>
	 * : Connection ID, label or host.
	 *
	 * [--post=<ids>]
	 * : Comma-separated post IDs (any post type, attachments included).
	 *
	 * [--post-type=<types>]
	 * : Comma-separated post types to sync in full.
	 *
	 * [--menu=<ids>]
	 * : Comma-separated menu IDs.
	 *
	 * [--form=<ids>]
	 * : Comma-separated Gravity Forms form IDs. Needs Gravity Forms on both sites.
	 *
	 * [--status=<mode>]
	 * : Status on the target: source (as here), draft, or keep (existing posts keep theirs).
	 * ---
	 * default: source
	 * options:
	 *   - source
	 *   - draft
	 *   - keep
	 * ---
	 *
	 * [--replace=<pair>]
	 * : Extra search-replace rule as `find=>replace`, on top of the connection's saved rules.
	 *
	 * [--[no-]dependencies]
	 * : Sync along the media, terms and posts the selection refers to (default). --no-dependencies leaves them out.
	 *
	 * [--deep]
	 * : Keep adding dependencies of dependencies until none are missing.
	 *
	 * [--confirm-conflicts]
	 * : Overwrite posts that were edited on the target since the last sync.
	 *
	 * [--trash-orphans]
	 * : When syncing whole post types, trash target posts whose source posts are gone.
	 *
	 * [--dry-run]
	 * : Show the plan and stop.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp silta push production --post=33,35
	 *     wp silta push production --post-type=event --dry-run
	 *     wp silta push production --form=3
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function push( array $args, array $assoc_args ): void {
		$this->sync( 'push', $args, $assoc_args );
	}

	/**
	 * Pulls content from a connected site to this one.
	 *
	 * ## OPTIONS
	 *
	 * <connection>
	 * : Connection ID, label or host.
	 *
	 * [--post=<ids>]
	 * : Comma-separated post IDs on the other site.
	 *
	 * [--post-type=<types>]
	 * : Comma-separated post types to sync in full.
	 *
	 * [--menu=<ids>]
	 * : Comma-separated menu IDs on the other site.
	 *
	 * [--form=<ids>]
	 * : Comma-separated Gravity Forms form IDs on the other site. Needs Gravity Forms on both sites.
	 *
	 * [--status=<mode>]
	 * : Status here: source (as there), draft, or keep (existing posts keep theirs).
	 * ---
	 * default: source
	 * options:
	 *   - source
	 *   - draft
	 *   - keep
	 * ---
	 *
	 * [--replace=<pair>]
	 * : Extra search-replace rule as `find=>replace`.
	 *
	 * [--[no-]dependencies]
	 * : Sync along what the selection refers to (default). --no-dependencies leaves them out.
	 *
	 * [--deep]
	 * : Keep adding dependencies of dependencies until none are missing.
	 *
	 * [--confirm-conflicts]
	 * : Overwrite posts edited here since the last sync.
	 *
	 * [--trash-orphans]
	 * : When syncing whole post types, trash posts here whose source posts are gone.
	 *
	 * [--dry-run]
	 * : Show the plan and stop.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function pull( array $args, array $assoc_args ): void {
		$this->sync( 'pull', $args, $assoc_args );
	}

	/**
	 * Shows what syncing one post would change, section by section.
	 *
	 * ## OPTIONS
	 *
	 * <connection>
	 * : Connection ID, label or host.
	 *
	 * --post=<id>
	 * : Post ID on the source side.
	 *
	 * [--direction=<direction>]
	 * : push compares this site's post with the other site's copy; pull the other way round.
	 * ---
	 * default: push
	 * options:
	 *   - push
	 *   - pull
	 * ---
	 *
	 * [--all]
	 * : Also list unchanged sections.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function diff( array $args, array $assoc_args ): void {
		$connection = Commands::connection( (string) $args[0] );
		$job        = Runner::create(
			(string) $connection['id'],
			(string) ( $assoc_args['direction'] ?? 'push' ),
			array( 'posts' => array( (int) $assoc_args['post'] ) ),
			array(
				'diff'         => true,
				'dependencies' => false,
			)
		);
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		$runner = $this->prepare( (int) $job['id'], false );
		$items  = Jobs::items( (int) $job['id'], array( 'level' => 0 ) );
		$diff   = empty( $items ) ? null : $runner->diff( (int) $items[0]['id'] );
		Jobs::delete( (int) $job['id'] );

		if ( null === $diff || is_wp_error( $diff ) ) {
			WP_CLI::error( null === $diff ? 'Nothing to compare.' : $diff->get_error_message() );
		}

		$match = $diff['match'] ?? null;
		WP_CLI::line( null === $match ? 'Not on the target yet: it would be created.' : sprintf( 'Target: %s (#%d, matched by %s)', $match['title'], $match['id'], $match['method'] ) );

		$changed = 0;
		foreach ( (array) $diff['sections'] as $key => $section ) {
			if ( ! $section['changed'] && empty( $assoc_args['all'] ) ) {
				continue;
			}
			$changed += $section['changed'] ? 1 : 0;
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( ( $section['changed'] ? '%Y' : '%G' ) . $section['label'] . '%n [' . $key . ']' ) );
			if ( ! $section['changed'] ) {
				continue;
			}
			foreach ( explode( "\n", (string) $section['before'] ) as $line ) {
				WP_CLI::line( WP_CLI::colorize( '%r- ' . $line . '%n' ) );
			}
			foreach ( explode( "\n", (string) $section['after'] ) as $line ) {
				WP_CLI::line( WP_CLI::colorize( '%g+ ' . $line . '%n' ) );
			}
		}

		foreach ( (array) ( $diff['errors'] ?? array() ) as $section => $error ) {
			WP_CLI::warning( ( '' !== $section ? $section . ': ' : '' ) . $error );
		}

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '%d section(s) differ.', $changed ) );
	}

	/**
	 * Lists syncs, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--page=<page>]
	 * : Page.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function jobs( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( Jobs::list( (int) ( $assoc_args['page'] ?? 1 ) )['jobs'] as $job ) {
			$summary = array();
			foreach ( (array) $job['summary'] as $bucket => $count ) {
				$summary[] = $bucket . ': ' . $count;
			}
			$rows[] = array(
				'id'      => $job['id'],
				'created' => $job['created_at'],
				'role'    => 'target' === $job['role'] ? 'received' : $job['direction'],
				'site'    => $job['remote_label'],
				'status'  => $job['status'],
				'summary' => implode( ', ', $summary ),
			);
		}

		Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'created', 'role', 'site', 'status', 'summary' ) );
	}

	/**
	 * Continues a sync that was interrupted.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function resume( array $args, array $assoc_args ): void {
		$runner = $this->prepare( (int) $args[0], false );
		$this->show_plan( (int) $args[0] );
		WP_CLI::confirm( 'Run this sync?', $assoc_args );
		$this->run( $runner );
	}

	/**
	 * Undoes a sync: restores what it overwrote, removes what it created and
	 * untrashes what it trashed.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID, from `wp silta jobs`.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	public function undo( array $args, array $assoc_args ): void {
		$job = Jobs::get( (int) $args[0] );
		if ( null === $job ) {
			WP_CLI::error( 'No such sync.' );
		}

		WP_CLI::confirm( sprintf( 'Undo sync #%d (%s)?', $job['id'], $job['remote_label'] ), $assoc_args );

		if ( 'target' === $job['role'] ) {
			$result = ( new Local( (string) $job['remote_site'] ) )->undo( array( 'job' => $job['uuid'] ) );
		} else {
			$runner = Runner::load( (int) $job['id'] );
			$result = is_wp_error( $runner ) ? $runner : $runner->undo();
		}

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Restored %d, removed %d, untrashed %d.', $result['restored'] ?? 0, $result['removed'] ?? 0, $result['untrashed'] ?? 0 ) );
		foreach ( (array) ( $result['errors'] ?? array() ) as $error ) {
			WP_CLI::warning( $error );
		}
	}

	/**
	 * Runs push or pull.
	 *
	 * @param string                $direction  push or pull.
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 *
	 * @return void
	 */
	private function sync( string $direction, array $args, array $assoc_args ): void {
		$connection = Commands::connection( (string) $args[0] );

		$rules = (array) ( $connection['rules'] ?? array() );
		if ( ! empty( $assoc_args['replace'] ) ) {
			$pair = explode( '=>', (string) $assoc_args['replace'], 2 );
			if ( 2 !== count( $pair ) ) {
				WP_CLI::error( '--replace takes find=>replace.' );
			}
			$rules[] = $pair;
		}

		$job = Runner::create(
			(string) $connection['id'],
			$direction,
			array(
				'posts'      => self::ids( $assoc_args['post'] ?? '' ),
				'post_types' => array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['post-type'] ?? '' ) ) ) ),
				'menus'      => self::ids( $assoc_args['menu'] ?? '' ),
				'forms'      => self::ids( $assoc_args['form'] ?? '' ),
			),
			array(
				'status_mode'  => (string) ( $assoc_args['status'] ?? 'source' ),
				'rules'        => $rules,
				'dependencies' => Utils\get_flag_value( $assoc_args, 'dependencies', true ),
			)
		);
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		$runner = $this->prepare( (int) $job['id'], ! empty( $assoc_args['deep'] ) );
		$job_id = (int) $job['id'];

		if ( ! empty( $assoc_args['confirm-conflicts'] ) ) {
			Jobs::update_items( $job_id, array( 'conflict' => 1 ), array( 'confirmed' => true ) );
		}
		if ( ! empty( $assoc_args['trash-orphans'] ) ) {
			Jobs::update_items( $job_id, array( 'object_type' => 'orphan' ), array( 'included' => true ) );
		}

		$this->show_plan( $job_id );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			Jobs::delete( $job_id );
			WP_CLI::success( 'Dry run: nothing was written.' );

			return;
		}

		WP_CLI::confirm( sprintf( '%s %s?', 'push' === $direction ? 'Push to' : 'Pull from', $connection['label'] ), $assoc_args );
		$this->run( $runner );
	}

	/**
	 * Prepares a job until it is ready.
	 *
	 * @param int  $job_id Job ID.
	 * @param bool $deep   Keep adding dependency levels.
	 *
	 * @return Runner
	 */
	private function prepare( int $job_id, bool $deep ): Runner {
		do {
			$runner = Runner::load( $job_id );
			if ( is_wp_error( $runner ) ) {
				WP_CLI::error( $runner->get_error_message() );
			}

			$progress = $runner->prepare( self::STEP );
			if ( is_wp_error( $progress ) ) {
				WP_CLI::error( $progress->get_error_message() );
			}

			if ( 'ready' === $progress['job']['status'] && $deep && $progress['job']['next'] > 0 ) {
				WP_CLI::log( sprintf( 'Adding %d further dependencies…', $progress['job']['next'] ) );
				$runner->expand();
				$progress['job']['status'] = 'preparing';
			}
		} while ( 'preparing' === $progress['job']['status'] );

		if ( $progress['job']['next'] > 0 ) {
			WP_CLI::warning( sprintf( '%d further dependencies are not included. Use --deep to add them.', $progress['job']['next'] ) );
		}

		return $runner;
	}

	/**
	 * Runs a job to the end.
	 *
	 * @param Runner $runner Runner.
	 *
	 * @return void
	 */
	private function run( Runner $runner ): void {
		$job_id = (int) $runner->job()['id'];

		do {
			$runner   = Runner::load( $job_id );
			$progress = is_wp_error( $runner ) ? $runner : $runner->run( self::STEP );
			if ( is_wp_error( $progress ) ) {
				WP_CLI::error( $progress->get_error_message() . sprintf( ' Continue with `wp silta resume %d`.', $job_id ) );
			}
			WP_CLI::log( sprintf( '%s: %d of %d done', $progress['job']['phase'], (int) ( $progress['counts']['imported'] ?? 0 ) + (int) ( $progress['counts']['skipped'] ?? 0 ) + (int) ( $progress['counts']['failed'] ?? 0 ), $progress['total'] ) );
		} while ( 'running' === $progress['job']['status'] );

		foreach ( Jobs::items( $job_id, array( 'included' => 1 ) ) as $item ) {
			$result = (array) $item['result'];
			if ( 'failed' === $item['status'] ) {
				WP_CLI::warning( sprintf( '%s "%s" failed: %s', $item['key'], $item['title'], $result['error'] ?? '' ) );
			} elseif ( 'skipped' === $item['status'] ) {
				WP_CLI::warning( sprintf( '%s "%s" skipped: %s', $item['key'], $item['title'], 'conflict' === ( $result['reason'] ?? '' ) ? 'edited on the target since the last sync (use --confirm-conflicts)' : (string) ( $result['reason'] ?? '' ) ) );
			}
			foreach ( (array) ( $result['warnings'] ?? array() ) as $warning ) {
				WP_CLI::warning( sprintf( '%s "%s": %s', $item['key'], $item['title'], $warning ) );
			}
		}

		$summary = array();
		foreach ( (array) $progress['job']['summary'] as $bucket => $count ) {
			$summary[] = $count . ' ' . $bucket;
		}
		WP_CLI::success( sprintf( 'Sync #%d done: %s. Undo with `wp silta undo %d`.', $job_id, implode( ', ', $summary ), $job_id ) );
	}

	/**
	 * Prints a job's plan.
	 *
	 * @param int $job_id Job ID.
	 *
	 * @return void
	 */
	private function show_plan( int $job_id ): void {
		$rows = array();
		$keys = array_flip( Jobs::keys( $job_id ) );
		foreach ( Jobs::items( $job_id ) as $item ) {
			$plan = (array) $item['plan'];

			// Dependencies added to the job after planning are no longer missing.
			$plan['missing'] = array_filter( (array) ( $plan['missing'] ?? array() ), static fn( $dependency ) => ! isset( $keys[ $dependency['key'] ] ) );

			$rows[] = array(
				'key'      => $item['key'],
				'type'     => $item['subtype'],
				'title'    => mb_strimwidth( (string) $item['title'], 0, 40, '…' ),
				'level'    => $item['level'] < 0 ? 'orphan' : (string) $item['level'],
				'action'   => $item['included'] ? $item['action'] : 'skip',
				'target'   => $item['target_id'] ? '#' . $item['target_id'] . ' (' . ( $plan['match']['method'] ?? '' ) . ')' : '',
				'conflict' => $item['conflict'] ? ( $item['confirmed'] ? 'overwrite' : 'skip' ) : '',
				'changes'  => implode( ', ', array_slice( (array) ( $plan['changed'] ?? array() ), 0, 6 ) ) . ( count( (array) ( $plan['changed'] ?? array() ) ) > 6 ? '…' : '' ),
				'problems' => implode( '; ', array_merge( array_values( (array) ( $plan['errors'] ?? array() ) ), array_map( static fn( $m ) => 'missing ' . $m['key'], (array) ( $plan['missing'] ?? array() ) ) ) ),
			);
		}

		Utils\format_items( 'table', $rows, array( 'key', 'type', 'title', 'level', 'action', 'target', 'conflict', 'changes', 'problems' ) );
	}

	/**
	 * Parses a comma-separated ID list.
	 *
	 * @param string $csv IDs.
	 *
	 * @return int[]
	 */
	private static function ids( string $csv ): array {
		return array_values( array_filter( array_map( 'intval', explode( ',', $csv ) ) ) );
	}
}
