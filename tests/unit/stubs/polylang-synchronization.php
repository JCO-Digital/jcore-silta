<?php
/**
 * Stand-ins for Polylang, Polylang for WooCommerce and core's hook object.
 *
 * @package Jcore\Silta\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, Squiz.Commenting -- Minimal stand-ins for classes of other plugins and core.

if ( ! class_exists( 'WP_Hook' ) ) {
	final class WP_Hook {
		/**
		 * Callbacks by priority, as core keeps them.
		 *
		 * @var array<int, array<int, array{function: mixed, accepted_args: int}>>
		 */
		public array $callbacks = array();
	}
}

if ( ! class_exists( 'PLL_Sync' ) ) {
	final class PLL_Sync {
		public function pll_save_post(): void {}
	}
}

if ( ! class_exists( 'PLLWC_Products' ) ) {
	final class PLLWC_Products {
		public function save_product(): void {}
		public function save_variation(): void {}
		public function copy_product(): void {}
		public function store_product_data(): void {}
	}
}
