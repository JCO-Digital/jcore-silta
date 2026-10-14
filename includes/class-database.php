<?php
/**
 * Custom tables and their migrations.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the plugin's tables.
 */
final class Database {

	/**
	 * Schema version. Bump it whenever a CREATE TABLE below changes.
	 */
	private const DB_VERSION = '1.2';

	/**
	 * Option holding the installed schema version.
	 */
	public const DB_VERSION_OPTION = 'jcore_silta_db_version';

	/**
	 * Transient used as a lock while an upgrade runs.
	 */
	private const LOCK = 'jcore_silta_upgrading';

	/**
	 * Short names mapped to unprefixed table names.
	 */
	public const TABLES = array(
		'map'       => 'jcore_silta_map',
		'jobs'      => 'jcore_silta_jobs',
		'items'     => 'jcore_silta_job_items',
		'snapshots' => 'jcore_silta_snapshots',
		'nonces'    => 'jcore_silta_nonces',
		'transfers' => 'jcore_silta_transfers',
	);

	/**
	 * Returns the prefixed name of a table.
	 *
	 * @param string $name Short name, a key of TABLES.
	 *
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLES[ $name ];
	}

	/**
	 * Creates or upgrades the tables when the stored version is behind.
	 *
	 * Runs on every load, because updates arrive through the update client
	 * without the activation hook firing.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '' );
		if ( self::DB_VERSION === $installed ) {
			return;
		}

		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, MINUTE_IN_SECONDS );

		self::install();

		// A failed CREATE leaves the version behind, so the next load retries.
		if ( self::complete() ) {
			delete_option( 'jcore_silta_media_transfers' );
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		}

		delete_transient( self::LOCK );
	}

	/**
	 * Whether every table exists.
	 *
	 * @return bool
	 */
	private static function complete(): bool {
		global $wpdb;

		// Querying each table works whatever case the server folds names to.
		$suppressed = $wpdb->suppress_errors();
		$complete   = true;
		foreach ( array_keys( self::TABLES ) as $name ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', self::table( $name ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check during upgrade.
				$complete = false;
				break;
			}
		}
		$wpdb->suppress_errors( $suppressed );

		return $complete;
	}

	/**
	 * Creates every table with dbDelta.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$map       = self::table( 'map' );
		$jobs      = self::table( 'jobs' );
		$items     = self::table( 'items' );
		$snapshots = self::table( 'snapshots' );
		$nonces    = self::table( 'nonces' );
		$transfers = self::table( 'transfers' );

		dbDelta(
			"CREATE TABLE {$transfers} (
transfer char(36) NOT NULL,
peer char(36) NOT NULL,
created bigint(20) unsigned NOT NULL,
manifest longtext NOT NULL,
PRIMARY KEY  (transfer),
KEY peer (peer),
KEY created (created)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$nonces} (
nonce char(64) NOT NULL,
expires bigint(20) unsigned NOT NULL,
PRIMARY KEY  (nonce),
KEY expires (expires)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$map} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
site char(36) NOT NULL,
object_type varchar(20) NOT NULL,
remote_id bigint(20) unsigned NOT NULL,
local_id bigint(20) unsigned NOT NULL,
remote_hash char(40) NOT NULL DEFAULT '',
local_hash char(40) NOT NULL DEFAULT '',
extra longtext NULL,
synced_at datetime NULL,
PRIMARY KEY  (id),
UNIQUE KEY remote (site,object_type,remote_id),
KEY local (object_type,local_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$jobs} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
uuid char(36) NOT NULL,
role varchar(10) NOT NULL,
direction varchar(10) NOT NULL,
connection varchar(36) NOT NULL DEFAULT '',
remote_site char(36) NOT NULL DEFAULT '',
remote_label varchar(191) NOT NULL DEFAULT '',
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
status varchar(20) NOT NULL,
options longtext NULL,
state longtext NULL,
summary longtext NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uuid (uuid),
KEY role_created (role,created_at)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
job_id bigint(20) unsigned NOT NULL,
object_type varchar(20) NOT NULL,
source_id bigint(20) unsigned NOT NULL,
subtype varchar(64) NOT NULL DEFAULT '',
title text NULL,
level smallint(5) NOT NULL DEFAULT 0,
position int(11) NOT NULL DEFAULT 0,
included tinyint(1) NOT NULL DEFAULT 1,
status varchar(20) NOT NULL DEFAULT 'pending',
action varchar(20) NOT NULL DEFAULT '',
target_id bigint(20) unsigned NOT NULL DEFAULT 0,
conflict tinyint(1) NOT NULL DEFAULT 0,
confirmed tinyint(1) NOT NULL DEFAULT 0,
sections longtext NULL,
override longtext NULL,
plan longtext NULL,
payload longtext NULL,
result longtext NULL,
PRIMARY KEY  (id),
UNIQUE KEY job_object (job_id,object_type,source_id),
KEY job_status (job_id,status)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$snapshots} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
job_uuid char(36) NOT NULL,
object_type varchar(20) NOT NULL,
local_id bigint(20) unsigned NOT NULL,
action varchar(20) NOT NULL,
data longtext NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY job (job_uuid),
KEY created (created_at)
) {$charset};"
		);
	}
}
