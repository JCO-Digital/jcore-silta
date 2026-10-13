<?php
/**
 * The Silta screen and the React app it hosts.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Admin;

use Jcore\Silta\Capabilities;
use Jcore\Silta\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Tools → JCORE Silta and enqueues its assets.
 */
final class Menu {

	/**
	 * Page slug, also the script and style handle.
	 */
	public const SLUG = 'jcore-silta';

	/**
	 * Hook suffix `add_management_page()` returns for this screen.
	 */
	private const HOOK = 'tools_page_' . self::SLUG;

	/**
	 * Build entry the screen runs on.
	 */
	private const ENTRY = 'admin';

	/**
	 * Hooks the page and its assets.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Returns the admin URL of the screen, optionally with query arguments.
	 *
	 * @param array<string, string|int> $args Query arguments.
	 *
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Registers the page under Tools.
	 *
	 * @return void
	 */
	public static function add_page(): void {
		add_management_page(
			__( 'JCORE Silta', 'jcore-silta' ),
			__( 'JCORE Silta', 'jcore-silta' ),
			Capabilities::SYNC,
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Outputs the mount point the React app renders into.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		require JCORE_SILTA_PATH . 'views/admin/page.php';
	}

	/**
	 * Enqueues the React app, on this screen only.
	 *
	 * @param string $hook The current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( self::HOOK !== $hook ) {
			return;
		}

		$asset_file = JCORE_SILTA_PATH . 'build/' . self::ENTRY . '.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::SLUG,
			JCORE_SILTA_URL . 'build/' . self::ENTRY . '.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( self::SLUG, 'jcore-silta', JCORE_SILTA_PATH . 'languages' );

		wp_add_inline_script(
			self::SLUG,
			'window.jcoreSilta = ' . wp_json_encode( self::data() ) . ';',
			'before'
		);

		$style_file = JCORE_SILTA_PATH . 'build/style-' . self::ENTRY . '.css';
		if ( is_readable( $style_file ) ) {
			wp_enqueue_style(
				self::SLUG,
				JCORE_SILTA_URL . 'build/style-' . self::ENTRY . '.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}
	}

	/**
	 * Data the app needs before its first request, including a selection
	 * passed from a list table (?posts=1,2&direction=push&connection=…).
	 *
	 * @return array<string, mixed>
	 */
	private static function data(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only preselection from a link.
		$posts      = isset( $_GET['posts'] ) ? array_filter( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_GET['posts'] ) ) ) ) ) : array();
		$direction  = isset( $_GET['direction'] ) && 'pull' === $_GET['direction'] ? 'pull' : 'push';
		$connection = isset( $_GET['connection'] ) ? sanitize_text_field( wp_unslash( $_GET['connection'] ) ) : '';
		$job        = isset( $_GET['job'] ) ? (int) $_GET['job'] : 0;
		$diff       = ! empty( $_GET['diff'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'site'      => Site::info(),
			'canManage' => current_user_can( 'manage_options' ),
			'adminUrl'  => admin_url(),
			'preselect' => array(
				'posts'      => array_values( $posts ),
				'direction'  => $direction,
				'connection' => $connection,
				'job'        => $job,
				'diff'       => $diff,
			),
		);
	}
}
