<?php
/**
 * Dev stack only: the content model shared by staging and production.
 *
 * Loaded on both sites, like a theme deployed to both, so the post type,
 * taxonomy, menu locations, ACF field groups and the ACF block are always
 * identical, field keys included. The keys are readable on purpose
 * (field_silta_*, group_silta_*): they show up in post meta and block data.
 *
 * The content field group has one field of every ID-bearing ACF type, plus
 * a repeater, flexible content and a group holding more of them, so Silta's
 * reference remapping can be tested against all of them.
 *
 * @package Jcore\Silta\Dev
 */

add_action(
	'init',
	static function (): void {
		register_post_type(
			'event',
			array(
				'labels'       => array(
					'name'          => 'Events',
					'singular_name' => 'Event',
				),
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'events' ),
				'menu_icon'    => 'dashicons-calendar-alt',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
			)
		);

		register_taxonomy(
			'event_type',
			array( 'event' ),
			array(
				'labels'            => array(
					'name'          => 'Event types',
					'singular_name' => 'Event type',
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
			)
		);
	}
);

// Block themes such as Twenty Twenty-Five have no classic menu locations.
add_action(
	'after_setup_theme',
	static function (): void {
		register_nav_menus(
			array(
				'silta-primary' => 'Silta primary',
				'silta-footer'  => 'Silta footer',
			)
		);
	}
);

// Translate events and event types with Polylang, on both sites alike.
add_filter(
	'pll_get_post_types',
	static function ( array $post_types, bool $is_settings ): array {
		return $is_settings ? $post_types : $post_types + array( 'event' => 'event' );
	},
	10,
	2
);
add_filter(
	'pll_get_taxonomies',
	static function ( array $taxonomies, bool $is_settings ): array {
		return $is_settings ? $taxonomies : $taxonomies + array( 'event_type' => 'event_type' );
	},
	10,
	2
);

