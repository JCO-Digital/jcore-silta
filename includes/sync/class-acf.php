<?php
/**
 * ACF field lookups.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes ACF fields by key, for remapping and for grouping meta.
 *
 * Works with ACF, ACF Pro and Secure Custom Fields, which share the API.
 */
final class Acf {

	/**
	 * Field types whose stored value holds object IDs, and the kind of object.
	 */
	public const ID_TYPES = array(
		'image'        => 'post',
		'file'         => 'post',
		'gallery'      => 'post',
		'post_object'  => 'post',
		'relationship' => 'post',
		'page_link'    => 'post',
		'taxonomy'     => 'term',
		'user'         => 'user',
		// The "ACF: Gravity Forms field" add-on stores form IDs.
		'forms'        => 'form',
	);

	/**
	 * Descriptions looked up in this request.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private static array $cache = array();

	/**
	 * Whether an ACF-compatible plugin is active.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return function_exists( 'acf_get_field' );
	}

	/**
	 * Whether a value looks like an ACF field key.
	 *
	 * @param mixed $value Value to test.
	 *
	 * @return bool
	 */
	public static function is_key( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^field_[A-Za-z0-9_]+$/', $value );
	}

	/**
	 * Describes a field: type, name, label and the top-level field it belongs to.
	 *
	 * @param string $key Field key.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function describe( string $key ): ?array {
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		self::$cache[ $key ] = null;
		if ( ! self::active() ) {
			return null;
		}

		$field = self::field( $key );
		if ( null === $field ) {
			return null;
		}

		$top = $field;
		for ( $depth = 0; $depth < 12; $depth++ ) {
			$parent = $top['parent'] ?? 0;
			if ( empty( $parent ) ) {
				break;
			}
			$parent_field = self::field( $parent );
			if ( null === $parent_field ) {
				break;
			}
			$top = $parent_field;
		}

		self::$cache[ $key ] = array(
			'type'      => (string) ( $field['type'] ?? '' ),
			'name'      => (string) ( $field['name'] ?? '' ),
			'label'     => (string) ( $field['label'] ?? '' ),
			'top'       => (string) ( $top['name'] ?? '' ),
			'top_label' => (string) ( $top['label'] ?? '' ),
		);

		return self::$cache[ $key ];
	}

	/**
	 * Whether this site knows a field key.
	 *
	 * @param string $key Field key.
	 *
	 * @return bool
	 */
	public static function exists( string $key ): bool {
		return self::active() && null !== self::field( $key );
	}

	/**
	 * Loads a field by key or ID, resolving the composite keys clone fields use.
	 *
	 * @param int|string $key Field key or post ID.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function field( int|string $key ): ?array {
		$field = acf_get_field( $key );
		if ( is_array( $field ) && ! empty( $field['type'] ) ) {
			return $field;
		}

		// Clone fields reference sub fields as field_<clone>_field_<sub>.
		if ( is_string( $key ) && str_contains( substr( $key, 6 ), '_field_' ) ) {
			$sub   = 'field_' . substr( $key, strrpos( $key, '_field_' ) + 7 );
			$field = acf_get_field( $sub );
			if ( is_array( $field ) && ! empty( $field['type'] ) ) {
				return $field;
			}
		}

		return null;
	}
}
