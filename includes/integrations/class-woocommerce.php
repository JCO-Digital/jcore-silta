<?php
/**
 * WooCommerce: products, variations and coupons of free WooCommerce core.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Integrations;

use Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore;
use Jcore\Silta\Sync\Meta_Writer;
use Jcore\Silta\Sync\State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes WooCommerce products travel intact. Products, variations and coupons
 * are ordinary posts for Silta; this adds what the generic path can't know.
 *
 * - IDs in WooCommerce meta (gallery, upsells, cross-sells, grouped children,
 *   coupon product and category lists, category thumbnails) are declared
 *   through `jcore_silta_meta_references`.
 * - A product's variations are its companions: they always travel with it,
 *   match by parent and attributes, and those the source removed are offered
 *   for the trash.
 * - Data the store derives (`_price`, the `outofstock` and `rated-*`
 *   visibility terms) or collects (stock, sales, ratings, coupon usage) is
 *   left out of the state, so it is never overwritten and never counts as a
 *   change. A product the sync creates gets the source's stock.
 * - Global attributes the target lacks are created there before their terms.
 * - After a batch is written, each product runs through WooCommerce so its
 *   price, stock status, visibility terms, lookup tables and transients are
 *   right.
 *
 * Orders never sync: their post types are excluded in Sync\Content.
 */
final class WooCommerce {

	public const PRODUCT   = 'product';
	public const VARIATION = 'product_variation';
	public const COUPON    = 'shop_coupon';

	/**
	 * Meta keys holding IDs. Lists are serialized, except the gallery and the
	 * coupon product lists, which are separated by commas.
	 */
	public const META_REFERENCES = array(
		'_product_image_gallery'     => 'post',
		'_upsell_ids'                => 'post',
		'_crosssell_ids'             => 'post',
		'_children'                  => 'post',
		'product_ids'                => 'post',
		'exclude_product_ids'        => 'post',
		'product_categories'         => 'term',
		'exclude_product_categories' => 'term',
		// Term meta: product category and brand images.
		'thumbnail_id'               => 'post',
	);

	/**
	 * Meta the target derives itself, per post type.
	 */
	private const DERIVED_META = array(
		self::PRODUCT   => array( '_price', '_product_version' ),
		self::VARIATION => array( '_price', '_product_version' ),
	);

	/**
	 * Meta the live store collects through orders and reviews, per post type.
	 */
	private const STORE_META = array(
		self::PRODUCT   => array( '_stock', '_stock_status', 'total_sales', '_wc_average_rating', '_wc_review_count', '_wc_rating_count' ),
		self::VARIATION => array( '_stock', '_stock_status', 'total_sales' ),
		self::COUPON    => array( 'usage_count', '_used_by' ),
	);

	/**
	 * Store meta a product created by the sync takes from the source.
	 */
	private const CREATE_META = array( '_stock', '_stock_status' );

	/**
	 * Visibility terms WooCommerce sets from stock and reviews.
	 */
	private const DERIVED_VISIBILITY = array( 'outofstock', 'rated-1', 'rated-2', 'rated-3', 'rated-4', 'rated-5' );

	/**
	 * Paid extensions that add product types, named in the preflight error
	 * when the target lacks the type.
	 */
	private const TYPE_EXTENSIONS = array(
		'bundle'                => 'WooCommerce Product Bundles',
		'composite'             => 'WooCommerce Composite Products',
		'subscription'          => 'WooCommerce Subscriptions',
		'variable-subscription' => 'WooCommerce Subscriptions',
	);

	/**
	 * Products and variations written in the current batch, by ID.
	 *
	 * @var array<int, true>
	 */
	private static array $pending = array();

	/**
	 * How many write batches are open.
	 *
	 * @var int
	 */
	private static int $writing = 0;

