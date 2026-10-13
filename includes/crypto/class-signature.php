<?php
/**
 * Request signatures.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the signed message and checks Ed25519 signatures.
 */
final class Signature {

	/**
	 * Version tag at the start of every signed message.
	 */
	public const VERSION = 'silta-v1';

	/**
	 * Seconds a signed request stays valid either side of now.
	 */
	public const WINDOW = 300;

	/**
	 * Builds the canonical message for a request.
	 *
	 * @param string $remote_site UUID of the site the request is for.
	 * @param string $method      HTTP method.
	 * @param string $route       REST route, e.g. `/jcore-silta/v1/remote/export`.
	 * @param string $timestamp   Unix time as sent in the header.
	 * @param string $nonce       Random nonce as sent in the header.
	 * @param string $body        Raw request body.
	 *
	 * @return string
	 */
	public static function message( string $remote_site, string $method, string $route, string $timestamp, string $nonce, string $body ): string {
		return implode(
			"\n",
			array(
				self::VERSION,
				strtolower( $remote_site ),
				strtoupper( $method ),
				'/' . trim( $route, '/' ),
				$timestamp,
				$nonce,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Checks a detached signature.
	 *
	 * @param string $public_key Raw 32-byte public key.
	 * @param string $signature  Raw 64-byte signature.
	 * @param string $message    The canonical message.
	 *
	 * @return bool
	 */
	public static function verify( string $public_key, string $signature, string $message ): bool {
		if ( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $signature, $message, $public_key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Returns the key ID of a public key: the first 12 bytes of its SHA-256, in hex.
	 *
	 * @param string $public_key Raw public key.
	 *
	 * @return string
	 */
	public static function fingerprint( string $public_key ): string {
		return substr( hash( 'sha256', $public_key ), 0, 24 );
	}
}
