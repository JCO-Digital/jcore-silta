<?php
/**
 * Admin notices.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Admin;

use Jcore\Silta\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells admins when Silta noticed the database was copied from another site.
 */
final class Notices {

	/**
	 * Hooks the notice.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'cloned' ) );
	}

	/**
	 * Shows the clone notice everywhere but on the Silta screen, which shows
	 * its own with the actions.
	 *
	 * @return void
	 */
	public static function cloned(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cloned = get_option( Site::CLONED_OPTION );
		$screen = get_current_screen();
		if ( empty( $cloned ) || ( $screen && 'tools_page_' . Menu::SLUG === $screen->id ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: address of the site the database came from. */
					__( 'JCORE Silta: this database was copied from %s, so Silta switched off the connections that came with it and gave this site a new identity.', 'jcore-silta' ),
					'' !== (string) ( $cloned['from'] ?? '' ) ? (string) $cloned['from'] : __( 'another site', 'jcore-silta' )
				)
			),
			esc_url( Menu::url( array( 'tab' => 'connections' ) ) ),
			esc_html__( 'Review the connections', 'jcore-silta' )
		);
	}
}
