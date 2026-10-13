<?php
/**
 * Removes everything the plugin stored when it is deleted.
 *
 * @package Jcore\Silta
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the plugin's options, transients, tables, cron event, capability
 * and temporary files on the current site.
 *
 * Names are spelled out rather than read from the classes so this file stands
 * alone, as uninstall.php runs without the plugin loaded. Add every option,
 * transient, table and meta key the plugin creates.
 *
 * @return void
 */
function jcore_silta_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'settings', 'site', 'identity', 'connections', 'keys', 'cloned', 'db_version' ) as $option ) {
		delete_option( 'jcore_silta_' . $option );
	}

	// Handshake caches, seen request nonces and the upgrade lock.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall.
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_jcore_silta_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_jcore_silta_' ) . '%'
		)
	);

	foreach ( array( 'jcore_silta_map', 'jcore_silta_jobs', 'jcore_silta_job_items', 'jcore_silta_snapshots' ) as $table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall.
	}

	wp_clear_scheduled_hook( 'jcore_silta_daily' );

	foreach ( wp_roles()->role_objects as $role ) {
		$role->remove_cap( 'jcore_silta_sync' );
	}

	$tmp = untrailingslashit( (string) wp_get_upload_dir()['basedir'] ) . '/jcore-silta-tmp';
	if ( is_dir( $tmp ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $tmp, true );
		}
	}
}

if ( is_multisite() ) {
	$jcore_silta_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $jcore_silta_site_ids as $jcore_silta_site_id ) {
		switch_to_blog( (int) $jcore_silta_site_id );
		jcore_silta_uninstall_site();
		restore_current_blog();
	}
} else {
	jcore_silta_uninstall_site();
}
