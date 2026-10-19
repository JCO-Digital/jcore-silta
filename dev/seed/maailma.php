<?php
/**
 * Dev stack only: global content from jcore-maailma, created on staging after
 * the sites diverged, so production has none of it yet and the IDs the other
 * seed files hand out stay the same with or without Maailma.
 *
 * - "Ota yhteyttä" (fi) and "Contact us" (en), translations of each other.
 *   The Finnish one embeds an image and "Aukioloajat" by slug only, the way
 *   templates and older blocks refer to global content.
 * - Two items both titled "Alatunniste", so the second has the slug
 *   `alatunniste-2`: Maailma would rename it after its title on update.
 * - A page, "Maailma-esittely", embedding "Ota yhteyttä" with the block
 *   (ID and slug).
 *
 * IDs go into the `silta_dev_maailma` option. Skipped when Maailma isn't active.
 *
 * Run by setup.sh, or by hand:
 *   docker compose run --rm cli-staging wp eval-file /seed/maailma.php --user=admin
 *
 * @package Jcore\Silta\Dev
 */

// Run with `wp eval-file`, never loaded by the plugin: these are not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( get_option( 'silta_dev_maailma' ) ) {
	WP_CLI::log( 'Global content already seeded, skipping.' );
	return;
}

$gc_type = defined( 'JCORE_MAAILMA_POST_TYPE' ) ? JCORE_MAAILMA_POST_TYPE : 'jcore-global-content';
if ( ! post_type_exists( $gc_type ) ) {
	WP_CLI::warning( 'jcore-maailma is not active: no global content seeded.' );
	return;
}

$seed = get_option( 'silta_dev_seed' );
if ( ! $seed ) {
	WP_CLI::error( 'No silta_dev_seed option: run seed.php first.' );
}

require_once __DIR__ . '/helpers.php';

$media = $seed['media'];
$gc    = array();

/**
 * A jco/global-content block.
 *
 * @param int    $id   Global content ID, or 0 for a block that only has the slug.
 * @param string $slug Global content slug.
 * @return string
 */
function silta_seed_global_content( int $id, string $slug ): string {
	$attrs = array_filter(
		array(
			'selectedPostId' => $id,
			'selectedSlug'   => $slug,
		)
	);

	return '<!-- wp:jco/global-content ' . serialize_block_attributes( $attrs ) . ' /-->';
}

$gc['hours_fi'] = silta_seed_post(
	array(
		'post_type'    => $gc_type,
		'post_title'   => 'Aukioloajat',
		'post_content' => silta_seed_paragraph( 'Avoinna arkisin 9–17.' ),
	)
);

$gc['contact_fi'] = silta_seed_post(
	array(
		'post_type'    => $gc_type,
		'post_title'   => 'Ota yhteyttä',
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'Soita tai tule käymään.' ),
			silta_seed_image( $media['lake'], 'medium' ),
			silta_seed_global_content( 0, get_post_field( 'post_name', $gc['hours_fi'] ) )
		),
	)
);

$gc['contact_en'] = silta_seed_post(
	array(
		'post_type'    => $gc_type,
		'post_title'   => 'Contact us',
		'lang'         => 'en',
		'post_content' => silta_seed_paragraph( 'Call us or drop by.' ),
	)
);
pll_save_post_translations(
	array(
		'fi' => $gc['contact_fi'],
		'en' => $gc['contact_en'],
	)
);

$gc['footer_a'] = silta_seed_post(
	array(
		'post_type'    => $gc_type,
		'post_title'   => 'Alatunniste',
		'post_content' => silta_seed_paragraph( 'Ensimmäinen alatunniste.' ),
	)
);
$gc['footer_b'] = silta_seed_post(
	array(
		'post_type'    => $gc_type,
		'post_title'   => 'Alatunniste',
		'post_content' => silta_seed_paragraph( 'Toinen alatunniste, slugilla alatunniste-2.' ),
	)
);

$gc['page_fi'] = silta_seed_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Maailma-esittely',
		'post_content' => silta_seed_blocks(
			silta_seed_paragraph( 'Tämä sivu näyttää globaalin sisällön.' ),
			silta_seed_global_content( $gc['contact_fi'], get_post_field( 'post_name', $gc['contact_fi'] ) )
		),
	)
);

update_option( 'silta_dev_maailma', $gc, false );

WP_CLI::log( 'Global content: ' . implode( ', ', array_map( static fn( $k, $v ) => "$k=$v", array_keys( $gc ), $gc ) ) );
WP_CLI::success( 'Global content seeded.' );
