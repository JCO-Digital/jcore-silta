<?php
/**
 * Attachment files: describing, reading and receiving them.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta\Sync;

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
	 * Describes an attachment's files.
	 *
	 * @param int  $id     Attachment ID.
	 * @param bool $hashes Whether to hash the files.
	 *
	 * @return array{path: string, metadata: array<string, mixed>, files: array<int, array<string, mixed>>}|null
	 */
	public static function describe( int $id, bool $hashes = true ): ?array {
		$path = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $path ) {
			return null;
		}

		$basedir = self::basedir();
		if ( str_starts_with( $path, $basedir ) ) {
			$path = ltrim( substr( $path, strlen( $basedir ) ), '/' );
		}

		$metadata = wp_get_attachment_metadata( $id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$dir      = dirname( $path );
		$dir      = '.' === $dir ? '' : $dir . '/';

		$names = array( $path );
		if ( ! empty( $metadata['original_image'] ) ) {
			$names[] = $dir . $metadata['original_image'];
		}
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$names[] = $dir . $size['file'];
			}
		}

		$files = array();
		foreach ( array_unique( $names ) as $name ) {
			$absolute = $basedir . '/' . $name;
			if ( ! is_file( $absolute ) ) {
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
		$described = self::describe( $id, false );
		$known     = array_column( (array) ( $described['files'] ?? array() ), 'size', 'path' );
		if ( ! isset( $known[ $path ] ) ) {
			return new WP_Error( 'jcore_silta_no_file', __( 'The file does not belong to this attachment.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$absolute = self::basedir() . '/' . $path;
		$handle   = fopen( $absolute, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Chunked reads.
		if ( false === $handle ) {
			return new WP_Error( 'jcore_silta_unreadable', __( 'The file could not be read.', 'jcore-silta' ), array( 'status' => 500 ) );
		}

		fseek( $handle, max( 0, $offset ) );
		$data = (string) fread( $handle, max( 1, $length ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Chunked reads.
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
	 *
	 * @return array<string, mixed>|WP_Error Transfer ID and file list.
	 */
	public static function prepare( array $record, int $local_id ): array|WP_Error {
		$file = $record['file'] ?? null;
		if ( ! is_array( $file ) || empty( $file['path'] ) || empty( $file['files'] ) ) {
			return new WP_Error( 'jcore_silta_no_file', __( 'The attachment has no file.', 'jcore-silta' ) );
		}

		$basedir = self::basedir();
		$files   = array();
		foreach ( (array) $file['files'] as $entry ) {
			$source = self::clean_path( (string) $entry['path'] );
			if ( null === $source ) {
				return new WP_Error( 'jcore_silta_bad_path', __( 'The attachment has an invalid file path.', 'jcore-silta' ) );
			}
			$files[ $source ] = array(
				'source' => $source,
				'target' => $source,
				'size'   => (int) $entry['size'],
				'hash'   => (string) $entry['hash'],
				'needed' => true,
			);
		}

		$main = self::clean_path( (string) $file['path'] );
		if ( null === $main || ! isset( $files[ $main ] ) ) {
			return new WP_Error( 'jcore_silta_bad_path', __( 'The attachment has an invalid file path.', 'jcore-silta' ) );
		}

		// The matched attachment may already hold the very same files.
		if ( $local_id > 0 ) {
			$local = self::describe( $local_id );
			if ( null !== $local && $local['path'] !== $main ) {
				$local_hashes = array_column( $local['files'], 'path', 'hash' );
				if ( isset( $local_hashes[ $files[ $main ]['hash'] ] ) ) {
					foreach ( $files as $source => $entry ) {
						if ( isset( $local_hashes[ $entry['hash'] ] ) ) {
							$files[ $source ]['target'] = $local_hashes[ $entry['hash'] ];
						}
					}
				}
			}
		}

		// When the main file is already there, the target keeps its own copies of
		// the generated sizes: another image editor produces different bytes for
		// the same size, which is no reason to duplicate the attachment.
		$main_file = $basedir . '/' . $files[ $main ]['target'];
		$main_same = is_file( $main_file ) && sha1_file( $main_file ) === $files[ $main ]['hash'];
		$clash     = is_file( $main_file ) && ! $main_same;

		foreach ( $files as $source => $entry ) {
			$absolute = $basedir . '/' . $entry['target'];
			if ( $clash || ! is_file( $absolute ) ) {
				continue;
			}
			if ( $main_same || sha1_file( $absolute ) === $entry['hash'] ) {
				$files[ $source ]['needed'] = false;
			} else {
				$clash = true;
			}
		}

		if ( $clash ) {
			$files = self::rename( $files, $main, (array) ( $file['metadata'] ?? array() ) );
		}

		$transfer = wp_generate_uuid4();
		$dir      = self::tmp_dir( $transfer );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'jcore_silta_tmp', __( 'The uploads folder is not writable.', 'jcore-silta' ) );
		}
		self::protect( dirname( $dir ) );

		$manifest = array(
			'transfer' => $transfer,
			'main'     => $files[ $main ]['target'],
			'files'    => array_values( $files ),
			'created'  => time(),
		);
		file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary manifest.

		return array(
			'transfer' => $transfer,
			'main'     => $manifest['main'],
			'files'    => array_map(
				static function ( array $entry ): array {
					return array(
						'path'   => $entry['source'],
						'size'   => $entry['size'],
						'needed' => $entry['needed'],
					);
				},
				$manifest['files']
			),
		);
	}

	/**
	 * Appends a chunk to a file of a transfer.
	 *
	 * @param string $transfer Transfer ID.
	 * @param string $path     Source path of the file.
	 * @param int    $offset   Byte offset of the chunk.
	 * @param string $data     Base64 chunk.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function write( string $transfer, string $path, int $offset, string $data ): array|WP_Error {
		$manifest = self::manifest( $transfer );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$entry = null;
		foreach ( $manifest['files'] as $candidate ) {
			if ( $candidate['source'] === $path && $candidate['needed'] ) {
				$entry = $candidate;
			}
		}
		if ( null === $entry ) {
			return new WP_Error( 'jcore_silta_no_file', __( 'The transfer does not expect this file.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		$tmp      = self::tmp_dir( $transfer ) . '/' . md5( $path );
		$received = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
		if ( $offset !== $received ) {
			return array(
				'received' => $received,
				'complete' => $received === $entry['size'],
				'resync'   => true,
			);
		}

		$bytes = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary over JSON.
		if ( false === $bytes || $received + strlen( $bytes ) > $entry['size'] ) {
			return new WP_Error( 'jcore_silta_bad_chunk', __( 'The file chunk is invalid.', 'jcore-silta' ), array( 'status' => 400 ) );
		}

		file_put_contents( $tmp, $bytes, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Chunked writes.
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
	}

	/**
	 * Moves a transfer's files into place.
	 *
	 * @param string $transfer Transfer ID.
	 *
	 * @return array{main: string, map: array<string, string>}|WP_Error Main file path and source → target paths.
	 */
	public static function finalize( string $transfer ): array|WP_Error {
		$manifest = self::manifest( $transfer );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$basedir = self::basedir();
		$tmp_dir = self::tmp_dir( $transfer );
		$map     = array();

		foreach ( $manifest['files'] as $entry ) {
			$map[ $entry['source'] ] = $entry['target'];
			if ( ! $entry['needed'] ) {
				continue;
			}

			$tmp = $tmp_dir . '/' . md5( $entry['source'] );
			if ( 0 === (int) $entry['size'] && ! is_file( $tmp ) ) {
				file_put_contents( $tmp, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Empty files never get a chunk.
			}
			if ( ! is_file( $tmp ) || (int) filesize( $tmp ) !== (int) $entry['size'] ) {
				return new WP_Error( 'jcore_silta_incomplete', __( 'Not every file of the attachment has arrived.', 'jcore-silta' ) );
			}

			$target = $basedir . '/' . $entry['target'];
			if ( ! wp_mkdir_p( dirname( $target ) ) ) {
				return new WP_Error( 'jcore_silta_tmp', __( 'The uploads folder is not writable.', 'jcore-silta' ) );
			}
			if ( is_file( $target ) && sha1_file( $target ) !== $entry['hash'] ) {
				return new WP_Error( 'jcore_silta_clash', __( 'Another file appeared at the attachment\'s path during the sync. Run the sync again.', 'jcore-silta' ) );
			}
			if ( ! is_file( $target ) && ! rename( $tmp, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same filesystem.
				return new WP_Error( 'jcore_silta_move', __( 'A file could not be moved into the uploads folder.', 'jcore-silta' ) );
			}
		}

		self::delete_dir( $tmp_dir );

		return array(
			'main' => (string) $manifest['main'],
			'map'  => $map,
		);
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
		$basedir = self::basedir();
		$dir     = dirname( $main );
		$dir     = '.' === $dir ? '' : $dir . '/';
		$by_name = array();
		foreach ( $map as $source => $target ) {
			$by_name[ basename( $source ) ] = basename( $target );
		}

		$metadata['file'] = $main;
		if ( ! empty( $metadata['original_image'] ) ) {
			$metadata['original_image'] = $by_name[ $metadata['original_image'] ] ?? $metadata['original_image'];
		}
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $name => $size ) {
			$file = $by_name[ $size['file'] ?? '' ] ?? ( $size['file'] ?? '' );
			if ( '' === $file || ! is_file( $basedir . '/' . $dir . $file ) ) {
				unset( $metadata['sizes'][ $name ] );
				continue;
			}
			$metadata['sizes'][ $name ]['file'] = $file;
		}

		return $metadata;
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
		$root = self::basedir() . '/' . self::TMP;
		if ( ! is_dir( $root ) ) {
			return;
		}

		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( is_string( $dir ) && filemtime( $dir ) < time() - DAY_IN_SECONDS ) {
				self::delete_dir( $dir );
			}
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
		$root_file = ! empty( $metadata['original_image'] ) ? (string) $metadata['original_image'] : basename( $main );
		$extension = pathinfo( $root_file, PATHINFO_EXTENSION );
		$root      = pathinfo( $root_file, PATHINFO_FILENAME );

		wp_mkdir_p( $basedir . '/' . $dir );
		$free     = wp_unique_filename( $basedir . '/' . $dir, $root_file );
		$new_root = pathinfo( $free, PATHINFO_FILENAME );

		foreach ( $files as $source => $entry ) {
			$name = basename( $source );
			if ( str_starts_with( $name, $root ) ) {
				$name = $new_root . substr( $name, strlen( $root ) );
			} else {
				$name = $new_root . '-' . $name;
			}

			$candidate = $name;
			$suffix    = 2;
			while ( is_file( $basedir . '/' . $dir . $candidate ) ) {
				$candidate = pathinfo( $name, PATHINFO_FILENAME ) . '-' . $suffix . ( '' !== $extension ? '.' . pathinfo( $name, PATHINFO_EXTENSION ) : '' );
				++$suffix;
			}

			$files[ $source ]['target'] = $dir . $candidate;
			$files[ $source ]['needed'] = true;
		}

		return $files;
	}

	/**
	 * Loads a transfer's manifest.
	 *
	 * @param string $transfer Transfer ID.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function manifest( string $transfer ): array|WP_Error {
		if ( ! wp_is_uuid( $transfer ) ) {
			return new WP_Error( 'jcore_silta_no_transfer', __( 'Unknown file transfer.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		$file = self::tmp_dir( $transfer ) . '/manifest.json';
		$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( ! is_array( $data ) || empty( $data['files'] ) ) {
			return new WP_Error( 'jcore_silta_no_transfer', __( 'Unknown file transfer.', 'jcore-silta' ), array( 'status' => 404 ) );
		}

		return $data;
	}

	/**
	 * Absolute path of a transfer's folder.
	 *
	 * @param string $transfer Transfer ID.
	 *
	 * @return string
	 */
	private static function tmp_dir( string $transfer ): string {
		return self::basedir() . '/' . self::TMP . '/' . $transfer;
	}

	/**
	 * Normalises a relative uploads path and rejects traversal.
	 *
	 * @param string $path Path.
	 *
	 * @return string|null
	 */
	private static function clean_path( string $path ): ?string {
		$path = ltrim( str_replace( '\\', '/', $path ), '/' );
		if ( '' === $path || str_contains( $path, '..' ) || str_contains( $path, "\0" ) || 0 !== validate_file( $path ) ) {
			return null;
		}

		return $path;
	}

	/**
	 * Keeps the transfer folder out of reach of the web server.
	 *
	 * @param string $dir Folder.
	 *
	 * @return void
	 */
	private static function protect( string $dir ): void {
		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Guard file.
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
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
		foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Temporary folder.
	}
}
