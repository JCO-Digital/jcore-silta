<?php
/**
 * Dev stack only: seeds staging with content for testing Silta.
 *
 * Runs once, on a fresh staging site, before setup.sh clones production from
 * it. It creates Finnish (default) and English languages, an editor,
 * generated images and a PDF, a category/tag/event type hierarchy, pages,
 * events, posts, a reusable block and two menus. The content references all of
 * it the way real content does: block attributes, wp-image-N classes, link
 * data-ids, ACF fields (repeater, flexible content, group, ACF block), Yoast
 * meta, featured images, Polylang translations and absolute URLs.
 *
 * IDs of everything created are stored in the `silta_dev_seed` option, which
 * the diverge scripts read.
 *
 * Run by setup.sh, or by hand on a fresh site:
 *   docker compose run --rm cli-staging wp eval-file /seed/seed.php --user=admin
 *
 * @package Jcore\Silta\Dev
 */

// Run with `wp eval-file`, never loaded by the plugin: these are not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( get_option( 'silta_dev_seeded' ) ) {
	WP_CLI::log( 'Already seeded on ' . get_option( 'silta_dev_seeded' ) . ', skipping.' );
	return;
}

foreach ( array( 'pll_set_post_language', 'update_field', 'acf_has_block_type' ) as $required ) {
	if ( ! function_exists( $required ) ) {
		WP_CLI::error( "$required() is missing: activate polylang and secure-custom-fields first." );
	}
}

require_once __DIR__ . '/helpers.php';

$admin = wp_get_current_user();
$seed  = array();

/*
 * Users. The editor is the author of a few posts and the value of an ACF user field.
 */

$editor = wp_insert_user(
	array(
		'user_login'   => 'editor',
		'user_pass'    => 'editor',
		'user_email'   => 'editor@example.com',
		'display_name' => 'Eeva Editori',
		'first_name'   => 'Eeva',
		'last_name'    => 'Editori',
		'role'         => 'editor',
	)
);
if ( is_wp_error( $editor ) ) {
	WP_CLI::error( 'Could not create the editor: ' . $editor->get_error_message() );
}
$seed['users'] = array(
	'admin'  => $admin->ID,
	'editor' => $editor,
);

/*
 * Languages. Polylang free has no WP-CLI; since 3.7 languages are added
 * through PLL()->model->languages (PLL()->model->add_language() before that).
 */

$languages = array(
	array(
		'locale'     => 'fi',
		'slug'       => 'fi',
		'name'       => 'Suomi',
		'flag'       => 'fi',
		'term_group' => 0,
	),
	array(
		'locale'     => 'en_US',
		'slug'       => 'en',
		'name'       => 'English',
		'flag'       => 'us',
		'term_group' => 1,
	),
);
foreach ( $languages as $language ) {
	if ( PLL()->model->get_language( $language['slug'] ) ) {
		continue;
	}
	$result = isset( PLL()->model->languages )
		? PLL()->model->languages->add( $language )
		: PLL()->model->add_language( $language );
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( "Could not add language {$language['slug']}: " . $result->get_error_message() );
	}
}
// The first language added (fi) is the default. Give the default content
// (Hello world, Sample Page, Uncategorized...) a language.
PLL()->model->set_language_in_mass();
PLL()->model->clean_languages_cache();

/*
 * Media: eight images in three formats, one wider than the 2560px
 * big-image threshold (so WordPress keeps a -scaled copy), and a PDF.
 */

$images = array(
	// key => file, format, width, height, from, to, label, title, caption, alt.
	'aurora'   => array( 'revontulet.jpg', 'jpg', 1920, 1080, '#0b1d3a', '#1fa37a', 'AURORA 1920x1080', 'Revontulet', 'Revontulet Inarin yllä.', 'Vihreät revontulet tähtitaivaalla' ),
	'lake'     => array( 'jarvi-illalla.jpg', 'jpg', 1600, 900, '#f08a24', '#5b2a86', 'LAKE 1600x900', 'Järvi illalla', 'Auringonlasku Päijänteellä.', 'Oranssi auringonlasku järven yllä' ),
	'forest'   => array( 'metsapolku.jpg', 'jpg', 1200, 800, '#1e4d2b', '#a7c957', 'FOREST 1200x800', 'Metsäpolku', 'Polku kuusimetsässä.', 'Kapea polku vihreässä metsässä' ),
	'panorama' => array( 'saariston-panoraama.jpg', 'jpg', 3200, 1200, '#0077b6', '#caf0f8', 'ARCHIPELAGO 3200x1200', 'Saariston panoraama', 'Turun saaristo kesällä.', 'Saaria ja merta sinisellä taivaalla' ),
	'sauna'    => array( 'saunan-ikkuna.png', 'png', 800, 800, '#6d4c41', '#ffcc80', 'SAUNA 800x800', 'Saunan ikkuna', 'Näkymä saunan ikkunasta.', 'Puinen saunan ikkuna' ),
	'card'     => array( 'kortti.jpg', 'jpg', 640, 480, '#c1121f', '#fdf0d5', 'CARD 640x480', 'Korttikuva', '', 'Punainen korttikuva' ),
	'rocks'    => array( 'rantakalliot.webp', 'webp', 1024, 768, '#264653', '#e9c46a', 'ROCKS 1024x768', 'Rantakalliot', 'Silokalliot rannalla.', 'Sileät kalliot meren rannalla' ),
	'pier'     => array( 'laituri.jpg', 'jpg', 1024, 683, '#457b9d', '#f1faee', 'PIER 1024x683', 'Laituri', 'Laituri aamu-usvassa.', 'Puulaituri tyynellä järvellä' ),
);

