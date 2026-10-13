<?php
/**
 * Dev stack only: work done on production after it was cloned from staging.
 *
 * New posts are created first, so they take the same IDs as the first posts
 * diverge-staging.php creates. Then "Tervetuloa Siltaan" is edited, which
 * staging edits too: Silta should flag it as a conflict.
 *
 * Run by setup.sh, or by hand:
 *   docker compose run --rm cli-production wp eval-file /seed/diverge-production.php --user=admin
 *
 * @package Jcore\Silta\Dev
 */

// Run with `wp eval-file`, never loaded by the plugin: these are not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( get_option( 'silta_dev_diverged_production' ) ) {
	WP_CLI::log( 'Production already diverged on ' . get_option( 'silta_dev_diverged_production' ) . ', skipping.' );
	return;
}

$seed = get_option( 'silta_dev_seed' );
if ( ! $seed ) {
	WP_CLI::error( 'No silta_dev_seed option: clone staging into production first.' );
}

require_once __DIR__ . '/helpers.php';

$p     = $seed['posts'];
$media = $seed['media'];
$t     = $seed['terms'];

$new = array();

$new['notice_fi'] = silta_seed_post(
	array(
		'post_title'   => 'Huoltokatko lauantaina',
		'terms'        => array( 'category' => array( $t['cat_company_fi'] ) ),
		'post_content' => silta_seed_paragraph( 'Tuotannossa kirjoitettu tiedote. Sivusto on huollossa lauantaina klo 8–10.' ),
	)
);
$new['press_fi']  = silta_seed_post(
	array(
		'post_title'   => 'Lehdistötiedote: uusi asiakas',
		'thumbnail'    => $media['aurora'],
		'terms'        => array( 'category' => array( $t['cat_news_fi'] ) ),
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'Tuotannossa kirjoitettu lehdistötiedote.' ),
			silta_seed_image( $media['aurora'], 'medium' )
		),
	)
);
update_field( 'field_silta_image', $media['aurora'], $new['press_fi'] );

// The same post staging edits: a conflict.
$result = wp_update_post(
	wp_slash(
		array(
			'ID'           => $p['welcome_fi'],
			'post_title'   => 'Tervetuloa Siltaan!',
			'post_excerpt' => 'Silta siirtää sisältöä sivustolta toiselle. Korjattu tuotannossa.',
		)
	),
	true
);
if ( is_wp_error( $result ) ) {
	WP_CLI::error( $result->get_error_message() );
}
update_post_meta( $p['welcome_fi'], '_yoast_wpseo_metadesc', 'Tuotannossa muokattu kuvaus.' );

update_option( 'silta_dev_diverged_production', gmdate( 'c' ), false );

WP_CLI::log( 'New on production: ' . implode( ', ', array_map( static fn( $k, $v ) => "$k=$v", array_keys( $new ), $new ) ) );
WP_CLI::log( "Edited on production: welcome_fi={$p['welcome_fi']} (title, excerpt, Yoast description)" );
WP_CLI::success( 'Production diverged.' );
