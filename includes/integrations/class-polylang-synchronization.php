<?php
/**
 * Polylang: its copying between translations.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps Polylang, and Polylang for WooCommerce, from copying between
 * translations while Silta writes.
 *
 * When a post is saved, Polylang copies the custom fields, terms and post
 * fields it is set to synchronize to the post's translations, translating
 * the IDs in them. Polylang for WooCommerce always adds the product fields
 * to that (prices, gallery, featured image, upsells, cross-sells), and on
 * every product save also copies variations, attributes, stock status and
 * bundled items, saves the translations, and deletes from a translated
 * bundle the items whose product has no translation in that language.
 *
 * That is right when an editor saves one language version. During a sync it
 * works against it, because Silta writes each language version as it is on
 * the source, one after the other:
 *
 * - Saving the second version copies its fields, as they are on this site
 *   before the sync has written them, over the first version that was just
 *   synced: galleries emptied, featured images and upsells removed,
 *   categories replaced.
 * - Translations that are not part of the sync are changed too.
 * - Translations of posts the sync creates are only linked once all of them
 *   exist. Until then an ID has no translation to be copied as, and is
 *   dropped.
 *
 * So for the duration of a write batch (an import or undo, and the
 * WooCommerce refresh after it) nothing is copied: the lists of fields and
 * taxonomies to synchronize are empty, and the callbacks that copy post
 * fields and product data are taken off their hooks. Languages and
 * translation links are written by Silta itself (Sync\Polylang).
 */
final class Polylang_Synchronization {

	/**
	 * Polylang's filters for what to copy: custom fields of posts and terms,
	 * and taxonomies. Their second argument says whether it is a
	 * synchronization (true) or the one-time copy to a new translation.
	 */
	private const LISTS = array( 'pll_copy_post_metas', 'pll_copy_term_metas', 'pll_copy_taxonomies' );

	/**
	 * Callbacks that copy without asking those filters, by hook: the class
	 * they belong to and the method.
	 */
	private const CALLBACKS = array(
		// Post date, parent, menu order and comment settings.
		array( 'pll_save_post', 'PLL_Sync', 'pll_save_post' ),
		// Polylang for WooCommerce: variations and bundled items.
		array( 'woocommerce_update_product', 'PLLWC_Products', 'save_product' ),
		array( 'woocommerce_new_product_variation', 'PLLWC_Products', 'save_variation' ),
		array( 'woocommerce_update_product_variation', 'PLLWC_Products', 'save_variation' ),
		// Polylang for WooCommerce: attributes and the stock status, saving each translation.
		array( 'woocommerce_after_product_object_save', 'PLLWC_Products', 'copy_product' ),
	);

	/**
	 * How many write batches are open.
	 *
	 * @var int
	 */
	private static int $writing = 0;

	/**
	 * Callbacks taken off: hook, callback, priority, accepted arguments.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int, 3: int}>
	 */
	private static array $suspended = array();

	/**
	 * Hooks the integration. Harmless without Polylang.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Around everything a batch does: before the first write, and after
		// the WooCommerce (10) and Product Bundles (20) refreshes.
		add_action( 'jcore_silta_writes_start', array( self::class, 'suspend' ), -100, 0 );
		add_action( 'jcore_silta_writes_done', array( self::class, 'resume' ), 100, 0 );

		// After everyone who adds to the lists.
		foreach ( self::LISTS as $filter ) {
			add_filter( $filter, array( self::class, 'nothing_while_writing' ), PHP_INT_MAX, 2 );
		}
	}

	/**
	 * Whether a write batch is open.
	 *
	 * @return bool
	 */
	public static function writing(): bool {
		return self::$writing > 0;
	}

	/**
	 * Empties a list of things to synchronize while Silta writes. The copy
	 * Polylang makes once, when a translation is created, is left alone.
	 *
	 * @param mixed $names Custom field names or taxonomies.
	 * @param mixed $sync  Whether this is a synchronization.
	 *
	 * @return mixed
	 */
	public static function nothing_while_writing( mixed $names, mixed $sync = false ): mixed {
		return self::writing() && $sync ? array() : $names;
	}

	/**
	 * Opens a write batch: takes the copying callbacks off their hooks.
	 *
	 * @return void
	 */
	public static function suspend(): void {
		++self::$writing;
		if ( 1 !== self::$writing ) {
			return;
		}

		foreach ( self::CALLBACKS as $copier ) {
			foreach ( self::callbacks( $copier[0], $copier[1], $copier[2] ) as $found ) {
				remove_action( $copier[0], $found[0], $found[1] );
				self::$suspended[] = array( $copier[0], $found[0], $found[1], $found[2] );
			}
		}
	}

	/**
	 * Closes a write batch: puts the callbacks back after the last one.
	 *
	 * @return void
	 */
	public static function resume(): void {
		self::$writing = max( 0, self::$writing - 1 );
		if ( self::$writing > 0 ) {
			return;
		}

		foreach ( self::$suspended as $callback ) {
			add_action( $callback[0], $callback[1], $callback[2], $callback[3] );
		}
		self::$suspended = array();
	}

	/**
	 * Finds a class's callbacks for a method on a hook.
	 *
	 * @param string $hook   Hook.
	 * @param string $owner  Class the callback's object is an instance of.
	 * @param string $method Method name.
	 *
	 * @return array<int, array{0: callable, 1: int, 2: int}> Callback, priority, accepted arguments.
	 */
	private static function callbacks( string $hook, string $owner, string $method ): array {
		global $wp_filter;

		$found = array();
		if ( ! class_exists( $owner, false ) || ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return $found;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && is_object( $function[0] ?? null ) && is_a( $function[0], $owner ) && $method === ( $function[1] ?? '' ) ) {
					$found[] = array( $function, (int) $priority, (int) ( $callback['accepted_args'] ?? 1 ) );
				}
			}
		}

		return $found;
	}
}
