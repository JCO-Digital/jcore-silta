<?php
/**
 * Classic navigation menus.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes menus with their items and locations.
 */
final class Menus {

	/**
	 * Reads a menu.
	 *
	 * @param int $id Menu term ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function state( int $id ): ?array {
		if ( ! Content::syncable_type( 'nav_menu_item', true ) ) {
			return null;
		}
		$menu = wp_get_nav_menu_object( $id );
		if ( ! $menu instanceof \WP_Term ) {
			return null;
		}

		$items = array();
		foreach ( array_values( self::item_posts( $id ) ) as $index => $post ) {
			$meta = State::raw_meta( 'post', $post->ID );
			$get  = static function ( string $key ) use ( $meta ): string {
				return (string) ( $meta[ $key ][0] ?? '' );
			};

			$items[] = array(
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'item_type'   => $get( '_menu_item_type' ),
				'object'      => $get( '_menu_item_object' ),
				'object_id'   => (int) $get( '_menu_item_object_id' ),
				'parent'      => (int) $get( '_menu_item_menu_item_parent' ),
				// Stored menu_order values can start at 0, which core reads as
				// "append"; the ordinal position is what matters.
				'position'    => $index + 1,
				'url'         => $get( '_menu_item_url' ),
				'target'      => $get( '_menu_item_target' ),
				'attr_title'  => $post->post_excerpt,
				'description' => $post->post_content,
				'classes'     => implode( ' ', self::classes( $get( '_menu_item_classes' ) ) ),
				'xfn'         => $get( '_menu_item_xfn' ),
				'status'      => $post->post_status,
				'meta'        => array_filter( $meta, array( Meta_Filter::class, 'synced' ), ARRAY_FILTER_USE_KEY ),
			);
		}

		return array(
			'type'      => 'menu',
			'id'        => $id,
			'slug'      => $menu->slug,
			'name'      => $menu->name,
			'locations' => self::locations_of( $id ),
			'items'     => $items,
		);
	}

	/**
	 * Lists the menus on this site.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = array(
				'id'        => (int) $menu->term_id,
				'title'     => $menu->name,
				'slug'      => $menu->slug,
				'count'     => (int) $menu->count,
				'locations' => self::locations_of( (int) $menu->term_id ),
			);
		}

		return $menus;
	}

	/**
	 * Creates or updates a menu from an incoming (already remapped) state.
	 *
	 * @param array<string, mixed> $incoming Incoming menu with local object IDs.
	 * @param int                  $local_id Matched local menu, or 0.
	 * @param callable             $wants    Callback( string $section ): bool.
	 * @param string               $site     Remote site UUID, for item mappings.
	 *
	 * @return array{id: int, warnings: string[]}|WP_Error
	 */
	public static function write( array $incoming, int $local_id, callable $wants, string $site ): array|WP_Error {
		if ( ! Content::syncable_type( 'nav_menu_item', true ) ) {
			return new WP_Error( 'jcore_silta_menu_type', __( 'Menu items cannot be synced on this site.', 'jcore-silta' ), array( 'status' => 400 ) );
		}
		$warnings = array();

		if ( 0 === $local_id ) {
			$created = wp_update_nav_menu_object( 0, wp_slash( array( 'menu-name' => self::free_name( (string) $incoming['name'] ) ) ) );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$local_id = (int) $created;
		} elseif ( $wants( 'fields.name' ) ) {
			$menu = wp_get_nav_menu_object( $local_id );
			if ( $menu instanceof \WP_Term && $menu->name !== $incoming['name'] ) {
				$renamed = wp_update_nav_menu_object( $local_id, wp_slash( array( 'menu-name' => (string) $incoming['name'] ) ) );
				if ( is_wp_error( $renamed ) ) {
					$warnings[] = $renamed->get_error_message();
				}
			}
		}

		if ( $wants( 'items' ) ) {
			$warnings = array_merge( $warnings, self::write_items( $local_id, (array) $incoming['items'], $site ) );
		}

		if ( $wants( 'locations' ) ) {
			$warnings = array_merge( $warnings, self::assign_locations( $local_id, (array) $incoming['locations'] ) );
		}

		return array(
			'id'       => $local_id,
			'warnings' => $warnings,
		);
	}