	/**
	 * Hooks the integration when WooCommerce is active.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! self::active() ) {
			return;
		}

		add_filter( 'jcore_silta_meta_references', array( self::class, 'meta_references' ) );
		add_filter( 'jcore_silta_post_state', array( self::class, 'post_state' ), 10, 2 );
		add_filter( 'jcore_silta_export_object', array( self::class, 'export_object' ), 10, 3 );
		add_filter( 'jcore_silta_match_post', array( self::class, 'match_post' ), 10, 3 );
		add_filter( 'jcore_silta_match_by_slug', array( self::class, 'match_by_slug' ), 10, 2 );
		add_filter( 'jcore_silta_preflight', array( self::class, 'preflight' ), 10, 2 );
		add_filter( 'jcore_silta_plan_notes', array( self::class, 'plan_notes' ), 10, 2 );
		add_action( 'jcore_silta_writes_start', array( self::class, 'writes_start' ) );
		add_action( 'jcore_silta_post_written', array( self::class, 'post_written' ), 10, 4 );
		add_action( 'jcore_silta_writes_done', array( self::class, 'writes_done' ) );
		add_action( 'trashed_post', array( self::class, 'post_changed' ) );
		add_action( 'untrashed_post', array( self::class, 'post_changed' ) );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Declares the meta keys that hold IDs.
	 *
	 * @param array<string, string> $keys Meta keys and kinds.
	 *
	 * @return array<string, string>
	 */
	public static function meta_references( array $keys ): array {
		return array_merge( $keys, self::META_REFERENCES );
	}

	/**
	 * Leaves the data the target derives or collects itself out of a post's
	 * state, so it is never exported, compared, hashed or written.
	 *
	 * @param array<string, mixed> $state State.
	 * @param \WP_Post|object      $post  Post.
	 *
	 * @return array<string, mixed>
	 */
	public static function post_state( array $state, object $post ): array {
		$own = self::own_meta( (string) ( $post->post_type ?? '' ) );
		if ( ! empty( $own ) && isset( $state['meta'] ) && is_array( $state['meta'] ) ) {
			$state['meta'] = array_diff_key( $state['meta'], array_flip( $own ) );
		}

		if ( ! empty( $state['terms']['product_visibility'] ) ) {
			$derived                              = self::derived_visibility_ids();
			$state['terms']['product_visibility'] = array_values(
				array_filter(
					(array) $state['terms']['product_visibility'],
					static fn( $id ): bool => ! in_array( (int) $id, $derived, true )
				)
			);
		}

		return $state;
	}

	/**
	 * Meta keys a post type's posts keep on each site.
	 *
	 * @param string $post_type Post type.
	 *
	 * @return string[]
	 */
	public static function own_meta( string $post_type ): array {
		return array_merge( self::DERIVED_META[ $post_type ] ?? array(), self::STORE_META[ $post_type ] ?? array() );
	}

	/**
	 * Adds a product's variations as companions, the definitions of the global
	 * attributes an object uses, and a new product's stock.
	 *
	 * @param array<string, mixed> $state Exported object.
	 * @param string               $type  post, term, menu or form.
	 * @param int                  $id    Object ID.
	 *
	 * @return array<string, mixed>
	 */
	public static function export_object( array $state, string $type, int $id ): array {
		$extra      = array();
		$taxonomies = array();

		if ( 'post' === $type ) {
			$post_type = (string) ( $state['post_type'] ?? '' );
			if ( self::PRODUCT === $post_type ) {
				$state['companions'] = array( self::VARIATION => self::variation_ids( $id ) );
				$product_type        = \WC_Product_Factory::get_product_type( $id );
				if ( is_string( $product_type ) && '' !== $product_type ) {
					$extra['type'] = $product_type;
				}
			}
			if ( in_array( $post_type, array( self::PRODUCT, self::VARIATION ), true ) ) {
				$stock = array_intersect_key( State::raw_meta( 'post', $id ), array_flip( self::CREATE_META ) );
				if ( ! empty( $stock ) ) {
					$extra['stock'] = $stock;
				}
			}
			foreach ( (array) ( $state['terms'] ?? array() ) as $taxonomy => $ids ) {
				if ( ! empty( $ids ) ) {
					$taxonomies[] = (string) $taxonomy;
				}
			}
		} elseif ( 'term' === $type ) {
			$taxonomies[] = (string) ( $state['taxonomy'] ?? '' );
		}

		$attributes = self::attribute_definitions( $taxonomies );
		if ( ! empty( $attributes ) ) {
			$extra['attributes'] = $attributes;
		}
		if ( ! empty( $extra ) ) {
			$state['extra']['woocommerce'] = $extra;
		}

		return $state;
	}

