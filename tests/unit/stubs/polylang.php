<?php
/**
 * Stand-ins for Polylang's API, for tests that run in a process of their own:
 * once these exist, Sync\Polylang counts as active.
 *
 * @package Jcore\Silta\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Stand-ins for another plugin's functions.

if ( ! function_exists( 'pll_get_post_language' ) ) {
	/**
	 * Returns a fixture post's language.
	 *
	 * @param int    $id    Post ID.
	 * @param string $field Field.
	 *
	 * @return string|false
	 */
	function pll_get_post_language( int $id, string $field = 'slug' ): string|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Polylang's signature.
		return $GLOBALS['jcore_silta_test_languages'][ $id ] ?? false;
	}
}

if ( ! function_exists( 'pll_get_post_translations' ) ) {
	/**
	 * Returns no translations: the tests set none.
	 *
	 * @param int $id Post ID.
	 *
	 * @return array<string, int>
	 */
	function pll_get_post_translations( int $id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Polylang's signature.
		return array();
	}
}

if ( ! function_exists( 'pll_save_post_translations' ) ) {
	/**
	 * Accepts translations.
	 *
	 * @param array<string, int> $translations Translations.
	 *
	 * @return void
	 */
	function pll_save_post_translations( array $translations ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Polylang's signature.
}

if ( ! function_exists( 'PLL' ) ) {
	/**
	 * Returns Polylang's main object, with the options the tests set.
	 *
	 * @return object
	 */
	function PLL(): object { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Polylang's name.
		return (object) array( 'options' => $GLOBALS['jcore_silta_test_polylang_options'] ?? array() );
	}
}
