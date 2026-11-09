<?php
/**
 * WooCommerce Product Bundles: a bundle's items.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Integrations;

use Jcore\Silta\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes a product bundle travel with its items. A bundle is an ordinary
 * product for Silta, but its items and their settings live in the extension's
 * own tables (`woocommerce_bundled_items` and `woocommerce_bundled_itemmeta`),
 * which the generic path never sees.
 *
 * - A bundle's state gets a `bundled_items` list: each item's product, its
 *   order and its settings, read through the extension
 *   (`WC_Product_Bundle::get_bundled_data_items()`). It is hashed, compared
 *   and snapshotted like the rest of the post, and is its own diff section.
 * - The bundled products, and the variations an item allows, are references:
 *   the source makes them dependencies, the target remaps them. An item whose
 *   product isn't on the target is left out.
 * - The target writes the items through the extension's CRUD
 *   (`set_bundled_data_items()` and `save_items()`), never into its tables.
 *   Items match the target's by product, in order, so a second push updates
 *   them; items the source no longer has are removed, as on the source.
 * - Prices and stock the extension derives from the items stay out of the
 *   state, and the extension recomputes them after the WooCommerce refresh.
 *
 * A target without the extension refuses bundles in preflight (see
 * WooCommerce::type_problem()), so they never arrive as empty products.
 */
final class WooCommerce_Product_Bundles {

	/**
	 * State key and diff section of a bundle's items.
	 */
	public const SECTION = 'bundled_items';

	/**
	 * The product type.
	 */
	private const TYPE = 'bundle';

	/**
	 * Bundle meta the extension derives from the items, their prices and
	 * stock, and the base prices. `_price` is left out by WooCommerce already.
	 */
	private const DERIVED_META = array(
		'_regular_price',
		'_sale_price',
		'_wc_pb_base_price',
		'_wc_sw_max_price',
		'_wc_sw_max_regular_price',
		'_wc_pb_bundled_items_stock_status',
		'_wc_pb_bundled_items_stock_sync_status',
		'_wc_pb_bundle_stock_quantity',
	);

	/**
	 * Item meta the extension derives from stock, or uses while saving.
	 */
	private const DERIVED_ITEM_META = array( 'stock_status', 'max_stock', 'max_variation_stock', 'real_id' );

	/**
	 * Item meta holding lists: variation IDs, and attribute slugs by attribute.
	 */
	private const LIST_META = array( 'allowed_variations', 'default_variation_attributes' );

	/**
	 * Item meta holding HTML, filtered unless the user may post unfiltered HTML.
	 */
	private const HTML_META = array( 'title', 'description' );

	/**
	 * Bundles written in the current batch, by ID.
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
	 * Hooks the integration when WooCommerce and Product Bundles are active.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! self::active() ) {
			return;
		}

		// After WooCommerce's own filters: the meta it leaves out is gone.
		add_filter( 'jcore_silta_post_state', array( self::class, 'post_state' ), 20, 2 );
		add_filter( 'jcore_silta_walk_post', array( self::class, 'walk' ), 10, 2 );
		add_filter( 'jcore_silta_post_sections', array( self::class, 'sections' ), 10, 3 );
		add_filter( 'jcore_silta_plan_notes', array( self::class, 'plan_notes' ), 10, 4 );
		add_action( 'jcore_silta_writes_start', array( self::class, 'writes_start' ) );
		add_action( 'jcore_silta_post_written', array( self::class, 'post_written' ), 10, 5 );
		// After WooCommerce's refresh, so the bundled products' prices are right.
		add_action( 'jcore_silta_writes_done', array( self::class, 'writes_done' ), 20 );
		add_filter( 'jcore_silta_woocommerce_sync_price', array( self::class, 'sync_price' ), 10, 2 );
		add_filter( 'jcore_silta_woocommerce_visibility_terms', array( self::class, 'visibility_terms' ), 10, 2 );
	}

	/**
	 * Whether WooCommerce and Product Bundles are active.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return WooCommerce::active() && class_exists( 'WC_Product_Bundle' );
	}

	/**
	 * Adds a bundle's items to its state, and leaves out what the extension
	 * derives from them.
	 *
	 * @param array<string, mixed> $state State.
	 * @param \WP_Post|object      $post  Post.
	 *
	 * @return array<string, mixed>
	 */
	public static function post_state( array $state, object $post ): array {
		if ( WooCommerce::PRODUCT !== ( $post->post_type ?? '' ) || ! self::is_bundle( (int) $post->ID ) ) {
			return $state;
		}

		$state = self::without_derived_meta( $state );

		$items = array();
		$data  = array();
		try {
			$data = ( new \WC_Product_Bundle( (int) $post->ID ) )->get_bundled_data_items( 'edit' );
		} catch ( \Throwable $e ) {
			$data = array();
		}
		foreach ( $data as $item ) {
			if ( $item instanceof \WC_Bundled_Item_Data ) {
				$item    = $item->get_data();
				$items[] = array(
					'product_id' => $item['product_id'] ?? 0,
					'menu_order' => $item['menu_order'] ?? 0,
					'meta'       => $item['meta_data'] ?? array(),
				);
			}
		}
		$state[ self::SECTION ] = self::normalize( $items );

		return $state;
	}

