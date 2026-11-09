<?php
/**
 * Dev stack only: WooCommerce content, when WooCommerce is active.
 *
 * WooCommerce is activated after all other seeding (it creates its shop pages
 * when it is activated), so the post IDs the integration tests rely on are the
 * same with and without it. Production was cloned before WooCommerce, so it
 * has none of staging's products: syncing them there creates them.
 *
 * On both sites it creates the global attribute "Väri" (pa_vari: Punainen,
 * Sininen), the way two clones of one store share their attributes.
 *
 * On staging it creates what Silta has to handle for a store:
 * - product categories Vaatteet › Paidat and Tarvikkeet, Tarvikkeet with an
 *   image in `thumbnail_id` term meta, and the tag Lahjat;
 * - "Silta-muki" (simple): SKU, regular and sale price, managed stock,
 *   featured image, a gallery (`_product_image_gallery`, a comma-separated ID
 *   list), categories and a tag;
 * - "Silta-juliste" (simple): an upsell and a cross-sell (`_upsell_ids`,
 *   `_crosssell_ids`, serialized ID lists);
 * - "Silta-paita" (variable): variations on pa_vari, a custom (non-taxonomy)
 *   attribute, and two variations with their own SKU, price, stock and image;
 * - "Silta-pipo" (simple) with the staging-only global attribute "Koko"
 *   (pa_koko), which production doesn't have;
 * - "Silta-lahjasetti" (grouped): muki and juliste (`_children`);
 * - the coupon "silta10" limited to muki and paita (`product_ids`, a
 *   comma-separated ID list).
 *
 * Content for paid extensions is in seed/woocommerce/<plugin slug>.php, which
 * setup.sh runs after this file on each site where that plugin is active.
 *
 * On production it creates "Tuotannon tuote", a product staging doesn't have,
 * so staging's products get other IDs when they are synced there.
 *
 * IDs are stored in the `silta_dev_woo_seed` option.
 *
 *   docker compose run --rm cli-staging wp eval-file /seed/woocommerce.php staging --user=admin
 *   docker compose run --rm cli-production wp eval-file /seed/woocommerce.php production --user=admin
 *
 * @package Jcore\Silta\Dev
 */

// Run with `wp eval-file`, never loaded by the plugin: these are not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::log( 'WooCommerce is not active, skipping.' );
	return;
}
if ( get_option( 'silta_dev_woo_seed' ) ) {
	WP_CLI::log( 'WooCommerce content already seeded, skipping.' );
	return;
}

$site  = $args[0] ?? 'staging';
$media = (array) ( get_option( 'silta_dev_seed', array() )['media'] ?? array() );
$seed  = array(
	'attributes' => array(),
	'terms'      => array(),
	'products'   => array(),
	'variations' => array(),
	'coupons'    => array(),
	'extensions' => array(),
);

/**
 * Fails loudly on an error.
 *
 * @param mixed  $result Result.
 * @param string $what   What was being created.
 * @return mixed The result.
 */
function silta_seed_wc_check( $result, string $what ) {
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( "Could not create $what: " . $result->get_error_message() );
	}

	return $result;
}

/**
 * Creates a global attribute and its terms. The attribute's taxonomy is
 * registered for the rest of this request, which WooCommerce would only do on
 * the next one.
 *
 * @param string   $name  Attribute name.
 * @param string   $slug  Attribute slug, without pa_.
 * @param string[] $terms Term names.
 * @return array{id: int, taxonomy: string, terms: array<string, int>}
 */
