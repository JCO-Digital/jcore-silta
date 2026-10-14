<?php
/**
 * Sites allowed to call this one.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Remote;

use Jcore\Silta\Crypto\Signature;
use Jcore\Silta\Site;
use Jcore\Silta\Util;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores pairing tokens and the public keys of paired sites.
 *
 * Only token hashes and public keys are stored, so nothing here lets anyone
 * sign a request to this site.
 */
final class Trusted_Keys {

	/**
	 * Option holding tokens and keys.
	 */
	public const OPTION = 'jcore_silta_keys';

	/**
	 * Seconds a pairing token stays valid.
	 */
	public const TOKEN_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Scopes a key can have.
	 */
	public const SCOPES = array( 'read', 'write' );

	/**
	 * Prefix of pairing strings, with the format version.
	 */
	public const PREFIX = 'silta1.';

	/**
	 * Creates a one-time pairing token and returns the string to paste on the
	 * other site.
	 *
	 * @param string   $label   What the admin calls the other site.
	 * @param string[] $scopes  Scopes the paired site gets.
	 * @param int      $user_id Admin the paired site will act as.
	 *
	 * @return string
	 */
	public static function create_token( string $label, array $scopes, int $user_id ): string {
		$token = Util::base64url_encode( random_bytes( 32 ) );
		$data  = self::load();

		$data['tokens'][ hash( 'sha256', $token ) ] = array(
			'label'   => $label,
			'scopes'  => self::clean_scopes( $scopes ),
			'user_id' => $user_id,
			'expires' => time() + self::TOKEN_TTL,
		);
		self::save( $data );

		$payload = array(
			'v'     => 1,
			'rest'  => rest_url( 'jcore-silta/v1/' ),
			'home'  => Site::home(),
			'site'  => Site::uuid(),
			'name'  => get_bloginfo( 'name' ),
			'token' => $token,
		);

		return self::PREFIX . Util::base64url_encode( Util::encode( $payload ) );
	}

