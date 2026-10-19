<?php
/**
 * Every reference in an object.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\References;

use Jcore\Silta\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a visitor to every object ID a post, term, menu or form refers to.
 *
 * The source runs it with a collecting visitor to find dependencies; the target
 * runs the same code with a remapping visitor. A visitor returns the ID to keep
 * (or null to drop the reference).
 */
final class Walker {

	/**
	 * Visits every reference in an object.
	 *
	 * @param array<string, mixed> $record Object, modified in place.
	 * @param callable             $visit  Visitor: ( string $kind, int $id ): ?int.
	 * @param callable             $field  ACF field resolver: ( string $key ): ?array.
	 * @param callable|null        $slug   Resolver for posts named by slug in block
	 *                                     attributes: ( string $post_type, string $slug ): ?int.
	 *                                     Defaults to a lookup on this site.
	 *
	 * @return void
	 */
	public static function walk( array &$record, callable $visit, callable $field, ?callable $slug = null ): void {
		switch ( $record['type'] ?? '' ) {
			case 'post':
				self::post( $record, $visit, $field, $slug );
				break;
			case 'term':
				self::term( $record, $visit, $field );
				break;
			case 'menu':
				self::menu( $record, $visit );
				break;
			case 'form':
				Integrations\Gravity_Forms::walk_form( $record, $visit );
				break;
		}
	}

	/**
	 * Visits a post's references.
	 *
	 * @param array<string, mixed> $record Post.
	 * @param callable             $visit  Visitor.
	 * @param callable             $field  Field resolver.
	 * @param callable|null        $slug   Slug resolver.
	 *
	 * @return void
	 */
	private static function post( array &$record, callable $visit, callable $field, ?callable $slug ): void {
		foreach ( array(
			'post_parent' => 'post',
			'post_author' => 'user',
		) as $name => $kind ) {
			$id = (int) ( $record['fields'][ $name ] ?? 0 );
			if ( $id > 0 ) {
				$record['fields'][ $name ] = (int) ( $visit( $kind, $id ) ?? 0 );
			}
		}

		$thumbnail = (int) ( $record['featured_image'] ?? 0 );
		if ( $thumbnail > 0 ) {
			$record['featured_image'] = (int) ( $visit( 'post', $thumbnail ) ?? 0 );
		}

		if ( isset( $record['content'] ) && is_string( $record['content'] ) ) {
			$record['content'] = Integrations\Gravity_Forms::walk_shortcodes( Blocks::walk( $record['content'], $visit, $field, $slug ), $visit );
		}

		self::meta( $record, $visit, $field );

		foreach ( (array) ( $record['terms'] ?? array() ) as $taxonomy => $ids ) {
			$mapped = array();
			foreach ( (array) $ids as $id ) {
				$new = $visit( 'term', (int) $id );
				if ( null !== $new ) {
					$mapped[] = (int) $new;
				}
			}
			sort( $mapped );
			$record['terms'][ $taxonomy ] = array_values( array_unique( $mapped ) );
		}

		self::translations( $record, 'post', $visit );
	}

	/**
	 * Visits a term's references.
	 *
	 * @param array<string, mixed> $record Term.
	 * @param callable             $visit  Visitor.
	 * @param callable             $field  Field resolver.
	 *
	 * @return void
	 */
	private static function term( array &$record, callable $visit, callable $field ): void {
		$parent = (int) ( $record['parent'] ?? 0 );
		if ( $parent > 0 ) {
			$record['parent'] = (int) ( $visit( 'term', $parent ) ?? 0 );
		}

		self::meta( $record, $visit, $field );
		self::translations( $record, 'term', $visit );
	}

	/**
	 * Visits the objects a menu's items link to.
	 *
	 * @param array<string, mixed> $record Menu.
	 * @param callable             $visit  Visitor.
	 *
	 * @return void
	 */
	private static function menu( array &$record, callable $visit ): void {
		foreach ( (array) ( $record['items'] ?? array() ) as $index => $item ) {
			$kind = match ( $item['item_type'] ?? '' ) {
				'post_type' => 'post',
				'taxonomy' => 'term',
				default => null,
			};
			if ( null === $kind || empty( $item['object_id'] ) ) {
				continue;
			}

			$record['items'][ $index ]['object_id'] = (int) ( $visit( $kind, (int) $item['object_id'] ) ?? 0 );
		}
	}

	/**
	 * Visits the IDs in meta.
	 *
	 * @param array<string, mixed> $record Object with raw meta.
	 * @param callable             $visit  Visitor.
	 * @param callable             $field  Field resolver.
	 *
	 * @return void
	 */
	private static function meta( array &$record, callable $visit, callable $field ): void {
		if ( empty( $record['meta'] ) || ! is_array( $record['meta'] ) ) {
			return;
		}

		Acf_Values::walk_meta( $record['meta'], $visit, $field );
		Meta_Keys::walk( $record['meta'], $visit );
		Integrations\Gravity_Forms::walk_meta( $record['meta'], $visit );
	}

	/**
	 * Visits Polylang translations, dropping unresolved ones.
	 *
	 * @param array<string, mixed> $record Object.
	 * @param string               $kind   post or term.
	 * @param callable             $visit  Visitor.
	 *
	 * @return void
	 */
	private static function translations( array &$record, string $kind, callable $visit ): void {
		if ( empty( $record['language']['translations'] ) ) {
			return;
		}

		$mapped = array();
		foreach ( (array) $record['language']['translations'] as $lang => $id ) {
			$new = $visit( $kind, (int) $id );
			if ( null !== $new ) {
				$mapped[ $lang ] = (int) $new;
			}
		}
		ksort( $mapped );
		$record['language']['translations'] = $mapped;
	}
}