$media = array();
foreach ( $images as $key => list( $file, $format, $width, $height, $from, $to, $label, $image_title, $caption, $alt ) ) {
	$tmp = wp_tempnam( $file );
	silta_seed_draw_image( $tmp, $format, $width, $height, $from, $to, $label );
	$media[ $key ] = silta_seed_sideload( $tmp, $file, $image_title, $caption, $alt, "Generated test image ($width x $height)." );
	WP_CLI::log( "Image $file: {$media[ $key ]}" );
}

$tmp = wp_tempnam( 'hinnasto-2026.pdf' );
file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions -- A temp file in a CLI script.
	$tmp,
	silta_seed_pdf(
		array(
			'Silta price list 2026',
			'Bridge inspection ..... 120 EUR',
			'Content sync, per site ..... 80 EUR',
			'Generated by dev/seed/seed.php for testing media transfer.',
		)
	)
);
$media['pdf'] = silta_seed_sideload( $tmp, 'hinnasto-2026.pdf', 'Hinnasto 2026', 'Hinnasto PDF-muodossa.', '', 'Testidokumentti.' );
WP_CLI::log( "PDF hinnasto-2026.pdf: {$media['pdf']}" );
$seed['media'] = $media;

/*
 * Terms, in both languages, linked as translations.
 */

$t = array();

$t['cat_news_fi']     = silta_seed_term( 'category', 'Uutiset', 'fi', 0, 'Ajankohtaista.' );
$t['cat_company_fi']  = silta_seed_term( 'category', 'Yritysuutiset', 'fi', $t['cat_news_fi'] );
$t['cat_product_fi']  = silta_seed_term( 'category', 'Tuoteuutiset', 'fi', $t['cat_news_fi'] );
$t['cat_software_fi'] = silta_seed_term( 'category', 'Ohjelmistot', 'fi', $t['cat_product_fi'] );
$t['cat_blog_fi']     = silta_seed_term( 'category', 'Blogi', 'fi' );
$t['cat_news_en']     = silta_seed_term( 'category', 'News', 'en', 0, 'What is new.' );
$t['cat_company_en']  = silta_seed_term( 'category', 'Company news', 'en', $t['cat_news_en'] );
$t['cat_product_en']  = silta_seed_term( 'category', 'Product news', 'en', $t['cat_news_en'] );
$t['cat_software_en'] = silta_seed_term( 'category', 'Software', 'en', $t['cat_product_en'] );
$t['cat_blog_en']     = silta_seed_term( 'category', 'Blog', 'en' );

$t['tag_summer_fi'] = silta_seed_term( 'post_tag', 'kesä', 'fi' );
$t['tag_winter_fi'] = silta_seed_term( 'post_tag', 'talvi', 'fi' );
$t['tag_sauna_fi']  = silta_seed_term( 'post_tag', 'sauna', 'fi' );
$t['tag_summer_en'] = silta_seed_term( 'post_tag', 'summer', 'en' );
$t['tag_winter_en'] = silta_seed_term( 'post_tag', 'winter', 'en' );
$t['tag_sauna_en']  = silta_seed_term( 'post_tag', 'sauna culture', 'en' );

$t['type_concert_fi']  = silta_seed_term( 'event_type', 'Konsertti', 'fi' );
$t['type_workshop_fi'] = silta_seed_term( 'event_type', 'Työpaja', 'fi' );
$t['type_online_fi']   = silta_seed_term( 'event_type', 'Verkkotyöpaja', 'fi', $t['type_workshop_fi'] );
$t['type_concert_en']  = silta_seed_term( 'event_type', 'Concert', 'en' );
$t['type_workshop_en'] = silta_seed_term( 'event_type', 'Workshop', 'en' );
$t['type_online_en']   = silta_seed_term( 'event_type', 'Online workshop', 'en', $t['type_workshop_en'] );

foreach ( array( 'cat_news', 'cat_company', 'cat_product', 'cat_software', 'cat_blog', 'tag_summer', 'tag_winter', 'tag_sauna', 'type_concert', 'type_workshop', 'type_online' ) as $key ) {
	pll_save_term_translations(
		array(
			'fi' => $t[ "{$key}_fi" ],
			'en' => $t[ "{$key}_en" ],
		)
	);
}

