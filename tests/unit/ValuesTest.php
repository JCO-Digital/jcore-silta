<?php
/**
 * Tests for remapping IDs in stored values, ACF meta and known meta keys.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\References\Acf_Values;
use Jcore\Silta\References\Meta_Keys;
use Jcore\Silta\References\Values;
use PHPUnit\Framework\TestCase;

/**
 * Values, Acf_Values and Meta_Keys.
 */
final class ValuesTest extends TestCase {

	/**
	 * Loads guarded WordPress helpers for this test process.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		require_once __DIR__ . '/stubs/incoming.php';
	}

	/**
	 * Visitor: posts 1–9 become 100+, terms 1–9 become 200+, user 1 becomes 7.
	 *
	 * @return callable
	 */
	private function visitor(): callable {
		return static function ( string $kind, int $id ): ?int {
			if ( $id > 9 ) {
				return null;
			}

			return match ( $kind ) {
				'post' => 100 + $id,
				'term' => 200 + $id,
				'user' => 1 === $id ? 7 : null,
			};
		};
	}

	public function test_raw_number(): void {
		$this->assertSame( '103', Values::map_raw( '3', 'post', $this->visitor() ) );
		$this->assertSame( '', Values::map_raw( '42', 'post', $this->visitor() ) );
		$this->assertSame( 'https://x', Values::map_raw( 'https://x', 'post', $this->visitor() ) );
	}

	public function test_raw_serialized_list_keeps_types_and_drops_unresolved(): void {
		$raw = serialize( array( '3', 4, '42' ) );

		$this->assertSame( serialize( array( '103', 104 ) ), Values::map_raw( $raw, 'post', $this->visitor() ) );
	}

	public function test_acf_meta_including_repeater_rows(): void {
		$fields = array(
			'field_image'   => array( 'type' => 'image' ),
			'field_gallery' => array( 'type' => 'gallery' ),
			'field_rows'    => array( 'type' => 'repeater' ),
			'field_row_img' => array( 'type' => 'image' ),
			'field_cats'    => array( 'type' => 'taxonomy' ),
			'field_owner'   => array( 'type' => 'user' ),
			'field_title'   => array( 'type' => 'text' ),
		);

		$meta = array(
			'hero'             => array( '3' ),
			'_hero'            => array( 'field_image' ),
			'gallery'          => array( serialize( array( '4', '5' ) ) ),
			'_gallery'         => array( 'field_gallery' ),
			'rows'             => array( '1' ),
			'_rows'            => array( 'field_rows' ),
			'rows_0_image'     => array( '6' ),
			'_rows_0_image'    => array( 'field_row_img' ),
			'cats'             => array( serialize( array( '2' ) ) ),
			'_cats'            => array( 'field_cats' ),
			'owner'            => array( '1' ),
			'_owner'           => array( 'field_owner' ),
			'title'            => array( '3' ),
			'_title'           => array( 'field_title' ),
			'unrelated_number' => array( '3' ),
		);

		Acf_Values::walk_meta( $meta, $this->visitor(), static fn( string $key ): ?array => $fields[ $key ] ?? null );

		$this->assertSame( array( '103' ), $meta['hero'] );
		$this->assertSame( array( serialize( array( '104', '105' ) ) ), $meta['gallery'] );
		$this->assertSame( array( '1' ), $meta['rows'], 'The repeater row count is not an ID.' );
		$this->assertSame( array( '106' ), $meta['rows_0_image'] );
		$this->assertSame( array( serialize( array( '202' ) ) ), $meta['cats'] );
		$this->assertSame( array( '7' ), $meta['owner'] );
		$this->assertSame( array( '3' ), $meta['title'] );
		$this->assertSame( array( '3' ), $meta['unrelated_number'] );
		$this->assertSame( array( 'field_image' ), $meta['_hero'] );
	}

	public function test_seo_and_filtered_meta_keys(): void {
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_meta_references'] = static function ( array $keys ): array {
			$keys['_jco_related'] = 'post';

			return $keys;
		};

		$meta = array(
			'_yoast_wpseo_opengraph-image-id' => array( '3' ),
			'_yoast_wpseo_primary_category'   => array( '2' ),
			'rank_math_primary_event_type'    => array( '4' ),
			'_yoast_wpseo_metadesc'           => array( '3' ),
			'_jco_related'                    => array( serialize( array( 1, 2 ) ) ),
		);

		Meta_Keys::walk( $meta, $this->visitor() );
		unset( $GLOBALS['jcore_silta_test_filters']['jcore_silta_meta_references'] );

		$this->assertSame( array( '103' ), $meta['_yoast_wpseo_opengraph-image-id'] );
		$this->assertSame( array( '202' ), $meta['_yoast_wpseo_primary_category'] );
		$this->assertSame( array( '204' ), $meta['rank_math_primary_event_type'] );
		$this->assertSame( array( '3' ), $meta['_yoast_wpseo_metadesc'] );
		$this->assertSame( array( serialize( array( 101, 102 ) ) ), $meta['_jco_related'] );
	}

	public function test_walk_path_wildcards(): void {
		$node = json_decode( '{"items":[{"id":1},{"id":42}],"deep":{"a":[2,"3"]}}' );

		Values::walk_path( $node, array( 'items', '*', 'id' ), 'post', $this->visitor() );
		Values::walk_path( $node, array( 'deep', '**' ), 'term', $this->visitor() );

		$this->assertSame( '{"items":[{"id":101},{}],"deep":{"a":[202,"203"]}}', json_encode( $node ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test.
	}

	public function test_serialized_references_are_not_walked(): void {
		$raw = 'a:1:{i:0;R:1;}';
		$this->assertSame( $raw, Values::map_raw( $raw, 'post', $this->visitor() ) );
	}

	public function test_decoded_self_reference_is_dropped(): void {
		$value         = array( 3 );
		$value['self'] = &$value;
		$this->assertSame( array( 103 ), Values::map_value( $value, 'post', $this->visitor() ) );
		Values::walk_path( $value, array( '**' ), 'post', $this->visitor() );
		$this->assertSame( array( 103 ), $value );
	}

	public function test_recursive_json_objects_stop(): void {
		$value       = (object) array( 'id' => 3 );
		$value->self = $value;
		Values::walk_path( $value, array( '**' ), 'post', $this->visitor() );
		$this->assertSame( 103, $value->id );
		$this->assertNull( $value->self );
	}

	public function test_deep_arrays_are_bounded(): void {
		$value = 3;
		for ( $i = 0; $i < 80; ++$i ) {
			$value = array( $value );
		}
		$visited = 0;
		Values::map_value(
			$value,
			'post',
			static function ( string $kind, int $id ) use ( &$visited ): int {
				++$visited;
				return $id;
			}
		);
		$this->assertSame( 0, $visited );
	}
}
