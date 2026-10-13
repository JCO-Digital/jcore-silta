<?php
/**
 * This site's identity.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta;

use Jcore\Silta\Crypto\Identity;
use Jcore\Silta\Remote\Connections;
use Jcore\Silta\Remote\Trusted_Keys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the site's UUID and notices when the database has been cloned.
 *
 * The UUID is tied to the home URL it was created for. A database copied to a
 * site with another home URL (a staging refresh, a local copy) gets a new UUID,
 * and the keys and connections that came with the copy are switched off, so
 * a clone never passes itself off as the site it was copied from.
 */
final class Site {

	/**
	 * Option holding the UUID and the home URL it belongs to.
	 */
	public const OPTION = 'jcore_silta_site';

	/**
	 * Option holding the notice shown after a clone was detected.
	 */
	public const CLONED_OPTION = 'jcore_silta_cloned';

	/**
	 * Protocol version spoken between sites. A remote with another major
	 * version is refused.
	 */
	public const PROTOCOL = 1;

	/**
	 * Cached option value for this request: uuid, home (hash) and seen (encoded URL).
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $record = null;

	/**
	 * Returns this site's UUID, creating it on first use.
	 *
	 * @return string
	 */
	public static function uuid(): string {
		return self::record()['uuid'];
	}

	/**
	 * Returns the home URL without a trailing slash.
	 *
	 * @return string
	 */
	public static function home(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * Returns the uploads base URL without a trailing slash.
	 *
	 * @return string
	 */
	public static function uploads_url(): string {
		return untrailingslashit( (string) wp_get_upload_dir()['baseurl'] );
	}

	/**
	 * Returns what another site needs to know about this one.
	 *
	 * @return array<string, mixed>
	 */
	public static function info(): array {
		return array(
			'uuid'     => self::uuid(),
			'home'     => self::home(),
			'uploads'  => self::uploads_url(),
			'name'     => get_bloginfo( 'name' ),
			'rest'     => rest_url( 'jcore-silta/v1/' ),
			'protocol' => self::PROTOCOL,
			'version'  => JCORE_SILTA_VERSION,
		);
	}

	/**
	 * Compares the stored home URL with the current one and reacts to a clone.
	 *
	 * @return void
	 */
	public static function check(): void {
		self::record();
	}

	/**
	 * Reduces a URL to host and path, so an http → https switch is not
	 * mistaken for a clone.
	 *
	 * @param string $url URL to reduce.
	 *
	 * @return string
	 */
	public static function url_key( string $url ): string {
		$parts = wp_parse_url( strtolower( $url ) );
		$host  = $parts['host'] ?? '';
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path  = untrailingslashit( $parts['path'] ?? '' );

		return $host . $port . $path;
	}

	/**
	 * Loads the record, creating or rotating it as needed.
	 *
	 * @return array<string, string>
	 */
	private static function record(): array {
		if ( null !== self::$record ) {
			return self::$record;
		}

		$stored  = get_option( self::OPTION );
		$current = self::url_key( self::home() );

		// The home URL is stored as a hash (plus an encoded copy for display),
		// because cloning a database usually includes a search-replace of the
		// old URL with the new one, which would rewrite a plain copy too.
		$fresh = array(
			'uuid' => wp_generate_uuid4(),
			'home' => sha1( $current ),
			'seen' => Util::base64url_encode( $current ),
		);

		if ( ! is_array( $stored ) || empty( $stored['uuid'] ) ) {
			self::$record = $fresh;
			update_option( self::OPTION, self::$record, true );

			return self::$record;
		}

		if ( ( $stored['home'] ?? '' ) !== $fresh['home'] ) {
			self::$record = $fresh;
			update_option( self::OPTION, self::$record, true );
			self::handle_clone( (string) Util::base64url_decode( (string) ( $stored['seen'] ?? '' ) ) );

			return self::$record;
		}

		self::$record = array(
			'uuid' => (string) $stored['uuid'],
			'home' => (string) $stored['home'],
			'seen' => (string) ( $stored['seen'] ?? '' ),
		);

		return self::$record;
	}

	/**
	 * Switches off what a copied database brought along.
	 *
	 * @param string $previous The home URL the copy came from.
	 *
	 * @return void
	 */
	private static function handle_clone( string $previous ): void {
		Identity::discard_stored();
		Connections::mark_inherited();
		Trusted_Keys::mark_inherited();

		update_option(
			self::CLONED_OPTION,
			array(
				'from' => $previous,
				'at'   => time(),
			),
			false
		);

		/**
		 * Fires when Silta notices it is running on a copy of another site's database.
		 *
		 * @param string $previous The home URL (host and path) the copy came from.
		 */
		do_action( 'jcore_silta_clone_detected', $previous );
	}
}
