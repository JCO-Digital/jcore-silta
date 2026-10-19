<?php
/**
 * Can this site take an object?
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks that the target has what an incoming object needs: its post type,
 * taxonomies, ACF fields and language, and Gravity Forms with the field
 * types a form uses.
 */
final class Preflight {

	/**
	 * Returns the problems, keyed by section; the empty key blocks the whole object.
	 *
	 * @param array<string, mixed> $record Incoming object.
	 *
	 * @return array<string, string>
	 */
	public static function check( array $record ): array {
		return match ( $record['type'] ?? '' ) {
			'post' => self::post( $record ),
			'term' => self::term( $record ),
			'form' => self::form( $record ),
			default => array(),
		};
	}

	/**
	 * Checks a post.
	 *
	 * @param array<string, mixed> $record Post.
	 *
	 * @return array<string, string>
	 */
	private static function post( array $record ): array {
		$errors = array();

		if ( ! Content::syncable_type( (string) $record['post_type'] ) ) {
			$errors[''] = sprintf(
				/* translators: %s: post type name. */
				__( 'Post type "%s" is missing or cannot be synced on this site.', 'jcore-silta' ),
				(string) $record['post_type']
			);

			return $errors;
		}

		if ( ! Content::syncable_status( $record['post_type'], (string) ( $record['fields']['post_status'] ?? '' ) ) ) {
			$errors[''] = __( 'This post status cannot be synced.', 'jcore-silta' );

			return $errors;
		}

		foreach ( array_keys( (array) ( $record['terms'] ?? array() ) ) as $taxonomy ) {
			if ( ! Content::syncable_taxonomy( (string) $taxonomy ) || ! is_object_in_taxonomy( $record['post_type'], (string) $taxonomy ) ) {
				$errors[ 'terms.' . $taxonomy ] = sprintf(
					/* translators: %s: taxonomy name. */
					__( 'Taxonomy "%s" is missing or cannot be synced for this object.', 'jcore-silta' ),
					(string) $taxonomy
				);
			}
		}

		$errors = array_merge( $errors, self::acf( $record ) );

		$lang = (string) ( $record['language']['lang'] ?? '' );
		if ( '' !== $lang ) {
			if ( ! Polylang::active() ) {
				$errors['language'] = __( 'Polylang is not active on this site.', 'jcore-silta' );
			} elseif ( ! in_array( $lang, Polylang::languages(), true ) ) {
				$errors['language'] = sprintf(
					/* translators: %s: language code. */
					__( 'This site has no language "%s".', 'jcore-silta' ),
					$lang
				);
			}
		}

		return $errors;
	}

	/**
	 * Checks a term.
	 *
	 * @param array<string, mixed> $record Term.
	 *
	 * @return array<string, string>
	 */
	private static function term( array $record ): array {
		if ( ! Content::syncable_taxonomy( (string) $record['taxonomy'] ) ) {
			return array(
				'' => sprintf(
					/* translators: %s: taxonomy name. */
					__( 'Taxonomy "%s" is missing or cannot be synced for this object.', 'jcore-silta' ),
					(string) $record['taxonomy']
				),
			);
		}

		return array();
	}

	/**
	 * Checks a Gravity Forms form.
	 *
	 * @param array<string, mixed> $record Form.
	 *
	 * @return array<string, string>
	 */
	private static function form( array $record ): array {
		if ( ! Gravity_Forms::active() ) {
			return array( '' => __( 'Gravity Forms is not active on this site, so forms can’t be synced to it. Activate Gravity Forms here first.', 'jcore-silta' ) );
		}

		$unknown = Gravity_Forms::unknown_field_types( $record );
		if ( ! empty( $unknown ) ) {
			return array(
				'' => sprintf(
					/* translators: %s: comma-separated Gravity Forms field types. */
					__( 'This form uses field types this site doesn’t have: %s. Activate the same Gravity Forms add-ons here first.', 'jcore-silta' ),
					implode( ', ', $unknown )
				),
			);
		}

		return array();
	}

	/**
	 * Checks that every ACF field the object's meta uses exists here.
	 *
	 * @param array<string, mixed> $record Post.
	 *
	 * @return array<string, string>
	 */
	private static function acf( array $record ): array {
		$errors = array();
		$meta   = (array) ( $record['meta'] ?? array() );
		$groups = (array) ( $record['meta_groups'] ?? array() );

		foreach ( $meta as $key => $values ) {
			if ( ! str_starts_with( (string) $key, '_' ) ) {
				continue;
			}
			$reference = $values[0] ?? null;
			if ( ! Acf::is_key( $reference ) || ! isset( $meta[ substr( (string) $key, 1 ) ] ) ) {
				continue;
			}

			$group = 'meta.' . ( $groups[ $key ] ?? substr( (string) $key, 1 ) );
			if ( isset( $errors[ $group ] ) ) {
				continue;
			}

			if ( ! Acf::active() ) {
				$errors[ $group ] = __( 'ACF is not active on this site.', 'jcore-silta' );
			} elseif ( ! Acf::exists( (string) $reference ) ) {
				$errors[ $group ] = sprintf(
					/* translators: %s: ACF field key. */
					__( 'This site has no ACF field %s. Sync the field group first.', 'jcore-silta' ),
					(string) $reference
				);
			}
		}

		return $errors;
	}
}
