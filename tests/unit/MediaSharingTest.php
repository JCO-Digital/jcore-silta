<?php
/**
 * Tests for media translations sharing one file, without a WordPress database.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Sync\Media;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WP_Error;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Isolated temporary test files.
// phpcs:disable WordPress.WP.GlobalVariablesOverride -- Isolated database fixture.

/**
 * A new attachment shares the file of a translation that is here already,
 * and only of one. In a process of its own: the Polylang stand-ins make
 * Polylang count as active.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class MediaSharingTest extends TestCase {

	private const PEER = 'peer-a';

	/**
	 * The file both translations have on the source.
	 */
	private const PATH = '2026/03/photo.png';

	/**
	 * Isolated upload directory.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Valid PNG fixture.
	 *
	 * @var string
	 */
	private string $png;

	/**
	 * Creates a fresh upload directory and database, on a site where Polylang
	 * translates media.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		require_once __DIR__ . '/stubs/media.php';
		require_once __DIR__ . '/stubs/polylang.php';

		$this->dir = sys_get_temp_dir() . '/jcore-silta-sharing-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->dir );
		$this->png                               = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=' );
		$GLOBALS['jcore_silta_media_test_dir']   = $this->dir;
		$GLOBALS['jcore_silta_test_options']     = array();
		$GLOBALS['jcore_silta_media_test_meta']  = array();
		$GLOBALS['jcore_silta_media_test_posts'] = array();
		$GLOBALS['jcore_silta_test_actions']     = array();
		$GLOBALS['wpdb']                         = new MediaDatabaseStub();

		$GLOBALS['jcore_silta_test_languages']        = array();
		$GLOBALS['jcore_silta_test_polylang_options'] = array( 'media_support' => true );
	}

	/**
	 * Deletes the fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->delete_tree( $this->dir );
		unset( $GLOBALS['jcore_silta_media_test_posts'], $GLOBALS['wpdb'], $GLOBALS['jcore_silta_test_filters']['jcore_silta_media_share_translations'] );
		unset( $GLOBALS['jcore_silta_test_languages'], $GLOBALS['jcore_silta_test_polylang_options'] );
		$GLOBALS['jcore_silta_test_actions'] = array();
	}

	/**
	 * Recursively removes fixture files.
	 *
	 * @param string $dir Directory.
	 *
	 * @return void
	 */
	private function delete_tree( string $dir ): void {
		foreach ( scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->delete_tree( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * The incoming attachment: the Swedish copy (source ID 8) of an image
	 * whose Finnish copy is source ID 7.
	 *
	 * @param array<string, int> $translations Translations the source lists.
	 * @param string             $bytes        Bytes of the uploaded file.
	 *
	 * @return array<string, mixed>
	 */
	private function record( array $translations = array( 'fi' => 7 ), string $bytes = '' ): array {
		$bytes = '' === $bytes ? $this->png : $bytes;

		return array(
			'type'      => 'post',
			'key'       => 'post:8',
			'id'        => 8,
			'post_type' => 'attachment',
			'language'  => array(
				'lang'         => 'sv',
				'translations' => $translations,
			),
			'file'      => array(
				'path'     => self::PATH,
				'metadata' => array(
					'sizes' => array(
						'thumbnail' => array( 'file' => 'photo-150x150.png' ),
						'large'     => array( 'file' => 'photo-1024x1024.png' ),
					),
				),
				'files'    => array(
					array(
						'path' => self::PATH,
						'size' => strlen( $bytes ),
						'hash' => sha1( $bytes ),
					),
					array(
						'path' => '2026/03/photo-150x150.png',
						'size' => strlen( $this->png ),
						'hash' => sha1( $this->png ),
					),
					array(
						'path' => '2026/03/photo-1024x1024.png',
						'size' => strlen( $this->png ),
						'hash' => sha1( $this->png ),
					),
				),
			),
		);
	}

	/**
	 * Puts the Finnish copy on this site as attachment 70: its file (under a
	 * name of its own, as when another photo.png was here first) and a
	 * thumbnail, mapped from the source's attachment 7, which a sync from
	 * that site brought here.
	 *
	 * @param string $peer     Site the mapping is for.
	 * @param bool   $received Whether its file came from that site.
	 *
	 * @return void
	 */
	private function sibling( string $peer = self::PEER, bool $received = true ): void {
		if ( ! is_dir( $this->dir . '/2026/03' ) ) {
			mkdir( $this->dir . '/2026/03', 0777, true );
		}
		file_put_contents( $this->dir . '/2026/03/photo-1.png', $this->png );
		file_put_contents( $this->dir . '/2026/03/photo-1-150x150.png', $this->png );

		$GLOBALS['jcore_silta_media_test_posts'][70] = new \WP_Post();
		$GLOBALS['jcore_silta_media_test_meta'][70]  = array(
			'_wp_attached_file'       => '2026/03/photo-1.png',
			'_wp_attachment_metadata' => array(
				'file'  => '2026/03/photo-1.png',
				'sizes' => array( 'thumbnail' => array( 'file' => 'photo-1-150x150.png' ) ),
			),
		);
		$GLOBALS['wpdb']->map[ $peer . ':7' ]        = 70;
		$GLOBALS['jcore_silta_test_languages'][70]   = 'fi';
		if ( $received ) {
			$GLOBALS['wpdb']->received[ $peer . ':7' ] = array(
				'local'  => 70,
				'hashes' => array( sha1( $this->png ) ),
			);
		}
	}

	/**
	 * Lists the files in the uploads month folder.
	 *
	 * @return string[]
	 */
	private function files(): array {
		$files = is_dir( $this->dir . '/2026/03' ) ? array_values( array_diff( scandir( $this->dir . '/2026/03' ), array( '.', '..' ) ) ) : array();
		sort( $files );

		return $files;
	}

	public function test_a_translation_that_is_here_shares_its_file_and_nothing_is_sent(): void {
		$this->sibling();
		$record = $this->record();

		$prepared = Media::prepare( $record, 0, self::PEER );
		$this->assertSame( '2026/03/photo-1.png', $prepared['main'] );
		$this->assertSame( array( false, false ), array_column( $prepared['files'], 'needed' ), 'The file and the size both have: neither is asked for.' );

		$finished = Media::finalize( $prepared['transfer'], self::PEER, $record );
		$this->assertSame( 70, $finished['shared'] );
		$this->assertSame( '2026/03/photo-1.png', $finished['main'] );
		$this->assertSame( 'image/png', $finished['mime'] );
		$this->assertSame(
			array(
				self::PATH                  => '2026/03/photo-1.png',
				'2026/03/photo-150x150.png' => '2026/03/photo-1-150x150.png',
			),
			$finished['map'],
			'Content pointing at the source\'s files is rewritten to the shared ones.'
		);
		$this->assertSame( $GLOBALS['jcore_silta_media_test_meta'][70]['_wp_attachment_metadata'], $finished['metadata'] );
		$this->assertSame( array( 'photo-1-150x150.png', 'photo-1.png' ), $this->files(), 'No second copy on disk.' );
		$this->assertArrayNotHasKey( $prepared['transfer'], $GLOBALS['wpdb']->transfers, 'The transfer is used up.' );
	}

	public function test_a_translation_that_arrived_earlier_in_the_same_sync_is_shared_at_import(): void {
		$record = $this->record();

		// The transfer opens before the other copy is imported: files are sent.
		$prepared = Media::prepare( $record, 0, self::PEER );
		$this->assertSame( self::PATH, $prepared['main'] );
		$this->assertSame( array( true, true, true ), array_column( $prepared['files'], 'needed' ) );
		foreach ( $record['file']['files'] as $entry ) {
			Media::write( $prepared['transfer'], $entry['path'], 0, base64_encode( $this->png ), self::PEER, 8 );
		}

		// Then the other copy is imported, and this one after it.
		$this->sibling();
		$finished = Media::finalize( $prepared['transfer'], self::PEER, $record );

		$this->assertSame( 70, $finished['shared'] );
		$this->assertSame( '2026/03/photo-1.png', $finished['main'] );
		$this->assertSame( array( 'photo-1-150x150.png', 'photo-1.png' ), $this->files(), 'What was received is dropped, not kept as a second copy.' );
		$this->assertDirectoryDoesNotExist( $this->dir . '/jcore-silta-tmp/' . $prepared['transfer'] );
	}

	public function test_only_a_translation_from_the_same_site_with_the_same_bytes_is_shared(): void {
		$this->sibling();
		$own = static fn( array $prepared ): bool => array( true, true, true ) === array_column( $prepared['files'], 'needed' ) && '2026/03/photo-1.png' !== $prepared['main'];

		// The source doesn't call it a translation of this attachment.
		$this->assertTrue( $own( Media::prepare( $this->record( array() ), 0, self::PEER ) ), 'not listed as a translation' );
		$this->assertTrue( $own( Media::prepare( $this->record( array( 'fi' => 9 ) ), 0, self::PEER ) ), 'another attachment is' );

		// A file with the same name and other bytes.
		$this->assertTrue( $own( Media::prepare( $this->record( array( 'fi' => 7 ), $this->png . 'x' ), 0, self::PEER ) ), 'other bytes' );

		// The copy here was mapped for another site.
		$this->assertTrue( $own( Media::prepare( $this->record(), 0, 'peer-b' ) ), 'another site' );

		// The source's word on the language isn't taken: the copy here is
		// Finnish, whatever the record calls it, and never the record's own.
		$this->assertTrue( $own( Media::prepare( $this->record( array( 'en' => 7 ) ), 0, self::PEER ) ), 'listed under another language' );
		$this->assertTrue( $own( Media::prepare( $this->record( array( 'sv' => 7 ) ), 0, self::PEER ) ), 'listed under its own language' );
		$this->assertTrue( $own( Media::prepare( $this->record( array( 7 ) ), 0, self::PEER ) ), 'listed without a language' );

		// The copy here is in the trash, or gone.
		$GLOBALS['jcore_silta_media_test_posts'][70]->post_status = 'trash';
		$this->assertTrue( $own( Media::prepare( $this->record(), 0, self::PEER ) ), 'in the trash' );
		unset( $GLOBALS['jcore_silta_media_test_posts'][70] );
		$this->assertTrue( $own( Media::prepare( $this->record(), 0, self::PEER ) ), 'deleted' );
	}

	public function test_an_upload_the_other_site_only_matched_is_never_taken_over(): void {
		// Attachment 70 was here before any sync. The other site got it mapped
		// to its attachment 7 by matching it (same file name, or by claiming
		// it as its origin) without sending a byte, knows the file's hash,
		// and now claims a translation of it.
		$this->sibling( self::PEER, false );
		$record = $this->record();

		$this->assertNull( Media::sibling( $record, self::PEER ) );
		$prepared = Media::prepare( $record, 0, self::PEER );
		$this->assertSame( array( true, true, true ), array_column( $prepared['files'], 'needed' ), 'It has to bring files of its own.' );
		$this->assertNotSame( '2026/03/photo-1.png', $prepared['main'] );
	}

	public function test_what_the_other_site_delivered_has_to_be_this_attachment_and_these_bytes(): void {
		$own = static fn( array $prepared ): bool => array( true, true, true ) === array_column( $prepared['files'], 'needed' );

		// It delivered a file for another attachment, and then got the mapping
		// moved onto attachment 70, which was here before.
		$this->sibling();
		$GLOBALS['wpdb']->received[ self::PEER . ':7' ]['local'] = 71;
		$this->assertTrue( $own( Media::prepare( $this->record(), 0, self::PEER ) ), 'delivered for another attachment' );

		// It replaced the file of 70 with bytes of its own, and that sync was
		// undone: 70 has its earlier file again, which it never delivered.
		$GLOBALS['wpdb']->received[ self::PEER . ':7' ] = array(
			'local'  => 70,
			'hashes' => array( sha1( 'The file it delivered' ) ),
		);
		$this->assertTrue( $own( Media::prepare( $this->record(), 0, self::PEER ) ), 'delivered other bytes than are there now' );

		// A mapping from before this was recorded says nothing either way.
		$GLOBALS['wpdb']->received[ self::PEER . ':7' ] = array();
		$this->assertTrue( $own( Media::prepare( $this->record(), 0, self::PEER ) ), 'nothing recorded' );
	}

	public function test_nothing_is_shared_where_polylang_does_not_translate_media(): void {
		$this->sibling();
		$GLOBALS['jcore_silta_test_polylang_options'] = array( 'media_support' => false );

		$this->assertFalse( Media::shares_translations() );
		$prepared = Media::prepare( $this->record(), 0, self::PEER );
		$this->assertSame( array( true, true, true ), array_column( $prepared['files'], 'needed' ), 'Its own copy, as before.' );
		$this->assertNull( Media::sibling( $this->record(), self::PEER ) );

		// And a site can switch it off where Polylang does.
		$GLOBALS['jcore_silta_test_polylang_options']                                = array( 'media_support' => true );
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_media_share_translations'] = static fn(): bool => false;
		$this->assertNull( Media::sibling( $this->record(), self::PEER ) );
	}

	public function test_an_existing_attachment_never_takes_over_another_ones_file(): void {
		$this->sibling();

		// Replacing the file of attachment 71 that is here already: own files.
		$prepared = Media::prepare( $this->record(), 71, self::PEER );
		$this->assertSame( array( true, true, true ), array_column( $prepared['files'], 'needed' ) );
	}

	public function test_bytes_sent_for_a_shared_transfer_never_reach_the_shared_file(): void {
		$this->sibling();
		$record   = $this->record();
		$prepared = Media::prepare( $record, 0, self::PEER );

		// The other site sends bytes anyway, for the uploaded file.
		$written = Media::write( $prepared['transfer'], self::PATH, 0, base64_encode( $this->png ), self::PEER, 8 );
		$this->assertNotInstanceOf( WP_Error::class, $written );

		Media::finalize( $prepared['transfer'], self::PEER, $record );
		$this->assertSame( $this->png, file_get_contents( $this->dir . '/2026/03/photo-1.png' ) );
		$this->assertSame( array( 'photo-1-150x150.png', 'photo-1.png' ), $this->files() );
	}

	public function test_a_translation_that_went_away_means_the_files_are_sent_after_all(): void {
		$this->sibling();
		$record   = $this->record();
		$prepared = Media::prepare( $record, 0, self::PEER );

		// Its file is replaced before the import: no longer the same image.
		file_put_contents( $this->dir . '/2026/03/photo-1.png', 'Another image now' );

		$finished = Media::finalize( $prepared['transfer'], self::PEER, $record );
		$this->assertInstanceOf( WP_Error::class, $finished );
		$this->assertSame( 'jcore_silta_transfer_expired', $finished->get_error_code() );
		$this->assertTrue( Media::resend( $finished ), 'The job opens a new transfer.' );
		$this->assertSame( 'Another image now', file_get_contents( $this->dir . '/2026/03/photo-1.png' ), 'Nothing was written over it.' );
		$this->assertArrayNotHasKey( $prepared['transfer'], $GLOBALS['wpdb']->transfers );

		// And that one brings the files as its own copy.
		$again = Media::prepare( $record, 0, self::PEER );
		$this->assertSame( array( true, true, true ), array_column( $again['files'], 'needed' ) );
	}

	public function test_undo_keeps_a_file_another_attachment_uses(): void {
		$this->sibling();

		// Attachment 80 has the same file as 70 (stored as a full path, as old
		// attachments have it).
		$GLOBALS['jcore_silta_media_test_posts'][80]                     = new \WP_Post();
		$GLOBALS['jcore_silta_media_test_meta'][80]                      = $GLOBALS['jcore_silta_media_test_meta'][70];
		$GLOBALS['jcore_silta_media_test_meta'][70]['_wp_attached_file'] = $this->dir . '/2026/03/photo-1.png';

		$this->assertTrue( Media::file_in_use( 80 ) );
		$this->assertTrue( Media::file_in_use( 70 ) );
		Media::delete_attachment( 80 );
		$this->assertArrayNotHasKey( 80, $GLOBALS['jcore_silta_media_test_posts'], 'The attachment is gone.' );
		$this->assertSame( array( 'photo-1-150x150.png', 'photo-1.png' ), $this->files(), 'The file stays for attachment 70.' );
		$this->assertEmpty( array_filter( $GLOBALS['jcore_silta_test_actions']['wp_delete_file'] ?? array() ), 'The guard is taken off again.' );

		// The last attachment on a file it owns takes it along.
		$GLOBALS['jcore_silta_media_test_meta'][70]['_wp_attached_file'] = '2026/03/photo-1.png';
		$this->assertFalse( Media::file_in_use( 70 ) );
		Media::delete_attachment( 70 );
		$this->assertSame( array(), $this->files() );
	}

	public function test_undo_never_deletes_files_an_attachment_was_only_sharing(): void {
		$this->sibling();

		// Attachment 80 was created on the files of 70. Then 70 got another
		// file (a later sync replaced it; the old one stays so that sync can
		// be undone), which leaves 80 as the only attachment on the old file.
		$GLOBALS['jcore_silta_media_test_posts'][80]                     = new \WP_Post();
		$GLOBALS['jcore_silta_media_test_meta'][80]                      = $GLOBALS['jcore_silta_media_test_meta'][70];
		$GLOBALS['jcore_silta_media_test_meta'][70]['_wp_attached_file'] = '2026/03/replacement.png';
		$this->assertFalse( Media::file_in_use( 80 ), 'Nothing else points at the old file any more.' );

		// Undoing the sync that created 80 removes it, and leaves the files
		// it never brought: they are what undoing the replacement goes back to.
		Media::delete_attachment( 80, true );
		$this->assertArrayNotHasKey( 80, $GLOBALS['jcore_silta_media_test_posts'] );
		$this->assertSame( array( 'photo-1-150x150.png', 'photo-1.png' ), $this->files() );
	}
}
