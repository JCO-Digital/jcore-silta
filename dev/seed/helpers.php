<?php
/**
 * Dev stack only: helpers shared by seed.php and the diverge scripts.
 *
 * Block helpers serialise blocks the way the block editor saves them, so the
 * content looks like it was written in the editor: attributes as JSON in the
 * comment, markup on its own lines, inner blocks separated by blank lines.
 *
 * @package Jcore\Silta\Dev
 */

// Run with `wp eval-file`, never loaded by the plugin.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Store block markup exactly as given, like an administrator's editor would.
wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
kses_remove_filters();

/**
 * Serialises a block the way the block editor does: attributes as JSON,
 * the markup on its own lines, and a void comment for empty blocks.
 *
 * @param string $name  Block name; `core/` may be left out.
 * @param array  $attrs Comment attributes.
 * @param string $html  Saved markup, inner blocks included.
 * @return string
 */
function silta_seed_block( string $name, array $attrs = array(), string $html = '' ): string {
	$name = preg_replace( '#^core/#', '', $name );
	$json = $attrs ? serialize_block_attributes( $attrs ) . ' ' : '';

	return '' === $html ? "<!-- wp:$name $json/-->" : "<!-- wp:$name $json-->\n$html\n<!-- /wp:$name -->";
}

/**
 * Joins blocks the way the editor separates them.
 *
 * @param string ...$blocks Serialised blocks.
 * @return string
 */
function silta_seed_blocks( string ...$blocks ): string {
	return implode( "\n\n", $blocks );
}

/**
 * A core/paragraph block.
 *
 * @param string $html  Inner HTML, already escaped.
 * @param array  $attrs Block attributes.
 * @return string
 */
function silta_seed_paragraph( string $html, array $attrs = array() ): string {
	return silta_seed_block( 'paragraph', $attrs, "<p>$html</p>" );
}

/**
 * A core/heading block.
 *
 * @param string $text  Heading text.
 * @param int    $level 1 to 6.
 * @return string
 */
function silta_seed_heading( string $text, int $level = 2 ): string {
	$attrs = 2 === $level ? array() : array( 'level' => $level );

	return silta_seed_block( 'heading', $attrs, "<h$level class=\"wp-block-heading\">" . esc_html( $text ) . "</h$level>" );
}

/**
 * An internal link as the editor's link search saves it, with data-type and data-id.
 *
 * @param int    $post_id Linked post.
 * @param string $text    Link text.
 * @return string
 */
function silta_seed_link( int $post_id, string $text ): string {
	return sprintf(
		'<a href="%s" data-type="%s" data-id="%d">%s</a>',
		esc_url( get_permalink( $post_id ) ),
		esc_attr( get_post_type( $post_id ) ),
		$post_id,
		esc_html( $text )
	);
}

/**
 * A core/image block with the attachment ID in its attributes and class.
 *
 * @param int    $id      Attachment ID.
 * @param string $size    Image size slug.
 * @param string $caption Optional caption.
 * @return string
 */
