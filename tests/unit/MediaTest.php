<?php
/**
 * Tests for media transfer validation without a WordPress database.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Tests;

use Jcore\Silta\Sync\Media;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/stubs/media.php';

// phpcs:disable WordPress.WP.AlternativeFunctions -- Isolated temporary test files.
// phpcs:disable WordPress.WP.GlobalVariablesOverride -- Isolated undo database fixture.

/**
 * Transfer boundaries, resource limits and attachment metadata.
 */
final class MediaTest extends TestCase {

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
	 * Creates a fresh upload directory and option store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/jcore-silta-media-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->dir );
		$this->png                              = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=' );
		$GLOBALS['jcore_silta_media_test_dir']  = $this->dir;
		$GLOBALS['jcore_silta_test_options']    = array();
		$GLOBALS['jcore_silta_media_test_meta'] = array();
	}

	/**
	 * Deletes fixtures without following symlinks.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->delete_tree( $this->dir );
		unset( $GLOBALS['jcore_silta_media_test_posts'], $GLOBALS['jcore_silta_media_test_updated'] );
		foreach ( array_keys( $GLOBALS['jcore_silta_test_filters'] ) as $hook ) {
			if ( str_starts_with( $hook, 'jcore_silta_media_' ) ) {
				unset( $GLOBALS['jcore_silta_test_filters'][ $hook ] );
			}
		}
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
			$file = $dir . '/' . $name;
			if ( is_dir( $file ) && ! is_link( $file ) ) {
				$this->delete_tree( $file );
			} else {
				unlink( $file );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Builds an attachment record matching the wire format.
	 *
	 * @param string $path Path.
	 * @param string $data File bytes.
	 *
	 * @return array<string, mixed>
	 */
	private function record( string $path = '2026/10/photo.png', string $data = '' ): array {
		$data = '' === $data ? $this->png : $data;
		return array(
			'type'      => 'post',
			'key'       => 'post:7',
			'id'        => 7,
			'post_type' => 'attachment',
			'file'      => array(
				'path'     => $path,
				'metadata' => array(),
				'files'    => array(
					array(
						'path' => $path,
						'size' => strlen( $data ),
						'hash' => sha1( $data ),
					),
				),
			),
		);
	}

	/**
	 * Checks the error code and HTTP status.
	 *
	 * @param string $code   Expected code.
	 * @param mixed  $result Result.
	 *
	 * @return void
	 */
	private function error( string $code, mixed $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertArrayHasKey( 'status', $result->get_error_data() );
	}

	public function test_transfer_is_bound_to_peer_record_and_consumed_once(): void {
		$record   = $this->record();
		$prepared = Media::prepare( $record, 0, 'peer-a' );
		$this->assertIsArray( $prepared );
		$transfer = $prepared['transfer'];
		$this->assertFileDoesNotExist( $this->dir . '/jcore-silta-tmp/' . $transfer . '/manifest.json' );
		$this->error( 'jcore_silta_transfer_owner', Media::write( $transfer, $record['file']['path'], 0, base64_encode( $this->png ), 'peer-b', 7 ) );
		$this->error( 'jcore_silta_transfer_owner', Media::write( $transfer, $record['file']['path'], 0, base64_encode( $this->png ), 'peer-a', 8 ) );
		$this->assertTrue( Media::write( $transfer, $record['file']['path'], 0, base64_encode( $this->png ), 'peer-a', 7 )['complete'] );
		$changed                                       = $record;
		$changed['file']['metadata']['original_image'] = 'other.png';
		$this->error( 'jcore_silta_transfer_record', Media::finalize( $transfer, 'peer-a', $changed ) );
		$this->error( 'jcore_silta_transfer_record', Media::finalize( $transfer, 'peer-a', $record, 99 ) );
		$this->error( 'jcore_silta_transfer_owner', Media::finalize( $transfer, 'peer-b', $record ) );
		$finished = Media::finalize( $transfer, 'peer-a', $record );
		$this->assertSame( 'image/png', $finished['mime'] );
		$this->assertSame( $this->png, file_get_contents( $this->dir . '/' . $finished['main'] ) );
		$this->error( 'jcore_silta_no_transfer', Media::finalize( $transfer, 'peer-a', $record ) );
		$this->error( 'jcore_silta_no_transfer', Media::write( $transfer, $record['file']['path'], 0, base64_encode( $this->png ), 'peer-a', 7 ) );
	}

	public function test_disk_manifest_cannot_authorize_a_transfer(): void {
		$transfer = wp_generate_uuid4();
		$dir      = $this->dir . '/jcore-silta-tmp/' . $transfer;
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/manifest.json', wp_json_encode( array( 'files' => array( array( 'target' => 'photo.php' ) ) ) ) );
		$this->error( 'jcore_silta_no_transfer', Media::finalize( $transfer, 'peer-a', $this->record() ) );
		$this->error( 'jcore_silta_no_transfer', Media::finalize( '../../bad', 'peer-a', $this->record() ) );
	}

	public function test_prepare_rejects_unclean_reserved_and_disallowed_paths(): void {
		foreach ( array( '../photo.png', '/photo.png', 'a/../photo.png', 'a//photo.png', 'a\\photo.png', 'a:photo.png', "bad\0.png", 'jcore-silta-tmp/photo.png', 'JCORE-SILTA-TMP/photo.png', '.hidden/photo.png', 'a/.user.ini', 'a/.htaccess', 'a/photo.php', 'a/photo.php.png', 'a/photo.phtml', 'a/photo.phar', 'a/photo.html', 'a/photo.svg', 'a/space name.png' ) as $path ) {
			$this->assertInstanceOf( WP_Error::class, Media::prepare( $this->record( $path ), 0, 'peer-a' ), $path );
		}
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_media_test_mimes'] = static fn(): array => array();
		$this->error( 'jcore_silta_file_type', Media::prepare( $this->record(), 0, 'peer-a' ) );
	}

	public function test_prepare_checks_every_metadata_filename_and_auxiliary_kind(): void {
		foreach ( array( '../outside.png', '.hidden.png', 'bad.php', 'image.svg', 'other.pdf' ) as $name ) {
			$record                                       = $this->record();
			$record['file']['metadata']['original_image'] = $name;
			$this->error( 'jcore_silta_file_type', Media::prepare( $record, 0, 'peer-a' ) );
			unset( $record['file']['metadata']['original_image'] );
			$record['file']['metadata']['sizes'] = array( 'thumbnail' => array( 'file' => $name ) );
			$this->error( 'jcore_silta_file_type', Media::prepare( $record, 0, 'peer-a' ) );
		}
		$record                    = $this->record();
		$record['file']['files'][] = array(
			'path' => '2026/10/other.pdf',
			'size' => 5,
			'hash' => sha1( '%PDF-' ),
		);
		$this->error( 'jcore_silta_file_type', Media::prepare( $record, 0, 'peer-a' ) );
	}

	public function test_prepare_limits_files_bytes_and_open_transfers_per_peer(): void {
		$record = $this->record();
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_media_max_bytes'] = static fn(): int => 10;
		$this->error( 'jcore_silta_media_limit', Media::prepare( $record, 0, 'peer-a' ) );
		unset( $GLOBALS['jcore_silta_test_filters']['jcore_silta_media_max_bytes'] );
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_media_max_files'] = static fn(): int => 1;
		$record['file']['files'][] = $record['file']['files'][0];
		$this->error( 'jcore_silta_media_limit', Media::prepare( $record, 0, 'peer-a' ) );
		unset( $GLOBALS['jcore_silta_test_filters']['jcore_silta_media_max_files'] );
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_media_max_open_transfers'] = static fn(): int => 1;
		$this->assertIsArray( Media::prepare( $this->record(), 0, 'peer-a' ) );
		$this->error( 'jcore_silta_media_limit', Media::prepare( $this->record(), 0, 'peer-a' ) );
		$this->assertIsArray( Media::prepare( $this->record(), 0, 'peer-b' ) );
	}

	public function test_prepare_rejects_malformed_size_hash_and_duplicate_entries(): void {
		foreach ( array( -1, '10', 1.5, null ) as $size ) {
			$record                             = $this->record();
			$record['file']['files'][0]['size'] = $size;
			$this->error( 'jcore_silta_bad_path', Media::prepare( $record, 0, 'peer-a' ) );
		}
		$record                             = $this->record();
		$record['file']['files'][0]['hash'] = 'not-a-hash';
		$this->error( 'jcore_silta_bad_path', Media::prepare( $record, 0, 'peer-a' ) );
		$record                    = $this->record();
		$record['file']['files'][] = $record['file']['files'][0];
		$this->error( 'jcore_silta_bad_path', Media::prepare( $record, 0, 'peer-a' ) );
	}

	public function test_writes_reject_gaps_overflows_invalid_base64_and_negative_offsets(): void {
		$record   = $this->record();
		$transfer = Media::prepare( $record, 0, 'peer-a' )['transfer'];
		$path     = $record['file']['path'];
		foreach ( array( -1, 1, strlen( $this->png ) + 1 ) as $offset ) {
			$this->error( 'jcore_silta_bad_chunk', Media::write( $transfer, $path, $offset, base64_encode( $this->png ), 'peer-a', 7 ) );
		}
		$this->error( 'jcore_silta_bad_chunk', Media::write( $transfer, $path, 0, '!!!!', 'peer-a', 7 ) );
		$this->error( 'jcore_silta_bad_chunk', Media::write( $transfer, $path, 0, base64_encode( $this->png . 'overflow' ), 'peer-a', 7 ) );
		$this->assertSame( 10, Media::write( $transfer, $path, 0, base64_encode( substr( $this->png, 0, 10 ) ), 'peer-a', 7 )['received'] );
		$this->assertTrue( Media::write( $transfer, $path, 0, base64_encode( substr( $this->png, 0, 10 ) ), 'peer-a', 7 )['resync'] );
		$this->assertTrue( Media::write( $transfer, $path, 10, base64_encode( substr( $this->png, 10 ) ), 'peer-a', 7 )['complete'] );
	}

	public function test_finalize_checks_all_bytes_before_moving_any_file(): void {
		$record                    = $this->record();
		$record['file']['files'][] = array(
			'path' => '2026/10/fake.png',
			'size' => 5,
			'hash' => sha1( '<?php' ),
		);
		$transfer                  = Media::prepare( $record, 0, 'peer-a' )['transfer'];
		Media::write( $transfer, $record['file']['path'], 0, base64_encode( $this->png ), 'peer-a', 7 );
		Media::write( $transfer, '2026/10/fake.png', 0, base64_encode( '<?php' ), 'peer-a', 7 );
		$this->error( 'jcore_silta_file_type', Media::finalize( $transfer, 'peer-a', $record ) );
		$this->assertFileDoesNotExist( $this->dir . '/' . $record['file']['path'] );
	}

	public function test_finalize_rechecks_hashes_and_target_collisions(): void {
		$record   = $this->record();
		$transfer = Media::prepare( $record, 0, 'peer-a' )['transfer'];
		$path     = $record['file']['path'];
		Media::write( $transfer, $path, 0, base64_encode( $this->png ), 'peer-a', 7 );
		$tmp = $this->dir . '/jcore-silta-tmp/' . $transfer . '/' . md5( $path );
		file_put_contents( $tmp, str_repeat( 'x', strlen( $this->png ) ) );
		$this->error( 'jcore_silta_incomplete', Media::finalize( $transfer, 'peer-a', $record ) );
		file_put_contents( $tmp, $this->png );
		mkdir( $this->dir . '/2026/10', 0777, true );
		file_put_contents( $this->dir . '/' . $path, $this->png );
		$this->error( 'jcore_silta_clash', Media::finalize( $transfer, 'peer-a', $record ) );
	}

	public function test_existing_identical_files_are_never_reused(): void {
		$record = $this->record();
		mkdir( $this->dir . '/2026/10', 0777, true );
		file_put_contents( $this->dir . '/' . $record['file']['path'], $this->png );
		$prepared = Media::prepare( $record, 0, 'peer-a' );
		$this->assertNotSame( $record['file']['path'], $prepared['main'] );
		$this->assertTrue( $prepared['files'][0]['needed'] );
		Media::write( $prepared['transfer'], $record['file']['path'], 0, base64_encode( $this->png ), 'peer-a', 7 );
		$finished = Media::finalize( $prepared['transfer'], 'peer-a', $record );
		$this->assertSame( $this->png, file_get_contents( $this->dir . '/' . $record['file']['path'] ) );
		$this->assertFileExists( $this->dir . '/' . $finished['main'] );
	}

	public function test_symlinks_cannot_escape_uploads_or_reach_staging(): void {
		$external = $this->dir . '-outside';
		mkdir( $external );
		file_put_contents( $external . '/secret.png', $this->png );
		try {
			symlink( $external, $this->dir . '/outside' );
			$this->error( 'jcore_silta_bad_path', Media::prepare( $this->record( 'outside/secret.png' ), 0, 'peer-a' ) );
			$GLOBALS['jcore_silta_media_test_meta'][7]['_wp_attached_file'] = 'outside/secret.png';
			$this->assertNull( Media::describe( 7 ) );
			wp_mkdir_p( $this->dir . '/jcore-silta-tmp' );
			symlink( $this->dir . '/jcore-silta-tmp', $this->dir . '/alias' );
			$this->error( 'jcore_silta_bad_path', Media::prepare( $this->record( 'alias/photo.png' ), 0, 'peer-a' ) );
			$record   = $this->record( 'later/photo.png' );
			$transfer = Media::prepare( $record, 0, 'peer-a' )['transfer'];
			Media::write( $transfer, $record['file']['path'], 0, base64_encode( $this->png ), 'peer-a', 7 );
			symlink( $external, $this->dir . '/later' );
			$this->error( 'jcore_silta_bad_path', Media::write( $transfer, $record['file']['path'], 0, base64_encode( $this->png ), 'peer-a', 7 ) );
			$this->error( 'jcore_silta_bad_path', Media::finalize( $transfer, 'peer-a', $record ) );
		} finally {
			$this->delete_tree( $external );
		}
	}

	public function test_staged_symlinks_are_rejected_at_write_and_finalize(): void {
		$record   = $this->record();
		$transfer = Media::prepare( $record, 0, 'peer-a' )['transfer'];
		$file     = $this->dir . '/jcore-silta-tmp/' . $transfer . '/' . md5( $record['file']['path'] );
		file_put_contents( $this->dir . '/innocent.png', $this->png );
		symlink( $this->dir . '/innocent.png', $file );
		$this->error( 'jcore_silta_bad_path', Media::write( $transfer, $record['file']['path'], 0, base64_encode( $this->png ), 'peer-a', 7 ) );
		$this->error( 'jcore_silta_bad_path', Media::finalize( $transfer, 'peer-a', $record ) );
	}

	public function test_describe_and_read_reject_traversal_and_bound_reads(): void {
		file_put_contents( $this->dir . '/photo.png', $this->png );
		file_put_contents( $this->dir . '/large.pdf', str_repeat( 'x', Media::chunk_size() + 20 ) );
		$GLOBALS['jcore_silta_media_test_meta'][7] = array(
			'_wp_attached_file'       => 'photo.png',
			'_wp_attachment_metadata' => array(
				'original_image' => '../outside.png',
				'sizes'          => array( 'bad' => array( 'file' => '../other.png' ) ),
			),
		);
		$this->assertCount( 1, Media::describe( 7 )['files'] );
		$this->error( 'jcore_silta_bad_chunk', Media::read( 7, 'photo.png', -1, 10 ) );
		$this->error( 'jcore_silta_bad_chunk', Media::read( 7, 'photo.png', 0, 0 ) );
		$GLOBALS['jcore_silta_media_test_meta'][7]['_wp_attached_file'] = '../../wp-config.php';
		$this->assertNull( Media::describe( 7 ) );
		$this->error( 'jcore_silta_no_file', Media::read( 7, '../../wp-config.php', 0, 10 ) );
		$GLOBALS['jcore_silta_media_test_meta'][7]['_wp_attached_file'] = 'large.pdf';
		$read = Media::read( 7, 'large.pdf', 0, PHP_INT_MAX );
		$this->assertSame( Media::chunk_size(), strlen( base64_decode( $read['data'] ) ) );
		$this->assertFalse( $read['eof'] );
	}

	public function test_metadata_contains_only_mapped_basenames(): void {
		mkdir( $this->dir . '/2026/10', 0777, true );
		foreach ( array( 'main.png', 'original.png', 'small.png', 'unrelated.png' ) as $name ) {
			file_put_contents( $this->dir . '/2026/10/' . $name, $this->png );
		}
		$metadata = array(
			'file'           => '../../bad.png',
			'thumb'          => 'unrelated.png',
			'original_image' => 'source.png',
			'sizes'          => array(
				'good'    => array( 'file' => 'source-small.png' ),
				'unknown' => array( 'file' => 'unrelated.png' ),
				'escape'  => array( 'file' => '../small.png' ),
			),
		);
		$map      = array(
			'old/source.png'       => '2026/10/original.png',
			'old/source-small.png' => '2026/10/small.png',
		);
		$result   = Media::target_metadata( $metadata, '2026/10/main.png', $map );
		$this->assertSame( '2026/10/main.png', $result['file'] );
		$this->assertSame( 'original.png', $result['original_image'] );
		$this->assertSame( 'small.png', $result['sizes']['good']['file'] );
		$this->assertSame( array( 'good' ), array_keys( $result['sizes'] ) );
		$this->assertArrayNotHasKey( 'thumb', $result );
		$result = Media::target_metadata( array( 'original_image' => '../original.png' ), '2026/10/main.png', $map );
		$this->assertArrayNotHasKey( 'original_image', $result );
	}

	public function test_cleanup_expires_server_manifests_and_staged_bytes(): void {
		$record   = $this->record();
		$transfer = Media::prepare( $record, 0, 'peer-a' )['transfer'];
		$GLOBALS['jcore_silta_test_options']['jcore_silta_media_transfers'][ $transfer ]['created'] = time() - DAY_IN_SECONDS - 1;
		$this->error( 'jcore_silta_no_transfer', Media::finalize( $transfer, 'peer-a', $record ) );
		Media::cleanup();
		$this->assertSame( array(), get_option( 'jcore_silta_media_transfers' ) );
		$this->assertDirectoryDoesNotExist( $this->dir . '/jcore-silta-tmp/' . $transfer );
		$this->assertIsArray( Media::prepare( $record, 0, 'peer-a' ) );
	}
	public function test_total_limit_counts_all_files_and_rejects_integer_overflow(): void {
		$record                    = $this->record();
		$record['file']['files'][] = array(
			'path' => '2026/10/small.png',
			'size' => strlen( $this->png ),
			'hash' => sha1( $this->png ),
		);
		$GLOBALS['jcore_silta_test_filters']['jcore_silta_media_max_bytes'] = fn(): int => 2 * strlen( $this->png ) - 1;
		$this->error( 'jcore_silta_media_limit', Media::prepare( $record, 0, 'peer-a' ) );
		unset( $GLOBALS['jcore_silta_test_filters']['jcore_silta_media_max_bytes'] );
		$record['file']['files'][0]['size'] = PHP_INT_MAX;
		$this->error( 'jcore_silta_media_limit', Media::prepare( $record, 0, 'peer-a' ) );
	}

	public function test_invalid_json_cannot_weaken_the_record_binding(): void {
		$record = $this->record();
		$record['file']['metadata']['image_meta']['caption'] = "\xFF";
		$this->error( 'jcore_silta_no_file', Media::prepare( $record, 0, 'peer-a' ) );
	}

	public function test_concurrent_finalize_is_blocked_by_the_shared_file_lock(): void {
		$record   = $this->record();
		$transfer = Media::prepare( $record, 0, 'peer-a' )['transfer'];
		$handle   = fopen( $this->dir . '/jcore-silta-tmp/.lock', 'cb' );
		$this->assertTrue( flock( $handle, LOCK_EX | LOCK_NB ) );
		try {
			$this->error( 'jcore_silta_transfer_busy', Media::finalize( $transfer, 'peer-a', $record ) );
			$this->error( 'jcore_silta_transfer_busy', Media::write( $transfer, $record['file']['path'], 0, base64_encode( $this->png ), 'peer-a', 7 ) );
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
		$this->assertTrue( Media::write( $transfer, $record['file']['path'], 0, base64_encode( $this->png ), 'peer-a', 7 )['complete'] );
		$this->assertIsArray( Media::finalize( $transfer, 'peer-a', $record ) );
	}

	public function test_cleanup_removes_legacy_manifests_without_following_symlinks(): void {
		$transfer = wp_generate_uuid4();
		$dir      = $this->dir . '/jcore-silta-tmp/' . $transfer;
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/manifest.json', '{}' );
		touch( $dir, time() - DAY_IN_SECONDS - 10 );
		Media::cleanup();
		$this->assertDirectoryDoesNotExist( $dir );
		$external = $this->dir . '/protected';
		mkdir( $external );
		file_put_contents( $external . '/manifest.json', 'keep' );
		$linked = $this->dir . '/jcore-silta-tmp/' . wp_generate_uuid4();
		symlink( $external, $linked );
		touch( $external, time() - DAY_IN_SECONDS - 10 );
		Media::cleanup();
		$this->assertSame( 'keep', file_get_contents( $external . '/manifest.json' ) );
	}
}
