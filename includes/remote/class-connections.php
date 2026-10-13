<?php
/**
 * Sites this one calls.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Remote;

use Jcore\Silta\Crypto\Identity;
use Jcore\Silta\Crypto\Vault;
use Jcore\Silta\Site;
use Jcore\Silta\Util;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores outgoing connections: the remote's address and identity, the scopes it
 * granted, optional basic auth and the saved search-replace rules.
 */
final class Connections {

	/**
	 * Option holding the connections, keyed by ID.
	 */
	public const OPTION = 'jcore_silta_connections';

	/**
	 * Transient prefix for cached handshakes.
	 */
	private const HANDSHAKE_CACHE = 'jcore_silta_hs_';

	/**
	 * Returns every connection, secrets included. Use public_view() for output.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Returns one connection.
	 *
	 * @param string $id Connection ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Returns a connection without its secrets.
	 *
	 * @param array<string, mixed> $connection Connection.
	 *
	 * @return array<string, mixed>
	 */
	public static function public_view( array $connection ): array {
		$view              = $connection;
		$view['auth_user'] = $connection['auth']['user'] ?? '';
		$view['has_auth']  = ! empty( $connection['auth'] );
		unset( $view['auth'] );

		return $view;
	}

	/**
	 * Pairs with a remote using the string it gave and stores the connection.
	 *
	 * @param string               $pairing Pairing string from the remote.
	 * @param string               $label   Optional label; defaults to the remote's name.
	 * @param array<string, mixed> $auth    Optional basic auth: user and pass.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function add( string $pairing, string $label = '', array $auth = array() ): array|WP_Error {
		$decoded = self::decode( $pairing );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		if ( $decoded['site'] === Site::uuid() ) {
			return new WP_Error( 'jcore_silta_self', __( 'This pairing string was created on this site. Paste it on the other site.', 'jcore-silta' ) );
		}

		$ready = Identity::ensure();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$stored_auth = self::stored_auth( $auth );

		$response = Client::pair(
			$decoded['rest'],
			$decoded['site'],
			array(
				'token'      => $decoded['token'],
				'public_key' => base64_encode( (string) Identity::public_key() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text.
				'site'       => Site::info(),
			),
			$stored_auth
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$remote = is_array( $response['site'] ?? null ) ? $response['site'] : array();
		if ( ( $remote['uuid'] ?? '' ) !== $decoded['site'] ) {
			return new WP_Error( 'jcore_silta_site_mismatch', __( 'The remote site answered with a different identity than the pairing string promised.', 'jcore-silta' ) );
		}

		$connections = self::all();

		// Pairing again with a site we already know replaces the old pairing but
		// keeps the ID, so history and saved rules stay attached.
		$id    = wp_generate_uuid4();
		$rules = array();
		foreach ( $connections as $existing ) {
			if ( $existing['site'] === $decoded['site'] ) {
				$id    = $existing['id'];
				$rules = $existing['rules'] ?? array();
				if ( '' === $label ) {
					$label = $existing['label'];
				}
				if ( null === $stored_auth ) {
					$stored_auth = $existing['auth'] ?? null;
				}
			}
		}

		$connection = array(
			'id'          => $id,
			'label'       => '' !== $label ? sanitize_text_field( $label ) : sanitize_text_field( (string) ( $remote['name'] ?? $decoded['name'] ) ),
			'rest'        => esc_url_raw( (string) ( $remote['rest'] ?? $decoded['rest'] ) ),
			'home'        => esc_url_raw( (string) ( $remote['home'] ?? $decoded['home'] ) ),
			'site'        => $decoded['site'],
			'name'        => sanitize_text_field( (string) ( $remote['name'] ?? '' ) ),
			'scopes'      => Trusted_Keys::clean_scopes( (array) ( $response['scopes'] ?? array() ) ),
			'auth'        => $stored_auth,
			'rules'       => $rules,
			'status'      => 'ok',
			'error'       => '',
			'created'     => time(),
			'verified_at' => time(),
		);

		$connections[ $id ] = $connection;
		update_option( self::OPTION, $connections, false );
		delete_transient( self::HANDSHAKE_CACHE . $id );

		return $connection;
	}

	/**
	 * Updates a connection's label, basic auth or saved rules.
	 *
	 * @param string               $id      Connection ID.
	 * @param array<string, mixed> $changes New values.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( string $id, array $changes ): array|WP_Error {
		$connections = self::all();
		if ( empty( $connections[ $id ] ) ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such connection.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$connection = $connections[ $id ];
		if ( isset( $changes['label'] ) ) {
			$connection['label'] = sanitize_text_field( (string) $changes['label'] );
		}
		if ( array_key_exists( 'auth', $changes ) ) {
			$auth = is_array( $changes['auth'] ) ? $changes['auth'] : array();
			// An empty password with an unchanged user keeps the stored password.
			$keep = ! empty( $auth['user'] ) && '' === (string) ( $auth['pass'] ?? '' ) && ( $connection['auth']['user'] ?? '' ) === $auth['user'];
			if ( ! $keep ) {
				$connection['auth'] = self::stored_auth( $auth );
			}
		}
		if ( isset( $changes['rules'] ) ) {
			$connection['rules'] = self::clean_rules( (array) $changes['rules'] );
		}

		$connections[ $id ] = $connection;
		update_option( self::OPTION, $connections, false );

		return $connection;
	}

	/**
	 * Deletes a connection.
	 *
	 * @param string $id Connection ID.
	 *
	 * @return bool
	 */
	public static function delete( string $id ): bool {
		$connections = self::all();
		if ( empty( $connections[ $id ] ) ) {
			return false;
		}

		unset( $connections[ $id ] );
		update_option( self::OPTION, $connections, false );
		delete_transient( self::HANDSHAKE_CACHE . $id );

		return true;
	}

