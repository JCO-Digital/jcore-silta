<?php
/**
 * Tests that an attachment is compared by the file that was uploaded, not by
 * the sizes each site generates from it.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Sync\State;
use PHPUnit\Framework\TestCase;

/**
 * Attachment file hashes.
 */
final class AttachmentHashTest extends TestCase {

	/**
	 * An attachment state.
	 *
	 * @param string   $main  Hash of the uploaded file.
	 * @param string[] $sizes Hashes of generated sizes, by file name.
	 *
	 * @return array<string, mixed>
	 */
	private function attachment( string $main, array $sizes ): array {
		$files = array(
			array(
				'path' => '2026/03/photo-scaled.jpg',
				'size' => 10,
				'hash' => $main,
			),
			array(
				'path' => '2026/03/photo.jpg',
				'size' => 20,
				'hash' => 'original',
			),
		);
		foreach ( $sizes as $name => $hash ) {
			$files[] = array(
				'path' => '2026/03/' . $name,
				'size' => 5,
				'hash' => $hash,
			);
		}

		return array(
			'type'      => 'post',
			'id'        => 7,
			'post_type' => 'attachment',
			'fields'    => array( 'post_title' => 'Photo' ),
			'content'   => '',
			'meta'      => array(),
			'terms'     => array(),
			'file'      => array(
				'path'     => '2026/03/photo-scaled.jpg',
				'metadata' => array( 'original_image' => 'photo.jpg' ),
				'files'    => $files,
			),
		);
	}

	public function test_only_the_uploaded_file_and_its_original_count(): void {
		$source = $this->attachment( 'main', array( 'photo-150x150.jpg' => 'thumb' ) );
		$target = $this->attachment(
			'main',
			array(
				'photo-150x150.jpg' => 'thumb',
				// Sizes this site's theme and plugins register, made on arrival.
				'photo-300x300.jpg' => 'woocommerce',
				'photo-32x32.jpg'   => 'search',
			)
		);

		$this->assertSame( array( 'main', 'original' ), State::file_hashes( $source['file'] ) );
		$this->assertSame( State::file_hashes( $source['file'] ), State::file_hashes( $target['file'] ), 'Generated sizes are each site\'s own.' );
		$this->assertSame( State::hash( $source ), State::hash( $target ) );
		$this->assertCount( 5, State::file_hashes( $target['file'], true ) );
	}

	public function test_a_replaced_file_is_a_change(): void {
		$before = $this->attachment( 'main', array( 'photo-150x150.jpg' => 'thumb' ) );
		$after  = $this->attachment( 'edited', array( 'photo-150x150.jpg' => 'thumb' ) );

		$this->assertNotSame( State::file_hashes( $before['file'] ), State::file_hashes( $after['file'] ) );
		$this->assertNotSame( State::hash( $before ), State::hash( $after ) );
	}

	public function test_hashes_stored_with_every_file_still_match(): void {
		$state  = $this->attachment( 'main', array( 'photo-150x150.jpg' => 'thumb' ) );
		$stored = State::legacy_hash( $state );

		$this->assertNotSame( $stored, State::hash( $state ) );
		$this->assertTrue( State::hash_matches( $stored, $state ), 'A mapping stored by an earlier version is not a conflict.' );
		$this->assertFalse( State::hash_matches( $stored, $this->attachment( 'edited', array( 'photo-150x150.jpg' => 'thumb' ) ) ) );
	}

	public function test_an_attachment_without_files_has_no_hashes(): void {
		$this->assertSame( array(), State::file_hashes( null ) );
	}
}
