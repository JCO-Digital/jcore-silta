<?php
/**
 * Attachment files: describing, reading and receiving them.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

use Jcore\Silta\Database;
use Jcore\Silta\Endpoint\Endpoint;
use Jcore\Silta\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves attachment files between sites in chunks.
 *
 * The source describes an attachment's files (original, scaled image, sizes)
 * and serves chunks of them. The target decides where each file goes, receives
 * the chunks into a temporary folder in uploads (shared between web servers,
 * unlike the system temp dir), checks the hashes and moves the files into
 * place. Existing files are never overwritten: when another file already sits
 * at the path, the incoming files get a new name and content URLs are rewritten.
 */
final class Media {

	/**
	 * Folder in uploads that receives files.
	 */
	private const TMP = 'jcore-silta-tmp';

	/**
	 * Per-transfer filesystem lock, released automatically when a request dies.
	 *
	 * @var resource|null
	 */
	private static $lock = null;

	/**
	 * Describes an attachment's files.
	 *
	 * @param int  $id     Attachment ID.
	 * @param bool $hashes Whether to hash the files.
	 *
	 * @return array{path: string, metadata: array<string, mixed>, files: array<int, array<string, mixed>>}|null
	 */
	public static function describe( int $id, bool $hashes = true ): ?array {
		$path = get_post_meta( $id, '_wp_attached_file', true );
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}

		$basedir = self::basedir();
		if ( str_starts_with( $path, $basedir . '/' ) ) {
			$path = ltrim( substr( $path, strlen( $basedir ) ), '/' );
		}

		if ( null === self::upload_path( $path ) || ! is_file( $basedir . '/' . $path ) ) {
			return null;
		}

