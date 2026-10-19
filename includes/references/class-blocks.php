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
	 * path syntax. `nav` resolves to post or term by the block's `kind`, and
	 * `slug:<post type>` marks a top-level attribute holding the slug of a post
	 * of that type (see slug()).
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
	 * @param string        $content Post content.
	 * @param callable      $visit   Visitor.
	 * @param callable      $field   ACF field resolver, for ACF block data.
	 * @param callable|null $slug    Slug resolver: ( string $post_type, string $slug ): ?int,
	 *                               the ID of the post a slug attribute names, in the
	 *                               visitor's IDs. Defaults to a lookup on this site.
	 *
	 * @return string
	 */
	public static function walk( string $content, callable $visit, callable $field, ?callable $slug = null ): string {
		if ( '' === $content ) {
			return $content;
		}

		$paths  = self::paths();
		$slug ??= array( self::class, 'local_slug' );

		if ( str_contains( $content, '<!-- wp:' ) ) {
			$content = (string) preg_replace_callback(
				self::DELIMITER,
				static function ( array $token ) use ( $visit, $field, $slug, $paths ): string {
					return self::block( $token, $visit, $field, $slug, $paths );
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
	 * @param callable                             $slug  Slug resolver.
	 * @param array<string, array<string, string>> $paths Attribute paths per block.
	 *
	 * @return string
	 */
	private static function block( array $token, callable $visit, callable $field, callable $slug, array $paths ): string {
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
			if ( str_starts_with( (string) $kind, 'slug:' ) ) {
				if ( self::slug( $attrs, (string) $path, substr( (string) $kind, 5 ), $visit, $slug ) ) {
					$changed = true;
				}
				continue;
			}
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
	 * Visits the post a slug attribute names, and rewrites the slug when the
	 * visitor maps the post to another one, so the block names the same post
	 * by the slug it has here. Blocks such as `jco/global-content` look their
	 * post up by slug first and fall back to an ID attribute.
	 *
	 * A slug that names no post is left as it is, and so is one whose post
	 * maps to the same ID or doesn't resolve: the post may still be on its way.
	 *
	 * @param object   $attrs     Block attributes, modified in place.
	 * @param string   $attribute Attribute name.
	 * @param string   $post_type Post type the slug belongs to.
	 * @param callable $visit     Visitor.
	 * @param callable $slug      Slug resolver.
	 *
	 * @return bool Whether the attribute changed.
	 */
	private static function slug( object $attrs, string $attribute, string $post_type, callable $visit, callable $slug ): bool {
		$value = $attrs->$attribute ?? null;
		if ( '' === $post_type || ! is_string( $value ) || '' === $value ) {
			return false;
		}

		$id = $slug( $post_type, $value );
		if ( ! is_int( $id ) || $id <= 0 ) {
			return false;
		}

		$new = $visit( 'post', $id );
		if ( ! is_int( $new ) || $new <= 0 || $new === $id || get_post_type( $new ) !== $post_type ) {
			return false;
		}

		$name = (string) get_post_field( 'post_name', $new );
		if ( '' === $name || $name === $value ) {
			return false;
		}
		$attrs->$attribute = $name;

		return true;
	}

	/**
	 * Finds the post a slug names on this site, the way `get_global_content()`
	 * in jcore-maailma does.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug      Slug.
	 *
	 * @return int|null
	 */
	public static function local_slug( string $post_type, string $slug ): ?int {
		$slug = sanitize_title( $slug );
		$post = '' !== $slug ? get_page_by_path( $slug, OBJECT, $post_type ) : null;

		return is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : null;
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
		 * and `**` for every number below. A top-level attribute holding a post
		 * slug maps to `slug:<post type>`. Example:
		 * `$paths['jco/hero'] = array( 'imageId' => 'post', 'posts.*' => 'post' );`
		 *
		 * @param array<string, array<string, string>> $paths Paths per block.
		 */
		return (array) apply_filters( 'jcore_silta_block_references', self::PATHS );
	}
}
