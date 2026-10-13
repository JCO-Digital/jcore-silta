<?php
/**
 * Tests for remapping IDs in block markup.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\References\Blocks;
use PHPUnit\Framework\TestCase;

/**
 * Blocks.
 */
final class BlocksTest extends TestCase {

	/**
	 * Remote ID => local ID, per kind. Anything else is unresolved.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $map = array(
		'post' => array(
			10 => 110,
			11 => 111,
			12 => 112,
			20 => 120,
		),
		'term' => array(
			5 => 105,
			6 => 106,
		),
	);

	/**
	 * Visitor mapping through $map; null for unknown IDs.
	 *
	 * @return callable
	 */
	private function visitor(): callable {
		return fn( string $kind, int $id ): ?int => $this->map[ $kind ][ $id ] ?? null;
	}

	/**
	 * ACF field resolver for the tests' field keys.
	 *
	 * @return callable
	 */
	private function fields(): callable {
		$types = array(
			'field_card_image' => 'image',
			'field_card_post'  => 'post_object',
			'field_card_rows'  => 'repeater',
			'field_row_image'  => 'image',
			'field_card_text'  => 'text',
		);

		return static fn( string $key ): ?array => isset( $types[ $key ] ) ? array( 'type' => $types[ $key ] ) : null;
	}

	/**
	 * Walks content with the test visitor.
	 *
	 * @param string $content Content.
	 *
	 * @return string
	 */
	private function walk( string $content ): string {
		return Blocks::walk( $content, $this->visitor(), $this->fields() );
	}

	public function test_image_block_attribute_and_class(): void {
		$content = '<!-- wp:image {"id":10,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="a.jpg" alt="" class="wp-image-10"/></figure><!-- /wp:image -->';

		$this->assertSame(
			'<!-- wp:image {"id":110,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="a.jpg" alt="" class="wp-image-110"/></figure><!-- /wp:image -->',
			$this->walk( $content )
		);
	}

	public function test_untouched_blocks_stay_byte_identical(): void {
		$content = "<!-- wp:paragraph {\"style\":{\"spacing\":{}}} -->\n<p>Hello &amp; \u{00e4}</p>\n<!-- /wp:paragraph -->";

		$this->assertSame( $content, $this->walk( $content ) );
	}

	public function test_gallery_ids_and_inner_images(): void {
		$content = '<!-- wp:gallery {"ids":[10,11],"linkTo":"none"} --><figure class="wp-block-gallery"><!-- wp:image {"id":10} --><figure><img src="a.jpg" data-id="10" class="wp-image-10"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->';

		$result = $this->walk( $content );

		$this->assertStringContainsString( '<!-- wp:gallery {"ids":[110,111],"linkTo":"none"} -->', $result );
		$this->assertStringContainsString( '<!-- wp:image {"id":110} -->', $result );
		$this->assertStringContainsString( 'data-id="110"', $result );
		$this->assertStringContainsString( 'class="wp-image-110"', $result );
	}

	public function test_data_id_outside_images_is_left_alone(): void {
		$content = '<div data-id="10">Not an attachment</div>';

		$this->assertSame( $content, $this->walk( $content ) );
	}

	public function test_unresolved_ids_are_dropped(): void {
		$content = '<!-- wp:image {"id":99,"sizeSlug":"large"} --><figure><img src="a.jpg" class="wp-image-99"/></figure><!-- /wp:image -->';

		$this->assertSame(
			'<!-- wp:image {"sizeSlug":"large"} --><figure><img src="a.jpg" class=""/></figure><!-- /wp:image -->',
			$this->walk( $content )
		);
	}

	public function test_reusable_block_reference_and_void_block(): void {
		$this->assertSame( '<!-- wp:block {"ref":120} /-->', $this->walk( '<!-- wp:block {"ref":20} /-->' ) );
	}

	public function test_navigation_link_follows_its_kind(): void {
		$this->assertSame(
			'<!-- wp:navigation-link {"label":"Cat","type":"category","id":105,"kind":"taxonomy"} /-->',
			$this->walk( '<!-- wp:navigation-link {"label":"Cat","type":"category","id":5,"kind":"taxonomy"} /-->' )
		);
		$this->assertSame(
			'<!-- wp:navigation-link {"label":"X","url":"/x","kind":"custom","id":5} /-->',
			$this->walk( '<!-- wp:navigation-link {"label":"X","url":"/x","kind":"custom","id":5} /-->' )
		);
	}

	public function test_query_block(): void {
		$result = $this->walk( '<!-- wp:query {"query":{"include":[10,12],"taxQuery":{"category":[5,6]},"perPage":3}} --><!-- /wp:query -->' );

		$this->assertSame( '<!-- wp:query {"query":{"include":[110,112],"taxQuery":{"category":[105,106]},"perPage":3}} --><!-- /wp:query -->', $result );
	}

	public function test_acf_block_data_by_name(): void {
		$content = '<!-- wp:acf/silta-card {"name":"acf/silta-card","data":{"image":10,"_image":"field_card_image","post":"20","_post":"field_card_post","text":"10","_text":"field_card_text","rows":1,"_rows":"field_card_rows","rows_0_image":11,"_rows_0_image":"field_row_image"},"mode":"preview"} /-->';

		$result = $this->walk( $content );

		$this->assertStringContainsString( '"image":110', $result );
		$this->assertStringContainsString( '"post":"120"', $result );
		$this->assertStringContainsString( '"text":"10"', $result );
		$this->assertStringContainsString( '"rows_0_image":111', $result );
	}

	public function test_acf_block_data_by_field_key(): void {
		$content = '<!-- wp:acf/silta-card {"name":"acf/silta-card","data":{"field_card_image":"10","field_card_rows":{"row-0":{"field_row_image":"12"}}}} /-->';

		$result = $this->walk( $content );

		$this->assertStringContainsString( '"field_card_image":"110"', $result );
		$this->assertStringContainsString( '"field_row_image":"112"', $result );
	}

	public function test_filter_adds_block_paths(): void {
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_block_references'] = static function ( array $paths ): array {
			$paths['jco/hero'] = array(
				'imageId'   => 'post',
				'posts.*'   => 'post',
				'nested.**' => 'term',
			);

			return $paths;
		};

		$result = $this->walk( '<!-- wp:jco/hero {"imageId":10,"posts":[11,12],"nested":{"a":{"b":[5]}}} /-->' );
		unset( $GLOBALS['jcore_silta_test_filters']['jcore_silta_block_references'] );

		$this->assertSame( '<!-- wp:jco/hero {"imageId":110,"posts":[111,112],"nested":{"a":{"b":[105]}}} /-->', $result );
	}

	public function test_special_characters_are_encoded_like_core(): void {
		// Core escapes <, &, -- and quotes in block attributes as \u sequences.
		$escaped = str_replace( '%', chr( 92 ), '"a %u003c b %u0026 c %u002d%u002d d"' );
		$result  = $this->walk( '<!-- wp:image {"id":10,"alt":' . $escaped . '} --><!-- /wp:image -->' );

		$this->assertSame( '<!-- wp:image {"id":110,"alt":' . $escaped . '} --><!-- /wp:image -->', $result );
	}
}
