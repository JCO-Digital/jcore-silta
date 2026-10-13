<?php
/**
 * Encryption at rest.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts secrets stored in the database with a key derived from the salts in
 * wp-config.php, so a database dump on its own reveals nothing usable.
 */
final class Vault {

	/**
	 * HKDF context string; changing it makes every stored secret unreadable.
	 */
	private const CONTEXT = 'jcore-silta-vault-v1';

	/**
	 * The placeholder WordPress ships in wp-config-sample.php.
	 */
	private const PLACEHOLDER = 'put your unique phrase here';

	/**
	 * Encrypts a value.
	 *
	 * @param string $plain Value to encrypt.
	 *
	 * @return string Base64 of nonce and ciphertext.
	 */
	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text.
	}

	/**
	 * Decrypts a value from encrypt().
	 *
	 * @param string $blob Base64 of nonce and ciphertext.
	 *
	 * @return string|null Null when the value can't be decrypted (other salts).
	 */
	public static function decrypt( string $blob ): ?string {
		$raw = base64_decode( $blob, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text to binary.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		} catch ( \Throwable $e ) {
			return null;
		}

		return false === $plain ? null : $plain;
	}

	/**
	 * Whether the salts live in the database rather than wp-config.php, in which
	 * case the encryption adds nothing.
	 *
	 * @return bool
	 */
	public static function is_weak(): bool {
		foreach ( array( 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT' ) as $name ) {
			if ( ! defined( $name ) ) {
				return true;
			}
			$value = (string) constant( $name );
			if ( '' === $value || self::PLACEHOLDER === $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Derives the encryption key.
	 *
	 * @return string
	 */
	private static function key(): string {
		$material = self::is_weak()
			? wp_salt( 'secure_auth' )
			: constant( 'SECURE_AUTH_KEY' ) . constant( 'SECURE_AUTH_SALT' );

		return hash_hkdf( 'sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::CONTEXT );
	}
}
