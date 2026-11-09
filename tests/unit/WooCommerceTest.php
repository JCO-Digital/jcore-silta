<?php
/**
 * Tests for the WooCommerce integration: ID meta, store data kept out of the
 * state, variation matching, global attributes, the refresh order, the
 * excluded order types and product types the target lacks.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Integrations\WooCommerce;
use Jcore\Silta\References\Meta_Keys;
use Jcore\Silta\Sync\Content;
use Jcore\Silta\Sync\Context;
use Jcore\Silta\Sync\State;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * WooCommerce.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommerceTest extends TestCase {

	/**
	 * The test database.
	 *
	 * @var \Jcore_Silta_WooCommerce_Wpdb
	 */
	private \Jcore_Silta_WooCommerce_Wpdb $db;

	protected function setUp(): void {
		require_once __DIR__ . '/stubs/woocommerce.php';
		require_once __DIR__ . '/stubs/incoming.php';

		$this->db                               = new \Jcore_Silta_WooCommerce_Wpdb();
		$GLOBALS['wpdb']                        = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fake database.
		$GLOBALS['jcore_silta_test_taxonomies'] = array( 'product_cat', 'product_visibility' );
		$GLOBALS['jcore_silta_test_caps']       = array( 'manage_product_terms' );
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_meta_references'] = array( WooCommerce::class, 'meta_references' );
	}

	/**
	 * Visitor: posts 1–9 become 100+, terms 1–9 become 200+.
	 *
	 * @return callable
	 */
	private function visitor(): callable {
		return static function ( string $kind, int $id ): ?int {
			if ( $id > 9 ) {
				return null;
			}

			return 'term' === $kind ? 200 + $id : 100 + $id;
		};
	}

	public function test_product_and_coupon_id_meta_is_remapped(): void {
		$meta = array(
			'_product_image_gallery'     => array( '1,2,3' ),
			'_upsell_ids'                => array( serialize( array( 4, 5 ) ) ),
			'_crosssell_ids'             => array( serialize( array( 6 ) ) ),
			'_children'                  => array( serialize( array( 7, 42 ) ) ),
			'product_ids'                => array( '1, 2' ),
			'exclude_product_ids'        => array( '3,42' ),
			'product_categories'         => array( serialize( array( 1, 2 ) ) ),
			'exclude_product_categories' => array( serialize( array() ) ),
			'thumbnail_id'               => array( '8' ),
			'_sku'                       => array( '12' ),
		);

		Meta_Keys::walk( $meta, $this->visitor() );

		$this->assertSame( array( '101,102,103' ), $meta['_product_image_gallery'] );
		$this->assertSame( array( serialize( array( 104, 105 ) ) ), $meta['_upsell_ids'] );
		$this->assertSame( array( serialize( array( 106 ) ) ), $meta['_crosssell_ids'] );
		$this->assertSame( array( serialize( array( 107 ) ) ), $meta['_children'], 'An unresolved child is dropped.' );
		$this->assertSame( array( '101, 102' ), $meta['product_ids'], 'Separators stay when nothing is dropped.' );
		$this->assertSame( array( '103' ), $meta['exclude_product_ids'] );
		$this->assertSame( array( serialize( array( 201, 202 ) ) ), $meta['product_categories'] );
		$this->assertSame( array( serialize( array() ) ), $meta['exclude_product_categories'] );
		$this->assertSame( array( '108' ), $meta['thumbnail_id'] );
		$this->assertSame( array( '12' ), $meta['_sku'], 'Undeclared keys are left alone.' );
	}

	public function test_store_and_derived_data_stays_out_of_the_state(): void {
		$state = array(
			'meta'  => array(
				'_regular_price'     => array( '10' ),
				'_price'             => array( '10' ),
				'_stock'             => array( '3' ),
				'_stock_status'      => array( 'instock' ),
				'_manage_stock'      => array( 'yes' ),
				'total_sales'        => array( '12' ),
				'_wc_average_rating' => array( '4.5' ),
				'_wc_rating_count'   => array( serialize( array( 5 => 1 ) ) ),
				'_wc_review_count'   => array( '1' ),
				'_product_version'   => array( '11.3.0' ),
			),
			'terms' => array(
				'product_visibility' => array( 3, 4, 9, 1 ),
				'product_cat'        => array( 15 ),
			),
		);

		$product = WooCommerce::post_state( $state, (object) array( 'post_type' => 'product' ) );
		$this->assertSame( array( '_regular_price', '_manage_stock' ), array_keys( $product['meta'] ) );
		$this->assertSame( array( 3, 1 ), $product['terms']['product_visibility'], 'Featured and catalog visibility travel; out of stock and ratings don’t.' );
		$this->assertSame( array( 15 ), $product['terms']['product_cat'] );

		$coupon = WooCommerce::post_state(
			array(
				'meta' => array(
					'coupon_amount' => array( '10' ),
					'usage_count'   => array( '4' ),
					'_used_by'      => array( 'customer@example.com', '7' ),
				),
			),
			(object) array( 'post_type' => 'shop_coupon' )
		);
		$this->assertSame( array( 'coupon_amount' ), array_keys( $coupon['meta'] ) );

		$page = WooCommerce::post_state( array( 'meta' => array( '_price' => array( '5' ) ) ), (object) array( 'post_type' => 'page' ) );
		$this->assertSame( array( '_price' ), array_keys( $page['meta'] ), 'Other post types keep their meta.' );
	}

	public function test_companions_and_extra_are_valid_but_not_hashed(): void {
		$record = $this->product_record();
		$this->assertNull( Context::record_error( $record ) );

		$with               = $record;
		$with['companions'] = array( 'product_variation' => array( 11, 12 ) );
		$with['extra']      = array( 'woocommerce' => array( 'stock' => array( '_stock' => array( '5' ) ) ) );
		$this->assertNull( Context::record_error( $with ) );
		$this->assertSame( State::hash( $record ), State::hash( $with ) );

		foreach ( array( array( 'product_variation' => array( 0 ) ), array( 'product_variation' => '11' ), array( array( 11 ) ) ) as $companions ) {
			$bad               = $record;
			$bad['companions'] = $companions;
			$this->assertNotNull( Context::record_error( $bad ) );
		}

		$bad          = $record;
		$bad['extra'] = 'x';
		$this->assertNotNull( Context::record_error( $bad ) );
	}

	public function test_variations_match_by_attributes_then_sku_within_the_product(): void {
		$this->db->children = array( 500 => array( 501, 502, 503 ) );
		$this->db->meta     = array(
			501 => array(
				'attribute_pa_color' => array( 'red' ),
				'attribute_size'     => array( 'S' ),
				'_sku'               => array( 'SHIRT-RS' ),
			),
			502 => array(
				'attribute_pa_color' => array( 'blue' ),
				'attribute_size'     => array( 'S' ),
				'_sku'               => array( 'SHIRT-BS' ),
			),
			503 => array(
				'attribute_pa_color' => array( 'blue' ),
				'attribute_size'     => array( 'S' ),
				'_sku'               => array( '' ),
			),
		);
		$resolve            = static fn( string $kind, int $id ): ?int => 50 === $id ? 500 : null;

		$red = $this->variation(
			array(
				'attribute_size'     => array( 'S' ),
				'attribute_pa_color' => array( 'red' ),
			)
		);
		$this->assertSame(
			array(
				'id'     => 501,
				'method' => 'attributes',
			),
			WooCommerce::match_post( null, $red, $resolve ),
			'Attribute order doesn’t matter.'
		);

		$blue = $this->variation(
			array(
				'attribute_pa_color' => array( 'blue' ),
				'attribute_size'     => array( 'S' ),
				'_sku'               => array( 'SHIRT-BS' ),
			)
		);
		$this->assertSame(
			array(
				'id'     => 502,
				'method' => 'sku',
			),
			WooCommerce::match_post( null, $blue, $resolve ),
			'Two variations share the attributes, so the SKU decides.'
		);

		$ambiguous = $this->variation(
			array(
				'attribute_pa_color' => array( 'blue' ),
				'attribute_size'     => array( 'S' ),
			)
		);
		$this->assertNull( WooCommerce::match_post( null, $ambiguous, $resolve ) );

		$orphan = $this->variation( array( 'attribute_pa_color' => array( 'red' ) ), 60 );
		$this->assertNull( WooCommerce::match_post( null, $orphan, $resolve ), 'A product that isn’t on the target has no variations to match.' );

		$earlier = array(
			'id'     => 9,
			'method' => 'guid',
		);
		$this->assertSame( $earlier, WooCommerce::match_post( $earlier, $red, $resolve ) );
		$page              = $red;
		$page['post_type'] = 'page';
		$this->assertNull( WooCommerce::match_post( null, $page, $resolve ) );

		$this->assertFalse( WooCommerce::match_by_slug( true, 'product_variation' ) );
		$this->assertTrue( WooCommerce::match_by_slug( true, 'product' ) );
	}

	public function test_missing_attributes_are_created_when_allowed(): void {
		$definition = array(
			'label'        => 'Color',
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		);
		$term       = array(
			'type'     => 'term',
			'taxonomy' => 'pa_color',
			'extra'    => array( 'woocommerce' => array( 'attributes' => array( 'pa_color' => $definition ) ) ),
		);
		$blocked    = array( '' => 'Taxonomy "pa_color" is missing.' );

		$this->assertSame( array(), WooCommerce::preflight( $blocked, $term ) );
		$this->assertSame( array( 'Creates the product attribute “Color” (pa_color) on this site.' ), WooCommerce::plan_notes( array(), $term ) );

		$GLOBALS['jcore_silta_test_caps'] = array();
		$errors                           = WooCommerce::preflight( $blocked, $term );
		$this->assertStringContainsString( 'you may not create it', $errors[''] );
		$this->assertSame( array(), WooCommerce::plan_notes( array(), $term ) );

		$GLOBALS['jcore_silta_test_caps'] = array( 'manage_product_terms' );
		$product                          = array(
			'type'  => 'post',
			'terms' => array(
				'pa_color' => array( 7 ),
				'pa_size'  => array(),
				'pa_shape' => array( 8 ),
			),
			'extra' => $term['extra'],
		);
		$errors                           = WooCommerce::preflight(
			array(
				'terms.pa_color' => 'missing',
				'terms.pa_size'  => 'missing',
				'terms.pa_shape' => 'missing',
				'meta.x'         => 'other',
			),
			$product
		);
		$this->assertSame(
			array(
				'terms.pa_shape' => 'missing',
				'meta.x'         => 'other',
			),
			$errors,
			'Created, unused, and undefined attributes.'
		);

		$GLOBALS['jcore_silta_test_taxonomies'][] = 'pa_color';
		$this->assertSame( array(), WooCommerce::plan_notes( array(), $term ), 'An existing attribute isn’t created again.' );
	}

	public function test_incoming_attribute_definitions_and_stock_are_validated(): void {
		$record = array(
			'extra' => array(
				'woocommerce' => array(
					'attributes' => array(
						'pa_color'                    => array(
							'label'        => '<b>Color</b>',
							'type'         => 'select',
							'order_by'     => 'name',
							'has_archives' => 1,
						),
						'pa_Bad Name'                 => array( 'label' => 'Bad' ),
						'category'                    => array( 'label' => 'Not an attribute' ),
						'pa_' . str_repeat( 'x', 30 ) => array( 'label' => 'Too long' ),
						'pa_empty'                    => array( 'label' => ' ' ),
						'pa_nolabel'                  => array(),
					),
					'stock'      => array(
						'_stock'        => array( '5' ),
						'_stock_status' => array( '<script>' ),
						'total_sales'   => array( '9' ),
					),
				),
			),
		);

		$this->assertSame(
			array(
				'pa_color' => array(
					'label'        => 'Color',
					'type'         => 'select',
					'order_by'     => 'name',
					'has_archives' => false,
				),
			),
			WooCommerce::incoming_attributes( $record )
		);
		$this->assertSame( array( '_stock' => array( '5' ) ), WooCommerce::incoming_stock( $record ) );
	}

	public function test_variations_refresh_before_their_products(): void {
		list( $variations, $products ) = WooCommerce::refresh_order(
			array(
				11 => array(
					'type'   => 'product_variation',
					'status' => 'publish',
					'parent' => 10,
				),
				12 => array(
					'type'   => 'product_variation',
					'status' => 'trash',
					'parent' => 20,
				),
				10 => array(
					'type'   => 'product',
					'status' => 'publish',
					'parent' => 0,
				),
				30 => array(
					'type'   => 'product',
					'status' => 'trash',
					'parent' => 0,
				),
			)
		);

		$this->assertSame( array( 11 ), $variations, 'A trashed variation isn’t refreshed itself.' );
		$this->assertSame( array( 10, 20 ), $products, 'But its product is, once.' );
	}

	public function test_visibility_terms_follow_woocommerce(): void {
		$this->assertSame(
			array( 'featured', 'outofstock', 'rated-4', 'exclude-from-search', 'exclude-from-catalog' ),
			WooCommerce::visibility_terms(
				new \WC_Product(
					array(
						'featured'           => true,
						'stock_status'       => 'outofstock',
						'average_rating'     => 4.4,
						'catalog_visibility' => 'hidden',
					)
				)
			)
		);
		$this->assertSame( array( 'exclude-from-catalog' ), WooCommerce::visibility_terms( new \WC_Product( array( 'catalog_visibility' => 'search' ) ) ) );
		$this->assertSame(
			array( 'outofstock' ),
			WooCommerce::visibility_terms(
				new \WC_Product(
					array(
						'type'         => 'variation',
						'stock_status' => 'outofstock',
						'featured'     => true,
					)
				)
			)
		);
	}

	public function test_orders_are_never_syncable(): void {
		foreach ( array( 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'wc_push_token' ) as $type ) {
			$this->assertFalse( Content::syncable_type( $type ), $type );
		}
		foreach ( array( 'product', 'product_variation', 'shop_coupon' ) as $type ) {
			$this->assertTrue( Content::syncable_type( $type ), $type );
		}
	}

	/**
	 * Returns a valid product record.
	 *
	 * @return array<string, mixed>
	 */
	private function product_record(): array {
		return array(
			'key'       => 'post:50',
			'type'      => 'post',
			'id'        => 50,
			'post_type' => 'product',
			'hash'      => 'x',
			'content'   => '',
			'fields'    => array(
				'post_title'     => 'Shirt',
				'post_name'      => 'shirt',
				'post_excerpt'   => '',
				'post_status'    => 'publish',
				'post_date'      => '2026-01-01 00:00:00',
				'post_date_gmt'  => '2026-01-01 00:00:00',
				'post_author'    => 1,
				'post_parent'    => 0,
				'menu_order'     => 0,
				'comment_status' => 'open',
				'ping_status'    => 'closed',
				'post_password'  => '',
			),
			'meta'      => array( '_sku' => array( 'SHIRT' ) ),
			'terms'     => array( 'product_cat' => array( 15 ) ),
		);
	}

	/**
	 * Returns a variation record of product 50 (or another parent).
	 *
	 * @param array<string, string[]> $meta   Meta.
	 * @param int                     $parent_id Source parent ID.
	 *
	 * @return array<string, mixed>
	 */
	private function variation( array $meta, int $parent_id = 50 ): array {
		return array(
			'key'       => 'post:51',
			'type'      => 'post',
			'id'        => 51,
			'post_type' => 'product_variation',
			'fields'    => array( 'post_parent' => $parent_id ),
			'meta'      => $meta,
		);
	}

	public function test_a_target_without_the_product_type_refuses_the_product(): void {
		$known = array( 'simple', 'grouped', 'external', 'variable' );

		$this->assertNull( WooCommerce::type_problem( 'simple', $known ) );
		$this->assertNull( WooCommerce::type_problem( null, $known ), 'An older source sends no type.' );
		$this->assertNull( WooCommerce::type_problem( 'bundle', array_merge( $known, array( 'bundle' ) ) ) );
		$this->assertStringContainsString( 'WooCommerce Product Bundles is not active here', (string) WooCommerce::type_problem( 'bundle', $known ) );
		$this->assertStringContainsString( '“booking” products: the WooCommerce extension that adds them', (string) WooCommerce::type_problem( 'booking', $known ) );
	}
}
