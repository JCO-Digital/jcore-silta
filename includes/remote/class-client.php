<?php
/**
 * Signed HTTP client for remote sites.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Remote;

use Jcore\Silta\Crypto\Identity;
use Jcore\Silta\Crypto\Signature;
use Jcore\Silta\Util;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls a remote's `jcore-silta/v1/remote/*` routes with signed requests.
 */
final class Client {

	/**
	 * Namespace the remote routes live in.
	 */
	private const ROUTE_BASE = '/jcore-silta/v1/remote/';

	/**
	 * The connection to call.
	 *
	 * @var array<string, mixed>
	 */
	private array $connection;

	/**
	 * Creates a client for a connection.
	 *
	 * @param array<string, mixed> $connection Connection from Connections.
	 */
	public function __construct( array $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Calls a remote action.
	 *
	 * @param string               $action  Route below remote/, e.g. `export`.
	 * @param array<string, mixed> $payload JSON body.
	 * @param int                  $timeout Seconds to wait.
	 *
	 * @return array<string, mixed>|WP_Error Decoded response.
	 */
	public function call( string $action, array $payload = array(), int $timeout = 60 ): array|WP_Error {
		$status = $this->connection['status'] ?? 'ok';
		if ( 'inherited' === $status ) {
			return new WP_Error( 'jcore_silta_inherited', __( 'This connection came with a copied database. Pair the sites again.', 'jcore-silta' ) );
		}
		if ( 'repair' === $status ) {
			return new WP_Error( 'jcore_silta_repair', __( "This site's key has changed since the connection was made. Pair the sites again.", 'jcore-silta' ) );
		}

		return self::send(
			(string) $this->connection['rest'],
			(string) $this->connection['site'],
			$action,
			$payload,
			Connections::auth( $this->connection ),
			$timeout
		);
	}

	/**
	 * Redeems a pairing token on a remote.
	 *
	 * The request is signed with the key it registers, which proves this site
	 * holds the private half.
	 *
	 * @param string                    $rest        Remote REST base URL.
	 * @param string                    $remote_site Remote site UUID.
	 * @param array<string, mixed>      $payload     Token, public key and site details.
	 * @param array<string, mixed>|null $auth        Stored (encrypted) basic auth.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function pair( string $rest, string $remote_site, array $payload, ?array $auth ): array|WP_Error {
		return self::send( $rest, $remote_site, 'pair', $payload, Connections::auth( array( 'auth' => $auth ) ), 30 );
	}

	/**
	 * Signs and sends a request.
	 *
	 * @param string                                 $rest        Remote REST base URL.
	 * @param string                                 $remote_site Remote site UUID.
	 * @param string                                 $action      Route below remote/.
	 * @param array<string, mixed>                   $payload     JSON body.
	 * @param array{user: string, pass: string}|null $auth        Basic auth.
	 * @param int                                    $timeout     Seconds to wait.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function send( string $rest, string $remote_site, string $action, array $payload, ?array $auth, int $timeout ): array|WP_Error {
		$body      = Util::encode( $payload );
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		$route     = self::ROUTE_BASE . $action;

		$signature = Identity::sign( Signature::message( $remote_site, 'POST', $route, $timestamp, $nonce, $body ) );
		if ( is_wp_error( $signature ) ) {
			return $signature;
		}

		$headers = array(
			'Content-Type'      => 'application/json',
			'Accept'            => 'application/json',
			'X-Silta-Key'       => Identity::fingerprint(),
			'X-Silta-Timestamp' => $timestamp,
			'X-Silta-Nonce'     => $nonce,
			'X-Silta-Signature' => base64_encode( $signature ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text.
		);
		if ( null !== $auth ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $auth['user'] . ':' . $auth['pass'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth.
		}

		$url = trailingslashit( $rest ) . 'remote/' . $action;
		if ( str_contains( $rest, 'rest_route=' ) ) {
			// Plain permalinks: the route is a query argument, which must not get a slash appended.
			$url = untrailingslashit( $rest ) . '/remote/' . $action;
		}

		/**
		 * Filters the URL of a request to a remote site.
		 *
		 * Useful when the address the remote advertises isn't reachable from this
		 * server, e.g. between containers in a local development stack.
		 *
		 * @param string $url    URL about to be requested.
		 * @param string $action Remote action.
		 */
		$url = (string) apply_filters( 'jcore_silta_remote_url', $url, $action );

		$response = wp_remote_post(
			$url,
			array(
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'jcore_silta_unreachable',
				sprintf(
					/* translators: %s: error message from the HTTP client. */
					__( 'The remote site could not be reached: %s', 'jcore-silta' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 300 && $code < 400 ) {
			return new WP_Error(
				'jcore_silta_redirect',
				sprintf(
					/* translators: %s: URL the remote redirected to. */
					__( 'The remote site redirected the request to %s. Pair the sites again using that address.', 'jcore-silta' ),
					(string) wp_remote_retrieve_header( $response, 'location' )
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'jcore_silta_bad_response',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The remote site answered with something other than Silta data (HTTP %d). Check that the plugin is active there and that nothing blocks its REST API.', 'jcore-silta' ),
					$code
				)
			);
		}

		if ( $code >= 400 ) {
			return new WP_Error(
				(string) ( $data['code'] ?? 'jcore_silta_remote_error' ),
				(string) ( $data['message'] ?? __( 'The remote site returned an error.', 'jcore-silta' ) ),
				array( 'status' => $code )
			);
		}

		return $data;
	}
}
