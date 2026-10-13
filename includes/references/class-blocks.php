<?php
/**
 * IDs in block markup.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\References;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds and remaps the object IDs in serialized blocks.
 *
 * Only the block comments whose attributes actually change are re-encoded, and
 * with core's own encoder, so content that doesn't change stays byte for byte
 * what the source stored.
 */
final class Blocks {

	/**
	 * Block comment delimiter, as core's block parser tokenizes it.
	 */
	private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Attribute paths holding IDs, per block. See Values::walk_path() for the
	 * path syntax. `nav` resolves to post or term by the block's `kind`.
	 */
	private const PATHS = array(
		'core/image'              => array( 'id' => 'post' ),
		'core/gallery'            => array( 'ids.*' => 'post' ),
		'core/cover'              => array( 'id' => 'post' ),
		'core/media-text'         => array( 'mediaId' => 'post' ),
		'core/file'               => array( 'id' => 'post' ),
		'core/video'              => array( 'id' => 'post' ),
		'core/audio'              => array( 'id' => 'post' ),
		'core/block'              => array( 'ref' => 'post' ),
		'core/navigation'         => array( 'ref' => 'post' ),
		'core/navigation-link'    => array( 'id' => 'nav' ),
		'core/navigation-submenu' => array( 'id' => 'nav' ),
		'core/page-list'          => array( 'parentPageID' => 'post' ),
		'core/latest-posts'       => array( 'categories.*.id' => 'term' ),
		'core/query'              => array(
			'query.include.*'   => 'post',
			'query.exclude.*'   => 'post',
			'query.parents.*'   => 'post',
			'query.taxQuery.**' => 'term',
		),
	);

	/**
	 * Applies a visitor to every ID in the content.
	 *
	 * @param string   $content Post content.
	 * @param callable $visit   Visitor.
	 * @param callable $field   ACF field resolver, for ACF block data.
	 *
	 * @return string
	 */
	public static function walk( string $content, callable $visit, callable $field ): string {
		if ( '' === $content ) {
			return $content;
		}

		$paths = self::paths();

		if ( str_contains( $content, '<!-- wp:' ) ) {
			$content = (string) preg_replace_callback(
				self::DELIMITER,
				static function ( array $token ) use ( $visit, $field, $paths ): string {
					return self::block( $token, $visit, $field, $paths );
				},
				$content
			);
		}

		// Image classes and gallery data attributes in the saved markup.
		$content = (string) preg_replace_callback(
			'/\bwp-image-(\d+)\b/',
			static function ( array $token ) use ( $visit ): string {
				$id = $visit( 'post', (int) $token[1] );

				return null === $id ? '' : 'wp-image-' . $id;
			},
			$content
		);

		return (string) preg_replace_callback(
			'/(<img\b[^>]*?\sdata-id=")(\d+)(")/',
			static function ( array $token ) use ( $visit ): string {
				$id = $visit( 'post', (int) $token[2] );

				return null === $id ? '' : $token[1] . $id . $token[3];
			},
			$content
		);
	}

	/**
	 * Remaps one block comment.
	 *
	 * @param array<string, string>                $token Delimiter match.
	 * @param callable                             $visit Visitor.
	 * @param callable                             $field ACF field resolver.
	 * @param array<string, array<string, string>> $paths Attribute paths per block.
	 *
	 * @return string
	 */
	private static function block( array $token, callable $visit, callable $field, array $paths ): string {
		if ( '' !== ( $token['closer'] ?? '' ) || empty( $token['attrs'] ) ) {
			return $token[0];
		}

		$attrs = json_decode( $token['attrs'] );
		if ( ! is_object( $attrs ) ) {
			return $token[0];
		}

		$name    = ( '' !== ( $token['namespace'] ?? '' ) ? $token['namespace'] : 'core/' ) . $token['name'];
		$changed = false;
		$track   = static function ( string $kind, int $id ) use ( $visit, &$changed ): ?int {
			$new = $visit( $kind, $id );
			if ( $new !== $id ) {
				$changed = true;
			}

			return $new;
		};

		foreach ( $paths[ $name ] ?? array() as $path => $kind ) {
			if ( 'nav' === $kind ) {
				$kind = match ( $attrs->kind ?? '' ) {
					'post-type' => 'post',
					'taxonomy' => 'term',
					default => '',
				};
				if ( '' === $kind ) {
					continue;
				}
			}
			Values::walk_path( $attrs, explode( '.', $path ), $kind, $track );
		}

		if ( str_starts_with( $name, 'acf/' ) && isset( $attrs->data ) ) {
			Acf_Values::walk_data( $attrs->data, $track, $field );
		}

		if ( ! $changed ) {
			return $token[0];
		}

		$void       = '' !== ( $token['void'] ?? '' ) ? '/' : '';
		$attributes = (array) $attrs;
		$encoded    = empty( $attributes ) ? '' : serialize_block_attributes( $attributes ) . ' ';

		return '<!-- wp:' . ( $token['namespace'] ?? '' ) . $token['name'] . ' ' . $encoded . $void . '-->';
	}

	/**
	 * Returns the attribute paths, with the filter applied.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function paths(): array {
		/**
		 * Filters which block attributes hold object IDs.
		 *
		 * Keys are block names; values map an attribute path to post, term or
		 * user. Path segments are attribute names, `*` for every element of a list
		 * and `**` for every number below. Example:
		 * `$paths['jco/hero'] = array( 'imageId' => 'post', 'posts.*' => 'post' );`
		 *
		 * @param array<string, array<string, string>> $paths Paths per block.
		 */
		return (array) apply_filters( 'jcore_silta_block_references', self::PATHS );
	}
}