	/**
	 * Redeems a pairing token and trusts the public key that came with it.
	 *
	 * @param string               $token      Token from the pairing string.
	 * @param string               $public_key Base64 public key of the calling site.
	 * @param array<string, mixed> $site       Calling site's details.
	 *
	 * @return array<string, mixed>|WP_Error The stored key.
	 */
	public static function pair( string $token, string $public_key, array $site ): array|WP_Error {
		$data = self::load();
		$hash = hash( 'sha256', $token );

		if ( empty( $data['tokens'][ $hash ] ) ) {
			return new WP_Error( 'jcore_silta_bad_token', __( 'This pairing string is not valid or has already been used.', 'jcore-silta' ), array( 'status' => 403 ) );
		}

		$pending = $data['tokens'][ $hash ];
		unset( $data['tokens'][ $hash ] );

		if ( (int) $pending['expires'] < time() ) {
			self::save( $data );

			return new WP_Error( 'jcore_silta_token_expired', __( 'This pairing string has expired. Create a new one.', 'jcore-silta' ), array( 'status' => 403 ) );
		}

		$raw = base64_decode( $public_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text to binary.
		if ( false === $raw || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $raw ) ) {
			return new WP_Error( 'jcore_silta_bad_key', __( 'The public key is not valid.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		$uuid = strtolower( sanitize_text_field( (string) ( $site['uuid'] ?? '' ) ) );
		if ( ! wp_is_uuid( $uuid ) ) {
			return new WP_Error( 'jcore_silta_bad_site', __( 'The site ID is not valid.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		$fingerprint = Signature::fingerprint( $raw );
		foreach ( $data['keys'] as $existing_fingerprint => $existing ) {
			if ( ( strtolower( (string) $existing['site'] ) === $uuid && $existing_fingerprint !== $fingerprint && ! empty( $existing['enabled'] ) ) || ( $existing_fingerprint === $fingerprint && strtolower( (string) $existing['site'] ) !== $uuid ) ) {
				return new WP_Error( 'jcore_silta_key_exists', __( 'This site already has a trusted key. Revoke the old key before pairing a different key.', 'jcore-silta' ), array( 'status' => 409 ) );
			}
		}
		// Disabled inherited keys may be replaced after a clone is reviewed.
		foreach ( $data['keys'] as $existing_fingerprint => $existing ) {
			if ( strtolower( (string) $existing['site'] ) === $uuid && $existing_fingerprint !== $fingerprint ) {
				unset( $data['keys'][ $existing_fingerprint ] );
			}
		}

		$key = array(
			'fingerprint' => $fingerprint,
			'public'      => $public_key,
			'label'       => '' !== $pending['label'] ? $pending['label'] : sanitize_text_field( (string) ( $site['name'] ?? '' ) ),
			'scopes'      => $pending['scopes'],
			'user_id'     => (int) $pending['user_id'],
			'site'        => $uuid,
			'home'        => esc_url_raw( (string) ( $site['home'] ?? '' ) ),
			'name'        => sanitize_text_field( (string) ( $site['name'] ?? '' ) ),
			'created'     => time(),
			'last_used'   => 0,
			'enabled'     => true,
			'inherited'   => false,
		);

		$data['keys'][ $fingerprint ] = $key;
		self::save( $data );

		return $key;
	}

	/**
	 * Returns a trusted key by fingerprint.
	 *
	 * @param string $fingerprint Key ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $fingerprint ): ?array {
		return self::load()['keys'][ $fingerprint ] ?? null;
	}

	/**
	 * Returns every trusted key.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		return array_values( self::load()['keys'] );
	}

	/**
	 * Returns the tokens still waiting to be used, without their hashes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function pending(): array {
		$tokens = array();
		foreach ( self::load()['tokens'] as $hash => $token ) {
			if ( (int) $token['expires'] < time() ) {
				continue;
			}
			$tokens[] = array(
				'id'      => substr( $hash, 0, 12 ),
				'label'   => $token['label'],
				'scopes'  => $token['scopes'],
				'expires' => (int) $token['expires'],
			);
		}

		return $tokens;
	}

	/**
	 * Cancels a pending token by the ID pending() returns.
	 *
	 * @param string $id First 12 characters of the token hash.
	 *
	 * @return bool
	 */
	public static function cancel_token( string $id ): bool {
		$data = self::load();
		foreach ( array_keys( $data['tokens'] ) as $hash ) {
			if ( str_starts_with( $hash, $id ) ) {
				unset( $data['tokens'][ $hash ] );
				self::save( $data );

				return true;
			}
		}

		return false;
	}

	/**
	 * Changes the label, scopes or enabled state of a key.
	 *
	 * @param string               $fingerprint Key ID.
	 * @param array<string, mixed> $changes     New values.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( string $fingerprint, array $changes ): array|WP_Error {
		$data = self::load();
		if ( empty( $data['keys'][ $fingerprint ] ) ) {
			return new WP_Error( 'jcore_silta_not_found', __( 'No such site.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$key = $data['keys'][ $fingerprint ];
		if ( isset( $changes['label'] ) ) {
			$key['label'] = sanitize_text_field( (string) $changes['label'] );
		}
		if ( isset( $changes['scopes'] ) ) {
			$key['scopes'] = self::clean_scopes( (array) $changes['scopes'] );
		}
		if ( isset( $changes['enabled'] ) ) {
			$key['enabled'] = (bool) $changes['enabled'];
			if ( $key['enabled'] ) {
				$key['inherited'] = false;
			}
		}

		$data['keys'][ $fingerprint ] = $key;
		self::save( $data );

		return $key;
	}

	/**
	 * Revokes a key.
	 *
	 * @param string $fingerprint Key ID.
	 *
	 * @return bool
	 */
	public static function delete( string $fingerprint ): bool {
		$data = self::load();
		if ( empty( $data['keys'][ $fingerprint ] ) ) {
			return false;
		}

		unset( $data['keys'][ $fingerprint ] );
		self::save( $data );
		delete_transient( 'jcore_silta_key_used_' . $fingerprint );

		return true;
	}

	/**
	 * Records that a key was used, at most once a minute.
	 *
	 * @param string $fingerprint Key ID.
	 *
	 * @return void
	 */
	public static function touch( string $fingerprint ): void {
		$key = self::get( $fingerprint );
		if ( null === $key || empty( $key['enabled'] ) || (int) $key['last_used'] > time() - MINUTE_IN_SECONDS ) {
			return;
		}

		set_transient( 'jcore_silta_key_used_' . $fingerprint, time(), YEAR_IN_SECONDS );
	}

	/**
	 * Switches off every key and drops every token. Called when the database
	 * turns out to be a copy of another site's.
	 *
	 * @return void
	 */
	public static function mark_inherited(): void {
		$data = self::load();
		foreach ( $data['keys'] as $fingerprint => $key ) {
			$data['keys'][ $fingerprint ]['enabled']   = false;
			$data['keys'][ $fingerprint ]['inherited'] = true;
		}
		$data['tokens'] = array();
		self::save( $data );
	}

	/**
	 * Keeps only known scopes.
	 *
	 * @param string[] $scopes Requested scopes.
	 *
	 * @return string[]
	 */
	public static function clean_scopes( array $scopes ): array {
		return array_values( array_intersect( self::SCOPES, array_map( 'strval', $scopes ) ) );
	}

	/**
	 * Loads the option, dropping expired tokens.
	 *
	 * @return array{tokens: array<string, array<string, mixed>>, keys: array<string, array<string, mixed>>}
	 */
	private static function load(): array {
		$data = get_option( self::OPTION );
		$data = array(
			'tokens' => is_array( $data['tokens'] ?? null ) ? $data['tokens'] : array(),
			'keys'   => is_array( $data['keys'] ?? null ) ? $data['keys'] : array(),
		);

		foreach ( $data['tokens'] as $hash => $token ) {
			if ( (int) ( $token['expires'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
				unset( $data['tokens'][ $hash ] );
			}
		}

		foreach ( $data['keys'] as $fingerprint => $key ) {
			$data['keys'][ $fingerprint ]['last_used'] = max( (int) ( $key['last_used'] ?? 0 ), (int) get_transient( 'jcore_silta_key_used_' . $fingerprint ) );
		}

		return $data;
	}

	/**
	 * Stores the option.
	 *
	 * @param array<string, mixed> $data Tokens and keys.
	 *
	 * @return void
	 */
	private static function save( array $data ): void {
		update_option( self::OPTION, $data, false );
	}
}
