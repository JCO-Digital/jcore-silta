<?php
/**
 * Tests for pausing Polylang's copying between translations.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Integrations\Polylang_Synchronization;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/polylang-synchronization.php';

// phpcs:disable WordPress.WP.GlobalVariablesOverride -- Isolated hook fixture.

/**
 * Polylang_Synchronization.
 */
final class PolylangSynchronizationTest extends TestCase {

	/**
	 * Polylang's synchronization object, as it hooks itself.
	 *
	 * @var \PLL_Sync
	 */
	private \PLL_Sync $sync;

	/**
	 * Polylang for WooCommerce's object, as it hooks itself.
	 *
	 * @var \PLLWC_Products
	 */
	private \PLLWC_Products $products;

	/**
	 * Hooks the plugins' callbacks the way they do, next to someone else's.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sync                          = new \PLL_Sync();
		$this->products                      = new \PLLWC_Products();
		$GLOBALS['jcore_silta_test_actions'] = array();
		$GLOBALS['wp_filter']                = array();

		foreach ( array(
			array( 'pll_save_post', array( $this->sync, 'pll_save_post' ), 10 ),
			array( 'woocommerce_update_product', array( $this->products, 'save_product' ), 10 ),
			array( 'woocommerce_update_product', 'someone_elses_callback', 10 ),
			array( 'woocommerce_new_product_variation', array( $this->products, 'save_variation' ), 10 ),
			array( 'woocommerce_update_product_variation', array( $this->products, 'save_variation' ), 10 ),
			array( 'woocommerce_after_product_object_save', array( $this->products, 'copy_product' ), 10 ),
			array( 'woocommerce_before_product_object_save', array( $this->products, 'store_product_data' ), 10 ),
		) as $hooked ) {
			add_action( $hooked[0], $hooked[1], $hooked[2] );
			$GLOBALS['wp_filter'][ $hooked[0] ]                          ??= new \WP_Hook();
			$GLOBALS['wp_filter'][ $hooked[0] ]->callbacks[ $hooked[2] ][] = array(
				'function'      => $hooked[1],
				'accepted_args' => 1,
			);
		}
	}

	/**
	 * Clears the fixture.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		while ( Polylang_Synchronization::writing() ) {
			Polylang_Synchronization::resume();
		}
		unset( $GLOBALS['wp_filter'] );
		$GLOBALS['jcore_silta_test_actions'] = array();
	}

	/**
	 * The copying callbacks, by hook.
	 *
	 * @return array<int, array{0: string, 1: callable}>
	 */
	private function copiers(): array {
		return array(
			array( 'pll_save_post', array( $this->sync, 'pll_save_post' ) ),
			array( 'woocommerce_update_product', array( $this->products, 'save_product' ) ),
			array( 'woocommerce_new_product_variation', array( $this->products, 'save_variation' ) ),
			array( 'woocommerce_update_product_variation', array( $this->products, 'save_variation' ) ),
			array( 'woocommerce_after_product_object_save', array( $this->products, 'copy_product' ) ),
		);
	}

	public function test_copying_is_off_during_a_write_batch_and_back_after(): void {
		Polylang_Synchronization::suspend();

		foreach ( $this->copiers() as $copier ) {
			$this->assertFalse( has_action( $copier[0], $copier[1] ), $copier[0] );
		}
		$this->assertSame( 10, has_action( 'woocommerce_update_product', 'someone_elses_callback' ), 'Other callbacks stay.' );
		$this->assertSame( 10, has_action( 'woocommerce_before_product_object_save', array( $this->products, 'store_product_data' ) ), 'Only the copying is paused.' );

		Polylang_Synchronization::resume();

		foreach ( $this->copiers() as $copier ) {
			$this->assertSame( 10, has_action( $copier[0], $copier[1] ), $copier[0] );
		}
	}

	public function test_nothing_is_synchronized_while_writing(): void {
		$fields = array( '_thumbnail_id', '_product_image_gallery', '_upsell_ids' );

		$this->assertSame( $fields, Polylang_Synchronization::nothing_while_writing( $fields, true ), 'Outside a sync, Polylang synchronizes as it is set to.' );

		Polylang_Synchronization::suspend();
		$this->assertSame( array(), Polylang_Synchronization::nothing_while_writing( $fields, true ) );
		$this->assertSame( array(), Polylang_Synchronization::nothing_while_writing( array( 'product_cat' ), true ) );
		$this->assertSame( $fields, Polylang_Synchronization::nothing_while_writing( $fields, false ), 'The one-time copy to a new translation is left alone.' );

		Polylang_Synchronization::resume();
		$this->assertSame( $fields, Polylang_Synchronization::nothing_while_writing( $fields, true ) );
	}

	public function test_a_batch_inside_a_batch_keeps_it_off_until_the_outer_one_ends(): void {
		Polylang_Synchronization::suspend();
		Polylang_Synchronization::suspend();
		Polylang_Synchronization::resume();
		$this->assertTrue( Polylang_Synchronization::writing() );
		$this->assertFalse( has_action( 'woocommerce_update_product', array( $this->products, 'save_product' ) ) );

		Polylang_Synchronization::resume();
		$this->assertFalse( Polylang_Synchronization::writing() );
		$this->assertSame( 10, has_action( 'woocommerce_update_product', array( $this->products, 'save_product' ) ) );

		// Once more than opened changes nothing.
		Polylang_Synchronization::resume();
		$this->assertCount( 2, $GLOBALS['jcore_silta_test_actions']['woocommerce_update_product'][10] );
	}
}