		$metadata = wp_get_attachment_metadata( $id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$dir      = dirname( $path );
		$dir      = '.' === $dir ? '' : $dir . '/';

		$names = array( $path );
		if ( isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && basename( $metadata['original_image'] ) === $metadata['original_image'] ) {
			$names[] = $dir . $metadata['original_image'];
		}
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) && basename( $size['file'] ) === $size['file'] ) {
				$names[] = $dir . $size['file'];
			}
		}

		$files = array();
		foreach ( array_unique( $names ) as $name ) {
			$absolute = self::upload_path( $name );
			if ( null === $absolute || ! is_file( $absolute ) ) {
				continue;
			}
			$files[] = array(
				'path' => $name,
				'size' => (int) filesize( $absolute ),
				'hash' => $hashes ? (string) sha1_file( $absolute ) : '',
			);
		}

		return array(
			'path'     => $path,
			'metadata' => $metadata,
			'files'    => $files,
		);
	}

	/**
	 * Reads a chunk of one of an attachment's files.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $path   File path relative to uploads, as describe() lists it.
	 * @param int    $offset Byte offset.
	 * @param int    $length Bytes to read.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function read( int $id, string $path, int $offset, int $length ): array|WP_Error {
		if ( $offset < 0 || $length < 1 ) {
			return self::invalid_chunk();
		}
		$length = min( $length, self::chunk_size() );

		$described = self::describe( $id, false );
		$known     = array_column( (array) ( $described['files'] ?? array() ), 'size', 'path' );
		if ( ! isset( $known[ $path ] ) ) {
			return new WP_Error( 'jcore_silta_no_file', __( 'The file does not belong to this attachment.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$absolute = self::upload_path( $path );
		if ( null === $absolute || ! is_file( $absolute ) ) {
			return self::invalid_path();
		}
		$handle = fopen( $absolute, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Chunked reads.
		if ( false === $handle ) {
			return new WP_Error( 'jcore_silta_unreadable', __( 'The file could not be read.', 'jcore-silta' ), array( 'status' => 500 ) );
		}

		fseek( $handle, $offset );
		$data = (string) fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Chunked reads.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Chunked reads.

		return array(
			'data' => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary over JSON.
			'size' => (int) $known[ $path ],
			'eof'  => $offset + strlen( $data ) >= (int) $known[ $path ],
		);
	}

	/**
	 * Decides where an incoming attachment's files go and which of them have to
	 * be transferred, and opens a transfer for them.
	 *
	 * @param array<string, mixed> $record   Incoming attachment.
	 * @param int                  $local_id Matched local attachment, or 0.
	 * @param string               $peer     Verified peer site UUID.
	 *
	 * @return array<string, mixed>|WP_Error Transfer ID and file list.
	 */
	public static function prepare( array $record, int $local_id, string $peer ): array|WP_Error {
		global $wpdb;

		$file = $record['file'] ?? null;
		if ( ! is_array( $file ) || ! isset( $file['path'], $file['files'] ) || ! is_string( $file['path'] ) || ! is_array( $file['files'] ) || empty( $file['files'] ) || null === self::record_key( $record ) || '' === $peer ) {
			return new WP_Error( 'jcore_silta_no_file', __( 'The attachment has no valid file description.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		$max_files = max( 1, (int) apply_filters( 'jcore_silta_media_max_files', 256 ) );
		$max_size  = max( 1, (int) apply_filters( 'jcore_silta_media_max_bytes', 5 * GB_IN_BYTES ) );
		if ( count( $file['files'] ) > $max_files ) {
			return self::limit_error();
		}
		if ( ! wp_mkdir_p( self::basedir() ) ) {
			return new WP_Error( 'jcore_silta_tmp', __( 'The uploads folder is not writable.', 'jcore-silta' ), array( 'status' => 500 ) );
		}

		$main_type = self::file_type( $file['path'] );
		if ( null === $main_type ) {
			return self::invalid_type();
		}
		$files = array();
		$total = 0;
		foreach ( $file['files'] as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['path'], $entry['size'], $entry['hash'] ) || ! is_string( $entry['path'] ) || ! is_int( $entry['size'] ) || $entry['size'] < 0 || ! is_string( $entry['hash'] ) || ! preg_match( '/^[a-f0-9]{40}$/D', $entry['hash'] ) ) {
				return self::invalid_path();
			}
			$source = self::clean_path( $entry['path'] );
			if ( null === $source || null === self::upload_path( $source ) || isset( $files[ $source ] ) || dirname( $source ) !== dirname( $file['path'] ) ) {
				return self::invalid_path();
			}
			$type = self::file_type( $source );
			if ( null === $type || ( $source !== $file['path'] && ( ( ! str_starts_with( $main_type, 'image/' ) && 'application/pdf' !== $main_type ) || ! str_starts_with( $type, 'image/' ) ) ) ) {
				return self::invalid_type();
			}
			if ( $entry['size'] > $max_size - $total ) {
				return self::limit_error();
			}
			$total           += $entry['size'];
			$files[ $source ] = array(
				'source' => $source,
				'target' => $source,
				'size'   => $entry['size'],
				'hash'   => $entry['hash'],
				'needed' => true,
			);
		}
		$main = self::clean_path( $file['path'] );
		if ( null === $main || ! isset( $files[ $main ] ) ) {
			return self::invalid_path();
		}

		$metadata = $file['metadata'] ?? array();
		if ( ! is_array( $metadata ) || ( isset( $metadata['sizes'] ) && ! is_array( $metadata['sizes'] ) ) ) {
			return self::invalid_path();
		}
		$names = array();
		if ( isset( $metadata['original_image'] ) ) {
			$names[] = $metadata['original_image'];
		}
		foreach ( $metadata['sizes'] ?? array() as $size ) {
			if ( ! is_array( $size ) || ! isset( $size['file'] ) ) {
				return self::invalid_path();
			}
			$names[] = $size['file'];
		}
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || basename( $name ) !== $name || null === self::file_type( $name ) || ( ! str_starts_with( $main_type, 'image/' ) && 'application/pdf' !== $main_type ) || ! str_starts_with( (string) self::file_type( $name ), 'image/' ) ) {
				return self::invalid_type();
			}
		}

		if ( ! self::lock() ) {
			return self::busy();
		}
		try {
			$transfers = (array) get_option( self::OPTION, array() );
			$open      = count( array_filter( $transfers, static fn( array $manifest ): bool => $manifest['peer'] === $peer ) );
			if ( $open >= max( 1, (int) apply_filters( 'jcore_silta_media_max_open_transfers', 1000, $peer ) ) ) {
				return self::limit_error();
			}

			// A new transfer always places its own copies; undo must never own a
			// file merely because its bytes happen to match an existing upload.
			foreach ( $files as $entry ) {
				if ( file_exists( self::basedir() . '/' . $entry['target'] ) ) {
					$files = self::rename( $files, $main, is_array( $file['metadata'] ?? null ) ? $file['metadata'] : array() );
					break;
				}
			}
			$transfer = wp_generate_uuid4();
			$dir      = self::staged_path( $transfer );
			if ( null === $dir || ! wp_mkdir_p( $dir ) || null === self::staged_path( $transfer ) ) {
				return self::invalid_path();
			}
			self::protect( dirname( $dir ) );
			foreach ( $files as $entry ) {
				if ( null === self::upload_path( $entry['target'] ) || null === self::staged_path( $transfer, $entry['source'] ) || null === self::file_type( $entry['target'] ) ) {
					self::delete_dir( $dir );
					return self::invalid_path();
				}
			}

			$manifest               = array(
				'transfer' => $transfer,
				'peer'     => $peer,
				'record'   => self::record_key( $record ),
				'id'       => $record['id'],
				'local_id' => $local_id,
				'main'     => $files[ $main ]['target'],
				'metadata' => $metadata,
				'files'    => array_values( $files ),
				'created'  => time(),
			);
			$transfers[ $transfer ] = $manifest;
			if ( ! update_option( self::OPTION, $transfers, false ) ) {
				self::delete_dir( $dir );
				return self::busy();
			}

			return array(
				'transfer' => $transfer,
				'main'     => $manifest['main'],
				'files'    => array_map(
					static fn( array $entry ): array => array(
						'path'   => $entry['source'],
						'size'   => $entry['size'],
						'needed' => true,
					),
					$manifest['files']
				),
			);
		} finally {
			self::unlock();
		}
	}

	/**
	 * Appends a chunk to a file of a transfer.
	 *
	 * @param string $transfer Transfer ID.
	 * @param string $path     Source path of the file.
	 * @param int    $offset   Byte offset of the chunk.
	 * @param string $data      Base64 chunk.
	 * @param string $peer      Verified peer site UUID.
	 * @param int    $record_id Source attachment ID.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function write( string $transfer, string $path, int $offset, string $data, string $peer, int $record_id ): array|WP_Error {
		$manifest = self::manifest( $transfer, $peer, $record_id );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		if ( ! self::lock( $transfer ) ) {
			return self::busy();
		}
		try {
			$manifest = self::manifest( $transfer, $peer, $record_id );
			if ( is_wp_error( $manifest ) ) {
				return $manifest;
			}
			$entry = null;
			foreach ( $manifest['files'] as $candidate ) {
				if ( $candidate['source'] === $path ) {
					$entry = $candidate;
				}
			}
			if ( null === $entry ) {
				return self::invalid_path();
			}
			$tmp = self::staged_path( $transfer, $path );
			if ( null === $tmp || null === self::upload_path( $entry['target'] ) ) {
				return self::invalid_path();
			}
			clearstatcache( true, $tmp );
			$received = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
			if ( $offset < 0 || $offset > $received || $received > $entry['size'] || strlen( $data ) > 4 * (int) ceil( 16 * MB_IN_BYTES / 3 ) ) {
				return self::invalid_chunk();
			}
			$bytes = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary over JSON.
			if ( false === $bytes || strlen( $bytes ) > 16 * MB_IN_BYTES || strlen( $bytes ) > $entry['size'] - $offset || ( '' === $bytes && $offset < $entry['size'] ) ) {
				return self::invalid_chunk();
			}
			if ( $offset < $received ) {
				return array(
					'received' => $received,
					'complete' => $received === $entry['size'],
					'resync'   => true,
				);
			}
			if ( strlen( $bytes ) !== file_put_contents( $tmp, $bytes, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Chunked writes.
				return new WP_Error( 'jcore_silta_write', __( 'The file chunk could not be saved.', 'jcore-silta' ), array( 'status' => 500 ) );
			}
			clearstatcache( true, $tmp );
			$received = (int) filesize( $tmp );
			$complete = $received === $entry['size'];
			if ( $complete && sha1_file( $tmp ) !== $entry['hash'] ) {
				wp_delete_file( $tmp );
				return new WP_Error( 'jcore_silta_hash', __( 'A file arrived damaged. Run the sync again.', 'jcore-silta' ), array( 'status' => 400 ) );
			}
			return array(
				'received' => $received,
				'complete' => $complete,
			);
		} finally {
			self::unlock();
		}
	}

	/**
	 * Moves a transfer's files into place.
	 *
	 * @param string               $transfer Transfer ID.
	 * @param string               $peer     Verified peer site UUID.
	 * @param array<string, mixed> $record   Original source attachment record.
	 * @param int                  $local_id Matched local attachment, or 0.
	 *
	 * @return array<string, mixed>|WP_Error Validated files, MIME type and metadata.
	 */
	public static function finalize( string $transfer, string $peer, array $record, int $local_id = 0 ): array|WP_Error {
		global $wpdb;

		$manifest = self::manifest( $transfer, $peer, (int) ( $record['id'] ?? 0 ) );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		if ( ! self::lock( $transfer ) ) {
			return self::busy();
		}
		try {
			$manifest = self::manifest( $transfer, $peer, (int) ( $record['id'] ?? 0 ) );
			if ( is_wp_error( $manifest ) ) {
				return $manifest;
			}
			if ( $manifest['record'] !== self::record_key( $record ) || $manifest['local_id'] !== $local_id ) {
				return new WP_Error( 'jcore_silta_transfer_record', __( 'The transfer belongs to another attachment.', 'jcore-silta' ), array( 'status' => 403 ) );
			}

			$map   = array();
			$types = array();
			// Validate every file before moving any bytes into public uploads.
			foreach ( $manifest['files'] as $entry ) {
				$tmp    = self::staged_path( $transfer, $entry['source'] );
				$target = self::upload_path( $entry['target'] );
				if ( null === $tmp || null === $target || null === self::clean_path( $entry['source'] ) ) {
					return self::invalid_path();
				}
				clearstatcache( true, $tmp );
				if ( ! is_file( $tmp ) || (int) filesize( $tmp ) !== $entry['size'] || sha1_file( $tmp ) !== $entry['hash'] ) {
					return new WP_Error( 'jcore_silta_incomplete', __( 'Not every file of the attachment has arrived intact.', 'jcore-silta' ), array( 'status' => 400 ) );
				}
				$expected = self::file_type( $entry['target'] );
				$checked  = wp_check_filetype_and_ext( $tmp, basename( $entry['target'] ), get_allowed_mime_types() );
				if ( null === $expected || empty( $checked['ext'] ) || ( $checked['type'] ?? false ) !== $expected || ! empty( $checked['proper_filename'] ) ) {
					return self::invalid_type();
				}
				if ( file_exists( $target ) ) {
					return new WP_Error( 'jcore_silta_clash', __( 'Another file appeared at the attachment\'s path during the sync. Run the sync again.', 'jcore-silta' ), array( 'status' => 409 ) );
				}
				$map[ $entry['source'] ]   = $entry['target'];
				$types[ $entry['target'] ] = $expected;
			}
			$mime = $types[ $manifest['main'] ];
			foreach ( $types as $path => $type ) {
				if ( $path !== $manifest['main'] && ( ( ! str_starts_with( $mime, 'image/' ) && 'application/pdf' !== $mime ) || ! str_starts_with( $type, 'image/' ) ) ) {
					return self::invalid_type();
				}
			}

			// Exactly one finalizer may consume the row before moving any files.
			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic single-use transfer consumption.
				$wpdb->prepare( 'DELETE FROM %i WHERE transfer = %s', Database::table( 'transfers' ), $transfer )
			);
			if ( false === $deleted ) {
				return self::busy();
			}
			if ( 1 !== $deleted ) {
				return self::missing_transfer();
			}
			foreach ( $manifest['files'] as $entry ) {
				$target = self::upload_path( $entry['target'] );
				$tmp    = self::staged_path( $transfer, $entry['source'] );
				if ( null === $target || null === $tmp || ! wp_mkdir_p( dirname( $target ) ) || null === self::upload_path( $entry['target'] ) || file_exists( $target ) || ! rename( $tmp, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same filesystem.
					return new WP_Error( 'jcore_silta_move', __( 'A file could not be moved into the uploads folder. Run the sync again.', 'jcore-silta' ), array( 'status' => 500 ) );
				}
			}
			self::delete_dir( (string) self::staged_path( $transfer ) );

			return array(
				'main'     => $manifest['main'],
				'map'      => $map,
				'mime'     => $mime,
				'metadata' => $manifest['metadata'],
			);
		} finally {
			self::unlock();
		}
	}

	/**
	 * Builds the attachment metadata for the target from the source's, with
	 * renamed files applied and missing files left out.
	 *
	 * @param array<string, mixed>  $metadata Source metadata.
	 * @param string                $main     Target path of the main file.
	 * @param array<string, string> $map      Source → target paths.
	 *
	 * @return array<string, mixed>
	 */
	public static function target_metadata( array $metadata, string $main, array $map ): array {
		$by_name = array();
		foreach ( $map as $source => $target ) {
			if ( null !== self::upload_path( $target ) && is_file( self::basedir() . '/' . $target ) && dirname( $target ) === dirname( $main ) ) {
				$by_name[ basename( $source ) ] = basename( $target );
			}
		}

		// Only core's non-path fields survive; legacy thumb and plugin-defined
		// path fields must not later make core delete an unrelated upload.
		$result = array( 'file' => $main );
		foreach ( array( 'width', 'height', 'filesize' ) as $key ) {
			if ( isset( $metadata[ $key ] ) && is_numeric( $metadata[ $key ] ) ) {
				$result[ $key ] = max( 0, (int) $metadata[ $key ] );
			}
		}
		if ( is_array( $metadata['image_meta'] ?? null ) ) {
			$result['image_meta'] = $metadata['image_meta'];
		}
		$original = $metadata['original_image'] ?? null;
		if ( is_string( $original ) && isset( $by_name[ $original ] ) ) {
			$result['original_image'] = $by_name[ $original ];
		}
		$result['sizes'] = array();
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $name => $size ) {
			if ( ! is_array( $size ) || ! is_string( $size['file'] ?? null ) || ! isset( $by_name[ $size['file'] ] ) ) {
				continue;
			}
			$file                     = $by_name[ $size['file'] ];
			$result['sizes'][ $name ] = array(
				'file'      => $file,
				'width'     => max( 0, (int) ( $size['width'] ?? 0 ) ),
				'height'    => max( 0, (int) ( $size['height'] ?? 0 ) ),
				'mime-type' => self::file_type( $file ),
			);
		}

		return $result;
	}

	/**
	 * Absolute path of the uploads base directory, without a trailing slash.
	 *
	 * @return string
	 */
	public static function basedir(): string {
		return untrailingslashit( (string) wp_get_upload_dir()['basedir'] );
	}

	/**
	 * Deletes transfers older than a day.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		if ( ! self::lock() ) {
			return;
		}
		try {
			$transfers = (array) get_option( self::OPTION, array() );
			foreach ( $transfers as $transfer => $manifest ) {
				if ( (int) ( $manifest['created'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
					$dir = self::staged_path( (string) $transfer );
					if ( null !== $dir && is_dir( $dir ) ) {
						self::delete_dir( $dir );
					}
					unset( $transfers[ $transfer ] );
				}
			}
			update_option( self::OPTION, $transfers, false );

			// Remove abandoned and legacy disk manifests without trusting them.
			$root = self::resolved_path( self::TMP, true );
			if ( null === $root || ! is_dir( $root ) ) {
				return;
			}
			foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
				$transfer = basename( $dir );
				if ( wp_is_uuid( $transfer ) && ! isset( $transfers[ $transfer ] ) && null !== self::staged_path( $transfer ) && filemtime( $dir ) < time() - DAY_IN_SECONDS ) {
					self::delete_dir( $dir );
				}
			}
		} finally {
			self::unlock();
		}
	}

	/**
	 * Gives every file a new base name derived from a free name for the main
	 * file, e.g. photo.jpg → photo-1.jpg and photo-300x200.jpg → photo-1-300x200.jpg.
	 *
	 * @param array<string, array<string, mixed>> $files    Files keyed by source path.
	 * @param string                              $main     Source path of the main file.
	 * @param array<string, mixed>                $metadata Source metadata.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function rename( array $files, string $main, array $metadata ): array {
		$basedir = self::basedir();
		$dir     = dirname( $main );
		$dir     = '.' === $dir ? '' : $dir . '/';

		// The root name is the original upload's, before "-scaled" was added.
		$original  = $metadata['original_image'] ?? null;
		$root_file = is_string( $original ) && isset( $files[ $dir . $original ] ) && basename( $original ) === $original ? $original : basename( $main );
		$extension = pathinfo( $root_file, PATHINFO_EXTENSION );
		$root      = pathinfo( $root_file, PATHINFO_FILENAME );

		$free     = wp_unique_filename( $basedir . '/' . $dir, $root_file );
		$new_root = pathinfo( $free, PATHINFO_FILENAME );

		$used = array();
		foreach ( $files as $source => $entry ) {
			$name = basename( $source );
			if ( str_starts_with( $name, $root ) ) {
				$name = $new_root . substr( $name, strlen( $root ) );
			} else {
				$name = $new_root . '-' . $name;
			}

			$candidate = $name;
			$suffix    = 2;
			while ( isset( $used[ $candidate ] ) || file_exists( $basedir . '/' . $dir . $candidate ) || is_link( $basedir . '/' . $dir . $candidate ) ) {
				$candidate = pathinfo( $name, PATHINFO_FILENAME ) . '-' . $suffix . ( '' !== $extension ? '.' . pathinfo( $name, PATHINFO_EXTENSION ) : '' );
				++$suffix;
			}

			$used[ $candidate ]         = true;
			$files[ $source ]['target'] = $dir . $candidate;
			$files[ $source ]['needed'] = true;
		}

		return $files;
	}

	/**
	 * Loads a server-owned manifest and checks its owner and attachment.
	 *
	 * @param string $transfer  Transfer ID.
	 * @param string $peer      Verified peer UUID.
	 * @param int    $record_id Source attachment ID.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function manifest( string $transfer, string $peer, int $record_id ): array|WP_Error {
		$transfers = (array) get_option( self::OPTION, array() );
		$data      = wp_is_uuid( $transfer ) ? ( $transfers[ $transfer ] ?? null ) : null;
		if ( ! is_array( $data ) || ! is_array( $data['files'] ?? null ) || empty( $data['files'] ) || ( $data['transfer'] ?? null ) !== $transfer || ! is_int( $data['created'] ?? null ) || $data['created'] < time() - DAY_IN_SECONDS || ! is_string( $data['main'] ?? null ) || ! is_string( $data['record'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['record'] ) || ! is_string( $data['peer'] ?? null ) || ! is_int( $data['id'] ?? null ) || ! is_int( $data['local_id'] ?? null ) || ! is_array( $data['metadata'] ?? null ) ) {
			return new WP_Error( 'jcore_silta_no_transfer', __( 'Unknown or expired file transfer.', 'jcore-silta' ), array( 'status' => 404 ) );
		}
		if ( $data['peer'] !== $peer || $data['id'] !== $record_id ) {
			return new WP_Error( 'jcore_silta_transfer_owner', __( 'The transfer belongs to another peer or attachment.', 'jcore-silta' ), array( 'status' => 403 ) );
		}

		$targets = array();
		foreach ( $data['files'] as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['source'] ?? null ) || ! is_string( $entry['target'] ?? null ) || ! is_int( $entry['size'] ?? null ) || $entry['size'] < 0 || ! is_string( $entry['hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{40}$/D', $entry['hash'] ) || null === self::clean_path( $entry['source'] ) || null === self::upload_path( $entry['target'] ) || null === self::staged_path( $transfer, $entry['source'] ) || isset( $targets[ $entry['target'] ] ) ) {
				return self::invalid_path();
			}
			$targets[ $entry['target'] ] = true;
		}
		if ( ! isset( $targets[ $data['main'] ] ) ) {
			return self::invalid_path();
		}

		return $data;
	}

	/**
	 * Normalises a relative uploads path and rejects traversal.
	 *
	 * @param string $path Path.
	 *
	 * @return string|null
	 */
	private static function clean_path( string $path ): ?string {
		if ( '' === $path || preg_match( '/[\x00-\x1f\x7f]/', $path ) || str_contains( $path, '\\' ) || str_contains( $path, ':' ) || 0 !== validate_file( $path ) ) {
			return null;
		}
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || str_starts_with( $part, '.' ) || strtolower( $part ) === self::TMP || sanitize_file_name( $part ) !== $part ) {
				return null;
			}
		}

		return $path;
	}

	/**
	 * Resolves a target's existing parents and rejects uploads escapes.
	 *
	 * @param string $path Relative uploads path.
	 *
	 * @return string|null
	 */
	private static function upload_path( string $path ): ?string {
		return null === self::clean_path( $path ) ? null : self::resolved_path( $path, false );
	}

	/**
	 * Checks existing parents, including symlinks, before using a path.
	 *
	 * @param string $path   Relative path, already cleaned or generated locally.
	 * @param bool   $staged Whether this is a server-generated staging path.
	 *
	 * @return string|null
	 */
	private static function resolved_path( string $path, bool $staged ): ?string {
		$base = realpath( self::basedir() );
		if ( false === $base || ! is_dir( $base ) ) {
			return null;
		}
		$current = $base;
		$parts   = explode( '/', $path );
		foreach ( $parts as $index => $part ) {
			$current .= '/' . $part;
			clearstatcache( true, $current );
			if ( is_link( $current ) && ( $staged || $index === count( $parts ) - 1 ) ) {
				return null;
			}
			if ( file_exists( $current ) || is_link( $current ) ) {
				$resolved = realpath( $current );
				if ( false === $resolved || ! str_starts_with( $resolved, $base . '/' ) || ( ! $staged && ( $resolved === $base . '/' . self::TMP || str_starts_with( $resolved, $base . '/' . self::TMP . '/' ) ) ) ) {
					return null;
				}
				$current = $resolved;
				if ( $index < count( $parts ) - 1 && ! is_dir( $current ) ) {
					return null;
				}
			}
		}

		return $current;
	}

	/**
	 * Resolves a staging path generated solely from a UUID and a path hash.
	 *
	 * @param string $transfer Transfer ID.
	 * @param string $source   Source path, or empty for the directory.
	 *
	 * @return string|null
	 */
	private static function staged_path( string $transfer, string $source = '' ): ?string {
		if ( ! wp_is_uuid( $transfer ) || ( '' !== $source && null === self::clean_path( $source ) ) ) {
			return null;
		}
		return self::resolved_path( self::TMP . '/' . $transfer . ( '' === $source ? '' : '/' . md5( $source ) ), true );
	}

	/**
	 * Checks a clean filename against the acting user's allowed MIME types.
	 *
	 * @param string $path Relative path.
	 *
	 * @return string|null
	 */
	private static function file_type( string $path ): ?string {
		if ( null === self::clean_path( $path ) || preg_match( '/\.(?:php\d*|phtml|pht|phar|html?|shtml|svg|svgz)(?:\.|$)/i', basename( $path ) ) ) {
			return null;
		}
		$type = wp_check_filetype( basename( $path ), get_allowed_mime_types() );
		if ( empty( $type['ext'] ) || empty( $type['type'] ) || in_array( $type['type'], array( 'text/html', 'image/svg+xml', 'application/x-httpd-php' ), true ) ) {
			return null;
		}
		return $type['type'];
	}

	/**
	 * Binds a transfer to the source attachment and its original file record.
	 *
	 * @param array<string, mixed> $record Incoming record before transformation.
	 *
	 * @return string|null
	 */
	private static function record_key( array $record ): ?string {
		if ( ! is_int( $record['id'] ?? null ) || $record['id'] < 1 || ( $record['key'] ?? '' ) !== 'post:' . $record['id'] || ( $record['post_type'] ?? '' ) !== 'attachment' || ( $record['type'] ?? '' ) !== 'post' ) {
			return null;
		}
		$encoded = wp_json_encode( array( $record['key'], $record['file'] ?? null ) );
		return false === $encoded ? null : hash( 'sha256', $encoded );
	}

	/**
	 * Maximum chunk size: the configured size, small enough that a base64
	 * chunk and its JSON envelope stay under the request body limit.
	 *
	 * @return int
	 */
	public static function chunk_size(): int {
		$fits = (int) floor( ( Endpoint::body_limit() - 64 * KB_IN_BYTES ) * 3 / 4 );

		return max( 1, min( 16 * MB_IN_BYTES, (int) Settings::get( 'chunk_kb' ) * 1024, $fits ) );
	}

	/**
	 * Locks manifest mutations, writes and finalization across requests.
	 *
	 * @return bool
	 */
	private static function lock(): bool {
		if ( null !== self::$lock ) {
			return false;
		}
		$root = self::resolved_path( self::TMP, true );
		if ( null === $root || ! wp_mkdir_p( $root ) ) {
			return false;
		}
		$path = self::resolved_path( self::TMP . '/.lock', true );
		if ( null === $path ) {
			return false;
		}
		self::protect( $root );
		$handle = fopen( $path, 'cb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Shared filesystem lock.
		if ( false === $handle ) {
			return false;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Shared filesystem lock.
			return false;
		}
		self::$lock = $handle;
		// A previous operation in this request may have cached the manifest
		// option before another request acquired this lock and changed it.
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return true;
	}

	/**
	 * Releases the manifest lock.
	 *
	 * @return void
	 */
	private static function unlock(): void {
		if ( null !== self::$lock ) {
			flock( self::$lock, LOCK_UN );
			fclose( self::$lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Shared filesystem lock.
			self::$lock = null;
		}
	}

	/**
	 * Reports a transfer currently being changed by another request.
	 *
	 * @return WP_Error
	 */
	private static function busy(): WP_Error {
		return new WP_Error( 'jcore_silta_transfer_busy', __( 'A media transfer is busy. Try again.', 'jcore-silta' ), array( 'status' => 409 ) );
	}

	/**
	 * Reports an invalid file path or declaration.
	 *
	 * @return WP_Error
	 */
	private static function invalid_path(): WP_Error {
		return new WP_Error( 'jcore_silta_bad_path', __( 'The attachment has an invalid file path or description.', 'jcore-silta' ), array( 'status' => 400 ) );
	}

	/**
	 * Reports a disallowed file type.
	 *
	 * @return WP_Error
	 */
	private static function invalid_type(): WP_Error {
		return new WP_Error( 'jcore_silta_file_type', __( 'The attachment contains a file type that is not allowed.', 'jcore-silta' ), array( 'status' => 400 ) );
	}

	/**
	 * Reports an invalid chunk.
	 *
	 * @return WP_Error
	 */
	private static function invalid_chunk(): WP_Error {
		return new WP_Error( 'jcore_silta_bad_chunk', __( 'The file chunk is invalid.', 'jcore-silta' ), array( 'status' => 400 ) );
	}

	/**
	 * Reports a transfer exceeding its resource allowance.
	 *
	 * @return WP_Error
	 */
	private static function limit_error(): WP_Error {
		return new WP_Error( 'jcore_silta_media_limit', __( 'The media transfer exceeds this site\'s resource limits.', 'jcore-silta' ), array( 'status' => 413 ) );
	}

	/**
	 * Keeps the transfer folder out of reach of the web server.
	 *
	 * @param string $dir Folder.
	 *
	 * @return void
	 */
	private static function protect( string $dir ): void {
		if ( ! file_exists( $dir . '/index.php' ) && ! is_link( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Guard file.
		}
		if ( ! file_exists( $dir . '/.htaccess' ) && ! is_link( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Guard file.
		}
	}

	/**
	 * Deletes a folder and its files.
	 *
	 * @param string $dir Folder.
	 *
	 * @return void
	 */
	private static function delete_dir( string $dir ): void {
		$transfer = basename( $dir );
		if ( self::staged_path( $transfer ) !== $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_string( $file ) && ( is_file( $file ) || is_link( $file ) ) ) {
				wp_delete_file( $file );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Temporary folder.
	}
}
