<?php
/**
 * Small helpers shared across the plugin.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encoding and time helpers.
 */
final class Util {

	/**
	 * Base64url-encodes binary data, without padding.
	 *
	 * @param string $data Data to encode.
	 *
	 * @return string
	 */
	public static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text.
	}

	/**
	 * Decodes base64url data.
	 *
	 * @param string $data Encoded data.
	 *
	 * @return string|null Null when the input is not valid base64url.
	 */
	public static function base64url_decode( string $data ): ?string {
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text to binary.

		return false === $decoded ? null : $decoded;
	}

	/**
	 * Returns the current UTC time in MySQL format.
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Decodes a JSON column into an array.
	 *
	 * @param string|null $json Stored JSON.
	 *
	 * @return array<mixed>
	 */
	public static function decode( ?string $json ): array {
		if ( null === $json || '' === $json ) {
			return array();
		}

		$value = json_decode( $json, true );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Encodes a value for a JSON column.
	 *
	 * @param mixed $value Value to encode.
	 *
	 * @return string
	 */
	public static function encode( mixed $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	}

	/**
	 * Builds the `type:id` key objects are referred to by.
	 *
	 * @param string $type Object type: post, term or menu.
	 * @param int    $id   Object ID.
	 *
	 * @return string
	 */
	public static function key( string $type, int $id ): string {
		return $type . ':' . $id;
	}

	/**
	 * Splits a `type:id` key.
	 *
	 * @param string $key Key to split.
	 *
	 * @return array{0: string, 1: int}
	 */
	public static function split_key( string $key ): array {
		$parts = explode( ':', $key, 2 );

		return array( $parts[0], (int) ( $parts[1] ?? 0 ) );
	}
}