function silta_seed_wc_attribute( string $name, string $slug, array $terms ): array {
	$taxonomy = wc_attribute_taxonomy_name( $slug );
	$id       = (int) wc_attribute_taxonomy_id_by_name( $taxonomy );
	if ( ! $id ) {
		$id = (int) silta_seed_wc_check(
			wc_create_attribute(
				array(
					'name'         => $name,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			),
			"attribute $name"
		);
	}
	if ( ! taxonomy_exists( $taxonomy ) ) {
		register_taxonomy( $taxonomy, array( 'product', 'product_variation' ), array( 'hierarchical' => false ) );
	}

	$ids = array();
	foreach ( $terms as $term ) {
		$found        = term_exists( $term, $taxonomy );
		$found        = $found ? $found : silta_seed_wc_check( wp_insert_term( $term, $taxonomy ), "term $term" );
		$ids[ $term ] = (int) $found['term_id'];
	}

	return array(
		'id'       => $id,
		'taxonomy' => $taxonomy,
		'terms'    => $ids,
	);
}

/**
 * Creates a term in a product taxonomy.
 *
 * @param string $taxonomy  Taxonomy.
 * @param string $name      Name.
 * @param int    $parent_id Parent term.
 * @return int Term ID.
 */
function silta_seed_wc_term( string $taxonomy, string $name, int $parent_id = 0 ): int {
	$term = silta_seed_wc_check( wp_insert_term( $name, $taxonomy, array( 'parent' => $parent_id ) ), "term $name" );

	return (int) $term['term_id'];
}

/**
 * A global attribute of a product.
 *
 * @param array $attribute From silta_seed_wc_attribute().
 * @param bool  $variation Whether variations use it.
 * @param int   $position  Position.
 * @return WC_Product_Attribute
 */
function silta_seed_wc_product_attribute( array $attribute, bool $variation, int $position = 0 ): WC_Product_Attribute {
	$product_attribute = new WC_Product_Attribute();
	$product_attribute->set_id( $attribute['id'] );
	$product_attribute->set_name( $attribute['taxonomy'] );
	$product_attribute->set_options( array_values( $attribute['terms'] ) );
	$product_attribute->set_position( $position );
	$product_attribute->set_visible( true );
	$product_attribute->set_variation( $variation );

	return $product_attribute;
}

/**
 * Saves a product and fails loudly.
 *
 * @param WC_Product $product Product.
 * @return int Product ID.
 */
function silta_seed_wc_save( WC_Product $product ): int {
	$product->set_status( 'publish' );
	$id = (int) $product->save();
	if ( ! $id ) {
		WP_CLI::error( 'Could not create product ' . $product->get_name() );
	}

	return $id;
}

$vari = silta_seed_wc_attribute( 'Väri', 'vari', array( 'Punainen', 'Sininen' ) );

$seed['attributes']['vari'] = $vari['id'];

if ( 'production' === $site ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Tuotannon tuote' );
	$product->set_slug( 'tuotannon-tuote' );
	$product->set_sku( 'TUOTANTO-1' );
	$product->set_regular_price( '5.00' );
	$seed['products']['production'] = silta_seed_wc_save( $product );
} else {
	$koko = silta_seed_wc_attribute( 'Koko', 'koko', array( 'S', 'M', 'L' ) );

	$seed['attributes']['koko'] = $koko['id'];

	$t = array();

	$t['clothes']     = silta_seed_wc_term( 'product_cat', 'Vaatteet' );
	$t['shirts']      = silta_seed_wc_term( 'product_cat', 'Paidat', $t['clothes'] );
	$t['accessories'] = silta_seed_wc_term( 'product_cat', 'Tarvikkeet' );
	$t['gifts']       = silta_seed_wc_term( 'product_tag', 'Lahjat' );
	update_term_meta( $t['accessories'], 'thumbnail_id', (int) ( $media['card'] ?? 0 ) );
	$seed['terms'] = $t;

	$p = array();

	$product = new WC_Product_Simple();
	$product->set_name( 'Silta-muki' );
	$product->set_slug( 'silta-muki' );
	$product->set_sku( 'SILTA-MUKI' );
	$product->set_short_description( 'Muki, jossa on silta.' );
	$product->set_description( 'Posliinimuki, 3 dl. Kuva: ' . esc_url( wp_get_attachment_url( (int) ( $media['lake'] ?? 0 ) ) ) );
	$product->set_regular_price( '19.90' );
	$product->set_sale_price( '14.90' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 25 );
	$product->set_image_id( (int) ( $media['lake'] ?? 0 ) );
	$product->set_gallery_image_ids( array( (int) ( $media['pier'] ?? 0 ), (int) ( $media['rocks'] ?? 0 ) ) );
	$product->set_category_ids( array( $t['accessories'] ) );
	$product->set_tag_ids( array( $t['gifts'] ) );
	$p['mug'] = silta_seed_wc_save( $product );

	$product = new WC_Product_Simple();
	$product->set_name( 'Silta-juliste' );
	$product->set_slug( 'silta-juliste' );
	$product->set_sku( 'SILTA-JULISTE' );
	$product->set_regular_price( '29.00' );
	$product->set_image_id( (int) ( $media['panorama'] ?? 0 ) );
	$product->set_category_ids( array( $t['accessories'] ) );
	$product->set_upsell_ids( array( $p['mug'] ) );
	$product->set_cross_sell_ids( array( $p['mug'] ) );
	$p['poster'] = silta_seed_wc_save( $product );

	$material = new WC_Product_Attribute();
	$material->set_name( 'Materiaali' );
	$material->set_options( array( 'Luomupuuvilla' ) );
	$material->set_position( 1 );
	$material->set_visible( true );

	$product = new WC_Product_Variable();
	$product->set_name( 'Silta-paita' );
	$product->set_slug( 'silta-paita' );
	$product->set_sku( 'SILTA-PAITA' );
	$product->set_image_id( (int) ( $media['forest'] ?? 0 ) );
	$product->set_category_ids( array( $t['shirts'] ) );
	$product->set_attributes( array( silta_seed_wc_product_attribute( $vari, true ), $material ) );
	$product->set_default_attributes( array( $vari['taxonomy'] => 'punainen' ) );
	$p['shirt'] = silta_seed_wc_save( $product );

	$v = array();
	foreach ( array(
		'red'  => array( 'punainen', 'SILTA-PAITA-PUN', '24.90', 10, 'sauna' ),
		'blue' => array( 'sininen', 'SILTA-PAITA-SIN', '26.90', 4, 'aurora' ),
	) as $key => list( $color, $sku, $price, $stock, $image ) ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $p['shirt'] );
		$variation->set_attributes( array( $vari['taxonomy'] => $color ) );
		$variation->set_sku( $sku );
		$variation->set_regular_price( $price );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( $stock );
		$variation->set_image_id( (int) ( $media[ $image ] ?? 0 ) );
		$v[ $key ] = (int) $variation->save();
	}
	WC_Product_Variable::sync( $p['shirt'] );
	$seed['variations'] = $v;

	$product = new WC_Product_Simple();
	$product->set_name( 'Silta-pipo' );
	$product->set_slug( 'silta-pipo' );
	$product->set_sku( 'SILTA-PIPO' );
	$product->set_regular_price( '17.00' );
	$product->set_category_ids( array( $t['clothes'] ) );
	$product->set_attributes( array( silta_seed_wc_product_attribute( $koko, false ) ) );
	$p['beanie'] = silta_seed_wc_save( $product );

	$product = new WC_Product_Grouped();
	$product->set_name( 'Silta-lahjasetti' );
	$product->set_slug( 'silta-lahjasetti' );
	$product->set_children( array( $p['mug'], $p['poster'] ) );
	$product->set_tag_ids( array( $t['gifts'] ) );
	$p['gift_set'] = silta_seed_wc_save( $product );

	$seed['products'] = $p;

	$coupon = new WC_Coupon();
	$coupon->set_code( 'silta10' );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( 10 );
	$coupon->set_product_ids( array( $p['mug'], $p['shirt'] ) );
	$seed['coupons']['silta10'] = (int) $coupon->save();
}

update_option( 'silta_dev_woo_seed', $seed, false );

WP_CLI::success(
	'WooCommerce: ' . implode( ', ', array_map( static fn( $k, $v ) => "$k=$v", array_keys( $seed['products'] ), $seed['products'] ) )
);
