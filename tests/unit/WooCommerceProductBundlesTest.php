<?php
/**
 * Tests for the WooCommerce Product Bundles integration: the items' shape,
 * their references, matching on a re-push, the diff section and the notes.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Integrations\WooCommerce_Product_Bundles as Bundles;
use Jcore\Silta\References\Walker;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Product Bundles.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommerceProductBundlesTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/stubs/woocommerce.php';
		require_once __DIR__ . '/stubs/incoming.php';
		require_once __DIR__ . '/stubs/kses.php';
		require_once __DIR__ . '/stubs/woocommerce-product-bundles.php';

		$GLOBALS['jcore_silta_test_titles'] = array(
			101 => 'Mug',
			102 => 'Shirt',
		);
	}

	/**
	 * An item as the state holds it.
	 *
	 * @param int                  $product Product ID.
	 * @param int                  $order   Menu order.
	 * @param array<string, mixed> $meta    Settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function item( int $product, int $order = 0, array $meta = array() ): array {
		return array(
			'product_id' => $product,
			'menu_order' => $order,
			'meta'       => $meta,
		);
	}

	/**
	 * Visitor: posts 1–9 become 100+, anything else is not on the target.
	 *
	 * @return callable
	 */
	private static function visitor(): callable {
		return static fn( string $kind, int $id ): ?int => 'post' === $kind && $id <= 9 ? 100 + $id : null;
	}

	public function test_items_are_normalized_so_both_sites_compare_equal(): void {
		$items = Bundles::normalize(
			array(
				array(
					'product_id' => '2',
					'menu_order' => 1,
					'meta'       => array(
						'quantity_max'                 => 2,
						'optional'                     => 'yes',
						'allowed_variations'           => array( 8, '7', 8, 0, 'x' ),
						'default_variation_attributes' => array(
							'pa_vari'    => 'punainen',
							'materiaali' => 'luomu',
						),
						'stock_status'                 => 'in_stock',
						'max_stock'                    => 10,
						'real_id'                      => '4',
						'discount'                     => null,
						'Bad Key'                      => 'x',
						'nested'                       => array( 'a' ),
					),
				),
				array(
					'product_id' => 1,
					'menu_order' => 0,
					'meta'       => 'not meta',
				),
				array( 'product_id' => 0 ),
				'not an item',
				array( 'menu_order' => 3 ),
			)
		);

		$this->assertSame(
			array(
				self::item( 1 ),
				self::item(
					2,
					1,
					array(
						'allowed_variations'           => array( 7, 8 ),
						'default_variation_attributes' => array(
							'materiaali' => 'luomu',
							'pa_vari'    => 'punainen',
						),
						'optional'                     => 'yes',
						'quantity_max'                 => 2,
					)
				),
			),
			$items,
			'Sorted by order, meta by key; malformed items, derived stock and arrays dropped.'
		);
		$this->assertSame( array(), Bundles::normalize( 'nope' ) );
	}

	public function test_derived_bundle_meta_stays_out_of_the_state(): void {
		$state = Bundles::without_derived_meta(
			array(
				'meta' => array(
					'_wc_pb_base_regular_price'         => array( '39.00' ),
					'_wc_pb_base_sale_price'            => array( '' ),
					'_wc_pb_layout_style'               => array( 'default' ),
					'_wc_pb_base_price'                 => array( '39.00' ),
					'_regular_price'                    => array( '39' ),
					'_sale_price'                       => array( '' ),
					'_wc_sw_max_price'                  => array( '83.82' ),
					'_wc_sw_max_regular_price'          => array( '87.8' ),
					'_wc_pb_bundled_items_stock_status' => array( 'instock' ),
					'_wc_pb_bundled_items_stock_sync_status' => array( 'synced' ),
					'_wc_pb_bundle_stock_quantity'      => array( '10' ),
				),
			)
		);

		$this->assertSame( array( '_wc_pb_base_regular_price', '_wc_pb_base_sale_price', '_wc_pb_layout_style' ), array_keys( $state['meta'] ) );
	}

	public function test_items_products_and_allowed_variations_are_remapped(): void {
		$record = array(
			Bundles::SECTION => array(
				self::item( 1, 0, array( 'quantity_min' => 1 ) ),
				self::item(
					2,
					1,
					array(
						'allowed_variations'           => array( 3, 42 ),
						'default_variation_attributes' => array( 'pa_vari' => 'punainen' ),
					)
				),
				self::item( 50, 2 ),
			),
		);

		$walked = Bundles::walk( $record, self::visitor() );

		$this->assertSame(
			array(
				self::item( 101, 0, array( 'quantity_min' => 1 ) ),
				self::item(
					102,
					1,
					array(
						'allowed_variations'           => array( 103 ),
						'default_variation_attributes' => array( 'pa_vari' => 'punainen' ),
					)
				),
			),
			$walked[ Bundles::SECTION ],
			'An item whose product is not here is left out, as is a variation that is not.'
		);
		$this->assertSame( array( 'type' => 'post' ), Bundles::walk( array( 'type' => 'post' ), self::visitor() ), 'Not a bundle: untouched.' );
	}

	public function test_the_exporter_collects_items_as_dependencies(): void {
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_walk_post'] = array( Bundles::class, 'walk' );

		$record = array(
			'type'           => 'post',
			'content'        => '',
			Bundles::SECTION => array(
				self::item( 1 ),
				self::item( 2, 1, array( 'allowed_variations' => array( 3 ) ) ),
			),
		);
		$refs   = array();
		Walker::walk(
			$record,
			static function ( string $kind, int $id ) use ( &$refs ): int {
				$refs[] = $kind . ':' . $id;

				return $id;
			},
			static fn(): ?array => null
		);

		$this->assertSame( array( 'post:1', 'post:2', 'post:3' ), $refs );
	}

	public function test_a_re_push_pairs_items_by_product_in_order(): void {
		$existing = array(
			array(
				'id'         => 11,
				'product_id' => 101,
				'menu_order' => 0,
			),
			array(
				'id'         => 13,
				'product_id' => 102,
				'menu_order' => 2,
			),
			array(
				'id'         => 12,
				'product_id' => 102,
				'menu_order' => 1,
			),
			array(
				'id'         => 14,
				'product_id' => 104,
				'menu_order' => 3,
			),
		);
		$incoming = array(
			self::item( 102, 0 ),
			self::item( 101, 1 ),
			self::item( 102, 2 ),
			self::item( 102, 3 ),
			self::item( 105, 4 ),
		);

		$this->assertSame(
			array( 12, 11, 13, 0, 0 ),
			Bundles::pair( $incoming, $existing ),
			'Reordered items keep their IDs, a product bundled twice pairs in order, new ones get 0.'
		);
		$this->assertSame( array( 14 ), array_column( Bundles::removed( $incoming, $existing ), 'id' ), 'The item the source no longer has is removed.' );

		$this->assertSame( array( 11, 12, 13, 14 ), Bundles::pair( array( self::item( 101 ), self::item( 102, 1 ), self::item( 102, 2 ), self::item( 104, 3 ) ), $existing ), 'The same items: nothing new.' );
		$this->assertSame( array(), Bundles::removed( array( self::item( 101 ), self::item( 102, 1 ), self::item( 102, 2 ), self::item( 104, 3 ) ), $existing ) );
		$this->assertSame( array( 0 ), Bundles::pair( array( self::item( 101 ) ), array() ) );
	}

	public function test_items_are_a_diff_section_of_their_own(): void {
		$items = array( self::item( 101, 0, array( 'quantity_min' => 1 ) ) );

		$parts = Bundles::sections( array( 'content' => array() ), array( Bundles::SECTION => $items ), array( Bundles::SECTION => $items ) );
		$this->assertSame( Bundles::SECTION, $parts[ Bundles::SECTION ]['group'] );
		$this->assertSame( $items, $parts[ Bundles::SECTION ]['value'] );
		$this->assertSame( "1. Mug (#101)\n   quantity_min: 1", $parts[ Bundles::SECTION ]['display'] );

		$local = Bundles::sections( array(), array( 'type' => 'post' ), array( Bundles::SECTION => $items ) );
		$this->assertNull( $local[ Bundles::SECTION ]['value'], 'A local product that is not a bundle has no items, so the section changes.' );

		$this->assertSame( array( 'content' => array() ), Bundles::sections( array( 'content' => array() ), array( 'type' => 'post' ), array( 'type' => 'post' ) ) );
	}

	public function test_display_lists_items_and_their_settings(): void {
		$display = Bundles::display(
			array(
				self::item(
					102,
					1,
					array(
						'allowed_variations'           => array( 103, 104 ),
						'default_variation_attributes' => array( 'pa_vari' => 'punainen' ),
						'optional'                     => true,
					)
				),
			),
			static fn( int $id ): string => 102 === $id ? 'Shirt' : ''
		);

		$this->assertSame( "1. Shirt (#102)\n   allowed_variations: 103, 104\n   default_variation_attributes: pa_vari=punainen\n   optional: yes", $display );
	}

	public function test_the_plan_says_what_is_removed_and_left_out(): void {
		$record   = array( Bundles::SECTION => array( self::item( 1 ), self::item( 2, 1 ), self::item( 50, 2 ) ) );
		$incoming = array( Bundles::SECTION => array( self::item( 101 ), self::item( 102, 1 ) ) );
		$local    = array( Bundles::SECTION => array( self::item( 101 ), self::item( 102, 1 ), self::item( 102, 2 ) ) );

		$notes = Bundles::plan_notes( array( 'earlier' ), $record, $incoming, $local );

		$this->assertSame( 'earlier', $notes[0] );
		$this->assertStringContainsString( 'Leaves out 1 bundled item:', $notes[1] );
		$this->assertSame( 'Removes bundled items the source no longer has: Shirt (#102). Undo puts them back.', $notes[2] );

		$this->assertSame( array(), Bundles::plan_notes( array(), $record, $record, $record ), 'Nothing left out or removed.' );
		$this->assertSame( array(), Bundles::plan_notes( array(), $record ), 'No incoming copy (a term): no notes.' );
		$this->assertCount( 1, Bundles::plan_notes( array(), $record, $incoming, null ), 'A new bundle removes nothing.' );
	}

	public function test_item_html_is_filtered_unless_allowed(): void {
		$meta = array(
			'title'       => 'Mug<script>alert(1)</script>',
			'description' => '<p onclick="x()">Hot</p>',
			'optional'    => 'no',
		);

		$this->assertSame( $meta, Bundles::writable_meta( $meta, false ) );
		$this->assertSame(
			array(
				'title'       => 'Mug',
				'description' => '<p>Hot</p>',
				'optional'    => 'no',
			),
			Bundles::writable_meta( $meta, true )
		);
	}
}
