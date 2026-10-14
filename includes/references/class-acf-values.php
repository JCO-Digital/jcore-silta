<?php
/**
 * IDs in ACF values.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\References;

use Jcore\Silta\Sync\Acf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remaps the IDs ACF stores for image, file, gallery, post object,
 * relationship, page link, taxonomy and user fields.
 *
 * ACF keeps every leaf value next to a reference to its field key: in meta as
 * `name` + `_name`, also for repeater rows (`rows_0_image` + `_rows_0_image`),
 * and in block data the same way, or keyed by field key directly. So a field's
 * type can be looked up for every stored value without walking the field tree.
 *
 * The field resolver is `callable( string $key ): ?array` returning at least
 * `type`; on the source it asks ACF, on the target it reads the definitions the
 * bundle carried.
 */
final class Acf_Values {

	/**
	 * Remaps ACF values in raw post or term meta.
	 *
	 * @param array<string, string[]> $meta  Raw meta, modified in place.
	 * @param callable                $visit Visitor.
	 * @param callable                $field Field resolver.
	 *
	 * @return void
	 */
	public static function walk_meta( array &$meta, callable $visit, callable $field ): void {
		foreach ( $meta as $key => $values ) {
			$reference = $meta[ '_' . $key ][0] ?? null;
			if ( ! Acf::is_key( $reference ) ) {
				continue;
			}

			$kind = self::kind( $field( (string) $reference ) );
			if ( null === $kind ) {
				continue;
			}

			$meta[ $key ] = array_map(
				static function ( string $raw ) use ( $kind, $visit ): string {
					return Values::map_raw( $raw, $kind, $visit );
				},
				$values
			);
		}
	}

	/**
	 * Remaps ACF values in a block's `data` attribute.
	 *
	 * @param mixed    $data  Decoded data (object or array), modified in place.
	 * @param callable $visit Visitor.
	 * @param callable $field Field resolver.
	 * @param int      $depth Nesting depth.
	 *
	 * @return void
	 */
	public static function walk_data( mixed &$data, callable $visit, callable $field, int $depth = 0 ): void {
		if ( $depth > 64 ) {
			$data = null;

			return;
		}
		if ( ! is_object( $data ) && ! is_array( $data ) ) {
			return;
		}

		$keys = is_object( $data ) ? array_keys( get_object_vars( $data ) ) : array_keys( $data );
		foreach ( $keys as $key ) {
			if ( is_array( $data ) && null !== \ReflectionReference::fromArrayElement( $data, $key ) ) {
				unset( $data[ $key ] );
				continue;
			}
			$key = (string) $key;
			if ( str_starts_with( $key, '_' ) ) {
				continue;
			}

			$reference = is_object( $data ) ? ( $data->{'_' . $key} ?? null ) : ( $data[ '_' . $key ] ?? null );
			$field_key = Acf::is_key( $reference ) ? (string) $reference : ( Acf::is_key( $key ) ? $key : null );
			$kind      = null === $field_key ? null : self::kind( $field( $field_key ) );

			if ( is_object( $data ) ) {
				$value = &$data->$key;
			} else {
				$value = &$data[ $key ];
			}

			if ( null !== $kind ) {
				$mapped = Values::map_value( $value, $kind, $visit, $depth );
				$value  = null === $mapped ? '' : $mapped;
			} elseif ( is_object( $value ) || is_array( $value ) ) {
				// Repeater rows, groups and flexible layouts keyed by field key.
				self::walk_data( $value, $visit, $field, $depth + 1 );
			}

			unset( $value );
		}
	}

	/**
	 * Returns the kind of ID a field stores, if any.
	 *
	 * @param array<string, mixed>|null $description Field description.
	 *
	 * @return string|null
	 */
	private static function kind( ?array $description ): ?string {
		if ( null === $description ) {
			return null;
		}

		return Acf::ID_TYPES[ (string) ( $description['type'] ?? '' ) ] ?? null;
	}
}
