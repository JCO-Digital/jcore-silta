<?php
/**
 * Checks signed requests from paired sites.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Remote;

use Jcore\Silta\Crypto\Signature;
use Jcore\Silta\Site;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission callbacks for the remote routes.
 */
final class Verifier {

	/**
	 * Transient prefix for seen nonces.
	 */
	private const NONCE_PREFIX = 'jcore_silta_n_';

	/**
	 * The key that signed the current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $current = null;

	/**
	 * Returns the key that signed the current request.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		return self::$current;
	}

	/**
	 * Hooks what has to happen before WordPress authenticates the request.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'application_password_is_api_request', array( self::class, 'skip_application_passwords' ) );
	}

	/**
	 * Keeps WordPress from treating a basic auth header meant for a protected
	 * staging site as a failed Application Password login.
	 *
	 * @param bool $is_api_request Whether WordPress treats the request as an API request.
	 *
	 * @return bool
	 */
	public static function skip_application_passwords( bool $is_api_request ): bool {
		if ( ! $is_api_request || empty( $_SERVER['HTTP_X_SILTA_SIGNATURE'] ) ) {
			return $is_api_request;
		}

		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$route = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only.

		if ( str_contains( $uri, 'jcore-silta/v1/remote/' ) || str_contains( $route, 'jcore-silta/v1/remote/' ) ) {
			return false;
		}

		return $is_api_request;
	}

	/**
	 * Returns a permission callback requiring a scope.
	 *
	 * @param string $scope `read`, `write` or `any`.
	 *
	 * @return callable
	 */
	public static function requires( string $scope ): callable {
		return static function ( WP_REST_Request $request ) use ( $scope ) {
			return self::verify( $request, $scope );
		};
	}

	/**
	 * Verifies a request's signature, freshness, key and scope, then switches to
	 * the user the key acts as.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $scope   Scope the route needs.
	 *
	 * @return true|WP_Error
	 */
	public static function verify( WP_REST_Request $request, string $scope ): bool|WP_Error {
		$fingerprint = (string) $request->get_header( 'x_silta_key' );
		$timestamp   = (string) $request->get_header( 'x_silta_timestamp' );
		$nonce       = (string) $request->get_header( 'x_silta_nonce' );
		$signature   = base64_decode( (string) $request->get_header( 'x_silta_signature' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text to binary.

		$denied = new WP_Error( 'jcore_silta_unauthorized', __( 'This site does not recognise the calling site. Pair the sites again.', 'jcore-silta' ), array( 'status' => 401 ) );

		if ( '' === $fingerprint || '' === $nonce || false === $signature || ! ctype_digit( $timestamp ) || ! preg_match( '/^[a-f0-9]{16,64}$/', $nonce ) ) {
			return $denied;
		}

		if ( abs( time() - (int) $timestamp ) > Signature::WINDOW ) {
			return new WP_Error( 'jcore_silta_clock', __( "The request is too old, or the two servers' clocks disagree by more than five minutes.", 'jcore-silta' ), array( 'status' => 401 ) );
		}

		$key = Trusted_Keys::get( $fingerprint );
		if ( null === $key ) {
			return $denied;
		}

		$message = Signature::message( Site::uuid(), $request->get_method(), $request->get_route(), $timestamp, $nonce, $request->get_body() );
		$public  = base64_decode( (string) $key['public'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text to binary.
		if ( false === $public || ! Signature::verify( $public, $signature, $message ) ) {
			return $denied;
		}

		$seen = self::NONCE_PREFIX . md5( $fingerprint . $nonce );
		if ( get_transient( $seen ) ) {
			return new WP_Error( 'jcore_silta_replay', __( 'This request has already been received.', 'jcore-silta' ), array( 'status' => 401 ) );
		}
		set_transient( $seen, 1, 2 * Signature::WINDOW );

		if ( empty( $key['enabled'] ) ) {
			return new WP_Error(
				'jcore_silta_disabled',
				! empty( $key['inherited'] )
					? __( 'The remote site was copied from another site, so its connections are switched off. An admin there has to re-enable this site, or you pair again.', 'jcore-silta' )
					: __( 'An admin on the remote site has switched off this connection.', 'jcore-silta' ),
				array( 'status' => 403 )
			);
		}

		if ( 'any' !== $scope && ! in_array( $scope, (array) $key['scopes'], true ) ) {
			return new WP_Error(
				'jcore_silta_scope',
				'write' === $scope
					? __( 'The remote site does not allow this site to write to it.', 'jcore-silta' )
					: __( 'The remote site does not allow this site to read from it.', 'jcore-silta' ),
				array( 'status' => 403 )
			);
		}

		$user = get_userdata( (int) $key['user_id'] );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			return new WP_Error( 'jcore_silta_no_user', __( 'The admin who allowed this connection on the remote site no longer has the rights to do so. Pair the sites again.', 'jcore-silta' ), array( 'status' => 403 ) );
		}

		wp_set_current_user( $user->ID );
		Trusted_Keys::touch( $fingerprint );
		self::$current = $key;

		return true;
	}

	/**
	 * Permission callback for the pairing route: the token in the body is the
	 * credential, and the signature proves the caller holds the key it registers.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_pairing( WP_REST_Request $request ): bool|WP_Error {
		$params    = $request->get_json_params();
		$public    = base64_decode( (string) ( $params['public_key'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text to binary.
		$timestamp = (string) $request->get_header( 'x_silta_timestamp' );
		$nonce     = (string) $request->get_header( 'x_silta_nonce' );
		$signature = base64_decode( (string) $request->get_header( 'x_silta_signature' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text to binary.

		$denied = new WP_Error( 'jcore_silta_bad_pairing', __( 'The pairing request could not be verified.', 'jcore-silta' ), array( 'status' => 401 ) );

		if ( false === $public || false === $signature || ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > Signature::WINDOW ) {
			return $denied;
		}

		$message = Signature::message( Site::uuid(), $request->get_method(), $request->get_route(), $timestamp, $nonce, $request->get_body() );

		return Signature::verify( $public, $signature, $message ) ? true : $denied;
	}
}