// Term meta: a plain value and an ACF image (an attachment ID in term meta).
update_term_meta( $t['type_concert_fi'], 'silta_color', '#c1121f' );
update_field( 'field_silta_term_image', $media['card'], 'term_' . $t['type_concert_fi'] );
update_term_meta( $t['type_concert_en'], 'silta_color', '#c1121f' );

$seed['terms'] = $t;

/*
 * Posts are created in dependency order, so content can point at what
 * already exists. A few links back to later posts are filled in at the end.
 */

$p = array();

$p['about_fi']   = silta_seed_post(
	array(
		'post_type'  => 'page',
		'post_title' => 'Tietoa meistä',
		'post_name'  => 'tietoa-meista',
		'menu_order' => 1,
	)
);
$p['team_fi']    = silta_seed_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Tiimi',
		'post_parent'  => $p['about_fi'],
		'menu_order'   => 1,
		'thumbnail'    => $media['forest'],
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'Meitä on kuusi: kehittäjiä, suunnittelijoita ja yksi sisällöntuottaja.' ),
			silta_seed_image( $media['forest'], 'large', 'Tiimin retkipäivä.' )
		),
	)
);
$p['history_fi'] = silta_seed_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Historia',
		'post_parent'  => $p['about_fi'],
		'menu_order'   => 2,
		'post_content' => silta_seed_paragraph( 'Ensimmäinen silta rakennettiin vuonna 2026.' ),
	)
);
$p['contact_fi'] = silta_seed_post(
	array(
		'post_type'     => 'page',
		'post_title'    => 'Yhteystiedot',
		'page_template' => 'page-no-title',
		'menu_order'    => 2,
	)
);
$p['about_en']   = silta_seed_post(
	array(
		'post_type'  => 'page',
		'post_title' => 'About us',
		'menu_order' => 1,
		'lang'       => 'en',
	)
);
$p['team_en']    = silta_seed_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Team',
		'post_parent'  => $p['about_en'],
		'menu_order'   => 1,
		'lang'         => 'en',
		'thumbnail'    => $media['forest'],
		'post_content' => silta_seed_paragraph( 'There are six of us: developers, designers and one content producer.' ),
	)
);
$p['contact_en'] = silta_seed_post(
	array(
		'post_type'     => 'page',
		'post_title'    => 'Contact',
		'page_template' => 'page-no-title',
		'menu_order'    => 2,
		'lang'          => 'en',
	)
);

// Reusable (synced) block, used by pages and posts through core/block.
$p['block_fi'] = silta_seed_post(
	array(
		'post_type'    => 'wp_block',
		'post_title'   => 'Yhteystietolaatikko',
		'post_content' => silta_seed_block(
			'group',
			array( 'layout' => array( 'type' => 'constrained' ) ),
			'<div class="wp-block-group">' . silta_seed_blocks(
				silta_seed_heading( 'Ota yhteyttä', 3 ),
				silta_seed_image( $media['card'], 'medium' ),
				silta_seed_paragraph( 'Soita 040 123 4567 tai katso ' . silta_seed_link( $p['contact_fi'], 'yhteystiedot' ) . '.' )
			) . '</div>'
		),
	)
);
$p['block_en'] = silta_seed_post(
	array(
		'post_type'    => 'wp_block',
		'post_title'   => 'Contact box',
		'lang'         => 'en',
		'post_content' => silta_seed_block(
			'group',
			array( 'layout' => array( 'type' => 'constrained' ) ),
			'<div class="wp-block-group">' . silta_seed_blocks(
				silta_seed_heading( 'Get in touch', 3 ),
				silta_seed_image( $media['card'], 'medium' ),
				silta_seed_paragraph( 'Call +358 40 123 4567 or see ' . silta_seed_link( $p['contact_en'], 'contact details' ) . '.' )
			) . '</div>'
		),
	)
);

silta_seed_update_content(
	$p['contact_fi'],
	silta_seed_blocks(
		silta_seed_paragraph( 'Sähköposti: <a href="mailto:silta@example.com">silta@example.com</a>' ),
		silta_seed_paragraph( 'Lataa <a href="' . esc_url( wp_get_attachment_url( $media['pdf'] ) ) . '">hinnasto (PDF)</a>.' ),
		silta_seed_block( 'block', array( 'ref' => $p['block_fi'] ) )
	)
);
silta_seed_update_content(
	$p['contact_en'],
	silta_seed_blocks(
		silta_seed_paragraph( 'Email: <a href="mailto:silta@example.com">silta@example.com</a>' ),
		silta_seed_block( 'block', array( 'ref' => $p['block_en'] ) )
	)
);
silta_seed_update_content(
	$p['about_fi'],
	silta_seed_blocks(
		silta_seed_media_text( $media['pier'], 'Rakennamme siltoja sivustojen välille. Tutustu ' . silta_seed_link( $p['team_fi'], 'tiimiin' ) . '.' ),
		silta_seed_heading( 'Alasivut' ),
		silta_seed_query(
			2,
			array(
				'perPage'  => 10,
				'postType' => 'page',
				'order'    => 'asc',
				'orderBy'  => 'title',
				'parents'  => array( $p['about_fi'] ),
			)
		),
		silta_seed_block( 'block', array( 'ref' => $p['block_fi'] ) )
	)
);
silta_seed_update_content(
	$p['about_en'],
	silta_seed_blocks(
		silta_seed_media_text( $media['pier'], 'We build bridges between sites. Meet the ' . silta_seed_link( $p['team_en'], 'team' ) . '.' ),
		silta_seed_block( 'block', array( 'ref' => $p['block_en'] ) )
	)
);

