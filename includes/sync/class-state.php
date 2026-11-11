<?php
/**
 * Normalised view of a post, term, menu or form.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads objects into the shape bundles carry.
 *
 * Both sides use the same reader: the source to export, the target to compare,
 * hash and snapshot its own copy. Meta values are the raw strings from the
 * database, so serialized data survives byte for byte.
 */
final class State {

	/**
	 * Core fields synced, each its own diff section.
	 */
	public const FIELDS = array(
		'post_title',
		'post_name',
		'post_excerpt',
		'post_status',
		'post_date',
		'post_author',
		'post_parent',
		'menu_order',
		'comment_status',
		'ping_status',
		'post_password',
		'page_template',
		'post_format',
		'sticky',
	);

	/**
	 * Reads a post.
	 *
	 * @param int  $id     Post ID.
	 * @param bool $hashes Whether to hash an attachment's files.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function post( int $id, bool $hashes = true ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! Content::syncable_type( $post->post_type ) ) {
			return null;
		}

		$meta = self::raw_meta( 'post', $id );

		$state = array(
			'type'           => 'post',
			'id'             => $id,
			'post_type'      => $post->post_type,
			'guid'           => $post->guid,
			'modified_gmt'   => $post->post_modified_gmt,
			'fields'         => array(
				'post_title'     => $post->post_title,
				'post_name'      => $post->post_name,
				'post_excerpt'   => $post->post_excerpt,
				'post_status'    => $post->post_status,
				'post_date'      => $post->post_date,
				'post_date_gmt'  => $post->post_date_gmt,
				'post_author'    => (int) $post->post_author,
				'post_parent'    => (int) $post->post_parent,
				'menu_order'     => (int) $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_password'  => $post->post_password,
				'page_template'  => (string) ( self::single_raw_meta( $id, '_wp_page_template' ) ?? '' ),
				'post_format'    => (string) get_post_format( $post ),
				'sticky'         => 'post' === $post->post_type && is_sticky( $id ),
			),
			'content'        => $post->post_content,
			'featured_image' => (int) ( self::single_raw_meta( $id, '_thumbnail_id' ) ?? 0 ),
			'meta'           => array_filter(
				$meta,
				array( Meta_Filter::class, 'synced' ),
				ARRAY_FILTER_USE_KEY
			),
			'terms'          => self::post_terms( $id, $post->post_type ),
			'language'       => Polylang::post( $id ),
		);

		if ( 'attachment' === $post->post_type ) {
			$state['fields']['post_mime_type'] = $post->post_mime_type;
			$state['file']                     = Media::describe( $id, $hashes );
		}

		/**
		 * Filters a post's state, as both sites read it to export, compare,
		 * hash and snapshot. Data a site derives or collects for itself (a
		 * shop's stock and sales) can be left out here, so it is never
		 * overwritten and never counts as a change.
		 *
		 * @param array<string, mixed> $state State.
		 * @param \WP_Post             $post  Post.
		 */
		return (array) apply_filters( 'jcore_silta_post_state', $state, $post );
	}

	/**
	 * Reads a term.
	 *
	 * @param int $id Term ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function term( int $id ): ?array {
		$term = get_term( $id );
		if ( ! $term instanceof \WP_Term || ! Content::syncable_taxonomy( $term->taxonomy ) ) {
			return null;
		}

		return array(
			'type'        => 'term',
			'id'          => $id,
			'taxonomy'    => $term->taxonomy,
			'slug'        => $term->slug,
			'name'        => $term->name,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'meta'        => array_filter(
				self::raw_meta( 'term', $id ),
				array( Meta_Filter::class, 'synced' ),
				ARRAY_FILTER_USE_KEY
			),
			'language'    => Polylang::term( $id ),
		);
	}

	/**
	 * Reads a menu.
	 *
	 * @param int $id Menu (nav_menu term) ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function menu( int $id ): ?array {
		return Menus::state( $id );
	}

	/**
	 * Reads a Gravity Forms form.
	 *
	 * @param int $id Form ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function form( int $id ): ?array {
		return Gravity_Forms::state( $id );
	}

	/**
	 * Reads an object of any type.
	 *
	 * @param string $type post, term, menu or form.
	 * @param int    $id   Object ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function read( string $type, int $id ): ?array {
		return match ( $type ) {
			'post' => self::post( $id ),
			'term' => self::term( $id ),
			'menu' => self::menu( $id ),
			'form' => self::form( $id ),
			default => null,
		};
	}

	/**
	 * Hashes the parts of a state a sync can change. IDs and timestamps are
	 * left out, so the hash says whether the content changed.
	 *
	 * A taxonomy with no terms counts as absent: a state lists every taxonomy
	 * registered for its post type, and a taxonomy that exists on only one
	 * site (or appears later, like a WooCommerce attribute) must not change
	 * the hash of a post that has no terms in it.
	 *
	 * Of an attachment's files only the uploaded one counts (and the original
	 * a large image was scaled down from). The sizes generated from it are
	 * each site's own: a site makes the sizes its theme and plugins register,
	 * so two copies of the same image rarely have the same set of them.
	 *
	 * @param array<string, mixed> $state State.
	 *
	 * @return string
	 */
	public static function hash( array $state ): string {
		return self::digest( self::without_empty_terms( $state ), false );
	}

	/**
	 * Hashes a state the way the first versions did: keeping empty term sets,
	 * and with every file of an attachment. Mappings stored by those versions
	 * hold these hashes; hash_matches() accepts them so an upgrade doesn't
	 * flag every synced object as changed.
	 *
	 * @param array<string, mixed> $state State.
	 *
	 * @return string
	 */
	public static function legacy_hash( array $state ): string {
		return self::digest( $state, true );
	}

	/**
	 * Says whether a stored hash still describes a state, in the current form
	 * or one an earlier version stored.
	 *
	 * @param string               $stored Hash stored at the last sync.
	 * @param array<string, mixed> $state  Current state.
	 *
	 * @return bool
	 */
	public static function hash_matches( string $stored, array $state ): bool {
		return $stored === self::hash( $state )
			// Up to 0.6: empty term sets left out, every file of an attachment.
			|| $stored === self::digest( self::without_empty_terms( $state ), true )
			|| $stored === self::legacy_hash( $state );
	}

	/**
	 * Leaves taxonomies without terms out of a state.
	 *
	 * @param array<string, mixed> $state State.
	 *
	 * @return array<string, mixed>
	 */
	private static function without_empty_terms( array $state ): array {
		if ( isset( $state['terms'] ) && is_array( $state['terms'] ) ) {
			$state['terms'] = array_filter( $state['terms'], static fn( $ids ): bool => ! empty( $ids ) );
		}

		return $state;
	}

	/**
	 * Hashes a state's content.
	 *
	 * @param array<string, mixed> $state     State.
	 * @param bool                 $all_files Whether an attachment's generated sizes count.
	 *
	 * @return string
	 */
	private static function digest( array $state, bool $all_files ): string {
		$parts = $state;
		unset( $parts['id'], $parts['key'], $parts['guid'], $parts['modified_gmt'], $parts['refs'], $parts['origins'], $parts['hash'], $parts['title'], $parts['meta_groups'], $parts['group_labels'], $parts['unresolved'], $parts['depth'], $parts['companions'], $parts['extra'] );

		if ( isset( $parts['meta'] ) && is_array( $parts['meta'] ) ) {
			ksort( $parts['meta'] );
		}
		if ( isset( $parts['terms'] ) && is_array( $parts['terms'] ) ) {
			ksort( $parts['terms'] );
		}
		if ( isset( $parts['file'] ) ) {
			$parts['file'] = self::file_hashes( $parts['file'], $all_files );
		}

		return sha1( (string) wp_json_encode( $parts ) );
	}

	/**
	 * Returns the sorted content hashes of an attachment's files: of the
	 * uploaded file and its unscaled original, or of every file.
	 *
	 * @param array<string, mixed>|null $file File description from Media::describe().
	 * @param bool                      $all  Whether to include the generated sizes.
	 *
	 * @return string[]
	 */
	public static function file_hashes( ?array $file, bool $all = false ): array {
		$main     = (string) ( $file['path'] ?? '' );
		$original = $file['metadata']['original_image'] ?? null;
		$dir      = dirname( $main );
		$own      = array(
			$main => true,
			( '.' === $dir ? '' : $dir . '/' ) . ( is_string( $original ) ? $original : '' ) => true,
		);

		$hashes = array();
		foreach ( (array) ( $file['files'] ?? array() ) as $entry ) {
			if ( $all || isset( $own[ (string) ( $entry['path'] ?? '' ) ] ) ) {
				$hashes[] = (string) ( $entry['hash'] ?? '' );
			}
		}
		sort( $hashes );

		return $hashes;
	}

	/**
	 * Reads every meta row of an object as raw strings, in insertion order.
	 *
	 * @param string $type post or term.
	 * @param int    $id   Object ID.
	 *
	 * @return array<string, string[]>
	 */
	public static function raw_meta( string $type, int $id ): array {
		global $wpdb;

		$table  = 'term' === $type ? $wpdb->termmeta : $wpdb->postmeta;
		$column = 'term' === $type ? 'term_id' : 'post_id';
		$id_col = 'term' === $type ? 'meta_id' : 'meta_id';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw values are needed, the cache holds unserialized ones.
			$wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE %i = %d ORDER BY %i', $table, $column, $id, $id_col ),
			ARRAY_A
		);

		$meta = array();
		foreach ( (array) $rows as $row ) {
			$meta[ (string) $row['meta_key'] ][] = (string) $row['meta_value'];
		}

		return $meta;
	}

	/**
	 * Reads one raw meta value of a post.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Meta key.
	 *
	 * @return string|null
	 */
	public static function single_raw_meta( int $id, string $key ): ?string {
		global $wpdb;

		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw value.
			$wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1', $wpdb->postmeta, $id, $key )
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Returns a post's term IDs per synced taxonomy.
	 *
	 * Read straight from the relationship tables, because Polylang can filter
	 * term queries by the current language.
	 *
	 * @param int    $id        Post ID.
	 * @param string $post_type Post type.
	 *
	 * @return array<string, int[]>
	 */
	public static function post_terms( int $id, string $post_type ): array {
		global $wpdb;

		$taxonomies = self::taxonomies( $post_type );
		$terms      = array_fill_keys( $taxonomies, array() );
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
			$wpdb->prepare(
				'SELECT tt.taxonomy, tt.term_id FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d',
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				$id
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $terms[ $row['taxonomy'] ] ) ) {
				$terms[ $row['taxonomy'] ][] = (int) $row['term_id'];
			}
		}

		foreach ( $terms as $taxonomy => $ids ) {
			sort( $ids );
			$terms[ $taxonomy ] = array_values( array_unique( $ids ) );
		}

		return $terms;
	}

	/**
	 * Returns the taxonomies synced for a post type.
	 *
	 * @param string $post_type Post type.
	 *
	 * @return string[]
	 */
	public static function taxonomies( string $post_type ): array {
		return array_values( array_filter( get_object_taxonomies( $post_type ), array( Content::class, 'syncable_taxonomy' ) ) );
	}
}