	/**
	 * Puts a menu back the way a snapshot recorded it.
	 *
	 * @param array<string, mixed> $snapshot Menu state from state().
	 *
	 * @return string[] Warnings for rejected values.
	 */
	public static function restore( array $snapshot ): array {
		$warnings = array();
		$menu_id  = (int) $snapshot['id'];
		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			return $warnings;
		}

		wp_update_nav_menu_object( $menu_id, wp_slash( array( 'menu-name' => (string) $snapshot['name'] ) ) );

		foreach ( self::item_posts( $menu_id ) as $post ) {
			wp_delete_post( $post->ID, true );
		}

		$new_ids = array();
		foreach ( self::parents_first( (array) $snapshot['items'] ) as $item ) {
			$id = wp_update_nav_menu_item( $menu_id, 0, wp_slash( self::item_args( $item, (int) ( $new_ids[ $item['parent'] ] ?? 0 ) ) ) );
			if ( ! is_wp_error( $id ) ) {
				$new_ids[ $item['id'] ] = (int) $id;
				$warnings               = array_merge( $warnings, self::write_item_meta( (int) $id, (array) ( $item['meta'] ?? array() ), true ) );
			}
		}

		$locations = get_nav_menu_locations();
		foreach ( $locations as $location => $assigned ) {
			if ( (int) $assigned === $menu_id && ! in_array( $location, (array) $snapshot['locations'], true ) ) {
				$locations[ $location ] = 0;
			}
		}
		foreach ( (array) $snapshot['locations'] as $location ) {
			$locations[ $location ] = $menu_id;
		}
		set_theme_mod( 'nav_menu_locations', $locations );