function silta_seed_image( int $id, string $size = 'large', string $caption = '' ): string {
	$html = sprintf(
		'<figure class="wp-block-image size-%s"><img src="%s" alt="%s" class="wp-image-%d"/>%s</figure>',
		$size,
		esc_url( wp_get_attachment_image_url( $id, $size ) ),
		esc_attr( get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
		$id,
		$caption ? '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>' : ''
	);

	return silta_seed_block(
		'image',
		array(
			'id'              => $id,
			'sizeSlug'        => $size,
			'linkDestination' => 'none',
		),
		$html
	);
}

/**
 * A core/gallery block in the nested format (one core/image per item).
 *
 * @param int[] $ids Attachment IDs.
 * @return string
 */
function silta_seed_gallery( array $ids ): string {
	$images = silta_seed_blocks( ...array_map( 'silta_seed_image', $ids ) );

	return silta_seed_block(
		'gallery',
		array( 'linkTo' => 'none' ),
		'<figure class="wp-block-gallery has-nested-images columns-default is-cropped">' . $images . '</figure>'
	);
}

/**
 * A core/cover block with an image background and a centred title.
 *
 * @param int    $id   Attachment ID.
 * @param string $text Title.
 * @return string
 */
function silta_seed_cover( int $id, string $text ): string {
	$url   = wp_get_attachment_url( $id );
	$inner = silta_seed_block(
		'paragraph',
		array(
			'align'       => 'center',
			'placeholder' => 'Write title…',
			'fontSize'    => 'large',
		),
		'<p class="has-text-align-center has-large-font-size">' . esc_html( $text ) . '</p>'
	);

	return silta_seed_block(
		'cover',
		array(
			'url'                => $url,
			'id'                 => $id,
			'dimRatio'           => 50,
			'customOverlayColor' => '#2b3a42',
			'isUserOverlayColor' => true,
			'sizeSlug'           => 'full',
			'layout'             => array( 'type' => 'constrained' ),
		),
		sprintf(
			'<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-%d size-full" alt="" src="%s" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-background-dim" style="background-color:#2b3a42"></span><div class="wp-block-cover__inner-container">%s</div></div>',
			$id,
			esc_url( $url ),
			$inner
		)
	);
}

/**
 * A core/media-text block with an image.
 *
 * @param int    $id   Attachment ID.
 * @param string $html Paragraph HTML, already escaped.
 * @return string
 */
function silta_seed_media_text( int $id, string $html ): string {
	$inner = silta_seed_block( 'paragraph', array( 'placeholder' => 'Content…' ), "<p>$html</p>" );

	return silta_seed_block(
		'media-text',
		array(
			'mediaId'   => $id,
			'mediaLink' => get_attachment_link( $id ),
			'mediaType' => 'image',
		),
		sprintf(
			'<div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="%s" alt="%s" class="wp-image-%d size-full"/></figure><div class="wp-block-media-text__content">%s</div></div>',
			esc_url( wp_get_attachment_url( $id ) ),
			esc_attr( get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
			$id,
			$inner
		)
	);
}

/**
 * A core/file block for a PDF, with the inline preview and a download button.
 *
 * @param int    $id     Attachment ID.
 * @param string $label  Link text.
 * @param string $button Button text.
 * @return string
 */
function silta_seed_file( int $id, string $label, string $button ): string {
	$url    = esc_url( wp_get_attachment_url( $id ) );
	$anchor = 'wp-block-file--media-' . wp_generate_uuid4();

	return silta_seed_block(
		'file',
		array(
			'id'             => $id,
			'href'           => wp_get_attachment_url( $id ),
			'displayPreview' => true,
		),
		sprintf(
			'<div class="wp-block-file"><object class="wp-block-file__embed" data="%1$s" type="application/pdf" style="width:100%%;height:600px" aria-label="%2$s"></object><a id="%3$s" href="%1$s">%2$s</a><a href="%1$s" class="wp-block-file__button wp-element-button" download aria-describedby="%3$s">%4$s</a></div>',
			$url,
			esc_html( $label ),
			$anchor,
			esc_html( $button )
		)
	);
}

/**
 * A core/buttons block with one button.
 *
 * @param string $url  Link target.
 * @param string $text Button text.
 * @return string
 */
function silta_seed_button( string $url, string $text ): string {
	$button = silta_seed_block(
		'button',
		array(),
		sprintf( '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="%s">%s</a></div>', esc_url( $url ), esc_html( $text ) )
	);

	return silta_seed_block( 'buttons', array(), '<div class="wp-block-buttons">' . $button . '</div>' );
}

/**
 * A core/query loop showing titles and dates.
 *
 * @param int   $query_id The block's queryId.
 * @param array $query    Overrides for the block's `query` attribute.
 * @return string
 */
function silta_seed_query( int $query_id, array $query ): string {
	$template = silta_seed_block(
		'post-template',
		array(),
		silta_seed_blocks( silta_seed_block( 'post-title', array( 'isLink' => true ) ), silta_seed_block( 'post-date' ) )
	);

	return silta_seed_block(
		'query',
		array(
			'queryId' => $query_id,
			'query'   => array_merge(
				array(
					'perPage'  => 3,
					'pages'    => 0,
					'offset'   => 0,
					'postType' => 'post',
					'order'    => 'desc',
					'orderBy'  => 'date',
					'author'   => '',
					'search'   => '',
					'exclude'  => array(),
					'sticky'   => '',
					'inherit'  => false,
					'parents'  => array(),
					'format'   => array(),
				),
				$query
			),
		),
		'<div class="wp-block-query">' . $template . '</div>'
	);
}

/**
 * The acf/silta-card block. `data` is keyed by field key, as the editor sends
 * it; SCF's content_save_pre filter rewrites it to the name/_name format it stores.
 *
 * @param int   $image_id       Image field.
 * @param int   $post_id        Post object field.
 * @param int[] $item_image_ids One repeater row per image.
 * @return string
 */
function silta_seed_card( int $image_id, int $post_id, array $item_image_ids ): string {
	return silta_seed_block(
		'acf/silta-card',
		array(
			'name' => 'acf/silta-card',
			'data' => array(
				'field_silta_card_image' => (string) $image_id,
				'field_silta_card_post'  => (string) $post_id,
				'field_silta_card_items' => array_map(
					static function ( int $id ): array {
						return array( 'field_silta_card_items_image' => (string) $id );
					},
					$item_image_ids
				),
			),
			'mode' => 'preview',
		)
	);
}

/**
 * Creates a post of any type, then sets its language, terms and featured image.
 *
 * Extra keys: lang (default fi), terms (taxonomy => term IDs), thumbnail.
 *
 * @param array $args wp_insert_post() arguments plus the extra keys.
 * @return int Post ID.
 */
function silta_seed_post( array $args ): int {
	$lang  = $args['lang'] ?? 'fi';
	$terms = $args['terms'] ?? array();
	$thumb = $args['thumbnail'] ?? 0;
	unset( $args['lang'], $args['terms'], $args['thumbnail'] );

	$args += array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'post_author' => get_current_user_id(),
	);

	$id = wp_insert_post( wp_slash( $args ), true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( "Could not create '{$args['post_title']}': " . $id->get_error_message() );
	}

	// Language first: Polylang checks term languages against the post's.
	pll_set_post_language( $id, $lang );
	foreach ( $terms as $taxonomy => $term_ids ) {
		wp_set_object_terms( $id, $term_ids, $taxonomy );
	}
	if ( $thumb ) {
		set_post_thumbnail( $id, $thumb );
	}

	return $id;
}

/**
 * Replaces a post's content, which also saves a revision.
 *
 * @param int    $id      Post ID.
 * @param string $content New content.
 * @return void
 */
function silta_seed_update_content( int $id, string $content ): void {
	$result = wp_update_post(
		wp_slash(
			array(
				'ID'           => $id,
				'post_content' => $content,
			)
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( "Could not update post $id: " . $result->get_error_message() );
	}
}

/**
 * Creates a term in a language, optionally under a parent.
 *
 * @param string $taxonomy    Taxonomy.
 * @param string $name        Term name.
 * @param string $lang        Polylang language slug.
 * @param int    $parent_id   Parent term ID.
 * @param string $description Term description.
 * @return int Term ID.
 */
function silta_seed_term( string $taxonomy, string $name, string $lang, int $parent_id = 0, string $description = '' ): int {
	$result = wp_insert_term(
		$name,
		$taxonomy,
		array(
			'parent'      => $parent_id,
			'description' => $description,
		)
	);
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( "Could not create $taxonomy '$name': " . $result->get_error_message() );
	}

	pll_set_term_language( $result['term_id'], $lang );

	return (int) $result['term_id'];
}

/**
 * Draws a gradient image with a few circles and a big label. The circles are
 * placed from a hash of the label, so every reset draws the same image.
 *
 * @param string $path   Where to write it.
 * @param string $format jpg, png or webp.
 * @param int    $width  Width in pixels.
 * @param int    $height Height in pixels.
 * @param string $from   Top colour, #rrggbb.
 * @param string $to     Bottom colour, #rrggbb.
 * @param string $label  ASCII label.
 * @return void
 */
function silta_seed_draw_image( string $path, string $format, int $width, int $height, string $from, string $to, string $label ): void {
	$from  = sscanf( $from, '#%02x%02x%02x' );
	$to    = sscanf( $to, '#%02x%02x%02x' );
	$image = imagecreatetruecolor( $width, $height );

	for ( $y = 0; $y < $height; $y++ ) {
		$t     = $y / max( 1, $height - 1 );
		$color = imagecolorallocate(
			$image,
			(int) round( $from[0] + ( $to[0] - $from[0] ) * $t ),
			(int) round( $from[1] + ( $to[1] - $from[1] ) * $t ),
			(int) round( $from[2] + ( $to[2] - $from[2] ) * $t )
		);
		imageline( $image, 0, $y, $width - 1, $y, $color );
	}

	$white = imagecolorallocatealpha( $image, 255, 255, 255, 96 );
	for ( $i = 0; $i < 6; $i++ ) {
		$hash = crc32( "$label/$i" );
		$size = (int) ( $height / 8 ) + $hash % (int) ( $height * 3 / 8 );
		imagefilledellipse( $image, ( $hash >> 8 ) % $width, ( $hash >> 16 ) % $height, $size, $size, $white );
	}

	// imagestring() only has a tiny bitmap font: draw it small, then scale it up.
	$font  = 5;
	$text  = imagecreatetruecolor( imagefontwidth( $font ) * strlen( $label ), imagefontheight( $font ) );
	$clear = imagecolorallocatealpha( $text, 0, 0, 0, 127 );
	imagealphablending( $text, false );
	imagefill( $text, 0, 0, $clear );
	imagestring( $text, $font, 0, 0, $label, imagecolorallocate( $text, 255, 255, 255 ) );
	$scale = ( $width * 0.7 ) / imagesx( $text );
	imagecopyresampled(
		$image,
		$text,
		(int) ( $width * 0.15 ),
		(int) ( ( $height - imagesy( $text ) * $scale ) / 2 ),
		0,
		0,
		(int) ( imagesx( $text ) * $scale ),
		(int) ( imagesy( $text ) * $scale ),
		imagesx( $text ),
		imagesy( $text )
	);

	match ( $format ) {
		'png'   => imagepng( $image, $path ),
		'webp'  => imagewebp( $image, $path, 85 ),
		default => imagejpeg( $image, $path, 85 ),
	};
}

/**
 * A one-page PDF, written by hand with a correct xref table.
 *
 * @param string[] $lines ASCII lines; the first is the title.
 * @return string PDF bytes.
 */
function silta_seed_pdf( array $lines ): string {
	$stream = '';
	$y      = 770;
	foreach ( $lines as $i => $line ) {
		$line    = strtr(
			$line,
			array(
				'\\' => '\\\\',
				'('  => '\\(',
				')'  => '\\)',
			)
		);
		$size    = 0 === $i ? 22 : 12;
		$stream .= "BT /F1 $size Tf 72 $y Td ($line) Tj ET\n";
		$y      -= 0 === $i ? 40 : 18;
	}
	$stream = rtrim( $stream );

	$objects = array(
		'<< /Type /Catalog /Pages 2 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		'<< /Length ' . strlen( $stream ) . " >>\nstream\n$stream\nendstream",
	);

	$pdf     = "%PDF-1.4\n";
	$offsets = array();
	foreach ( $objects as $i => $object ) {
		$offsets[] = strlen( $pdf );
		$pdf      .= ( $i + 1 ) . " 0 obj\n$object\nendobj\n";
	}
	$xref = strlen( $pdf );
	$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
	foreach ( $offsets as $offset ) {
		$pdf .= sprintf( "%010d 00000 n \n", $offset );
	}

	return $pdf . 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

/**
 * Sideloads a file from a temp path into the media library. The temp file is
 * moved into uploads.
 *
 * @param string $tmp         Temp file path.
 * @param string $filename    File name in uploads.
 * @param string $title       Attachment title.
 * @param string $caption     Caption (post_excerpt).
 * @param string $alt         Alt text.
 * @param string $description Description (post_content).
 * @return int Attachment ID.
 */
function silta_seed_sideload( string $tmp, string $filename, string $title, string $caption = '', string $alt = '', string $description = '' ): int {
	$id = media_handle_sideload(
		array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		),
		0,
		$title,
		array(
			'post_excerpt' => $caption,
			'post_content' => $description,
		)
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( "Could not sideload $filename: " . $id->get_error_message() );
	}
	if ( $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}

	return $id;
}

/**
 * Adds a published menu item.
 *
 * @param int   $menu_id Menu term ID.
 * @param array $item    wp_update_nav_menu_item() data.
 * @return int Menu item ID.
 */
function silta_seed_menu_item( int $menu_id, array $item ): int {
	$id = wp_update_nav_menu_item( $menu_id, 0, $item + array( 'menu-item-status' => 'publish' ) );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( 'Could not add menu item: ' . $id->get_error_message() );
	}

	return $id;
}