// Events.
$p['concert_fi']  = silta_seed_post(
	array(
		'post_type'    => 'event',
		'post_title'   => 'Kesäkonsertti kalliolla',
		'post_excerpt' => 'Kamarimusiikkia rantakallioilla.',
		'thumbnail'    => $media['rocks'],
		'terms'        => array( 'event_type' => array( $t['type_concert_fi'] ) ),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-20 days' ) ),
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'Lauantaina 12.6. klo 18. Vapaa pääsy.' ),
			silta_seed_image( $media['rocks'], 'large', 'Konserttipaikka.' )
		),
	)
);
$p['workshop_fi'] = silta_seed_post(
	array(
		'post_type'    => 'event',
		'post_title'   => 'Verkkotyöpaja: sisällön siirto',
		'thumbnail'    => $media['card'],
		'terms'        => array( 'event_type' => array( $t['type_online_fi'] ) ),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-15 days' ) ),
		'post_content' => silta_seed_paragraph( 'Opitaan siirtämään sisältöä stagingista tuotantoon.' ),
	)
);
$p['xmas_fi']     = silta_seed_post(
	array(
		'post_type'    => 'event',
		'post_title'   => 'Joulukonsertti',
		'thumbnail'    => $media['sauna'],
		'terms'        => array( 'event_type' => array( $t['type_concert_fi'] ) ),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-12 days' ) ),
		'post_content' => silta_seed_paragraph( 'Perinteinen joulukonsertti kirkossa.' ),
	)
);
$p['concert_en']  = silta_seed_post(
	array(
		'post_type'    => 'event',
		'post_title'   => 'Summer concert on the rocks',
		'lang'         => 'en',
		'thumbnail'    => $media['rocks'],
		'terms'        => array( 'event_type' => array( $t['type_concert_en'] ) ),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-20 days' ) ),
		'post_content' => silta_seed_paragraph( 'Saturday 12 June at 6 pm. Free entry.' ),
	)
);

// Posts.
$p['software_fi']    = silta_seed_post(
	array(
		'post_title'   => 'Ohjelmistopäivitys 2.0',
		'post_author'  => $editor,
		'thumbnail'    => $media['card'],
		'terms'        => array( 'category' => array( $t['cat_software_fi'] ) ),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
		'post_content' => silta_seed_paragraph( 'Versio 2.0 tuo mukanaan diff-näkymän.' ),
	)
);
$p['software_en']    = silta_seed_post(
	array(
		'post_title'   => 'Software update 2.0',
		'post_author'  => $editor,
		'lang'         => 'en',
		'thumbnail'    => $media['card'],
		'terms'        => array( 'category' => array( $t['cat_software_en'] ) ),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
		'post_content' => silta_seed_paragraph( 'Version 2.0 brings a diff view.' ),
	)
);
$p['archipelago_en'] = silta_seed_post(
	array(
		'post_title'   => 'Notes from the archipelago',
		'lang'         => 'en',
		'thumbnail'    => $media['panorama'],
		'terms'        => array(
			'category' => array( $t['cat_blog_en'] ),
			'post_tag' => array( $t['tag_summer_en'] ),
		),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-25 days' ) ),
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'An English-only post with a very wide image.' ),
			silta_seed_image( $media['panorama'], 'full', 'The -scaled copy is what WordPress serves as full size.' )
		),
	)
);
$p['welcome_fi']     = silta_seed_post(
	array(
		'post_title'   => 'Tervetuloa Siltaan',
		'post_name'    => 'tervetuloa-siltaan',
		'post_excerpt' => 'Silta siirtää sisältöä sivustolta toiselle.',
		'thumbnail'    => $media['aurora'],
		'terms'        => array(
			'category' => array( $t['cat_company_fi'], $t['cat_product_fi'] ),
			'post_tag' => array( $t['tag_summer_fi'], $t['tag_sauna_fi'] ),
		),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'Tämä artikkeli on täynnä ACF-kenttiä. Kaikki kenttätyypit, joissa on ID-viittauksia, ovat mukana.' ),
			silta_seed_image( $media['aurora'], 'large', 'Revontulet Inarin yllä.' )
		),
	)
);
$p['welcome_en']     = silta_seed_post(
	array(
		'post_title'   => 'Welcome to Silta',
		'post_excerpt' => 'Silta moves content from one site to another.',
		'lang'         => 'en',
		'thumbnail'    => $media['aurora'],
		'terms'        => array(
			'category' => array( $t['cat_company_en'] ),
			'post_tag' => array( $t['tag_summer_en'] ),
		),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'This post is full of ACF fields.' ),
			silta_seed_image( $media['aurora'], 'large', 'Northern lights over Inari.' )
		),
	)
);

