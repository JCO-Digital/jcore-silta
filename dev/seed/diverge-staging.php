<?php
/**
 * Dev stack only: work done on staging after production was cloned from it.
 *
 * New posts are created first, so they take the same IDs as the posts
 * diverge-production.php creates: the same IDs, different content. Then two
 * seeded posts are edited; production edits one of them too, so Silta sees a
 * conflict there.
 *
 * Run by setup.sh, or by hand:
 *   docker compose run --rm cli-staging wp eval-file /seed/diverge-staging.php --user=admin
 *
 * @package Jcore\Silta\Dev
 */

// Run with `wp eval-file`, never loaded by the plugin: these are not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( get_option( 'silta_dev_diverged_staging' ) ) {
	WP_CLI::log( 'Staging already diverged on ' . get_option( 'silta_dev_diverged_staging' ) . ', skipping.' );
	return;
}

$seed = get_option( 'silta_dev_seed' );
if ( ! $seed ) {
	WP_CLI::error( 'No silta_dev_seed option: run seed.php first.' );
}

require_once __DIR__ . '/helpers.php';

$p     = $seed['posts'];
$media = $seed['media'];
$t     = $seed['terms'];

$new = array();

$new['launch_fi'] = silta_seed_post(
	array(
		'post_title'   => 'Silta 1.0 julkaistu',
		'post_excerpt' => 'Uusi artikkeli stagingissa.',
		'thumbnail'    => $media['panorama'],
		'terms'        => array(
			'category' => array( $t['cat_software_fi'] ),
			'post_tag' => array( $t['tag_summer_fi'] ),
		),
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'Uusi artikkeli, joka on vain stagingissa. Lue myös ' . silta_seed_link( $p['welcome_fi'], 'Tervetuloa Siltaan' ) . '.' ),
			silta_seed_image( $media['panorama'], 'large', 'Saaristo.' ),
			silta_seed_card( $media['pier'], $p['blocks_fi'], array( $media['lake'] ) )
		),
	)
);
update_field( 'field_silta_image', $media['lake'], $new['launch_fi'] );
update_field( 'field_silta_relationship', array( $p['welcome_fi'], $p['software_fi'] ), $new['launch_fi'] );

$new['launch_en'] = silta_seed_post(
	array(
		'post_title'   => 'Silta 1.0 released',
		'lang'         => 'en',
		'thumbnail'    => $media['panorama'],
		'terms'        => array( 'category' => array( $t['cat_software_en'] ) ),
		'post_content' => silta_seed_paragraph( 'A new post that only exists on staging. See also ' . silta_seed_link( $p['welcome_en'], 'Welcome to Silta' ) . '.' ),
	)
);
pll_save_post_translations(
	array(
		'fi' => $new['launch_fi'],
		'en' => $new['launch_en'],
	)
);

$new['autumn_fi'] = silta_seed_post(
	array(
		'post_title'   => 'Syksyn tapahtumakalenteri',
		'post_author'  => $seed['users']['editor'],
		'terms'        => array( 'category' => array( $t['cat_blog_fi'] ) ),
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'Syksyllä luvassa ' . silta_seed_link( $p['workshop_fi'], 'verkkotyöpaja' ) . ' ja ' . silta_seed_link( $p['xmas_fi'], 'joulukonsertti' ) . '.' ),
			silta_seed_gallery( array( $media['rocks'], $media['sauna'] ) )
		),
	)
);

// Two edits to seeded content.
silta_seed_update_content(
	$p['welcome_fi'],
	get_post_field( 'post_content', $p['welcome_fi'] ) . "\n\n" . silta_seed_paragraph( 'Lisätty stagingissa: Silta tukee nyt myös valikkoja.' )
);
update_field( 'field_silta_subtitle', 'Silta kahden sivuston välillä (päivitetty stagingissa)', $p['welcome_fi'] );
add_row(
	'field_silta_slides',
	array(
		'field_silta_slides_image'   => $media['card'],
		'field_silta_slides_caption' => 'Uusi dia stagingista',
		'field_silta_slides_post'    => $new['launch_fi'],
	),
	$p['welcome_fi']
);

$result = wp_update_post(
	wp_slash(
		array(
			'ID'         => $p['summer_fi'],
			'post_title' => 'Kesän kuulumiset ja syksyn suunnitelmat',
		)
	),
	true
);
if ( is_wp_error( $result ) ) {
	WP_CLI::error( $result->get_error_message() );
}
set_post_thumbnail( $p['summer_fi'], $media['lake'] );

update_option( 'silta_dev_diverged_staging', gmdate( 'c' ), false );

WP_CLI::log( 'New on staging: ' . implode( ', ', array_map( static fn( $k, $v ) => "$k=$v", array_keys( $new ), $new ) ) );
WP_CLI::log( "Edited on staging: welcome_fi={$p['welcome_fi']} (content, subtitle, new slide), summer_fi={$p['summer_fi']} (title, featured image)" );
WP_CLI::success( 'Staging diverged.' );
