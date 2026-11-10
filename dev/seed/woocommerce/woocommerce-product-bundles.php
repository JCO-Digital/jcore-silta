<?php
/**
 * Dev stack only: WooCommerce Product Bundles content, when the extension is
 * active (a paid extension: put its zip in dev/woocommerce/extensions/).
 *
 * The dev stack's setup.sh runs every seed/woocommerce/<plugin slug>.php after
 * seed/woocommerce.php, on each site where that plugin is active, with the
 * site name as the first argument. Each file adds its IDs under
 * `extensions.<plugin slug>` in the `silta_dev_woo_seed` option, and skips
 * when they are already there.
 *
 * On staging it creates "Silta-paketti", a bundle of Silta-muki (exactly one)
 * and Silta-paita (optional, one or two). The shirt is priced individually
 * with a 10 % discount, and only its red variation is allowed (and the
 * default), so the item meta holds a variation ID and an attribute slug.
 * The bundled items live in the extension's own tables
 * (woocommerce_bundled_items and woocommerce_bundled_itemmeta), not in post
 * meta.
 *
 *   docker compose run --rm cli-staging wp eval-file /seed/woocommerce/woocommerce-product-bundles.php staging --user=admin
 *
 * @package Jcore\Silta\Dev
 */

// Run with `wp eval-file`, never loaded by the plugin: these are not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

$slug = 'woocommerce-product-bundles';
$site = $args[0] ?? 'staging';
$seed = get_option( 'silta_dev_woo_seed' );

if ( ! class_exists( 'WC_Product_Bundle' ) || ! is_array( $seed ) ) {
	WP_CLI::log( 'WooCommerce Product Bundles or the WooCommerce seed is missing, skipping.' );
	return;
}
if ( isset( $seed['extensions'][ $slug ] ) ) {
	WP_CLI::log( 'WooCommerce Product Bundles content already seeded, skipping.' );
	return;
}

$ids = array();

if ( 'staging' === $site ) {
	$product = new WC_Product_Bundle();
	if ( ! method_exists( $product, 'set_bundled_data_items' ) ) {
		WP_CLI::error( 'WC_Product_Bundle::set_bundled_data_items() is missing; update this seed for this version of Product Bundles.' );
	}
	$product->set_name( 'Silta-paketti' );
	$product->set_slug( 'silta-paketti' );
	$product->set_sku( 'SILTA-PAKETTI' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '39.00' );
	// Item settings go under meta_data, as WC_Bundled_Item_Data::get_data() returns them.
	$product->set_bundled_data_items(
		array(
			array(
				'product_id' => (int) $seed['products']['mug'],
				'menu_order' => 0,
				'meta_data'  => array(
					'quantity_min' => 1,
					'quantity_max' => 1,
				),
			),
			array(
				'product_id' => (int) $seed['products']['shirt'],
				'menu_order' => 1,
				'meta_data'  => array(
					'quantity_min'                 => 1,
					'quantity_max'                 => 2,
					'optional'                     => 'yes',
					'priced_individually'          => 'yes',
					'discount'                     => 10,
					'override_variations'          => 'yes',
					'allowed_variations'           => array( (int) $seed['variations']['red'] ),
					'override_default_variation_attributes' => 'yes',
					'default_variation_attributes' => array( 'pa_vari' => 'punainen' ),
				),
			),
		)
	);
	$ids['bundle'] = (int) $product->save();
	if ( ! $ids['bundle'] ) {
		WP_CLI::error( 'Could not create the bundle Silta-paketti.' );
	}
}

$seed['extensions'][ $slug ] = $ids;
update_option( 'silta_dev_woo_seed', $seed, false );

WP_CLI::success( 'WooCommerce Product Bundles: ' . ( $ids ? 'bundle=' . $ids['bundle'] : 'nothing to seed on ' . $site ) );