// The block showcase: every core block that holds an ID, plus the ACF block.
$uploads        = wp_get_upload_dir()['baseurl'];
$p['blocks_fi'] = silta_seed_post(
	array(
		'post_title'   => 'Lohkojen esittely',
		'post_excerpt' => 'Kuva, galleria, kansikuva, media ja teksti, tiedosto, uudelleenkäytettävä lohko, kysely ja ACF-lohko.',
		'thumbnail'    => $media['lake'],
		'terms'        => array(
			'category' => array( $t['cat_product_fi'] ),
			'post_tag' => array( $t['tag_summer_fi'] ),
		),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-8 days' ) ),
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph(
				'Lue ensin ' . silta_seed_link( $p['welcome_fi'], 'Tervetuloa Siltaan' ) . ', tutustu ' . silta_seed_link( $p['team_fi'], 'tiimiin' )
				. ' ja ' . silta_seed_link( $p['concert_fi'], 'kesäkonserttiin' ) . '. Täysikokoinen kuva: <a href="' . esc_url( wp_get_attachment_url( $media['lake'] ) ) . '">jarvi-illalla.jpg</a>.'
			),
			silta_seed_heading( 'Kuva' ),
			silta_seed_image( $media['lake'], 'large', 'Auringonlasku Päijänteellä.' ),
			silta_seed_heading( 'Galleria' ),
			silta_seed_gallery( array( $media['forest'], $media['rocks'], $media['pier'] ) ),
			silta_seed_heading( 'Kansikuva' ),
			silta_seed_cover( $media['panorama'], 'Saaristossa' ),
			silta_seed_heading( 'Media ja teksti' ),
			silta_seed_media_text( $media['sauna'], 'Saunan ikkunasta näkyy järvi.' ),
			silta_seed_heading( 'Tiedosto' ),
			silta_seed_file( $media['pdf'], 'hinnasto-2026', 'Lataa' ),
			silta_seed_heading( 'Uudelleenkäytettävä lohko' ),
			silta_seed_block( 'block', array( 'ref' => $p['block_fi'] ) ),
			silta_seed_heading( 'Uusimmat uutiset' ),
			silta_seed_query(
				1,
				array(
					'exclude'  => array( $p['software_fi'] ),
					'taxQuery' => array(
						'include' => array( 'category' => array( $t['cat_news_fi'], $t['cat_product_fi'] ) ),
						'exclude' => array( 'post_tag' => array( $t['tag_winter_fi'] ) ),
					),
				)
			),
			silta_seed_block(
				'latest-posts',
				array(
					'categories'  => array(
						array(
							'id'   => $t['cat_company_fi'],
							'name' => 'Yritysuutiset',
						),
					),
					'postsToShow' => 3,
				)
			),
			silta_seed_heading( 'ACF-lohko' ),
			silta_seed_card( $media['card'], $p['welcome_fi'], array( $media['forest'], $media['rocks'] ) ),
			silta_seed_button( get_permalink( $p['team_fi'] ), 'Tiimi' ),
			silta_seed_paragraph( 'Kuvat löytyvät hakemistosta ' . esc_html( $uploads ) . '.' )
		),
	)
);
$p['blocks_en'] = silta_seed_post(
	array(
		'post_title'   => 'Block showcase',
		'lang'         => 'en',
		'thumbnail'    => $media['lake'],
		'terms'        => array( 'category' => array( $t['cat_product_en'] ) ),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-8 days' ) ),
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'Read ' . silta_seed_link( $p['welcome_en'], 'Welcome to Silta' ) . ' first.' ),
			silta_seed_image( $media['lake'], 'large', 'Sunset over Lake Päijänne.' ),
			silta_seed_card( $media['card'], $p['welcome_en'], array( $media['pier'] ) ),
			silta_seed_block( 'block', array( 'ref' => $p['block_en'] ) )
		),
	)
);

