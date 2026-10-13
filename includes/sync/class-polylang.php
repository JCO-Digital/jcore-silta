<?php
/**
 * Polylang languages and translations.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes languages and translation groups through Polylang's API.
 */
final class Polylang {

	/**
	 * Taxonomies Polylang uses internally; never synced as terms.
	 */
	public const TAXONOMIES = array( 'language', 'term_language', 'post_translations', 'term_translations' );

	/**
	 * Whether Polylang is active.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return function_exists( 'pll_get_post_language' ) && function_exists( 'pll_save_post_translations' );
	}

	/**
	 * Returns the language slugs this site has.
	 *
	 * @return string[]
	 */
	public static function languages(): array {
		return self::active() && function_exists( 'pll_languages_list' ) ? array_map( 'strval', (array) pll_languages_list( array( 'fields' => 'slug' ) ) ) : array();
	}

	/**
	 * Returns a post's language and its translations (excluding itself).
	 *
	 * @param int $id Post ID.
	 *
	 * @return array{lang: string, translations: array<string, int>}|null
	 */
	public static function post( int $id ): ?array {
		if ( ! self::active() ) {
			return null;
		}

		$lang = pll_get_post_language( $id, 'slug' );
		if ( ! is_string( $lang ) || '' === $lang ) {
			return null;
		}

		$translations = array();
		foreach ( (array) pll_get_post_translations( $id ) as $slug => $post_id ) {
			if ( (int) $post_id !== $id ) {
				$translations[ (string) $slug ] = (int) $post_id;
			}
		}
		ksort( $translations );

		return array(
			'lang'         => $lang,
			'translations' => $translations,
		);
	}

	/**
	 * Returns a term's language and its translations (excluding itself).
	 *
	 * @param int $id Term ID.
	 *
	 * @return array{lang: string, translations: array<string, int>}|null
	 */
	public static function term( int $id ): ?array {
		if ( ! self::active() || ! function_exists( 'pll_get_term_language' ) ) {
			return null;
		}

		$lang = pll_get_term_language( $id, 'slug' );
		if ( ! is_string( $lang ) || '' === $lang ) {
			return null;
		}

		$translations = array();
		foreach ( (array) pll_get_term_translations( $id ) as $slug => $term_id ) {
			if ( (int) $term_id !== $id ) {
				$translations[ (string) $slug ] = (int) $term_id;
			}
		}
		ksort( $translations );

		return array(
			'lang'         => $lang,
			'translations' => $translations,
		);
	}

	/**
	 * Sets a post's language and links it with its translations, keeping links
	 * to local translations the source doesn't know about.
	 *
	 * @param int                                                   $id       Local post ID.
	 * @param array{lang: string, translations: array<string, int>} $language Language and local translation IDs.
	 *
	 * @return void
	 */
	public static function set_post( int $id, array $language ): void {
		if ( ! self::active() ) {
			return;
		}

		pll_set_post_language( $id, $language['lang'] );

		$group = (array) pll_get_post_translations( $id );
		foreach ( $language['translations'] as $slug => $post_id ) {
			if ( $post_id > 0 && pll_get_post_language( $post_id, 'slug' ) === $slug ) {
				$group[ $slug ] = $post_id;
			}
		}
		$group[ $language['lang'] ] = $id;

		pll_save_post_translations( array_map( 'intval', $group ) );
	}

	/**
	 * Sets a term's language and links it with its translations.
	 *
	 * @param int                                                   $id       Local term ID.
	 * @param array{lang: string, translations: array<string, int>} $language Language and local translation IDs.
	 *
	 * @return void
	 */
	public static function set_term( int $id, array $language ): void {
		if ( ! self::active() || ! function_exists( 'pll_set_term_language' ) ) {
			return;
		}

		pll_set_term_language( $id, $language['lang'] );

		$group = (array) pll_get_term_translations( $id );
		foreach ( $language['translations'] as $slug => $term_id ) {
			if ( $term_id > 0 && pll_get_term_language( $term_id, 'slug' ) === $slug ) {
				$group[ $slug ] = $term_id;
			}
		}
		$group[ $language['lang'] ] = $id;

		pll_save_term_translations( array_map( 'intval', $group ) );
	}
}
