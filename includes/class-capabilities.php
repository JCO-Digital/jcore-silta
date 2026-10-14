<?php
/**
 * The capability that allows syncing.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grants `jcore_silta_sync` to everyone who can manage options.
 *
 * Granting through a filter instead of editing roles works on every site of a
 * network without per-site activation. Other roles (editors) get it the usual
 * way, with a role editor or `add_cap()`.
 */
final class Capabilities {

	/**
	 * Capability needed to run syncs.
	 */
	public const SYNC = 'jcore_silta_sync';

	/**
	 * Hooks the filter.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant' ), 10, 1 );
	}

	/**
	 * Whether the current user syncs without per-object checks and sees every
	 * job: an administrator, or WP-CLI run without --user, since shell access
	 * already reaches the whole site.
	 *
	 * @return bool
	 */
	public static function unrestricted(): bool {
		return current_user_can( 'manage_options' ) || self::cli_without_user();
	}

	/**
	 * Whether synced HTML may skip kses: the current user has unfiltered_html,
	 * or WP-CLI runs without --user.
	 *
	 * @return bool
	 */
	public static function unfiltered_html(): bool {
		return current_user_can( 'unfiltered_html' ) || self::cli_without_user();
	}

	/**
	 * Whether this is WP-CLI with no user set.
	 *
	 * @return bool
	 */
	private static function cli_without_user(): bool {
		return defined( 'WP_CLI' ) && WP_CLI && 0 === get_current_user_id();
	}

	/**
	 * Adds the sync capability to users who can manage options.
	 *
	 * @param array<string, bool> $allcaps The user's capabilities.
	 *
	 * @return array<string, bool>
	 */
	public static function grant( array $allcaps ): array {
		if ( empty( $allcaps[ self::SYNC ] ) && ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::SYNC ] = true;
		}

		return $allcaps;
	}
}