	/**
	 * Runs (or returns the cached) handshake with a remote: its site details,
	 * what content it has and the scopes this site holds.
	 *
	 * @param string $id    Connection ID.
	 * @param bool   $fresh Skip the cache.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handshake( string $id, bool $fresh = false ): array|WP_Error {
		$connection = self::get( $id );
		if ( null === $connection ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such connection.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		if ( ! $fresh ) {
			$cached = get_transient( self::HANDSHAKE_CACHE . $id );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = ( new Client( $connection ) )->call( 'handshake' );
		if ( is_wp_error( $response ) ) {
			self::set_status( $id, 'error', $response->get_error_message() );

			return $response;
		}

		$protocol = (int) ( $response['site']['protocol'] ?? 0 );
		if ( Site::PROTOCOL !== $protocol ) {
			$error = new WP_Error(
				'jcore_silta_protocol',
				sprintf(
					/* translators: 1: remote protocol version, 2: local protocol version. */
					__( 'The remote runs an incompatible version of Silta (protocol %1$d, this site %2$d). Update the plugin on both sites.', 'jcore-silta' ),
					$protocol,
					Site::PROTOCOL
				)
			);
			self::set_status( $id, 'error', $error->get_error_message() );

			return $error;
		}

		$connections = self::all();
		if ( isset( $connections[ $id ] ) ) {
			$connections[ $id ]['status']      = 'ok';
			$connections[ $id ]['error']       = '';
			$connections[ $id ]['scopes']      = Trusted_Keys::clean_scopes( (array) ( $response['scopes'] ?? array() ) );
			$connections[ $id ]['verified_at'] = time();
			$connections[ $id ]['name']        = sanitize_text_field( (string) ( $response['site']['name'] ?? '' ) );
			update_option( self::OPTION, $connections, false );
		}

		set_transient( self::HANDSHAKE_CACHE . $id, $response, 10 * MINUTE_IN_SECONDS );

		return $response;
	}

	/**
	 * Sets a connection's status.
	 *
	 * @param string $id     Connection ID.
	 * @param string $status ok, error, inherited or repair.
	 * @param string $error  Error message for the error status.
	 *
	 * @return void
	 */
	public static function set_status( string $id, string $status, string $error = '' ): void {
		$connections = self::all();
		if ( empty( $connections[ $id ] ) ) {
			return;
		}

		$connections[ $id ]['status'] = $status;
		$connections[ $id ]['error']  = $error;
		update_option( self::OPTION, $connections, false );
	}

	/**
	 * Marks every connection as inherited from a copied database.
	 *
	 * @return void
	 */
	public static function mark_inherited(): void {
		self::mark_all( 'inherited' );
	}

	/**
	 * Marks every connection as needing a new pairing because the key changed.
	 *
	 * @return void
	 */
	public static function mark_key_lost(): void {
		self::mark_all( 'repair' );
	}

	/**
	 * Whether plain http is acceptable for a remote.
	 *
	 * @param string $url Remote URL.
	 *
	 * @return bool
	 */
	public static function allow_insecure( string $url ): bool {
		$allowed = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );

		/**
		 * Filters whether a remote may be reached over plain http.
		 *
		 * @param bool   $allowed Whether http is allowed. Defaults to true on local and development environments.
		 * @param string $url     The remote URL.
		 */
		return (bool) apply_filters( 'jcore_silta_allow_insecure', $allowed, $url );
	}

	/**
	 * Decodes and validates a pairing string.
	 *
	 * @param string $pairing Pairing string.
	 *
	 * @return array<string, string>|WP_Error
	 */
	public static function decode( string $pairing ): array|WP_Error {
		$pairing = trim( $pairing );
		$invalid = new WP_Error( 'jcore_silta_bad_pairing', __( 'That is not a valid pairing string. Copy it again from the other site.', 'jcore-silta' ) );

		if ( ! str_starts_with( $pairing, Trusted_Keys::PREFIX ) ) {
			return $invalid;
		}

		$json = Util::base64url_decode( substr( $pairing, strlen( Trusted_Keys::PREFIX ) ) );
		$data = null === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['rest'] ) || empty( $data['site'] ) || empty( $data['token'] ) || ! wp_is_uuid( (string) $data['site'] ) ) {
			return $invalid;
		}

		$rest   = esc_url_raw( (string) $data['rest'] );
		$scheme = wp_parse_url( $rest, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return $invalid;
		}
		if ( 'http' === $scheme && ! self::allow_insecure( $rest ) ) {
			return new WP_Error( 'jcore_silta_insecure', __( 'The other site does not use HTTPS. Silta only connects to sites over HTTPS, except in local and development environments.', 'jcore-silta' ) );
		}

		return array(
			'rest'  => $rest,
			'home'  => esc_url_raw( (string) ( $data['home'] ?? '' ) ),
			'site'  => strtolower( (string) $data['site'] ),
			'name'  => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'token' => (string) $data['token'],
		);
	}

	/**
	 * Cleans search-replace rules: a list of [find, replace] pairs.
	 *
	 * @param array<mixed> $rules Raw rules.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function clean_rules( array $rules ): array {
		$clean = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$find    = (string) ( $rule[0] ?? $rule['find'] ?? '' );
			$replace = (string) ( $rule[1] ?? $rule['replace'] ?? '' );
			if ( '' === $find ) {
				continue;
			}
			$clean[] = array( $find, $replace );
		}

		return $clean;
	}

	/**
	 * Returns decrypted basic auth credentials.
	 *
	 * @param array<string, mixed> $connection Connection.
	 *
	 * @return array{user: string, pass: string}|null
	 */
	public static function auth( array $connection ): ?array {
		if ( empty( $connection['auth']['user'] ) ) {
			return null;
		}

		return array(
			'user' => (string) $connection['auth']['user'],
			'pass' => (string) Vault::decrypt( (string) ( $connection['auth']['pass'] ?? '' ) ),
		);
	}

	/**
	 * Encrypts basic auth credentials for storage.
	 *
	 * @param array<string, mixed> $auth User and pass.
	 *
	 * @return array{user: string, pass: string}|null
	 */
	private static function stored_auth( array $auth ): ?array {
		$user = sanitize_text_field( (string) ( $auth['user'] ?? '' ) );
		if ( '' === $user ) {
			return null;
		}

		return array(
			'user' => $user,
			'pass' => Vault::encrypt( (string) ( $auth['pass'] ?? '' ) ),
		);
	}

	/**
	 * Sets every connection to one status.
	 *
	 * @param string $status New status.
	 *
	 * @return void
	 */
	private static function mark_all( string $status ): void {
		$connections = self::all();
		if ( empty( $connections ) ) {
			return;
		}

		foreach ( $connections as $id => $connection ) {
			$connections[ $id ]['status'] = $status;
		}
		update_option( self::OPTION, $connections, false );
	}
}
