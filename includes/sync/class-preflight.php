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
 * taxonomies, ACF fields and language.
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

		if ( ! post_type_exists( (string) $record['post_type'] ) ) {
			$errors[''] = sprintf(
				/* translators: %s: post type name. */
				__( 'This site has no post type "%s".', 'jcore-silta' ),
				(string) $record['post_type']
			);

			return $errors;
		}

		foreach ( array_keys( (array) ( $record['terms'] ?? array() ) ) as $taxonomy ) {
			if ( ! taxonomy_exists( (string) $taxonomy ) ) {
				$errors[ 'terms.' . $taxonomy ] = sprintf(
					/* translators: %s: taxonomy name. */
					__( 'This site has no taxonomy "%s".', 'jcore-silta' ),
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
		if ( ! taxonomy_exists( (string) $record['taxonomy'] ) ) {
			return array(
				'' => sprintf(
					/* translators: %s: taxonomy name. */
					__( 'This site has no taxonomy "%s".', 'jcore-silta' ),
					(string) $record['taxonomy']
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
