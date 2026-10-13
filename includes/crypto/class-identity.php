<?php
/**
 * This site's signing key.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Crypto;

use Jcore\Silta\Remote\Connections;
use Jcore\Silta\Site;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the Ed25519 key pair this site signs its outgoing requests with.
 *
 * The pair is created the first time the site adds an outgoing connection; a
 * site that only receives never has a private key. The 32-byte seed lives in
 * the database encrypted with the Vault, or in wp-config.php as
 * JCORE_SILTA_PRIVATE_KEY (base64), in which case the database copy is removed.
 */
final class Identity {

	/**
	 * Option holding the public key and the encrypted seed.
	 */
	public const OPTION = 'jcore_silta_identity';

	/**
	 * Constant that can hold the base64 seed instead of the database.
	 */
	public const CONSTANT = 'JCORE_SILTA_PRIVATE_KEY';

	/**
	 * Seed for this request, once decrypted.
	 *
	 * @var string|null
	 */
	private static ?string $seed = null;

	/**
	 * Where the key lives: `constant`, `database`, `unreadable` (stored, but
	 * the salts changed) or `none`.
	 *
	 * @return string
	 */
	public static function storage(): string {
		if ( null !== self::constant_seed() ) {
			return 'constant';
		}

		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['seed'] ) ) {
			return 'none';
		}

		return null === Vault::decrypt( (string) $stored['seed'] ) ? 'unreadable' : 'database';
	}

	/**
	 * Whether a usable key exists.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		return null !== self::seed();
	}

	/**
	 * Makes sure a usable key exists, creating one if needed.
	 *
	 * A stored key that can't be decrypted any more is replaced, and the
	 * connections that relied on it are marked as needing a new pairing.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure(): bool|WP_Error {
		if ( self::exists() ) {
			return true;
		}

		if ( defined( self::CONSTANT ) ) {
			return new WP_Error(
				'jcore_silta_bad_constant',
				__( 'JCORE_SILTA_PRIVATE_KEY in wp-config.php is not a valid key. Remove it or replace it with the value from "wp silta key export".', 'jcore-silta' )
			);
		}

		if ( 'unreadable' === self::storage() ) {
			Connections::mark_key_lost();
		}

		self::store( random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES ) );

		return true;
	}

	/**
	 * Replaces the key pair with a new one. Every remote needs a new pairing.
	 *
	 * @return true|WP_Error
	 */
	public static function regenerate(): bool|WP_Error {
		if ( null !== self::constant_seed() ) {
			return new WP_Error(
				'jcore_silta_key_in_config',
				__( 'The key is defined in wp-config.php. Change it there.', 'jcore-silta' )
			);
		}

		Connections::mark_key_lost();
		self::store( random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES ) );

		return true;
	}

	/**
	 * Returns the raw public key, or null without a key.
	 *
	 * @return string|null
	 */
	public static function public_key(): ?string {
		$seed = self::seed();
		if ( null === $seed ) {
			return null;
		}

		return sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( $seed ) );
	}

	/**
	 * Returns the key ID, or an empty string without a key.
	 *
	 * @return string
	 */
	public static function fingerprint(): string {
		$public = self::public_key();

		return null === $public ? '' : Signature::fingerprint( $public );
	}

	/**
	 * Signs a message.
	 *
	 * @param string $message Message to sign.
	 *
	 * @return string|WP_Error Raw signature.
	 */
	public static function sign( string $message ): string|WP_Error {
		$seed = self::seed();
		if ( null === $seed ) {
			return new WP_Error( 'jcore_silta_no_key', __( 'This site has no usable signing key. Add the connection again.', 'jcore-silta' ) );
		}

		$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( $seed ) );

		return sodium_crypto_sign_detached( $message, $secret );
	}

	/**
	 * Returns the seed as base64, for moving the key into wp-config.php.
	 *
	 * @return string|null
	 */
	public static function export_seed(): ?string {
		$seed = self::seed();

		return null === $seed ? null : base64_encode( $seed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text.
	}

	/**
	 * Removes the copy in the database once wp-config.php holds the key.
	 *
	 * @return void
	 */
	public static function sync_with_constant(): void {
		if ( null === self::constant_seed() ) {
			return;
		}

		if ( false !== get_option( self::OPTION ) ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Forgets a key stored in the database. Used when the database turns out to
	 * be a copy of another site's.
	 *
	 * @return void
	 */
	public static function discard_stored(): void {
		delete_option( self::OPTION );
		self::$seed = null;
	}

	/**
	 * Returns what the admin screens show about the key.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		$public = self::public_key();

		return array(
			'storage'     => self::storage(),
			'fingerprint' => null === $public ? '' : Signature::fingerprint( $public ),
			'public_key'  => null === $public ? '' : base64_encode( $public ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text.
			'weak'        => Vault::is_weak(),
			'site'        => Site::uuid(),
		);
	}

	/**
	 * Returns the seed, from wp-config.php or the database.
	 *
	 * @return string|null
	 */
	private static function seed(): ?string {
		if ( null !== self::$seed ) {
			return self::$seed;
		}

		$constant = self::constant_seed();
		if ( null !== $constant ) {
			self::$seed = $constant;

			return self::$seed;
		}

		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['seed'] ) ) {
			return null;
		}

		$seed = Vault::decrypt( (string) $stored['seed'] );
		if ( null === $seed || SODIUM_CRYPTO_SIGN_SEEDBYTES !== strlen( $seed ) ) {
			return null;
		}

		self::$seed = $seed;

		return self::$seed;
	}

	/**
	 * Returns the seed from wp-config.php, if it is defined and valid.
	 *
	 * @return string|null
	 */
	private static function constant_seed(): ?string {
		if ( ! defined( self::CONSTANT ) ) {
			return null;
		}

		$seed = base64_decode( (string) constant( self::CONSTANT ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text to binary.

		return false !== $seed && SODIUM_CRYPTO_SIGN_SEEDBYTES === strlen( $seed ) ? $seed : null;
	}

	/**
	 * Encrypts and stores a seed.
	 *
	 * @param string $seed Raw seed.
	 *
	 * @return void
	 */
	private static function store( string $seed ): void {
		update_option(
			self::OPTION,
			array(
				'public'  => base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( $seed ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text.
				'seed'    => Vault::encrypt( $seed ),
				'created' => time(),
			),
			false
		);

		self::$seed = $seed;
	}
}