	/**
	 * Whether a product is a bundle, from its terms: WooCommerce caches the
	 * type, and Silta writes the terms without telling it.
	 *
	 * @param int $id Product ID.
	 *
	 * @return bool
	 */
	private static function is_bundle( int $id ): bool {
		return has_term( self::TYPE, 'product_type', $id );
	}

	/**
	 * Leaves the meta a bundle derives out of its state.
	 *
	 * @param array<string, mixed> $state State.
	 *
	 * @return array<string, mixed>
	 */
	public static function without_derived_meta( array $state ): array {
		if ( isset( $state['meta'] ) && is_array( $state['meta'] ) ) {
			$state['meta'] = array_diff_key( $state['meta'], array_flip( self::DERIVED_META ) );
		}

		return $state;
	}

	/**
	 * Brings items into one shape, so both sites compare equal: a list of
	 * `product_id`, `menu_order` and `meta` (sorted by key), in bundle order.
	 * Anything malformed (it may come from the other site) is dropped, and so
	 * is the meta the extension derives.
	 *
	 * @param mixed $items Items.
	 *
	 * @return array<int, array{product_id: int, menu_order: int, meta: array<string, mixed>}>
	 */
	public static function normalize( mixed $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$normal = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! is_numeric( $item['product_id'] ?? null ) || (int) $item['product_id'] <= 0 ) {
				continue;
			}
			$normal[] = array(
				'product_id' => (int) $item['product_id'],
				'menu_order' => is_numeric( $item['menu_order'] ?? null ) ? max( 0, (int) $item['menu_order'] ) : 0,
				'meta'       => self::normalize_meta( $item['meta'] ?? array() ),
			);
		}

		// Stable: items with the same order keep theirs.
		usort( $normal, static fn( array $a, array $b ): int => $a['menu_order'] <=> $b['menu_order'] );

		return $normal;
	}

	/**
	 * Keeps an item's settings: scalar values under plain keys, the variation
	 * list as sorted IDs and the default attributes as slugs.
	 *
	 * @param mixed $meta Item meta.
	 *
	 * @return array<string, mixed>
	 */
	private static function normalize_meta( mixed $meta ): array {
		if ( ! is_array( $meta ) ) {
			return array();
		}

		$normal = array();
		foreach ( $meta as $key => $value ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9_]{1,64}$/', $key ) || in_array( $key, self::DERIVED_ITEM_META, true ) || null === $value ) {
				continue;
			}
			if ( 'allowed_variations' === $key ) {
				$ids = is_array( $value ) ? array_map( 'intval', array_filter( $value, 'is_numeric' ) ) : array();
				$ids = array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
				sort( $ids );
				$normal[ $key ] = $ids;
			} elseif ( 'default_variation_attributes' === $key ) {
				$attributes = array();
				foreach ( is_array( $value ) ? $value : array() as $attribute => $slug ) {
					if ( is_string( $attribute ) && '' !== $attribute && is_scalar( $slug ) ) {
						$attributes[ $attribute ] = (string) $slug;
					}
				}
				ksort( $attributes );
				$normal[ $key ] = $attributes;
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || ( is_string( $value ) && strlen( $value ) <= 65535 ) ) {
				$normal[ $key ] = $value;
			}
		}
		ksort( $normal );

		return $normal;
	}

	/**
	 * Visits the products and variations a bundle's items point at. An item
	 * whose product doesn't resolve is dropped, as is a variation that
	 * doesn't.
	 *
	 * @param array<string, mixed> $record Post state.
	 * @param callable             $visit  Visitor: ( string $kind, int $id ): ?int.
	 *
	 * @return array<string, mixed>
	 */
	public static function walk( array $record, callable $visit ): array {
		if ( ! array_key_exists( self::SECTION, $record ) ) {
			return $record;
		}

		$items = array();
		foreach ( self::normalize( $record[ self::SECTION ] ) as $item ) {
			$product = $visit( 'post', $item['product_id'] );
			if ( null === $product || (int) $product <= 0 ) {
				continue;
			}
			$item['product_id'] = (int) $product;

			if ( isset( $item['meta']['allowed_variations'] ) ) {
				$variations = array();
				foreach ( $item['meta']['allowed_variations'] as $id ) {
					$variation = $visit( 'post', $id );
					if ( null !== $variation && (int) $variation > 0 ) {
						$variations[] = (int) $variation;
					}
				}
				sort( $variations );
				$item['meta']['allowed_variations'] = array_values( array_unique( $variations ) );
			}

			$items[] = $item;
		}
		$record[ self::SECTION ] = $items;

		return $record;
	}

	/**
	 * Offers a bundle's items as a diff section.
	 *
	 * @param array<string, array<string, mixed>> $parts    Sections.
	 * @param array<string, mixed>                $state    State split.
	 * @param array<string, mixed>                $incoming Incoming post.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function sections( array $parts, array $state, array $incoming ): array {
		if ( ! array_key_exists( self::SECTION, $state ) && ! array_key_exists( self::SECTION, $incoming ) ) {
			return $parts;
		}

		$items                  = array_key_exists( self::SECTION, $state ) ? self::normalize( $state[ self::SECTION ] ) : null;
		$parts[ self::SECTION ] = array(
			'label'   => __( 'Bundled items', 'jcore-silta' ),
			'group'   => self::SECTION,
			'value'   => $items,
			'display' => null === $items ? '' : self::display( $items, array( self::class, 'title' ) ),
			'text'    => true,
		);

		return $parts;
	}

	/**
	 * Renders items for the diff: one line per item, then its settings.
	 *
	 * @param array<int, array{product_id: int, menu_order: int, meta: array<string, mixed>}> $items Items.
	 * @param callable                                                                        $title Product title by ID.
	 *
	 * @return string
	 */
	public static function display( array $items, callable $title ): string {
		$lines = array();
		foreach ( array_values( $items ) as $index => $item ) {
			$lines[] = sprintf( '%d. %s (#%d)', $index + 1, (string) $title( $item['product_id'] ), $item['product_id'] );
			foreach ( $item['meta'] as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = implode(
						', ',
						array_map(
							static fn( $k, $v ): string => is_int( $k ) ? (string) $v : $k . '=' . $v,
							array_keys( $value ),
							$value
						)
					);
				} elseif ( is_bool( $value ) ) {
					$value = $value ? 'yes' : 'no';
				}
				$lines[] = '   ' . $key . ': ' . (string) $value;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Returns a product's title, for display.
	 *
	 * @param int $id Product ID.
	 *
	 * @return string
	 */
	public static function title( int $id ): string {
		return get_the_title( $id );
	}

	/**
	 * Pairs incoming items with a bundle's existing ones: each incoming item
	 * takes the first unused existing item with the same product, in bundle
	 * order. The same product can be bundled twice, so order breaks the tie;
	 * reordering items doesn't make them new.
	 *
	 * @param array<int, array{product_id: int, menu_order: int}>          $incoming Incoming items, normalized.
	 * @param array<int, array{id: int, product_id: int, menu_order: int}> $existing Existing items.
	 *
	 * @return array<int, int> Incoming index => existing item ID, 0 for a new item.
	 */
	public static function pair( array $incoming, array $existing ): array {
		usort( $existing, static fn( array $a, array $b ): int => array( $a['menu_order'], $a['id'] ) <=> array( $b['menu_order'], $b['id'] ) );

		$free = array();
		foreach ( $existing as $item ) {
			$free[ (int) $item['product_id'] ][] = (int) $item['id'];
		}

		$pairs = array();
		foreach ( $incoming as $index => $item ) {
			$product         = (int) $item['product_id'];
			$pairs[ $index ] = empty( $free[ $product ] ) ? 0 : (int) array_shift( $free[ $product ] );
		}

		return $pairs;
	}

	/**
	 * Returns the existing items no incoming item pairs with: those the sync
	 * removes.
	 *
	 * @param array<int, array{product_id: int, menu_order: int}>          $incoming Incoming items, normalized.
	 * @param array<int, array{id: int, product_id: int, menu_order: int}> $existing Existing items.
	 *
	 * @return array<int, array{id: int, product_id: int, menu_order: int}>
	 */
	public static function removed( array $incoming, array $existing ): array {
		$kept = array_flip( array_filter( self::pair( $incoming, $existing ) ) );

		return array_values( array_filter( $existing, static fn( array $item ): bool => ! isset( $kept[ (int) $item['id'] ] ) ) );
	}

	/**
	 * Says what writing a bundle's items does beyond the diff: the items it
	 * removes, and those it leaves out because their products aren't here.
	 *
	 * @param string[]                  $notes    Notes.
	 * @param array<string, mixed>      $record   Incoming object, as exported.
	 * @param array<string, mixed>|null $incoming Incoming object with local IDs.
	 * @param array<string, mixed>|null $local    Local copy, if any.
	 *
	 * @return string[]
	 */
	public static function plan_notes( array $notes, array $record, ?array $incoming = null, ?array $local = null ): array {
		if ( null === $incoming || ! array_key_exists( self::SECTION, $incoming ) ) {
			return $notes;
		}

		$items   = self::normalize( $incoming[ self::SECTION ] );
		$dropped = count( self::normalize( $record[ self::SECTION ] ?? array() ) ) - count( $items );
		if ( $dropped > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of bundled items. */
				_n(
					'Leaves out %d bundled item: its product is not on this site and not part of this sync.',
					'Leaves out %d bundled items: their products are not on this site and not part of this sync.',
					$dropped,
					'jcore-silta'
				),
				$dropped
			);
		}

		if ( null !== $local && is_array( $local[ self::SECTION ] ?? null ) ) {
			$existing = array();
			foreach ( self::normalize( $local[ self::SECTION ] ) as $index => $item ) {
				$existing[] = array( 'id' => $index + 1 ) + $item;
			}
			$removed = self::removed( $items, $existing );
			if ( ! empty( $removed ) ) {
				$notes[] = sprintf(
					/* translators: %s: product names. */
					__( 'Removes bundled items the source no longer has: %s. Undo puts them back.', 'jcore-silta' ),
					implode( ', ', array_map( static fn( array $item ): string => self::title( $item['product_id'] ) . ' (#' . $item['product_id'] . ')', $removed ) )
				);
			}
		}

		return $notes;
	}

	/**
	 * Opens a write batch.
	 *
	 * @return void
	 */
	public static function writes_start(): void {
		++self::$writing;
	}

	/**
	 * Writes a bundle's items when their section is to be written: on import,
	 * when they changed and are selected, and on undo, from the snapshot.
	 *
	 * @param int                  $id       Local post ID.
	 * @param array<string, mixed> $incoming State written, with local IDs.
	 * @param bool                 $created  Whether the post was just created.
	 * @param bool                 $restore  Whether this restored a snapshot.
	 * @param callable|null        $wants    Whether a section is to be written.
	 *
	 * @return void
	 */
	public static function post_written( int $id, array $incoming, bool $created = false, bool $restore = false, ?callable $wants = null ): void {
		if ( WooCommerce::PRODUCT !== ( $incoming['post_type'] ?? '' ) ) {
			return;
		}
		if ( self::is_bundle( $id ) ) {
			self::$pending[ $id ] = true;
		}
		if ( ! array_key_exists( self::SECTION, $incoming ) || ( null !== $wants && ! $wants( self::SECTION ) ) ) {
			return;
		}

		self::write_items( $id, self::normalize( $incoming[ self::SECTION ] ), $restore );
	}

	/**
	 * Writes a bundle's items through the extension: existing items are
	 * updated (settings the source doesn't have are removed), new ones
	 * created, and those the source no longer has deleted.
	 *
	 * @param int                                                                             $id      Bundle ID.
	 * @param array<int, array{product_id: int, menu_order: int, meta: array<string, mixed>}> $items   Items with local IDs.
	 * @param bool                                                                            $restore Whether this restores a snapshot.
	 *
	 * @return void
	 */
	private static function write_items( int $id, array $items, bool $restore ): void {
		\WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
		$bundle = wc_get_product( $id );
		if ( ! $bundle instanceof \WC_Product_Bundle ) {
			// The product type wasn't written: nothing to put the items on.
			return;
		}

		$current  = $bundle->get_bundled_data_items( 'edit' );
		$existing = array();
		$objects  = array();
		foreach ( $current as $item ) {
			$existing[]                 = array(
				'id'         => (int) $item->get_id(),
				'product_id' => (int) $item->get_product_id(),
				'menu_order' => (int) $item->get_menu_order(),
			);
			$objects[ $item->get_id() ] = $item;
		}
		if ( empty( $items ) && empty( $existing ) ) {
			return;
		}

		$filter = ! $restore && ! Capabilities::unfiltered_html();
		$data   = array();
		foreach ( self::pair( $items, $existing ) as $index => $existing_id ) {
			$meta = self::writable_meta( $items[ $index ]['meta'], $filter );
			if ( $existing_id > 0 ) {
				// The extension merges settings: clear those the source lacks.
				foreach ( array_keys( $objects[ $existing_id ]->get_meta_data() ) as $key ) {
					if ( ! array_key_exists( $key, $meta ) && ! in_array( $key, self::DERIVED_ITEM_META, true ) ) {
						$objects[ $existing_id ]->delete_meta( $key );
					}
				}
			}
			$data[] = array(
				'bundled_item_id' => $existing_id,
				'product_id'      => $items[ $index ]['product_id'],
				'menu_order'      => $items[ $index ]['menu_order'],
				'meta_data'       => $meta,
			);
		}

		$bundle->set_bundled_data_items( $data );
		// Only the items: the product was just written, and the WooCommerce
		// refresh saves it after the batch.
		$bundle->save_items();
		self::$pending[ $id ] = true;
	}

	/**
	 * Returns an item's settings as the extension takes them, with HTML in
	 * the title and description filtered when asked.
	 *
	 * @param array<string, mixed> $meta   Normalized item meta.
	 * @param bool                 $filter Whether to filter HTML.
	 *
	 * @return array<string, mixed>
	 */
	public static function writable_meta( array $meta, bool $filter ): array {
		if ( $filter ) {
			foreach ( self::HTML_META as $key ) {
				if ( is_string( $meta[ $key ] ?? null ) ) {
					$meta[ $key ] = wp_kses_post( $meta[ $key ] );
				}
			}
		}

		return $meta;
	}

	/**
	 * Sets a bundle's base price, which the extension keeps apart from
	 * `_price` (that one holds the bundle's lowest price with its items), the
	 * way its data store does when the regular or sale price changes. Core's
	 * price sync would write the base price into `_price`.
	 *
	 * @param bool        $synced  Whether the price is synced.
	 * @param \WC_Product $product Product.
	 *
	 * @return bool
	 */
	public static function sync_price( bool $synced, \WC_Product $product ): bool {
		if ( $synced || ! $product instanceof \WC_Product_Bundle ) {
			return $synced;
		}

		$base = $product->is_on_sale( 'update-price' ) ? $product->get_sale_price( 'edit' ) : $product->get_regular_price( 'edit' );
		update_post_meta( $product->get_id(), '_wc_pb_base_price', $base );
		$product->set_price( $base );

		return true;
	}

	/**
	 * Hides a bundle whose items are out of stock from catalogs that hide
	 * products out of stock, as the extension does.
	 *
	 * @param string[]    $terms   Visibility term slugs.
	 * @param \WC_Product $product Product.
	 *
	 * @return string[]
	 */
	public static function visibility_terms( array $terms, \WC_Product $product ): array {
		if ( $product instanceof \WC_Product_Bundle && ! in_array( 'outofstock', $terms, true ) && 'instock' === $product->get_stock_status() && 'outofstock' === $product->get_bundled_items_stock_status( 'edit' ) ) {
			$terms[] = 'outofstock';
		}

		return $terms;
	}

	/**
	 * Closes a write batch and brings the written bundles' prices and stock
	 * up to date through the extension, after WooCommerce refreshed every
	 * product of the batch: a bundle's price follows its items' products.
	 *
	 * @return void
	 */
	public static function writes_done(): void {
		self::$writing = max( 0, self::$writing - 1 );
		if ( self::$writing > 0 ) {
			return;
		}

		$ids           = array_keys( self::$pending );
		self::$pending = array();
		foreach ( $ids as $id ) {
			if ( 'trash' === get_post_status( $id ) ) {
				continue;
			}
			try {
				$bundle = wc_get_product( $id );
				if ( ! $bundle instanceof \WC_Product_Bundle ) {
					continue;
				}
				// A fresh object, so the raw prices compare with what is stored:
				// sync() writes `_price`, `_regular_price`, `_sale_price` and
				// `_wc_sw_max_*` where they differ, and the stock status.
				$bundle->sync( true );
				$store = $bundle->get_data_store();
				if ( is_object( $store ) && method_exists( $store, 'save_raw_price_props' ) ) {
					$store->save_raw_price_props( $bundle );
				}
				WooCommerce::refresh_meta_lookup( $bundle );
				wc_delete_product_transients( $id );
			} catch ( \Throwable $e ) {
				// The items were written; the extension's next save or its own
				// sync puts the derived data right.
				continue;
			}
		}
	}
}