	/**
	 * Returns the IDs of a product's variations that can be synced.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return int[]
	 */
	public static function variation_ids( int $product_id ): array {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Every variation, whatever the language filters say.
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit') ORDER BY menu_order ASC, ID ASC",
				$wpdb->posts,
				$product_id,
				self::VARIATION
			)
		);

		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Matches a variation within its product: the one with the same
	 * attributes, else the one with the same SKU.
	 *
	 * @param array{id: int, method: string}|null $matched Match so far.
	 * @param array<string, mixed>                $record  Incoming post.
	 * @param callable                            $resolve Resolves a remote ID.
	 *
	 * @return array{id: int, method: string}|null
	 */
	public static function match_post( ?array $matched, array $record, callable $resolve ): ?array {
		if ( null !== $matched || self::VARIATION !== ( $record['post_type'] ?? '' ) ) {
			return $matched;
		}

		$parent = (int) ( $record['fields']['post_parent'] ?? 0 );
		$local  = $parent > 0 ? (int) $resolve( 'post', $parent ) : 0;
		if ( $local <= 0 ) {
			return null;
		}

		$meta     = (array) ( $record['meta'] ?? array() );
		$children = array();
		foreach ( self::variation_ids( $local ) as $child ) {
			$children[ $child ] = State::raw_meta( 'post', $child );
		}

		$attributes = self::attributes( $meta );
		if ( ! empty( $attributes ) ) {
			$found = array_keys( array_filter( $children, static fn( array $theirs ): bool => self::attributes( $theirs ) === $attributes ) );
			if ( 1 === count( $found ) ) {
				return array(
					'id'     => $found[0],
					'method' => 'attributes',
				);
			}
		}

		$sku = (string) ( $meta['_sku'][0] ?? '' );
		if ( '' !== $sku ) {
			$found = array_keys( array_filter( $children, static fn( array $theirs ): bool => (string) ( $theirs['_sku'][0] ?? '' ) === $sku ) );
			if ( 1 === count( $found ) ) {
				return array(
					'id'     => $found[0],
					'method' => 'sku',
				);
			}
		}

		return null;
	}

	/**
	 * Returns a variation's attribute values (`attribute_*` meta), sorted.
	 *
	 * @param array<string, mixed> $meta Raw meta.
	 *
	 * @return array<string, string>
	 */
	public static function attributes( array $meta ): array {
		$attributes = array();
		foreach ( $meta as $key => $values ) {
			if ( str_starts_with( (string) $key, 'attribute_' ) ) {
				$attributes[ (string) $key ] = (string) ( ( (array) $values )[0] ?? '' );
			}
		}
		ksort( $attributes );

		return $attributes;
	}

	/**
	 * Keeps variations from matching by slug: WooCommerce generates their
	 * slugs from the product's title, so they repeat across products.
	 *
	 * @param bool   $by_slug   Whether to match by slug.
	 * @param string $post_type Post type.
	 *
	 * @return bool
	 */
	public static function match_by_slug( bool $by_slug, string $post_type ): bool {
		return self::VARIATION === $post_type ? false : $by_slug;
	}

	/**
	 * Lets global attributes the target lacks through when they will be
	 * created, and says what to do when they can't be.
	 *
	 * @param array<string, string> $errors Errors by section.
	 * @param array<string, mixed>  $record Incoming object.
	 *
	 * @return array<string, string>
	 */
	public static function preflight( array $errors, array $record ): array {
		$definitions = self::incoming_attributes( $record );

		if ( 'term' === ( $record['type'] ?? '' ) ) {
			$taxonomy = (string) ( $record['taxonomy'] ?? '' );
			if ( isset( $errors[''] ) && isset( $definitions[ $taxonomy ] ) ) {
				$problem = self::attribute_problem( $taxonomy, $definitions[ $taxonomy ] );
				if ( null === $problem ) {
					unset( $errors[''] );
				} else {
					$errors[''] = $problem;
				}
			}

			return $errors;
		}

		if ( 'post' !== ( $record['type'] ?? '' ) ) {
			return $errors;
		}

		if ( self::PRODUCT === ( $record['post_type'] ?? '' ) && ! isset( $errors[''] ) ) {
			$problem = self::type_problem( $record['extra']['woocommerce']['type'] ?? null, array_keys( (array) wc_get_product_types() ) );
			if ( null !== $problem ) {
				$errors[''] = $problem;

				return $errors;
			}
		}

		foreach ( (array) ( $record['terms'] ?? array() ) as $taxonomy => $ids ) {
			$section = 'terms.' . $taxonomy;
			if ( ! isset( $errors[ $section ] ) || ! str_starts_with( (string) $taxonomy, 'pa_' ) ) {
				continue;
			}
			if ( empty( $ids ) ) {
				// Nothing to write: the attribute isn't used by this product.
				unset( $errors[ $section ] );
				continue;
			}
			if ( isset( $definitions[ $taxonomy ] ) ) {
				$problem = self::attribute_problem( (string) $taxonomy, $definitions[ $taxonomy ] );
				if ( null === $problem ) {
					unset( $errors[ $section ] );
				} else {
					$errors[ $section ] = $problem;
				}
			}
		}

		return $errors;
	}

	/**
	 * Says why a product can't be written here when its type (from the
	 * source's WooCommerce) is one this site doesn't have, because the
	 * extension that adds it is missing. Without the type the product would
	 * arrive as an empty product of another type.
	 *
	 * @param mixed    $type  Product type the source sent, if any.
	 * @param string[] $known Product types this site has.
	 *
	 * @return string|null
	 */
	public static function type_problem( mixed $type, array $known ): ?string {
		if ( ! is_string( $type ) || '' === $type || in_array( $type, $known, true ) ) {
			return null;
		}

		$type = sanitize_key( $type );
		if ( isset( self::TYPE_EXTENSIONS[ $type ] ) ) {
			return sprintf(
				/* translators: 1: product type, e.g. bundle, 2: extension name. */
				__( 'This site has no “%1$s” products: %2$s is not active here. Activate it on this site, then sync again.', 'jcore-silta' ),
				$type,
				self::TYPE_EXTENSIONS[ $type ]
			);
		}

		return sprintf(
			/* translators: %s: product type. */
			__( 'This site has no “%s” products: the WooCommerce extension that adds them is not active here. Activate it on this site, then sync again.', 'jcore-silta' ),
			$type
		);
	}

	/**
	 * Says which global attributes the sync will create on this site.
	 *
	 * @param string[]             $notes  Notes.
	 * @param array<string, mixed> $record Incoming object.
	 *
	 * @return string[]
	 */
	public static function plan_notes( array $notes, array $record ): array {
		foreach ( self::to_create( $record ) as $taxonomy => $definition ) {
			$notes[] = sprintf(
				/* translators: 1: attribute name, 2: attribute taxonomy, e.g. pa_color. */
				__( 'Creates the product attribute “%1$s” (%2$s) on this site.', 'jcore-silta' ),
				$definition['label'],
				$taxonomy
			);
		}

		return $notes;
	}

	/**
	 * Opens a write batch, creating the global attributes its objects need.
	 *
	 * @param array<string, array<string, mixed>> $objects Incoming objects.
	 *
	 * @return void
	 */
	public static function writes_start( array $objects ): void {
		++self::$writing;

		$created = array();
		foreach ( $objects as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			foreach ( self::to_create( $record ) as $taxonomy => $definition ) {
				if ( ! isset( $created[ $taxonomy ] ) ) {
					$created[ $taxonomy ] = true;
					self::create_attribute( $taxonomy, $definition );
				}
			}
		}
	}

	/**
	 * Remembers a written product or variation, and gives a product the sync
	 * created the source's stock.
	 *
	 * @param int                  $id       Local post ID.
	 * @param array<string, mixed> $incoming State written.
	 * @param bool                 $created  Whether the post was just created.
	 * @param bool                 $restore  Whether this restored a snapshot.
	 *
	 * @return void
	 */
	public static function post_written( int $id, array $incoming, bool $created, bool $restore ): void {
		if ( ! in_array( $incoming['post_type'] ?? '', array( self::PRODUCT, self::VARIATION ), true ) ) {
			return;
		}

		// WooCommerce caches a product's type, and the terms were written
		// behind its back.
		\WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );

		if ( $created && ! $restore ) {
			$stock = self::incoming_stock( $incoming );
			if ( ! empty( $stock ) ) {
				Meta_Writer::replace( 'post', $id, array_keys( $stock ), $stock, true );
			}
		}

		self::$pending[ $id ] = true;
	}

	/**
	 * Remembers a product or variation trashed or restored while Silta writes
	 * (an orphan variation, or an undo), so its product is synced again.
	 *
	 * @param int $id Post ID.
	 *
	 * @return void
	 */
	public static function post_changed( int $id ): void {
		if ( self::$writing > 0 && in_array( get_post_type( $id ), array( self::PRODUCT, self::VARIATION ), true ) ) {
			self::$pending[ $id ] = true;
		}
	}

	/**
	 * Closes a write batch and runs what it wrote through WooCommerce.
	 *
	 * @return void
	 */
	public static function writes_done(): void {
		self::$writing = max( 0, self::$writing - 1 );
		if ( 0 === self::$writing ) {
			self::refresh();
		}
	}

	/**
	 * Returns the products to refresh for the pending posts: variations
	 * first, then every product written or whose variations were.
	 *
	 * @param array<int, array{type: string, status: string, parent: int}> $posts Pending posts by ID.
	 *
	 * @return array{0: int[], 1: int[]} Variation IDs, product IDs.
	 */
	public static function refresh_order( array $posts ): array {
		$variations = array();
		$products   = array();
		foreach ( $posts as $id => $post ) {
			if ( self::VARIATION === $post['type'] ) {
				if ( 'trash' !== $post['status'] ) {
					$variations[] = (int) $id;
				}
				if ( $post['parent'] > 0 ) {
					$products[ $post['parent'] ] = true;
				}
			} elseif ( self::PRODUCT === $post['type'] && 'trash' !== $post['status'] ) {
				$products[ (int) $id ] = true;
			}
		}

		return array( $variations, array_keys( $products ) );
	}

	/**
	 * Runs the pending products through WooCommerce.
	 *
	 * @return void
	 */
	private static function refresh(): void {
		$posts         = array();
		$ids           = array_keys( self::$pending );
		self::$pending = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$posts[ $id ] = array(
					'type'   => $post->post_type,
					'status' => $post->post_status,
					'parent' => (int) $post->post_parent,
				);
			}
		}

		list( $variations, $products ) = self::refresh_order( $posts );
		foreach ( $variations as $id ) {
			self::refresh_product( $id, false );
		}
		foreach ( $products as $id ) {
			if ( self::PRODUCT === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
				self::refresh_product( $id, true );
			}
		}
	}

	/**
	 * Runs one product or variation through WooCommerce: its price (from its
	 * variations or children for variable and grouped products), stock status,
	 * visibility terms, lookup tables and transients.
	 *
	 * @param int  $id     Post ID.
	 * @param bool $lookup Whether to regenerate the attributes lookup (a
	 *                     product's covers its variations).
	 *
	 * @return void
	 */
	private static function refresh_product( int $id, bool $lookup ): void {
		try {
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product ) {
				return;
			}

			/**
			 * Filters whether an integration brought a product's price up to
			 * date itself, before the product is saved. Return true for a
			 * product type whose price WooCommerce core can't derive (a
			 * bundle's follows its items); core's own price sync is skipped.
			 *
			 * @param bool        $synced  Whether the price is synced.
			 * @param \WC_Product $product Product.
			 */
			if ( ! apply_filters( 'jcore_silta_woocommerce_sync_price', false, $product ) ) {
				if ( $product->is_type( 'variable' ) ) {
					\WC_Product_Variable::sync( $product, false );
				} elseif ( $product->is_type( 'grouped' ) ) {
					\WC_Product_Grouped::sync( $product, false );
				} else {
					// The data store never writes `_price` from the prop: it sets it
					// itself only when the regular or sale price changes through it.
					$price = $product->is_on_sale( 'edit' ) ? $product->get_sale_price( 'edit' ) : $product->get_regular_price( 'edit' );
					update_post_meta( $id, '_price', $price );
					$product->set_price( $price );
				}
			}
			$product->save();

			/**
			 * Filters the visibility terms a product written by Silta gets.
			 *
			 * @param string[]    $terms   Term slugs.
			 * @param \WC_Product $product Product.
			 */
			wp_set_post_terms( $id, (array) apply_filters( 'jcore_silta_woocommerce_visibility_terms', self::visibility_terms( $product ), $product ), 'product_visibility', false );
			self::refresh_meta_lookup( $product );
			if ( $lookup && class_exists( LookupDataStore::class ) ) {
				wc_get_container()->get( LookupDataStore::class )->on_product_changed( $product );
			}
			wc_delete_product_transients( $id );
		} catch ( \Throwable $e ) {
			// The product itself was written; WooCommerce's own cron and the
			// next save put derived data right.
			return;
		}
	}

	/**
	 * Returns the visibility terms WooCommerce sets for a product, as its data
	 * store does: featured, out of stock, rating and catalog visibility; for a
	 * variation only out of stock.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return string[]
	 */
	public static function visibility_terms( \WC_Product $product ): array {
		$out_of_stock = 'outofstock' === $product->get_stock_status() ? array( 'outofstock' ) : array();
		if ( $product->is_type( 'variation' ) ) {
			return $out_of_stock;
		}

		$terms  = array_merge( $product->get_featured() ? array( 'featured' ) : array(), $out_of_stock );
		$rating = (int) min( 5, round( (float) $product->get_average_rating(), 0 ) );
		if ( $rating > 0 ) {
			$terms[] = 'rated-' . $rating;
		}

		return array_merge(
			$terms,
			match ( $product->get_catalog_visibility() ) {
				'hidden' => array( 'exclude-from-search', 'exclude-from-catalog' ),
				'catalog' => array( 'exclude-from-search' ),
				'search' => array( 'exclude-from-catalog' ),
				default => array(),
			}
		);
	}

	/**
	 * Refreshes a product's row in `wc_product_meta_lookup`, through the
	 * product type's own data store: an extension's (a bundle's) reads its
	 * prices from meta of its own.
	 *
	 * @param \WC_Product $product Product or variation.
	 *
	 * @return void
	 */
	public static function refresh_meta_lookup( \WC_Product $product ): void {
		$id    = $product->get_id();
		$class = $product->get_data_store() ? $product->get_data_store()->get_current_class_name() : '';
		$store = is_string( $class ) && is_a( $class, \WC_Product_Data_Store_CPT::class, true ) ? new $class() : new \WC_Product_Data_Store_CPT();
		if ( method_exists( $store, 'refresh_product_lookup_table' ) ) {
			$store->refresh_product_lookup_table( $id );

			return;
		}

		// Before WooCommerce 10.8 the refresh is only reachable from inside
		// the data store.
		( function () use ( $id ): void {
			$this->update_lookup_table( $id, 'wc_product_meta_lookup' );
		} )->call( $store );
	}

	/**
	 * Returns the IDs of the visibility terms WooCommerce derives.
	 *
	 * @return int[]
	 */
	private static function derived_visibility_ids(): array {
		$ids = function_exists( 'wc_get_product_visibility_term_ids' ) ? (array) wc_get_product_visibility_term_ids() : array();

		return array_values( array_filter( array_map( 'intval', array_intersect_key( $ids, array_flip( self::DERIVED_VISIBILITY ) ) ) ) );
	}

	/**
	 * Describes the global attributes behind taxonomies.
	 *
	 * @param string[] $taxonomies Taxonomies.
	 *
	 * @return array<string, array{label: string, type: string, order_by: string, has_archives: bool}>
	 */
	private static function attribute_definitions( array $taxonomies ): array {
		$definitions = array();
		foreach ( array_unique( $taxonomies ) as $taxonomy ) {
			if ( ! str_starts_with( $taxonomy, 'pa_' ) ) {
				continue;
			}
			$attribute = wc_get_attribute( (int) wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			if ( ! is_object( $attribute ) ) {
				continue;
			}
			$definitions[ $taxonomy ] = array(
				'label'        => (string) $attribute->name,
				'type'         => (string) $attribute->type,
				'order_by'     => (string) $attribute->order_by,
				'has_archives' => (bool) $attribute->has_archives,
			);
		}

		return $definitions;
	}

	/**
	 * Returns the valid attribute definitions an incoming object carries.
	 *
	 * @param array<string, mixed> $record Incoming object.
	 *
	 * @return array<string, array{label: string, type: string, order_by: string, has_archives: bool}>
	 */
	public static function incoming_attributes( array $record ): array {
		$definitions = array();
		foreach ( (array) ( $record['extra']['woocommerce']['attributes'] ?? array() ) as $taxonomy => $definition ) {
			if ( ! is_string( $taxonomy ) || ! self::valid_taxonomy( $taxonomy ) || ! is_array( $definition ) || ! is_string( $definition['label'] ?? null ) ) {
				continue;
			}
			$label = trim( sanitize_text_field( $definition['label'] ) );
			if ( '' === $label ) {
				continue;
			}
			$definitions[ $taxonomy ] = array(
				'label'        => $label,
				'type'         => is_string( $definition['type'] ?? null ) ? sanitize_key( $definition['type'] ) : 'select',
				'order_by'     => is_string( $definition['order_by'] ?? null ) ? sanitize_key( $definition['order_by'] ) : 'menu_order',
				'has_archives' => true === ( $definition['has_archives'] ?? false ),
			);
		}

		return $definitions;
	}

	/**
	 * Whether a name can be a global attribute's taxonomy: `pa_` and a slug,
	 * at most 32 characters as WordPress allows for taxonomies.
	 *
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return bool
	 */
	public static function valid_taxonomy( string $taxonomy ): bool {
		return 1 === preg_match( '/^pa_[a-z0-9_-]+$/', $taxonomy ) && strlen( $taxonomy ) <= 32;
	}

	/**
	 * Returns the attributes an incoming object needs that this site lacks
	 * and the acting user may create.
	 *
	 * @param array<string, mixed> $record Incoming object.
	 *
	 * @return array<string, array{label: string, type: string, order_by: string, has_archives: bool}>
	 */
	private static function to_create( array $record ): array {
		$needed = array();
		if ( 'term' === ( $record['type'] ?? '' ) ) {
			$needed[] = (string) ( $record['taxonomy'] ?? '' );
		} elseif ( 'post' === ( $record['type'] ?? '' ) ) {
			foreach ( (array) ( $record['terms'] ?? array() ) as $taxonomy => $ids ) {
				if ( ! empty( $ids ) ) {
					$needed[] = (string) $taxonomy;
				}
			}
		}

		$create = array();
		foreach ( array_intersect_key( self::incoming_attributes( $record ), array_flip( $needed ) ) as $taxonomy => $definition ) {
			if ( null === self::attribute_problem( $taxonomy, $definition ) && ! taxonomy_exists( $taxonomy ) ) {
				$create[ $taxonomy ] = $definition;
			}
		}

		return $create;
	}

	/**
	 * Says why a missing attribute can't be created here, or null when it
	 * exists or can be.
	 *
	 * @param string                                                                   $taxonomy   Taxonomy.
	 * @param array{label: string, type: string, order_by: string, has_archives: bool} $definition Definition.
	 *
	 * @return string|null
	 */
	private static function attribute_problem( string $taxonomy, array $definition ): ?string {
		if ( taxonomy_exists( $taxonomy ) || current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce's capability for attributes.
			return null;
		}

		return sprintf(
			/* translators: 1: attribute name, 2: attribute taxonomy, e.g. pa_color. */
			__( 'This site has no product attribute “%1$s” (%2$s), and you may not create it. Ask an administrator to sync it, or to create it under Products → Attributes.', 'jcore-silta' ),
			$definition['label'],
			$taxonomy
		);
	}

	/**
	 * Creates a global attribute and registers its taxonomy for the rest of
	 * the request, as WooCommerce does on `init`.
	 *
	 * @param string                                                                   $taxonomy   Taxonomy.
	 * @param array{label: string, type: string, order_by: string, has_archives: bool} $definition Definition.
	 *
	 * @return void
	 */
	private static function create_attribute( string $taxonomy, array $definition ): void {
		if ( taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$id = wc_attribute_taxonomy_id_by_name( $taxonomy );
		if ( empty( $id ) ) {
			$id = wc_create_attribute(
				array(
					'name'         => $definition['label'],
					'slug'         => substr( $taxonomy, 3 ),
					'type'         => $definition['type'],
					'order_by'     => $definition['order_by'],
					'has_archives' => $definition['has_archives'],
				)
			);
			if ( is_wp_error( $id ) ) {
				return;
			}
		}

		$public = $definition['has_archives'];
		$args   = array(
			'hierarchical'          => false,
			'update_count_callback' => '_update_post_term_count',
			'labels'                => array(
				'name'          => $definition['label'],
				'singular_name' => $definition['label'],
			),
			'show_ui'               => true,
			'show_in_quick_edit'    => false,
			'show_in_menu'          => false,
			'meta_box_cb'           => false,
			'query_var'             => $public,
			'rewrite'               => false,
			'sort'                  => false,
			'public'                => $public,
			'capabilities'          => array(
				'manage_terms' => 'manage_product_terms',
				'edit_terms'   => 'edit_product_terms',
				'delete_terms' => 'delete_product_terms',
				'assign_terms' => 'assign_product_terms',
			),
		);

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filters, as it applies them on init.
		$objects = (array) apply_filters( "woocommerce_taxonomy_objects_{$taxonomy}", array( self::PRODUCT ) );
		$args    = (array) apply_filters( "woocommerce_taxonomy_args_{$taxonomy}", $args );
		// phpcs:enable

		register_taxonomy( $taxonomy, $objects, $args );
	}

	/**
	 * Returns the valid stock values an incoming product carries.
	 *
	 * @param array<string, mixed> $incoming Incoming post.
	 *
	 * @return array<string, string[]>
	 */
	public static function incoming_stock( array $incoming ): array {
		$stock = array();
		foreach ( (array) ( $incoming['extra']['woocommerce']['stock'] ?? array() ) as $key => $values ) {
			if ( ! in_array( $key, self::CREATE_META, true ) || ! is_array( $values ) || ! array_is_list( $values ) ) {
				continue;
			}
			$values = array_values( array_filter( $values, static fn( $value ): bool => is_string( $value ) && strlen( $value ) <= 64 && ! str_contains( $value, '<' ) ) );
			if ( ! empty( $values ) ) {
				$stock[ $key ] = $values;
			}
		}

		return $stock;
	}
}
