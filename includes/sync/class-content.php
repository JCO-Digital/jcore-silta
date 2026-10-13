<?php
/**
 * What there is to sync on this site.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists post types and posts for the picker.
 */
final class Content {

	/**
	 * Post types that are never offered.
	 */
	private const EXCLUDED_TYPES = array(
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_font_family',
		'wp_font_face',
		'acf-field-group',
		'acf-field',
		'acf-post-type',
		'acf-taxonomy',
		'acf-ui-options-page',
	);

	/**
	 * Returns the post types that can be synced.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function types(): array {
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, self::EXCLUDED_TYPES, true ) ) {
				continue;
			}

			$types[] = array(
				'name'         => $type->name,
				'label'        => (string) $type->labels->name,
				'singular'     => (string) $type->labels->singular_name,
				'hierarchical' => (bool) $type->hierarchical,
				'count'        => self::count( $type->name ),
				'taxonomies'   => State::taxonomies( $type->name ),
			);
		}

		/**
		 * Filters the post types offered for syncing.
		 *
		 * @param array<int, array<string, mixed>> $types Post types.
		 */
		return (array) apply_filters( 'jcore_silta_post_types', $types );
	}

	/**
	 * Searches posts for the picker.
	 *
	 * @param array<string, mixed> $args post_type, search, page, per_page, include.
	 *
	 * @return array<string, mixed>
	 */
	public static function query( array $args ): array {
		$post_type = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		$per_page  = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );

		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => self::statuses( $post_type ),
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'lang'                   => '',
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		);

		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}
		if ( ! empty( $args['include'] ) ) {
			$query_args['post__in']  = array_map( 'intval', (array) $args['include'] );
			$query_args['post_type'] = 'any';
			$query_args['orderby']   = 'post__in';
		}

		$query = new \WP_Query( $query_args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$language = Polylang::post( $post->ID );
			$items[]  = array(
				'id'           => $post->ID,
				'title'        => '' !== $post->post_title ? $post->post_title : $post->post_name,
				'post_type'    => $post->post_type,
				'status'       => $post->post_status,
				'status_label' => (string) ( get_post_status_object( $post->post_status )->label ?? $post->post_status ),
				'modified'     => $post->post_modified_gmt,
				'parent'       => (int) $post->post_parent,
				'lang'         => $language['lang'] ?? '',
				'thumb'        => 'attachment' === $post->post_type ? (string) wp_get_attachment_image_url( $post->ID, 'thumbnail', true ) : (string) get_the_post_thumbnail_url( $post, 'thumbnail' ),
				'link'         => 'attachment' === $post->post_type ? (string) wp_get_attachment_url( $post->ID ) : (string) get_permalink( $post ),
			);
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Returns every ID of a post type, for "sync all posts of this type".
	 *
	 * @param string $post_type Post type.
	 *
	 * @return int[]
	 */
	public static function ids( string $post_type ): array {
		$ids = get_posts(
			array(
				'post_type'        => sanitize_key( $post_type ),
				'post_status'      => self::statuses( $post_type ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'lang'             => '',
				'suppress_filters' => false,
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Statuses a post type's posts are synced in.
	 *
	 * @param string $post_type Post type.
	 *
	 * @return string[]
	 */
	private static function statuses( string $post_type ): array {
		if ( 'attachment' === $post_type ) {
			return array( 'inherit', 'private' );
		}

		return array_values( array_diff( get_post_stati( array( 'internal' => false ) ), array( 'trash', 'auto-draft', 'inherit' ) ) );
	}

	/**
	 * Counts a post type's syncable posts.
	 *
	 * @param string $post_type Post type.
	 *
	 * @return int
	 */
	private static function count( string $post_type ): int {
		$counts = (array) wp_count_posts( $post_type );
		$total  = 0;
		foreach ( self::statuses( $post_type ) as $status ) {
			$total += (int) ( $counts[ $status ] ?? 0 );
		}

		return $total;
	}
}