add_action(
	'acf/init',
	static function (): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		$any_post_type = array( 'post', 'page', 'event' );

		acf_add_local_field_group(
			array(
				'key'      => 'group_silta_content',
				'title'    => 'Silta: every reference type',
				'position' => 'normal',
				'fields'   => array(
					array(
						'key'   => 'field_silta_subtitle',
						'label' => 'Subtitle',
						'name'  => 'silta_subtitle',
						'type'  => 'text',
					),
					array(
						'key'           => 'field_silta_image',
						'label'         => 'Image',
						'name'          => 'silta_image',
						'type'          => 'image',
						'return_format' => 'array',
						'preview_size'  => 'medium',
					),
					array(
						'key'           => 'field_silta_gallery',
						'label'         => 'Gallery',
						'name'          => 'silta_gallery',
						'type'          => 'gallery',
						'return_format' => 'array',
						'preview_size'  => 'thumbnail',
					),
					array(
						'key'           => 'field_silta_file',
						'label'         => 'File',
						'name'          => 'silta_file',
						'type'          => 'file',
						'return_format' => 'array',
					),
					array(
						'key'           => 'field_silta_post_object',
						'label'         => 'Post object (single)',
						'name'          => 'silta_post_object',
						'type'          => 'post_object',
						'post_type'     => $any_post_type,
						'multiple'      => 0,
						'allow_null'    => 1,
						'return_format' => 'object',
					),
					array(
						'key'           => 'field_silta_post_objects',
						'label'         => 'Post object (multiple)',
						'name'          => 'silta_post_objects',
						'type'          => 'post_object',
						'post_type'     => $any_post_type,
						'multiple'      => 1,
						'allow_null'    => 1,
						'return_format' => 'id',
					),
					array(
						'key'           => 'field_silta_relationship',
						'label'         => 'Relationship',
						'name'          => 'silta_relationship',
						'type'          => 'relationship',
						'post_type'     => $any_post_type,
						'filters'       => array( 'search', 'post_type' ),
						'return_format' => 'object',
					),
					array(
						'key'            => 'field_silta_page_link',
						'label'          => 'Page link',
						'name'           => 'silta_page_link',
						'type'           => 'page_link',
						'post_type'      => array( 'page' ),
						'allow_archives' => 1,
						'multiple'       => 0,
						'allow_null'     => 1,
					),
					array(
						'key'           => 'field_silta_categories',
						'label'         => 'Categories (not saved as terms)',
						'name'          => 'silta_categories',
						'type'          => 'taxonomy',
						'taxonomy'      => 'category',
						'field_type'    => 'multi_select',
						'add_term'      => 0,
						'save_terms'    => 0,
						'load_terms'    => 0,
						'return_format' => 'id',
					),
					array(
						'key'           => 'field_silta_user',
						'label'         => 'User',
						'name'          => 'silta_user',
						'type'          => 'user',
						'multiple'      => 0,
						'allow_null'    => 1,
						'return_format' => 'array',
					),
					array(
						'key'           => 'field_silta_link',
						'label'         => 'Link',
						'name'          => 'silta_link',
						'type'          => 'link',
						'return_format' => 'array',
					),
					array(
						'key'          => 'field_silta_body',
						'label'        => 'Body (may hold image URLs)',
						'name'         => 'silta_body',
						'type'         => 'wysiwyg',
						'tabs'         => 'all',
						'toolbar'      => 'full',
						'media_upload' => 1,
					),
					array(
						'key'          => 'field_silta_slides',
						'label'        => 'Slides (repeater)',
						'name'         => 'silta_slides',
						'type'         => 'repeater',
						'layout'       => 'row',
						'button_label' => 'Add slide',
						'sub_fields'   => array(
							array(
								'key'           => 'field_silta_slides_image',
								'label'         => 'Image',
								'name'          => 'image',
								'type'          => 'image',
								'return_format' => 'id',
								'preview_size'  => 'thumbnail',
							),
							array(
								'key'   => 'field_silta_slides_caption',
								'label' => 'Caption',
								'name'  => 'caption',
								'type'  => 'text',
							),
							array(
								'key'           => 'field_silta_slides_post',
								'label'         => 'Linked post',
								'name'          => 'post',
								'type'          => 'post_object',
								'post_type'     => $any_post_type,
								'allow_null'    => 1,
								'return_format' => 'id',
							),
						),
					),
					array(
						'key'          => 'field_silta_sections',
						'label'        => 'Sections (flexible content)',
						'name'         => 'silta_sections',
						'type'         => 'flexible_content',
						'button_label' => 'Add section',
						'layouts'      => array(
							'layout_silta_media'   => array(
								'key'        => 'layout_silta_media',
								'name'       => 'media',
								'label'      => 'Media',
								'display'    => 'block',
								'sub_fields' => array(
									array(
										'key'           => 'field_silta_sections_media_image',
										'label'         => 'Image',
										'name'          => 'image',
										'type'          => 'image',
										'return_format' => 'id',
										'preview_size'  => 'thumbnail',
									),
									array(
										'key'   => 'field_silta_sections_media_text',
										'label' => 'Text',
										'name'  => 'text',
										'type'  => 'textarea',
									),
								),
							),
							'layout_silta_related' => array(
								'key'        => 'layout_silta_related',
								'name'       => 'related',
								'label'      => 'Related posts',
								'display'    => 'block',
								'sub_fields' => array(
									array(
										'key'           => 'field_silta_sections_related_posts',
										'label'         => 'Posts',
										'name'          => 'posts',
										'type'          => 'relationship',
										'post_type'     => $any_post_type,
										'return_format' => 'id',
									),
								),
							),
						),
					),
					array(
						'key'        => 'field_silta_hero',
						'label'      => 'Hero (group)',
						'name'       => 'silta_hero',
						'type'       => 'group',
						'layout'     => 'block',
						'sub_fields' => array(
							array(
								'key'           => 'field_silta_hero_image',
								'label'         => 'Image',
								'name'          => 'image',
								'type'          => 'image',
								'return_format' => 'id',
								'preview_size'  => 'medium',
							),
							array(
								'key'   => 'field_silta_hero_heading',
								'label' => 'Heading',
								'name'  => 'heading',
								'type'  => 'text',
							),
						),
					),
				),
				'location' => array_map(
					static function ( string $post_type ): array {
						return array(
							array(
								'param'    => 'post_type',
								'operator' => '==',
								'value'    => $post_type,
							),
						);
					},
					$any_post_type
				),
			)
		);

		// An image in term meta, to test term references.
		acf_add_local_field_group(
			array(
				'key'      => 'group_silta_term',
				'title'    => 'Silta: term fields',
				'fields'   => array(
					array(
						'key'           => 'field_silta_term_image',
						'label'         => 'Term image',
						'name'          => 'silta_term_image',
						'type'          => 'image',
						'return_format' => 'id',
						'preview_size'  => 'thumbnail',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'taxonomy',
							'operator' => '==',
							'value'    => 'event_type',
						),
					),
				),
			)
		);

		acf_add_local_field_group(
			array(
				'key'      => 'group_silta_card',
				'title'    => 'Silta card block',
				'fields'   => array(
					array(
						'key'           => 'field_silta_card_image',
						'label'         => 'Image',
						'name'          => 'image',
						'type'          => 'image',
						'return_format' => 'id',
						'preview_size'  => 'medium',
					),
					array(
						'key'           => 'field_silta_card_post',
						'label'         => 'Post',
						'name'          => 'post',
						'type'          => 'post_object',
						'post_type'     => $any_post_type,
						'allow_null'    => 1,
						'return_format' => 'id',
					),
					array(
						'key'          => 'field_silta_card_items',
						'label'        => 'Items',
						'name'         => 'items',
						'type'         => 'repeater',
						'layout'       => 'table',
						'button_label' => 'Add item',
						'sub_fields'   => array(
							array(
								'key'           => 'field_silta_card_items_image',
								'label'         => 'Image',
								'name'          => 'image',
								'type'          => 'image',
								'return_format' => 'id',
								'preview_size'  => 'thumbnail',
							),
						),
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'block',
							'operator' => '==',
							'value'    => 'acf/silta-card',
						),
					),
				),
			)
		);

		if ( function_exists( 'acf_register_block_type' ) ) {
			acf_register_block_type(
				array(
					'name'            => 'silta-card',
					'title'           => 'Silta card',
					'description'     => 'Dev fixture: an image, a linked post and a row of thumbnails.',
					'category'        => 'design',
					'icon'            => 'id-alt',
					'mode'            => 'preview',
					'supports'        => array(
						'align' => false,
						'mode'  => true,
					),
					'render_callback' => 'jcore_silta_dev_render_card_block',
				)
			);
		}
	}
);

/**
 * Renders the acf/silta-card block.
 *
 * @param array $block Block settings and attributes.
 * @return void
 */
function jcore_silta_dev_render_card_block( array $block ): void {
	$image_id = (int) get_field( 'image' );
	$post_id  = (int) get_field( 'post' );
	$items    = get_field( 'items' );
	$classes  = 'silta-card' . ( empty( $block['className'] ) ? '' : ' ' . $block['className'] );

	echo '<div class="' . esc_attr( $classes ) . '">';
	if ( $image_id ) {
		echo wp_get_attachment_image( $image_id, 'medium' );
	}
	if ( $post_id ) {
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( get_permalink( $post_id ) ),
			esc_html( get_the_title( $post_id ) )
		);
	}
	if ( is_array( $items ) && $items ) {
		echo '<ul class="silta-card__items">';
		foreach ( $items as $item ) {
			echo '<li>' . wp_get_attachment_image( (int) $item['image'], 'thumbnail' ) . '</li>';
		}
		echo '</ul>';
	}
	echo '</div>';
}
