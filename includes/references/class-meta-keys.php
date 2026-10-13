<?php
/**
 * IDs in known meta keys.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\References;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remaps meta keys known to hold IDs: SEO plugins' images and primary terms,
 * plus whatever themes and plugins declare through the filter.
 */
final class Meta_Keys {

	/**
	 * Meta keys (a trailing * matches a prefix) and the kind of ID they hold.
	 */
	private const KEYS = array(
		'_yoast_wpseo_opengraph-image-id'            => 'post',
		'_yoast_wpseo_twitter-image-id'              => 'post',
		'_yoast_wpseo_primary_*'                     => 'term',
		'rank_math_facebook_image_id'                => 'post',
		'rank_math_twitter_image_id'                 => 'post',
		'rank_math_primary_*'                        => 'term',
		'_seopress_social_fb_img_attachment_id'      => 'post',
		'_seopress_social_twitter_img_attachment_id' => 'post',
		'_seopress_robots_primary_cat'               => 'term',
	);

	/**
	 * Remaps the known keys in raw meta.
	 *
	 * @param array<string, string[]> $meta  Raw meta, modified in place.
	 * @param callable                $visit Visitor.
	 *
	 * @return void
	 */
	public static function walk( array &$meta, callable $visit ): void {
		$patterns = self::patterns();

		foreach ( $meta as $key => $values ) {
			$kind = self::kind( (string) $key, $patterns );
			if ( null === $kind ) {
				continue;
			}

			$meta[ $key ] = array_map(
				static function ( string $raw ) use ( $kind, $visit ): string {
					return Values::map_raw( $raw, $kind, $visit );
				},
				$values
			);
		}
	}

	/**
	 * Returns the kind of ID a key holds.
	 *
	 * @param string                $key      Meta key.
	 * @param array<string, string> $patterns Patterns.
	 *
	 * @return string|null
	 */
	private static function kind( string $key, array $patterns ): ?string {
		if ( isset( $patterns[ $key ] ) ) {
			return $patterns[ $key ];
		}

		foreach ( $patterns as $pattern => $kind ) {
			if ( str_ends_with( $pattern, '*' ) && str_starts_with( $key, substr( $pattern, 0, -1 ) ) ) {
				return $kind;
			}
		}

		return null;
	}

	/**
	 * Returns the patterns, with the filter applied.
	 *
	 * @return array<string, string>
	 */
	private static function patterns(): array {
		/**
		 * Filters the meta keys that hold object IDs.
		 *
		 * Keys are meta keys (a trailing * matches a prefix); values are post,
		 * term or user. The value may be a single ID or a serialized list.
		 * Example: `$keys['_jco_related_post'] = 'post';`
		 *
		 * @param array<string, string> $keys Meta keys and kinds.
		 */
		return (array) apply_filters( 'jcore_silta_meta_references', self::KEYS );
	}
}
