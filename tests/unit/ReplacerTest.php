<?php
/**
 * Tests for the serialization-safe replacer.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Sync\Replacer;
use PHPUnit\Framework\TestCase;

/**
 * Replacer.
 */
final class ReplacerTest extends TestCase {

	/**
	 * Loads guarded WordPress helpers for this test process.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		require_once __DIR__ . '/stubs/incoming.php';
	}

	/**
	 * A replacer moving staging URLs to production.
	 *
	 * @return Replacer
	 */
	private function sites(): Replacer {
		return Replacer::between(
			array(
				'home'    => 'http://staging.example.com',
				'uploads' => 'http://staging.example.com/wp-content/uploads',
			),
			array(
				'home'    => 'https://example.com',
				'uploads' => 'https://cdn.example.com/uploads',
			)
		);
	}

	public function test_plain_urls(): void {
		$this->assertSame(
			'<a href="https://example.com/about/">About</a>',
			$this->sites()->string( '<a href="http://staging.example.com/about/">About</a>' )
		);
	}

	public function test_uploads_win_over_home(): void {
		$this->assertSame(
			'<img src="https://cdn.example.com/uploads/2026/10/a.jpg">',
			$this->sites()->string( '<img src="http://staging.example.com/wp-content/uploads/2026/10/a.jpg">' )
		);
	}

	public function test_either_scheme_on_the_source(): void {
		$this->assertSame( 'https://example.com/x', $this->sites()->string( 'https://staging.example.com/x' ) );
	}

	public function test_json_escaped_and_url_encoded(): void {
		$this->assertSame( '{"url":"https:\/\/example.com\/x"}', $this->sites()->string( '{"url":"http:\/\/staging.example.com\/x"}' ) );
		$this->assertSame( 'u=https%3A%2F%2Fexample.com%2Fx', $this->sites()->string( 'u=http%3A%2F%2Fstaging.example.com%2Fx' ) );
	}

	public function test_protocol_relative(): void {
		$this->assertSame( '<link href="//example.com/a.css">', $this->sites()->string( '<link href="//staging.example.com/a.css">' ) );
	}

	public function test_serialized_lengths_are_recomputed(): void {
		$value  = serialize(
			array(
				'link' => 'http://staging.example.com/a',
				'n'    => 3,
			)
		);
		$result = $this->sites()->string( $value );

		$this->assertSame(
			array(
				'link' => 'https://example.com/a',
				'n'    => 3,
			),
			unserialize( $result )
		);
	}

	public function test_nested_serialization(): void {
		$inner  = serialize( array( 'http://staging.example.com/deep' ) );
		$outer  = serialize( array( 'inner' => $inner ) );
		$result = unserialize( $this->sites()->string( $outer ) );

		$this->assertSame( array( 'https://example.com/deep' ), unserialize( $result['inner'] ) );
	}

	public function test_objects_are_refused(): void {
		$value = 'O:11:"Some_Widget":2:{s:4:"href";s:28:"http://staging.example.com/x";s:5:"count";i:2;}';
		$this->expectException( \UnexpectedValueException::class );
		$this->sites()->string( $value );
	}

	public function test_custom_serialized_objects_are_refused(): void {
		$value = 'a:1:{i:0;C:3:"Foo":28:{http://staging.example.com/x}}';

		$this->expectException( \UnexpectedValueException::class );
		$this->sites()->string( $value );
	}

	public function test_doubles_with_exponents_survive(): void {
		$value = 'a:2:{i:0;d:1.0E+25;i:1;s:28:"http://staging.example.com/x";}';

		$this->assertSame( 'a:2:{i:0;d:1.0E+25;i:1;s:21:"https://example.com/x";}', $this->sites()->string( $value ) );
	}

	public function test_malformed_serialized_data_falls_back_to_plain_replacement(): void {
		$this->assertSame( 'a:1:{oops https://example.com}', $this->sites()->string( 'a:1:{oops http://staging.example.com}' ) );
	}

	public function test_replacements_are_not_replaced_again(): void {
		$replacer = new Replacer(
			array(
				array( 'cat', 'dog' ),
				array( 'dog', 'bird' ),
			)
		);

		$this->assertSame( 'dog bird', $replacer->string( 'cat dog' ) );
	}

	public function test_user_rules_include_json_escaped_form(): void {
		$replacer = Replacer::between( array(), array(), array( array( '/old-path/', '/new-path/' ) ) );

		$this->assertSame( '{"a":"\/new-path\/x"} /new-path/y', $replacer->string( '{"a":"\/old-path\/x"} /old-path/y' ) );
	}

	public function test_same_sites_need_no_replacement(): void {
		$site = array(
			'home'    => 'https://example.com',
			'uploads' => 'https://example.com/wp-content/uploads',
		);

		$this->assertTrue( Replacer::between( $site, $site )->is_empty() );
	}

	public function test_nested_serialized_objects_are_refused_even_without_rules(): void {
		$this->expectException( \UnexpectedValueException::class );
		( new Replacer() )->string( serialize( array( serialize( (object) array( 'url' => 'x' ) ) ) ) );
	}

	public function test_nested_serialized_strings_share_the_depth_limit(): void {
		$value = 'leaf';
		for ( $i = 0; $i < 70; ++$i ) {
			$value = serialize( $value );
		}
		$this->expectException( \UnexpectedValueException::class );
		( new Replacer() )->string( $value );
	}

	public function test_object_token_text_is_plain_text(): void {
		$this->assertSame( 'Mention O: and C: tokens https://example.com', $this->sites()->string( 'Mention O: and C: tokens http://staging.example.com' ) );
		$this->assertSame( serialize( 'O: and C: tokens' ), ( new Replacer() )->string( serialize( 'O: and C: tokens' ) ) );
	}

	/**
	 * Declared lengths cannot overflow tokenizer offsets.
	 *
	 * @return void
	 */
	public function test_oversized_string_length_does_not_overflow(): void {
		$value = 's:999999999999999999999999:"x";';
		$this->assertSame( $value, ( new Replacer() )->string( $value ) );
	}

	/**
	 * Invalid replacement rules cannot reach string operations.
	 *
	 * @return void
	 */
	public function test_malformed_rules_are_ignored(): void {
		$replacer = Replacer::between( array(), array(), array( 'bad', array( array(), 'x' ), array( 'old', 'new' ) ) );
		$this->assertSame( 'new', $replacer->string( 'old' ) );
	}

	public function test_mapping_passes_every_string_through_the_callback(): void {
		$upper = Replacer::mapping( 'strtoupper' );
		$this->assertFalse( $upper->is_empty() );
		$this->assertSame( 'PLAIN', $upper->string( 'plain' ) );

		$value = serialize(
			array(
				'key'  => 'value',
				'n'    => 1,
				'deep' => serialize( array( 'inner' ) ),
			)
		);
		$this->assertSame(
			array(
				'KEY'  => 'VALUE',
				'N'    => 1,
				'DEEP' => serialize( array( 'INNER' ) ),
			),
			unserialize( $upper->string( $value ) )
		);

		$this->expectException( \UnexpectedValueException::class );
		$upper->string( 'a:1:{i:0;O:8:"stdClass":0:{}}' );
	}
}
