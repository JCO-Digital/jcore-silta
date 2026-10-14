<?php
/**
 * WordPress stand-ins for isolated media validation tests.
 *
 * @package Jcore\Silta
 */

// phpcs:disable Universal.Namespaces -- Scoped fixture helpers coexist with guarded global core stand-ins.

namespace Jcore\Silta\Sync {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_get_upload_dir' ) ) {
		/**
		 * Isolates media's upload directory from other test fixture helpers.
		 *
		 * @return array<string, string>
		 */
		function wp_get_upload_dir(): array {
			return array(
				'basedir' => $GLOBALS['jcore_silta_media_test_dir'] ?? sys_get_temp_dir(),
				'baseurl' => 'https://local.example/uploads',
			);
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_cache_delete' ) ) {
		/**
		 * No object cache is used in media fixtures.
		 *
		 * @param string $key   Cache key.
		 * @param string $group Cache group.
		 *
		 * @return bool
		 */
		function wp_cache_delete( string $key, string $group ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Core signature.
			return true;
		}
	}
}

namespace {

	require_once __DIR__ . '/wp-error.php';

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Guarded WordPress class stand-ins.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Guarded WordPress stand-ins.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Match core signatures.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Core function stand-ins.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Isolated temporary test files.

	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
	if ( ! defined( 'KB_IN_BYTES' ) ) {
		define( 'KB_IN_BYTES', 1024 );
	}
	if ( ! defined( 'MB_IN_BYTES' ) ) {
		define( 'MB_IN_BYTES', 1024 * 1024 );
	}
	if ( ! defined( 'GB_IN_BYTES' ) ) {
		define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );
	}

	if ( ! class_exists( 'WP_Post' ) ) {
		/**
		 * Attachment post used by undo fixtures.
		 */
		class WP_Post {
			/**
			 * Post type.
			 *
			 * @var string
			 */
			public string $post_type = 'attachment';
		}
	}

	if ( ! function_exists( 'get_post' ) ) {
		/**
		 * Returns the fixture attachment.
		 *
		 * @param int $id Post ID.
		 *
		 * @return WP_Post|null
		 */
		function get_post( int $id ): ?WP_Post {
			return $GLOBALS['jcore_silta_media_test_posts'][ $id ] ?? null;
		}
	}

	if ( ! function_exists( 'wp_update_post' ) ) {
		/**
		 * Records changes without deleting fixture files.
		 *
		 * @param array $data  Post fields.
		 * @param bool  $error Return errors.
		 *
		 * @return int
		 */
		function wp_update_post( array $data, bool $error = false ): int {
			$GLOBALS['jcore_silta_media_test_updated'][ $data['ID'] ] = $data;
			return $data['ID'];
		}
	}

	if ( ! function_exists( 'delete_post_meta' ) ) {
		/**
		 * Removes scheduled deletion metadata from the fixture attachment.
		 *
		 * @param int    $id  Post ID.
		 * @param string $key Meta key.
		 *
		 * @return bool
		 */
		function delete_post_meta( int $id, string $key ): bool {
			unset( $GLOBALS['jcore_silta_media_test_meta'][ $id ][ $key ] );
			return true;
		}
	}

	if ( ! function_exists( '__' ) ) {
		/**
		 * Returns untranslated text in tests.
		 *
		 * @param string $text   Text.
		 * @param string $domain Domain.
		 *
		 * @return string
		 */
		function __( string $text, string $domain = 'default' ): string {
			return $text;
		}
	}

	if ( ! function_exists( 'wp_get_upload_dir' ) ) {
		/**
		 * Returns the isolated upload directory.
		 *
		 * @return array<string, string>
		 */
		function wp_get_upload_dir(): array {
			return array(
				'basedir' => $GLOBALS['jcore_silta_media_test_dir'] ?? sys_get_temp_dir(),
				'baseurl' => 'https://local.example/uploads',
			);
		}
	}

	if ( ! function_exists( 'get_post_meta' ) ) {
		/**
		 * Returns test attachment meta.
		 *
		 * @param int    $id     ID.
		 * @param string $key    Meta key.
		 * @param bool   $single Single value.
		 *
		 * @return mixed
		 */
		function get_post_meta( int $id, string $key = '', bool $single = false ): mixed {
			return $GLOBALS['jcore_silta_media_test_meta'][ $id ][ $key ] ?? '';
		}
	}

	if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
		/**
		 * Returns test file metadata.
		 *
		 * @param int $id Attachment.
		 *
		 * @return mixed
		 */
		function wp_get_attachment_metadata( int $id ): mixed {
			return get_post_meta( $id, '_wp_attachment_metadata', true );
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		/**
		 * Reads the test option store.
		 *
		 * @param string $key     Key.
		 * @param mixed  $fallback Default.
		 *
		 * @return mixed
		 */
		function get_option( string $key, mixed $fallback = false ): mixed {
			return $GLOBALS['jcore_silta_test_options'][ $key ] ?? $fallback;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		/**
		 * Updates a test option.
		 *
		 * @param string $key      Key.
		 * @param mixed  $value    Value.
		 * @param bool   $autoload Autoload.
		 *
		 * @return bool
		 */
		function update_option( string $key, mixed $value, bool $autoload = true ): bool {
			if ( get_option( $key ) === $value ) {
				return false;
			}
			$GLOBALS['jcore_silta_test_options'][ $key ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'wp_generate_uuid4' ) ) {
		/**
		 * Generates an isolated test transfer UUID.
		 *
		 * @return string
		 */
		function wp_generate_uuid4(): string {
			return '12345678-1234-4234-8234-' . bin2hex( random_bytes( 6 ) );
		}
	}

	if ( ! function_exists( 'wp_is_uuid' ) ) {
		/**
		 * Validates a UUID.
		 *
		 * @param string $value UUID.
		 *
		 * @return bool
		 */
		function wp_is_uuid( string $value ): bool {
			return (bool) preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $value );
		}
	}

	if ( ! function_exists( 'validate_file' ) ) {
		/**
		 * Rejects traversal and drive-letter paths like core.
		 *
		 * @param string $file Path.
		 *
		 * @return int
		 */
		function validate_file( string $file ): int {
			return str_contains( $file, '../' ) || '..' === $file || str_contains( $file, ':' ) ? 1 : 0;
		}
	}

	if ( ! function_exists( 'sanitize_file_name' ) ) {
		/**
		 * Cleans the ASCII test filenames and executable inner extensions.
		 *
		 * @param string $name Filename.
		 *
		 * @return string
		 */
		function sanitize_file_name( string $name ): string {
			$clean = preg_replace( '/[\\x00-\\x20?<>:"\\\\|*]/', '', $name );
			$clean = preg_replace( '/\\.(php|phtml|phar)\\./i', '.$1_.', $clean );
			return trim( $clean, '.-_' );
		}
	}

	if ( ! function_exists( 'get_allowed_mime_types' ) ) {
		/**
		 * Core-like types used by the fixtures, including filtered unsafe types.
		 *
		 * @return array<string, string>
		 */
		function get_allowed_mime_types(): array {
			return apply_filters(
				'jcore_silta_media_test_mimes',
				array(
					'png'  => 'image/png',
					'jpg'  => 'image/jpeg',
					'webp' => 'image/webp',
					'pdf'  => 'application/pdf',
					'php'  => 'application/x-httpd-php',
					'html' => 'text/html',
					'svg'  => 'image/svg+xml',
				)
			);
		}
	}

	if ( ! function_exists( 'wp_check_filetype' ) ) {
		/**
		 * Checks the extension against the supplied allowlist.
		 *
		 * @param string     $file  Filename.
		 * @param array|null $mimes Allowlist.
		 *
		 * @return array<string, string|false>
		 */
		function wp_check_filetype( string $file, ?array $mimes = null ): array {
			$mimes = $mimes ?? get_allowed_mime_types();
			$ext   = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			return array(
				'ext'  => isset( $mimes[ $ext ] ) ? $ext : false,
				'type' => $mimes[ $ext ] ?? false,
			);
		}
	}

	if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
		/**
		 * Inspects image bytes; other test bytes must be a PDF header.
		 *
		 * @param string $file     Staged file.
		 * @param string $filename Original filename.
		 * @param array  $mimes    Allowed types.
		 *
		 * @return array<string, string|false>
		 */
		function wp_check_filetype_and_ext( string $file, string $filename, array $mimes ): array {
			$type = wp_check_filetype( $filename, $mimes );
			if ( str_starts_with( (string) $type['type'], 'image/' ) ) {
				$image  = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image fixture.
				$actual = $image['mime'] ?? false;
			} else {
				$actual = str_starts_with( (string) file_get_contents( $file ), '%PDF-' ) ? 'application/pdf' : false;
			}
			return array(
				'ext'             => $actual === $type['type'] ? $type['ext'] : false,
				'type'            => $actual === $type['type'] ? $actual : false,
				'proper_filename' => false,
			);
		}
	}

	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		/**
		 * Creates a test directory.
		 *
		 * @param string $dir Directory.
		 *
		 * @return bool
		 */
		function wp_mkdir_p( string $dir ): bool {
			return is_dir( $dir ) || mkdir( $dir, 0777, true );
		}
	}

	if ( ! function_exists( 'wp_delete_file' ) ) {
		/**
		 * Removes a test file.
		 *
		 * @param string $file File.
		 *
		 * @return void
		 */
		function wp_delete_file( string $file ): void {
			unlink( $file );
		}
	}

	if ( ! function_exists( 'wp_unique_filename' ) ) {
		/**
		 * Chooses a unique test filename.
		 *
		 * @param string $dir  Directory.
		 * @param string $name Filename.
		 *
		 * @return string
		 */
		function wp_unique_filename( string $dir, string $name ): string {
			$ext  = pathinfo( $name, PATHINFO_EXTENSION );
			$root = pathinfo( $name, PATHINFO_FILENAME );
			$free = $name;
			$i    = 1;
			while ( file_exists( $dir . '/' . $free ) ) {
				$free = $root . '-' . $i . '.' . $ext;
				++$i;
			}
			return $free;
		}
	}
}
