<?php
/**
 * Daily cleanup.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta;

use Jcore\Silta\Jobs\Jobs;
use Jcore\Silta\Sync\Media;
use Jcore\Silta\Sync\Snapshots;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prunes old snapshots and job items and abandoned file transfers.
 */
final class Cron {

	/**
	 * Cron hook.
	 */
	public const HOOK = 'jcore_silta_daily';

	/**
	 * Hooks the event and makes sure it is scheduled.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Removes the event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Runs the cleanup.
	 *
	 * @return void
	 */
	public static function run(): void {
		$days = (int) Settings::get( 'snapshot_days' );

		Snapshots::prune( $days );
		Jobs::prune( max( $days, 30 ) );
		Media::cleanup();

		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Expired nonce reservations.
			$wpdb->prepare( 'DELETE FROM %i WHERE expires < %d', Database::table( 'nonces' ), time() )
		);
	}
}