		return $warnings;
	}

	/**
	 * Syncs the items of a menu, reusing mapped items so their IDs stay stable.
	 *
	 * @param int                              $menu_id Local menu ID.
	 * @param array<int, array<string, mixed>> $items   Incoming items.
	 * @param string                           $site    Remote site UUID.
	 *
	 * @return string[] Warnings.
	 */
	private static function write_items( int $menu_id, array $items, string $site ): array {
		$warnings   = array();
		$existing   = array();
		$signatures = array();
		foreach ( self::item_posts( $menu_id ) as $post ) {
			$existing[ $post->ID ]                    = true;
			$signatures[ self::signature( $post ) ][] = $post->ID;
		}

		$local_ids = array();
		$kept      = array();
		foreach ( self::parents_first( $items ) as $item ) {
			if ( ( in_array( $item['item_type'], array( 'post_type', 'post_type_archive' ), true ) && ! Content::syncable_type( $item['object'] ) ) || ( 'taxonomy' === $item['item_type'] && ! Content::syncable_taxonomy( $item['object'] ) ) ) {
				$warnings[] = __( 'A menu item was skipped: its linked post type or taxonomy cannot be synced.', 'jcore-silta' );
				continue;
			}
			if ( in_array( $item['item_type'], array( 'post_type', 'taxonomy' ), true ) && empty( $item['object_id'] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: menu item title. */
					__( 'Menu item "%s" was left out: the page or term it links to is not on this site.', 'jcore-silta' ),
					(string) $item['title']
				);
				continue;
			}

			$local_item = Mapping::get( $site, 'post', (int) $item['id'] );
			$item_id    = ( null !== $local_item && isset( $existing[ $local_item['local_id'] ] ) && ! isset( $kept[ $local_item['local_id'] ] ) ) ? (int) $local_item['local_id'] : 0;
			if ( 0 === $item_id ) {
				// First sync: reuse an item pointing at the same thing, so IDs stay put.
				foreach ( $signatures[ self::item_signature( $item ) ] ?? array() as $candidate ) {
					if ( ! isset( $kept[ $candidate ] ) ) {
						$item_id = (int) $candidate;
						break;
					}
				}
			}
			$parent = (int) ( $local_ids[ $item['parent'] ] ?? 0 );

			$saved = wp_update_nav_menu_item( $menu_id, $item_id, wp_slash( self::item_args( $item, $parent ) ) );
			if ( is_wp_error( $saved ) ) {
				$warnings[] = $saved->get_error_message();
				continue;
			}

			$local_ids[ $item['id'] ] = (int) $saved;
			$kept[ (int) $saved ]     = true;
			$warnings                 = array_merge( $warnings, self::write_item_meta( (int) $saved, (array) ( $item['meta'] ?? array() ) ) );
			Mapping::set( $site, 'post', (int) $item['id'], (int) $saved );
		}

		foreach ( array_keys( $existing ) as $id ) {
			if ( ! isset( $kept[ $id ] ) ) {
				wp_delete_post( $id, true );
				Mapping::forget_local( 'post', $id );
			}
		}

		return $warnings;
	}

	/**
	 * Identifies what a local item points at.
	 *
	 * @param \WP_Post $post Item post.
	 *
	 * @return string
	 */
	private static function signature( \WP_Post $post ): string {
		$type = (string) get_post_meta( $post->ID, '_menu_item_type', true );

		return self::item_signature(
			array(
				'item_type' => $type,
				'object'    => (string) get_post_meta( $post->ID, '_menu_item_object', true ),
				'object_id' => (int) get_post_meta( $post->ID, '_menu_item_object_id', true ),
				'url'       => (string) get_post_meta( $post->ID, '_menu_item_url', true ),
				'title'     => $post->post_title,
			)
		);
	}

	/**
	 * Identifies what an incoming item points at.
	 *
	 * @param array<string, mixed> $item Item with local object IDs.
	 *
	 * @return string
	 */
	private static function item_signature( array $item ): string {
		if ( 'custom' === $item['item_type'] ) {
			return 'custom|' . $item['url'] . '|' . $item['title'];
		}

		return $item['item_type'] . '|' . $item['object'] . '|' . (int) $item['object_id'];
	}

	/**
	 * Assigns a menu to the source's locations that exist in this theme.
	 *
	 * @param int      $menu_id   Local menu ID.
	 * @param string[] $locations Location slugs.
	 *
	 * @return string[] Warnings.
	 */
	private static function assign_locations( int $menu_id, array $locations ): array {
		$registered = get_registered_nav_menus();
		$assigned   = get_nav_menu_locations();
		$warnings   = array();

		foreach ( $locations as $location ) {
			if ( ! isset( $registered[ $location ] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: menu location slug. */
					__( 'The theme here has no menu location "%s".', 'jcore-silta' ),
					$location
				);
				continue;
			}
			$assigned[ $location ] = $menu_id;
		}

		set_theme_mod( 'nav_menu_locations', $assigned );

		return $warnings;
	}

	/**
	 * Builds the arguments wp_update_nav_menu_item() takes.
	 *
	 * @param array<string, mixed> $item      Item.
	 * @param int                  $parent_id Local parent item ID.
	 *
	 * @return array<string, mixed>
	 */
	private static function item_args( array $item, int $parent_id ): array {
		return array(
			'menu-item-object-id'   => (int) $item['object_id'],
			'menu-item-object'      => sanitize_key( $item['object'] ),
			'menu-item-parent-id'   => $parent_id,
			'menu-item-position'    => (int) $item['position'],
			'menu-item-type'        => sanitize_key( $item['item_type'] ),
			'menu-item-title'       => (string) $item['title'],
			'menu-item-url'         => esc_url_raw( $item['url'] ),
			'menu-item-description' => (string) $item['description'],
			'menu-item-attr-title'  => (string) $item['attr_title'],
			'menu-item-target'      => sanitize_text_field( $item['target'] ),
			// Core explode()s this on spaces and sanitizes each class itself.
			'menu-item-classes'     => implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $item['classes'] ), -1, PREG_SPLIT_NO_EMPTY ) ) ),
			'menu-item-xfn'         => sanitize_text_field( $item['xfn'] ),
			'menu-item-status'      => 'draft' === ( $item['status'] ?? '' ) ? 'draft' : 'publish',
		);
	}

	/**
	 * Replaces an item's custom meta (plugins add icons, mega menu settings…).
	 *
	 * @param int                     $id      Item ID.
	 * @param array<string, string[]> $meta    Raw meta.
	 * @param bool                    $restore Whether this restores a snapshot (written unfiltered).
	 *
	 * @return string[] Warnings for rejected values.
	 */
	private static function write_item_meta( int $id, array $meta, bool $restore = false ): array {
		$current = array_filter( State::raw_meta( 'post', $id ), array( Meta_Filter::class, 'synced' ), ARRAY_FILTER_USE_KEY );
		return Meta_Writer::replace( 'post', $id, array_unique( array_merge( array_keys( $current ), array_keys( $meta ) ) ), $meta, false, $restore );
	}

	/**
	 * Reads menu classes without instantiating classes or accepting nested values.
	 *
	 * @param string $raw Raw classes meta.
	 *
	 * @return string[]
	 */
	private static function classes( string $raw ): array {
		if ( ! Meta_Writer::valid_value( $raw ) ) {
			return array();
		}
		$classes = $raw;
		if ( Replacer::is_serialized( trim( $raw ) ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Validated raw data; malformed legacy values return false and no classes are allowed.
			$classes = @unserialize(
				trim( $raw ),
				array(
					'allowed_classes' => false,
					'max_depth'       => 64,
				)
			);
		}
		$classes = array_filter( (array) $classes, 'is_string' );

		return array_values( array_filter( array_map( 'sanitize_html_class', $classes ) ) );
	}

	/**
	 * Orders items so every parent comes before its children.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function parents_first( array $items ): array {
		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item['id'] ] = $item;
		}

		$depth = static function ( array $item ) use ( $by_id ): int {
			$level  = 0;
			$parent = (int) $item['parent'];
			while ( $parent > 0 && isset( $by_id[ $parent ] ) && $level < 50 ) {
				++$level;
				$parent = (int) $by_id[ $parent ]['parent'];
			}

			return $level;
		};

		usort(
			$items,
			static function ( array $a, array $b ) use ( $depth ): int {
				return array( $depth( $a ), (int) $a['position'] ) <=> array( $depth( $b ), (int) $b['position'] );
			}
		);

		return $items;
	}

	/**
	 * Returns the item posts of a menu, in any status.
	 *
	 * @param int $menu_id Menu ID.
	 *
	 * @return \WP_Post[]
	 */
	private static function item_posts( int $menu_id ): array {
		$posts = get_posts(
			array(
				'post_type'   => 'nav_menu_item',
				'post_status' => 'any',
				'numberposts' => -1,
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
				'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One menu.
					array(
						'taxonomy' => 'nav_menu',
						'field'    => 'term_id',
						'terms'    => $menu_id,
					),
				),
			)
		);

		return array_filter( $posts, static fn( $post ) => $post instanceof \WP_Post && Content::syncable_status( 'nav_menu_item', $post->post_status ) );
	}

	/**
	 * Returns the locations a menu is assigned to.
	 *
	 * @param int $menu_id Menu ID.
	 *
	 * @return string[]
	 */
	private static function locations_of( int $menu_id ): array {
		$locations = array();
		foreach ( get_nav_menu_locations() as $location => $assigned ) {
			if ( (int) $assigned === $menu_id ) {
				$locations[] = (string) $location;
			}
		}
		sort( $locations );

		return $locations;
	}

	/**
	 * Returns a menu name not yet used here.
	 *
	 * @param string $name Wanted name.
	 *
	 * @return string
	 */
	private static function free_name( string $name ): string {
		$candidate = $name;
		$suffix    = 2;
		while ( wp_get_nav_menu_object( $candidate ) ) {
			$candidate = $name . ' ' . $suffix;
			++$suffix;
		}

		return $candidate;
	}
}
