<?php
/**
 * The block editor panel.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Admin;

use Jcore\Silta\Capabilities;
use Jcore\Silta\Remote\Connections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the document settings panel that shows a post's sync status on
 * each connected site.
 */
final class Editor {

	/**
	 * Script handle.
	 */
	private const HANDLE = 'jcore-silta-editor';

	/**
	 * Build entry.
	 */
	private const ENTRY = 'editor';

	/**
	 * Hooks the assets.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
	}

	/**
	 * Enqueues the panel for users who can sync, when a connection can be pushed to.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! current_user_can( Capabilities::SYNC ) ) {
			return;
		}

		$writable = array_filter(
			Connections::all(),
			static fn( array $connection ): bool => in_array( 'write', (array) $connection['scopes'], true )
		);
		if ( empty( $writable ) ) {
			return;
		}

		$asset_file = JCORE_SILTA_PATH . 'build/' . self::ENTRY . '.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			JCORE_SILTA_URL . 'build/' . self::ENTRY . '.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( self::HANDLE, 'jcore-silta', JCORE_SILTA_PATH . 'languages' );
		wp_add_inline_script(
			self::HANDLE,
			'window.jcoreSiltaEditor = ' . wp_json_encode( array( 'screen' => Menu::url() ) ) . ';',
			'before'
		);

		$style_file = JCORE_SILTA_PATH . 'build/style-' . self::ENTRY . '.css';
		if ( is_readable( $style_file ) ) {
			wp_enqueue_style( self::HANDLE, JCORE_SILTA_URL . 'build/style-' . self::ENTRY . '.css', array(), $asset['version'] );
		}
	}
}
