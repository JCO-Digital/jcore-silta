<?php
/**
 * Stand-ins for the WooCommerce integration tests: post types and taxonomies
 * from globals, a capability switch, WooCommerce's visibility terms, a minimal
 * WC_Product, and a database that answers the variation and meta queries.
 *
 * @package Jcore\Silta
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Guarded stand-ins for core and WooCommerce signatures.

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'post_type_exists' ) ) {
	/**
	 * Checks the test post types: core ones plus WooCommerce's.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	function post_type_exists( string $type ): bool {
		return in_array( $type, array( 'post', 'page', 'attachment', 'product', 'product_variation', 'shop_coupon', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'wc_push_token' ), true );
	}
}
if ( ! function_exists( 'taxonomy_exists' ) ) {
	/**
	 * Checks the taxonomies a test registered.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	function taxonomy_exists( string $taxonomy ): bool {
		return in_array( $taxonomy, $GLOBALS['jcore_silta_test_taxonomies'] ?? array(), true );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Checks the capabilities a test granted.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	function current_user_can( string $capability ): bool {
		return in_array( $capability, $GLOBALS['jcore_silta_test_caps'] ?? array(), true );
	}
}
if ( ! function_exists( 'wc_get_product_visibility_term_ids' ) ) {
	/**
	 * Returns the visibility term IDs.
	 *
	 * @return array<string, int>
	 */
	function wc_get_product_visibility_term_ids(): array {
		return array(
			'exclude-from-catalog' => 1,
			'exclude-from-search'  => 2,
			'featured'             => 3,
			'outofstock'           => 4,
			'rated-1'              => 5,
			'rated-2'              => 6,
			'rated-3'              => 7,
			'rated-4'              => 8,
			'rated-5'              => 9,
		);
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	/**
	 * The product getters the visibility terms read.
	 */
	class WC_Product {
		/**
		 * Props.
		 *
		 * @var array<string, mixed>
		 */
		private array $props;

		/**
		 * Creates a product.
		 *
		 * @param array<string, mixed> $props type, stock_status, featured, average_rating, catalog_visibility.
		 */
		public function __construct( array $props ) {
			$this->props = $props + array(
				'type'               => 'simple',
				'stock_status'       => 'instock',
				'featured'           => false,
				'average_rating'     => 0,
				'catalog_visibility' => 'visible',
			);
		}

		/**
		 * Whether the product is of a type.
		 *
		 * @param string $type Type.
		 * @return bool
		 */
		public function is_type( string $type ): bool {
			return $this->props['type'] === $type;
		}

		/**
		 * Stock status.
		 *
		 * @return string
		 */
		public function get_stock_status(): string {
			return $this->props['stock_status'];
		}

		/**
		 * Featured flag.
		 *
		 * @return bool
		 */
		public function get_featured(): bool {
			return $this->props['featured'];
		}

		/**
		 * Average rating.
		 *
		 * @return float
		 */
		public function get_average_rating(): float {
			return (float) $this->props['average_rating'];
		}

		/**
		 * Catalog visibility.
		 *
		 * @return string
		 */
		public function get_catalog_visibility(): string {
			return $this->props['catalog_visibility'];
		}
	}
}

/**
 * Answers the variation and raw meta queries from arrays.
 */
final class Jcore_Silta_WooCommerce_Wpdb {

	/**
	 * Posts table.
	 *
	 * @var string
	 */
	public string $posts = 'wp_posts';

	/**
	 * Post meta table.
	 *
	 * @var string
	 */
	public string $postmeta = 'wp_postmeta';

	/**
	 * Term meta table.
	 *
	 * @var string
	 */
	public string $termmeta = 'wp_termmeta';

	/**
	 * Variation IDs per product ID.
	 *
	 * @var array<int, int[]>
	 */
	public array $children = array();

	/**
	 * Raw meta per post ID.
	 *
	 * @var array<int, array<string, string[]>>
	 */
	public array $meta = array();

	/**
	 * Keeps the query and its arguments for the reader.
	 *
	 * @param string $query   Query.
	 * @param mixed  ...$args Arguments.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	public function prepare( string $query, mixed ...$args ): array {
		return array( $query, $args );
	}

	/**
	 * Returns a product's variation IDs.
	 *
	 * @param array{0: string, 1: array<int, mixed>} $prepared Prepared query.
	 * @return string[]
	 */
	public function get_col( array $prepared ): array {
		return array_map( 'strval', $this->children[ (int) $prepared[1][1] ] ?? array() );
	}

	/**
	 * Returns a post's meta rows.
	 *
	 * @param array{0: string, 1: array<int, mixed>} $prepared Prepared query.
	 * @param string                                 $output   Output type.
	 * @return array<int, array<string, string>>
	 */
	public function get_results( array $prepared, string $output ): array {
		$rows = array();
		foreach ( $this->meta[ (int) $prepared[1][2] ] ?? array() as $key => $values ) {
			foreach ( $values as $value ) {
				$rows[] = array(
					'meta_key'   => $key,
					'meta_value' => $value,
				);
			}
		}

		return $rows;
	}
}
