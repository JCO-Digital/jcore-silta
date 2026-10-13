<?php
/**
 * Mapping IDs inside stored values.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\References;

use Jcore\Silta\Sync\Replacer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a visitor to the IDs in raw meta values and decoded data.
 *
 * A visitor is `callable( string $kind, int $id ): ?int`; kind is post, term or
 * user. It returns the ID to store, or null to drop the reference.
 */
final class Values {

	/**
	 * Maps the IDs in a raw meta value: a number, or a serialized list of them.
	 * Anything else (URLs, text) is left alone.
	 *
	 * @param string   $raw   Raw value.
	 * @param string   $kind  Kind of the IDs.
	 * @param callable $visit Visitor.
	 *
	 * @return string
	 */
	public static function map_raw( string $raw, string $kind, callable $visit ): string {
		if ( '' === $raw ) {
			return $raw;
		}

		if ( ctype_digit( $raw ) ) {
			$id = $visit( $kind, (int) $raw );

			return null === $id ? '' : (string) $id;
		}

		if ( Replacer::is_serialized( $raw ) ) {
			$value = unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- No classes allowed, so nothing is instantiated.
			if ( is_array( $value ) ) {
				return serialize( self::map_value( $value, $kind, $visit ) ?? array() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Writes the format it read.
			}
		}

		return $raw;
	}

	/**
	 * Maps the IDs in a decoded value, keeping each ID's type (int or numeric
	 * string). Dropped list entries are removed.
	 *
	 * @param mixed    $value Value.
	 * @param string   $kind  Kind of the IDs.
	 * @param callable $visit Visitor.
	 *
	 * @return mixed Null when a scalar reference is dropped.
	 */
	public static function map_value( mixed $value, string $kind, callable $visit ): mixed {
		if ( is_int( $value ) ) {
			return 0 === $value ? 0 : $visit( $kind, $value );
		}

		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
			if ( '0' === $value ) {
				return $value;
			}
			$id = $visit( $kind, (int) $value );

			return null === $id ? null : (string) $id;
		}

		if ( is_array( $value ) ) {
			$list   = array_is_list( $value );
			$mapped = array();
			foreach ( $value as $key => $item ) {
				$new = self::map_value( $item, $kind, $visit );
				if ( null === $new && null !== $item ) {
					continue;
				}
				$mapped[ $key ] = $new;
			}

			return $list ? array_values( $mapped ) : $mapped;
		}

		return $value;
	}

	/**
	 * Maps the IDs at a path in decoded JSON (objects and arrays).
	 *
	 * Path segments are property names, `*` (every element) or `**` (every
	 * scalar below).
	 *
	 * @param mixed    $node     Node, modified in place.
	 * @param string[] $segments Path segments.
	 * @param string   $kind     Kind of the IDs.
	 * @param callable $visit    Visitor.
	 *
	 * @return void
	 */
	public static function walk_path( mixed &$node, array $segments, string $kind, callable $visit ): void {
		if ( empty( $segments ) ) {
			$node = self::map_value( $node, $kind, $visit ) ?? ( is_string( $node ) ? '' : 0 );

			return;
		}

		$segment = array_shift( $segments );

		if ( '**' === $segment ) {
			if ( is_array( $node ) || is_object( $node ) ) {
				foreach ( $node as $key => &$child ) {
					self::walk_path( $child, array( '**' ), $kind, $visit );
				}
				unset( $child );
			} elseif ( is_int( $node ) || ( is_string( $node ) && ctype_digit( $node ) ) ) {
				$node = self::map_value( $node, $kind, $visit ) ?? ( is_string( $node ) ? '' : 0 );
			}

			return;
		}

		if ( '*' === $segment ) {
			if ( is_array( $node ) ) {
				$list = array_is_list( $node );
				foreach ( $node as $key => $child ) {
					if ( empty( $segments ) ) {
						$mapped = self::map_value( $child, $kind, $visit );
						if ( null === $mapped && null !== $child ) {
							unset( $node[ $key ] );
							continue;
						}
						$node[ $key ] = $mapped;
					} else {
						self::walk_path( $node[ $key ], $segments, $kind, $visit );
					}
				}
				if ( $list ) {
					$node = array_values( $node );
				}
			} elseif ( is_object( $node ) ) {
				foreach ( get_object_vars( $node ) as $key => $child ) {
					self::walk_path( $node->$key, $segments, $kind, $visit );
				}
			}

			return;
		}

		if ( is_object( $node ) && property_exists( $node, $segment ) ) {
			if ( empty( $segments ) ) {
				$mapped = self::map_value( $node->$segment, $kind, $visit );
				if ( null === $mapped && null !== $node->$segment ) {
					unset( $node->$segment );
				} else {
					$node->$segment = $mapped;
				}

				return;
			}
			self::walk_path( $node->$segment, $segments, $kind, $visit );
		} elseif ( is_array( $node ) && array_key_exists( $segment, $node ) ) {
			if ( empty( $segments ) ) {
				$mapped = self::map_value( $node[ $segment ], $kind, $visit );
				if ( null === $mapped && null !== $node[ $segment ] ) {
					unset( $node[ $segment ] );
				} else {
					$node[ $segment ] = $mapped;
				}

				return;
			}
			self::walk_path( $node[ $segment ], $segments, $kind, $visit );
		}
	}
}
