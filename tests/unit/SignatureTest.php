<?php
/**
 * Tests for request signatures and encoding helpers.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Crypto\Signature;
use Jcore\Silta\Util;
use PHPUnit\Framework\TestCase;

/**
 * Signature and Util.
 */
final class SignatureTest extends TestCase {

	public function test_message_is_canonical(): void {
		$message = Signature::message( 'ABC-1', 'post', 'jcore-silta/v1/remote/export/', '1700000000', 'abcd', '{"a":1}' );

		$this->assertSame(
			"silta-v1\nabc-1\nPOST\n/jcore-silta/v1/remote/export\n1700000000\nabcd\n" . hash( 'sha256', '{"a":1}' ),
			$message
		);
	}

	public function test_signatures_verify_and_tampering_fails(): void {
		$pair    = sodium_crypto_sign_seed_keypair( str_repeat( 'k', SODIUM_CRYPTO_SIGN_SEEDBYTES ) );
		$public  = sodium_crypto_sign_publickey( $pair );
		$message = Signature::message( 'site', 'POST', '/jcore-silta/v1/remote/import', '1', 'n', 'body' );
		$sig     = sodium_crypto_sign_detached( $message, sodium_crypto_sign_secretkey( $pair ) );

		$this->assertTrue( Signature::verify( $public, $sig, $message ) );
		$this->assertFalse( Signature::verify( $public, $sig, Signature::message( 'site', 'POST', '/jcore-silta/v1/remote/import', '1', 'n', 'other body' ) ) );
		$this->assertFalse( Signature::verify( $public, $sig, Signature::message( 'other-site', 'POST', '/jcore-silta/v1/remote/import', '1', 'n', 'body' ) ) );
		$this->assertFalse( Signature::verify( 'short', $sig, $message ) );
	}

	public function test_fingerprint(): void {
		$this->assertSame( substr( hash( 'sha256', 'key' ), 0, 24 ), Signature::fingerprint( 'key' ) );
	}

	public function test_base64url_round_trip(): void {
		$data = random_bytes( 64 );

		$this->assertSame( $data, Util::base64url_decode( Util::base64url_encode( $data ) ) );
		$this->assertDoesNotMatchRegularExpression( '/[+\/=]/', Util::base64url_encode( $data ) );
	}

	public function test_keys(): void {
		$this->assertSame( 'post:12', Util::key( 'post', 12 ) );
		$this->assertSame( array( 'term', 5 ), Util::split_key( 'term:5' ) );
	}
}