$p['summer_fi'] = silta_seed_post(
	array(
		'post_title'   => 'Kesän kuulumiset',
		'post_author'  => $editor,
		'thumbnail'    => $media['forest'],
		'terms'        => array(
			'category' => array( $t['cat_blog_fi'] ),
			'post_tag' => array( $t['tag_summer_fi'] ),
		),
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '-5 days' ) ),
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'Kesä meni nopeasti. Katso ' . silta_seed_link( $p['blocks_fi'], 'lohkojen esittely' ) . ' ja <a href="' . esc_url( home_url( '/events/' ) ) . '">tapahtumat</a>.' ),
			silta_seed_image( $media['pier'], 'large' )
		),
	)
);
stick_post( $p['summer_fi'] );

$p['draft_fi']     = silta_seed_post(
	array(
		'post_title'   => 'Talven suunnitelmat',
		'post_status'  => 'draft',
		'terms'        => array(
			'category' => array( $t['cat_blog_fi'] ),
			'post_tag' => array( $t['tag_winter_fi'] ),
		),
		'post_content' => silta_seed_paragraph( 'Luonnos: talvella keskitytään diff-näkymään.' ),
	)
);
$p['scheduled_fi'] = silta_seed_post(
	array(
		'post_title'   => 'Ensi kuun julkaisu',
		'post_status'  => 'future',
		'post_date'    => wp_date( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
		'thumbnail'    => $media['sauna'],
		'terms'        => array( 'category' => array( $t['cat_company_fi'] ) ),
		'post_content' => silta_seed_paragraph( 'Ajastettu artikkeli, julkaistaan kuukauden päästä.' ),
	)
);
$p['private_fi']   = silta_seed_post(
	array(
		'post_title'    => 'Sisäinen tiedote',
		'post_password' => 'silta',
		'terms'         => array( 'category' => array( $t['cat_company_fi'] ) ),
		'post_date'     => wp_date( 'Y-m-d H:i:s', strtotime( '-3 days' ) ),
		'post_content'  => silta_seed_paragraph( 'Salasanalla suojattu sisältö. Salasana on "silta".' ),
	)
);

foreach ( array( 'about', 'team', 'contact', 'block', 'concert', 'software', 'welcome', 'blocks' ) as $key ) {
	pll_save_post_translations(
		array(
			'fi' => $p[ "{$key}_fi" ],
			'en' => $p[ "{$key}_en" ],
		)
	);
}

// Attach a few images to the posts they were "uploaded" to; the rest stay unattached.
foreach ( array(
	'aurora' => 'welcome_fi',
	'lake'   => 'blocks_fi',
	'rocks'  => 'concert_fi',
	'pdf'    => 'blocks_fi',
) as $media_key => $post_key ) {
	wp_update_post(
		array(
			'ID'          => $media[ $media_key ],
			'post_parent' => $p[ $post_key ],
		)
	);
}

/*
 * ACF field values.
 */

$thumb = wp_get_attachment_image_src( $media['sauna'], 'medium' );
update_field( 'field_silta_subtitle', 'Silta kahden sivuston välillä', $p['welcome_fi'] );
update_field( 'field_silta_image', $media['aurora'], $p['welcome_fi'] );
update_field( 'field_silta_gallery', array( $media['forest'], $media['rocks'], $media['pier'] ), $p['welcome_fi'] );
update_field( 'field_silta_file', $media['pdf'], $p['welcome_fi'] );
update_field( 'field_silta_post_object', $p['blocks_fi'], $p['welcome_fi'] );
update_field( 'field_silta_post_objects', array( $p['blocks_fi'], $p['summer_fi'], $p['about_fi'] ), $p['welcome_fi'] );
update_field( 'field_silta_relationship', array( $p['summer_fi'], $p['concert_fi'], $p['team_fi'] ), $p['welcome_fi'] );
update_field( 'field_silta_page_link', $p['about_fi'], $p['welcome_fi'] );
update_field( 'field_silta_categories', array( $t['cat_product_fi'], $t['cat_software_fi'] ), $p['welcome_fi'] );
update_field( 'field_silta_user', $editor, $p['welcome_fi'] );
update_field(
	'field_silta_link',
	array(
		'title'  => 'Tiimi',
		'url'    => get_permalink( $p['team_fi'] ),
		'target' => '',
	),
	$p['welcome_fi']
);
update_field(
	'field_silta_body',
	"<p>Wysiwyg-kentässä on klassisen editorin kuva:</p>\n"
	. sprintf(
		'<p><img class="alignnone size-medium wp-image-%d" src="%s" alt="Saunan ikkuna" width="%d" height="%d" /></p>',
		$media['sauna'],
		esc_url( $thumb[0] ),
		$thumb[1],
		$thumb[2]
	)
	. "\n<p>Ja linkki: <a href=\"" . esc_url( get_permalink( $p['blocks_fi'] ) ) . '">lohkojen esittely</a>.</p>',
	$p['welcome_fi']
);
update_field(
	'field_silta_slides',
	array(
		array(
			'field_silta_slides_image'   => $media['lake'],
			'field_silta_slides_caption' => 'Järvi illalla',
			'field_silta_slides_post'    => $p['summer_fi'],
		),
		array(
			'field_silta_slides_image'   => $media['panorama'],
			'field_silta_slides_caption' => 'Saaristo',
			'field_silta_slides_post'    => $p['concert_fi'],
		),
		array(
			'field_silta_slides_image'   => $media['rocks'],
			'field_silta_slides_caption' => 'Kalliot',
			'field_silta_slides_post'    => '',
		),
	),
	$p['welcome_fi']
);
update_field(
	'field_silta_sections',
	array(
		array(
			'acf_fc_layout'                    => 'media',
			'field_silta_sections_media_image' => $media['sauna'],
			'field_silta_sections_media_text'  => 'Saunan ikkunasta näkyy järvi.',
		),
		array(
			'acf_fc_layout'                      => 'related',
			'field_silta_sections_related_posts' => array( $p['blocks_fi'], $p['workshop_fi'], $p['software_fi'] ),
		),
		array(
			'acf_fc_layout'                    => 'media',
			'field_silta_sections_media_image' => $media['pier'],
			'field_silta_sections_media_text'  => 'Laituri aamu-usvassa.',
		),
	),
	$p['welcome_fi']
);
update_field(
	'field_silta_hero',
	array(
		'field_silta_hero_image'   => $media['panorama'],
		'field_silta_hero_heading' => 'Sisältö kulkee sillan yli',
	),
	$p['welcome_fi']
);

update_field( 'field_silta_subtitle', 'A bridge between two sites', $p['welcome_en'] );
update_field( 'field_silta_image', $media['aurora'], $p['welcome_en'] );
update_field( 'field_silta_post_object', $p['blocks_en'], $p['welcome_en'] );
update_field( 'field_silta_relationship', array( $p['archipelago_en'], $p['software_en'] ), $p['welcome_en'] );
update_field( 'field_silta_page_link', $p['about_en'], $p['welcome_en'] );
update_field(
	'field_silta_slides',
	array(
		array(
			'field_silta_slides_image'   => $media['lake'],
			'field_silta_slides_caption' => 'Lake at dusk',
			'field_silta_slides_post'    => $p['archipelago_en'],
		),
	),
	$p['welcome_en']
);
update_field(
	'field_silta_hero',
	array(
		'field_silta_hero_image'   => $media['panorama'],
		'field_silta_hero_heading' => 'Content crosses the bridge',
	),
	$p['welcome_en']
);

update_field( 'field_silta_image', $media['rocks'], $p['concert_fi'] );
update_field( 'field_silta_relationship', array( $p['welcome_fi'], $p['blocks_fi'] ), $p['concert_fi'] );
update_field( 'field_silta_page_link', $p['contact_fi'], $p['concert_fi'] );
update_field(
	'field_silta_hero',
	array(
		'field_silta_hero_image'   => $media['lake'],
		'field_silta_hero_heading' => 'Musiikkia meren äärellä',
	),
	$p['concert_fi']
);
update_field( 'field_silta_image', $media['rocks'], $p['concert_en'] );

update_field(
	'field_silta_hero',
	array(
		'field_silta_hero_image'   => $media['pier'],
		'field_silta_hero_heading' => 'Rakennamme siltoja',
	),
	$p['about_fi']
);
update_field( 'field_silta_page_link', $p['contact_fi'], $p['about_fi'] );
update_field(
	'field_silta_sections',
	array(
		array(
			'acf_fc_layout'                      => 'related',
			'field_silta_sections_related_posts' => array( $p['team_fi'], $p['history_fi'] ),
		),
	),
	$p['about_fi']
);

/*
 * Yoast SEO meta: an image as both ID and URL, a primary category and a description.
 */

foreach ( array(
	'welcome_fi' => array( 'aurora', $t['cat_company_fi'], 'Silta siirtää sisältöä stagingista tuotantoon.' ),
	'blocks_fi'  => array( 'lake', $t['cat_product_fi'], 'Kaikki ydinlohkot, joissa on ID-viittauksia.' ),
	'welcome_en' => array( 'aurora', $t['cat_company_en'], 'Silta moves content from staging to production.' ),
	'about_fi'   => array( 'pier', 0, 'Tietoa Silta-tiimistä.' ),
) as $post_key => list( $media_key, $primary, $description ) ) {
	update_post_meta( $p[ $post_key ], '_yoast_wpseo_metadesc', $description );
	update_post_meta( $p[ $post_key ], '_yoast_wpseo_opengraph-image', wp_get_attachment_url( $media[ $media_key ] ) );
	update_post_meta( $p[ $post_key ], '_yoast_wpseo_opengraph-image-id', (string) $media[ $media_key ] );
	if ( $primary ) {
		update_post_meta( $p[ $post_key ], '_yoast_wpseo_primary_category', (string) $primary );
	}
}
update_post_meta( $p['welcome_fi'], '_yoast_wpseo_title', 'Tervetuloa Siltaan %%sep%% %%sitename%%' );

/*
 * Menus: a Finnish main menu with a nested item, and an English one.
 * Polylang keeps a menu per language for each location in its own option.
 */

$menus = array(
	'main_fi' => wp_create_nav_menu( 'Main' ),
	'main_en' => wp_create_nav_menu( 'Main EN' ),
);
foreach ( $menus as $key => $menu_id ) {
	if ( is_wp_error( $menu_id ) ) {
		WP_CLI::error( "Could not create menu $key: " . $menu_id->get_error_message() );
	}
}

$about_item = silta_seed_menu_item(
	$menus['main_fi'],
	array(
		'menu-item-type'      => 'post_type',
		'menu-item-object'    => 'page',
		'menu-item-object-id' => $p['about_fi'],
	)
);
silta_seed_menu_item(
	$menus['main_fi'],
	array(
		'menu-item-type'      => 'post_type',
		'menu-item-object'    => 'page',
		'menu-item-object-id' => $p['team_fi'],
		'menu-item-parent-id' => $about_item,
	)
);
silta_seed_menu_item(
	$menus['main_fi'],
	array(
		'menu-item-type'      => 'taxonomy',
		'menu-item-object'    => 'category',
		'menu-item-object-id' => $t['cat_news_fi'],
	)
);
silta_seed_menu_item(
	$menus['main_fi'],
	array(
		'menu-item-type'      => 'post_type',
		'menu-item-object'    => 'post',
		'menu-item-object-id' => $p['welcome_fi'],
		'menu-item-title'     => 'Tervetuloa!',
	)
);
silta_seed_menu_item(
	$menus['main_fi'],
	array(
		'menu-item-type'  => 'custom',
		'menu-item-title' => 'Tapahtumat',
		'menu-item-url'   => home_url( '/events/' ),
	)
);
silta_seed_menu_item(
	$menus['main_fi'],
	array(
		'menu-item-type'   => 'custom',
		'menu-item-title'  => 'J&Co',
		'menu-item-url'    => 'https://jco.fi/',
		'menu-item-target' => '_blank',
	)
);

$about_item = silta_seed_menu_item(
	$menus['main_en'],
	array(
		'menu-item-type'      => 'post_type',
		'menu-item-object'    => 'page',
		'menu-item-object-id' => $p['about_en'],
	)
);
silta_seed_menu_item(
	$menus['main_en'],
	array(
		'menu-item-type'      => 'post_type',
		'menu-item-object'    => 'page',
		'menu-item-object-id' => $p['team_en'],
		'menu-item-parent-id' => $about_item,
	)
);
silta_seed_menu_item(
	$menus['main_en'],
	array(
		'menu-item-type'      => 'taxonomy',
		'menu-item-object'    => 'category',
		'menu-item-object-id' => $t['cat_news_en'],
	)
);
silta_seed_menu_item(
	$menus['main_en'],
	array(
		'menu-item-type'      => 'post_type',
		'menu-item-object'    => 'post',
		'menu-item-object-id' => $p['welcome_en'],
	)
);

set_theme_mod( 'nav_menu_locations', array( 'silta-primary' => $menus['main_fi'] ) + (array) get_theme_mod( 'nav_menu_locations', array() ) );

$nav_menus = PLL()->options->get( 'nav_menus' );

$nav_menus[ get_stylesheet() ]['silta-primary'] = array(
	'fi' => $menus['main_fi'],
	'en' => $menus['main_en'],
);
PLL()->options->set( 'nav_menus', $nav_menus );
PLL()->options->save();

$seed['menus'] = $menus;
$seed['posts'] = $p;

update_option( 'silta_dev_seed', $seed, false );
update_option( 'silta_dev_seeded', gmdate( 'c' ), false );

// Polylang only adds its /en/ rewrite rules when it boots with languages, and
// this request booted without any: drop the rules so the next request
// rebuilds them.
delete_option( 'rewrite_rules' );

/*
 * Summary.
 */

$rows = array();
foreach ( $p as $key => $row_id ) {
	$rows[] = array(
		'key'    => $key,
		'ID'     => $row_id,
		'type'   => get_post_type( $row_id ),
		'status' => get_post_status( $row_id ),
		'lang'   => pll_get_post_language( $row_id ),
		'title'  => get_the_title( $row_id ),
	);
}
WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'ID', 'type', 'status', 'lang', 'title' ) );

WP_CLI::log( 'Media: ' . implode( ', ', array_map( static fn( $k, $v ) => "$k=$v", array_keys( $media ), $media ) ) );
WP_CLI::log( 'Menus: ' . implode( ', ', array_map( static fn( $k, $v ) => "$k=$v", array_keys( $menus ), $menus ) ) );
WP_CLI::log( 'Users: admin=' . $admin->ID . ", editor=$editor" );
WP_CLI::success( sprintf( 'Seeded %d posts, %d media, %d terms.', count( $p ), count( $media ), count( $t ) ) );
