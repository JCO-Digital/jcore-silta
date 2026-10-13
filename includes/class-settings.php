<?php
/**
 * General settings.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the `jcore_silta_settings` option.
 */
final class Settings {

	/**
	 * Option holding the settings array.
	 */
	public const OPTION = 'jcore_silta_settings';

	/**
	 * Default value of every setting. Only these keys are stored.
	 */
	public const DEFAULTS = array(
		// Objects exported, planned or imported per request.
		'batch_size'     => 10,
		// Media chunk size in kilobytes.
		'chunk_kb'       => 2048,
		// Days snapshots (and with them, undo) are kept.
		'snapshot_days'  => 30,
		// Extra meta keys never synced, one per line. A trailing * matches a prefix.
		'excluded_meta'  => '',
		// Seconds a single admin request may spend on a job before reporting progress.
		'request_budget' => 15,
	);

	/**
	 * Returns the stored settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::DEFAULTS, is_array( $stored ) ? array_intersect_key( $stored, self::DEFAULTS ) : array() );
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting name, a key of DEFAULTS.
	 *
	 * @return mixed
	 */
	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Validates and stores the given settings. Unknown keys are ignored.
	 *
	 * @param array<string, mixed> $values New values.
	 *
	 * @return array<string, mixed> The stored settings.
	 */
	public static function update( array $values ): array {
		$settings = self::all();

		if ( isset( $values['batch_size'] ) ) {
			$settings['batch_size'] = max( 1, min( 100, (int) $values['batch_size'] ) );
		}
		if ( isset( $values['chunk_kb'] ) ) {
			$settings['chunk_kb'] = max( 256, min( 16384, (int) $values['chunk_kb'] ) );
		}
		if ( isset( $values['snapshot_days'] ) ) {
			$settings['snapshot_days'] = max( 1, min( 365, (int) $values['snapshot_days'] ) );
		}
		if ( isset( $values['excluded_meta'] ) ) {
			$lines                     = preg_split( '/\R/', (string) $values['excluded_meta'] );
			$lines                     = array_filter( array_map( 'sanitize_text_field', (array) $lines ) );
			$settings['excluded_meta'] = implode( "\n", array_unique( $lines ) );
		}
		if ( isset( $values['request_budget'] ) ) {
			$settings['request_budget'] = max( 5, min( 120, (int) $values['request_budget'] ) );
		}

		update_option( self::OPTION, $settings, false );

		return $settings;
	}
}
